<?php

namespace App\Support;

use App\Models\GanttChart;
use App\Models\GanttRow;
use App\Models\GanttTask;
use Carbon\CarbonImmutable;

/**
 * ガントチャートの Excel 出力。
 * シート1「ガントチャート」: 列 = 月、行 = 科目・学習分野。区間はその月の範囲を色で塗り、先頭セルに名前（結合）。
 * シート2「区間一覧」: 行・区間・開始日・終了日・日数・進捗・メモの一覧。
 */
final class GanttExcel
{
    /** 行の中で区間が重なっているときに使う、2 本目以降の表示（結合せずに塗りだけ） */
    public static function build(GanttChart $chart): string
    {
        $w = new XlsxStyledWriter;
        $start = CarbonImmutable::parse($chart->start_on->toDateString())->startOfMonth();
        $end = CarbonImmutable::parse($chart->end_on->toDateString())->startOfMonth();

        // 月の一覧
        $months = [];
        for ($m = $start; $m->lte($end); $m = $m->addMonth()) {
            $months[] = $m;
        }
        $monthCount = count($months);
        $firstMonthCol = 2; // A=グループ, B=項目, C〜=月

        // ---- 書式 ----
        $title = $w->style(['bold' => true, 'size' => 14]);
        $sub = $w->style(['color' => '6B7280', 'size' => 10]);
        $head = $w->style(['bold' => true, 'fill' => 'F1F2F4', 'align' => 'center', 'border' => 'thin', 'size' => 10]);
        $headLeft = $w->style(['bold' => true, 'fill' => 'F1F2F4', 'align' => 'left', 'border' => 'thin', 'size' => 10]);
        $yearHead = $w->style(['bold' => true, 'fill' => 'E6E8EB', 'align' => 'center', 'border' => 'thin', 'size' => 10]);

        $cells = [];
        $merges = [];
        $rowHeights = [];

        $cells[0][0] = ['v' => $chart->title, 's' => $title];
        $rowHeights[0] = 24;
        $cells[1][0] = ['v' => sprintf('%d年%d月 〜 %d年%d月', $start->year, $start->month, $end->year, $end->month).($chart->note ? '　'.$chart->note : ''), 's' => $sub];

        // ---- 見出し（年・月） ----
        $hy = 3; // 年の行
        $hm = 4; // 月の行
        $cells[$hy][0] = ['v' => 'グループ', 's' => $headLeft];
        $cells[$hm][0] = ['v' => '', 's' => $headLeft];
        $cells[$hy][1] = ['v' => '科目・学習分野', 's' => $headLeft];
        $cells[$hm][1] = ['v' => '', 's' => $headLeft];
        $merges[] = 'A'.($hy + 1).':A'.($hm + 1);
        $merges[] = 'B'.($hy + 1).':B'.($hm + 1);
        $rowHeights[$hy] = 20;
        $rowHeights[$hm] = 20;

        $yearStartCol = null;
        $curYear = null;
        foreach ($months as $i => $m) {
            $col = $firstMonthCol + $i;
            if ($curYear !== $m->year) {
                if ($yearStartCol !== null) {
                    self::yearMerge($merges, $yearStartCol, $col - 1, $hy);
                }
                $curYear = $m->year;
                $yearStartCol = $col;
                $cells[$hy][$col] = ['v' => $m->year.'年', 's' => $yearHead];
            } else {
                $cells[$hy][$col] = ['v' => '', 's' => $yearHead];
            }
            $cells[$hm][$col] = ['v' => (string) $m->month, 's' => $head];
        }
        if ($yearStartCol !== null) {
            self::yearMerge($merges, $yearStartCol, $firstMonthCol + $monthCount - 1, $hy);
        }

        // ---- 行 ----
        $r = $hm + 1;
        $prevGroup = null;
        $listRows = [];
        foreach ($chart->rows as $row) {
            /** @var GanttRow $row */
            $groupStart = $prevGroup !== null && $row->group_name !== $prevGroup;
            $prevGroup = $row->group_name;
            $topBorder = $groupStart ? 'medium' : null;

            $nameStyle = $w->style(['align' => 'left', 'border' => 'thin', 'borderTop' => $topBorder, 'size' => 10, 'wrap' => true]);
            $groupStyle = $w->style(['align' => 'left', 'border' => 'thin', 'borderTop' => $topBorder, 'size' => 9, 'color' => '6B7280']);
            $blank = $w->style(['border' => 'thin', 'borderTop' => $topBorder]);

            $cells[$r][0] = ['v' => $row->group_name ?? '', 's' => $groupStyle];
            $cells[$r][1] = ['v' => $row->title, 's' => $nameStyle];
            for ($i = 0; $i < $monthCount; $i++) {
                $cells[$r][$firstMonthCol + $i] = ['v' => '', 's' => $blank];
            }
            $rowHeights[$r] = 22;

            $occupied = array_fill(0, $monthCount, false);
            foreach ($row->tasks as $t) {
                /** @var GanttTask $t */
                $a = self::monthIndex($start, $t->start_on->toDateString());
                $b = self::monthIndex($start, $t->end_on->toDateString());
                $a = max(0, $a);
                $b = min($monthCount - 1, $b);
                if ($b < $a) {
                    continue; // 表示期間の外
                }
                $hex = ltrim($t->color, '#');
                $fill = $w->style([
                    'fill' => strtoupper($hex),
                    'color' => self::isDark($hex) ? 'FFFFFF' : '1C2024',
                    'align' => 'center',
                    'border' => 'thin',
                    'borderTop' => $topBorder,
                    'size' => 9,
                    'wrap' => true,
                ]);
                $label = $t->kind === 'milestone' ? '◆ '.$t->title : $t->title;
                if ($t->progress > 0 && $t->kind !== 'milestone') {
                    $label .= '（'.$t->progress.'%）';
                }
                $free = true;
                for ($i = $a; $i <= $b; $i++) {
                    if ($occupied[$i]) {
                        $free = false;
                    }
                }
                for ($i = $a; $i <= $b; $i++) {
                    $occupied[$i] = true;
                    $cells[$r][$firstMonthCol + $i] = ['v' => $i === $a ? $label : '', 's' => $fill];
                }
                if ($free && $b > $a) {
                    $merges[] = XlsxHelper::colLetter($firstMonthCol + $a).($r + 1).':'.XlsxHelper::colLetter($firstMonthCol + $b).($r + 1);
                }

                $listRows[] = [
                    $row->group_name ?? '',
                    $row->title,
                    $t->title,
                    $t->kind === 'milestone' ? '節目' : '期間',
                    $t->start_on->toDateString(),
                    $t->end_on->toDateString(),
                    (string) ($t->start_on->diffInDays($t->end_on) + 1),
                    $t->kind === 'milestone' ? '' : (string) $t->progress,
                    $t->note ?? '',
                ];
            }
            $r++;
        }

        $colWidths = [0 => 12, 1 => 30];
        for ($i = 0; $i < $monthCount; $i++) {
            $colWidths[$firstMonthCol + $i] = 6.5;
        }
        $w->addSheet('ガントチャート', $cells, [
            'merges' => $merges,
            'colWidths' => $colWidths,
            'rowHeights' => $rowHeights,
            'freeze' => ['col' => $firstMonthCol, 'row' => $hm + 1],
        ]);

        // ---- 区間一覧 ----
        $listHead = ['グループ', '科目・学習分野', '区間', '種別', '開始日', '終了日', '日数', '進捗(%)', 'メモ'];
        $lc = [];
        foreach ($listHead as $ci => $h) {
            $lc[0][$ci] = ['v' => $h, 's' => $head];
        }
        $cellStyle = $w->style(['border' => 'thin', 'size' => 10]);
        foreach ($listRows as $ri => $vals) {
            foreach ($vals as $ci => $v) {
                $lc[$ri + 1][$ci] = ['v' => $v, 's' => $cellStyle];
            }
        }
        $w->addSheet('区間一覧', $lc, [
            'colWidths' => [0 => 12, 1 => 28, 2 => 18, 3 => 7, 4 => 12, 5 => 12, 6 => 7, 7 => 9, 8 => 40],
            'freeze' => ['col' => 0, 'row' => 1],
        ]);

        return $w->build();
    }

    private static function yearMerge(array &$merges, int $fromCol, int $toCol, int $row): void
    {
        if ($toCol > $fromCol) {
            $merges[] = XlsxHelper::colLetter($fromCol).($row + 1).':'.XlsxHelper::colLetter($toCol).($row + 1);
        }
    }

    /** 表示開始月から数えた月のインデックス（範囲外は負や上限超え） */
    private static function monthIndex(CarbonImmutable $start, string $iso): int
    {
        $d = CarbonImmutable::parse($iso);

        return ($d->year - $start->year) * 12 + ($d->month - $start->month);
    }

    private static function isDark(string $hex): bool
    {
        if (strlen($hex) !== 6) {
            return false;
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 < 0.55;
    }
}
