<?php

namespace App\Support;

use App\Models\CardDeck;
use App\Models\CardItem;
use App\Models\GrammarQuestion;
use Illuminate\Support\Collection;

/**
 * 英語学習・数学学習の問題と解答の Excel 出力（スクランブル／ファーストステップの問題、学習カード）。
 * 1 行 1 問。文字で登録されている問題はそのまま、画像だけの問題は「画像」列に ○ を付け、答えが登録されていれば答えだけ出す。
 * 自分の回答状況（最終結果・正解数・不正解数・最後に解いた日）も右側に付ける。
 */
final class StudyExcel
{
    private const CIRCLED = ['①', '②', '③', '④', '⑤', '⑥', '⑦', '⑧', '⑨'];

    /**
     * スクランブル（複数の問題集 = 複数シート）またはファーストステップの問題。
     *
     * @param  array<string, Collection<int, GrammarQuestion>>  $byBook  問題集キー => 問題（番号順）
     * @param  array<int, array>  $stats  問題 id => 回答状況（GrammarController::stats）
     */
    public static function grammar(array $byBook, array $stats): string
    {
        $w = new XlsxStyledWriter;
        $head = $w->style(['bold' => true, 'fill' => 'F1F2F4', 'align' => 'center', 'valign' => 'center', 'border' => 'thin', 'size' => 10, 'wrap' => true]);
        $cell = $w->style(['border' => 'thin', 'size' => 10, 'valign' => 'top', 'wrap' => true]);
        $num = $w->style(['border' => 'thin', 'size' => 10, 'valign' => 'top', 'align' => 'center']);

        $header = ['No.', '章', 'Theme', '形式', '重要度', '画像', '指示', '問題', '選択肢①', '選択肢②', '選択肢③', '選択肢④', '選択肢⑤', '正解', '完成文', '不要な語句', '不足の語句', '注記', '和訳', '出典', '備考', '最終結果', '正解数', '不正解数', '最後に解いた日'];
        $widths = [0 => 6, 1 => 16, 2 => 7, 3 => 9, 4 => 7, 5 => 5, 6 => 22, 7 => 52, 8 => 18, 9 => 18, 10 => 18, 11 => 18, 12 => 18, 13 => 22, 14 => 44, 15 => 12, 16 => 12, 17 => 16, 18 => 36, 19 => 14, 20 => 16, 21 => 9, 22 => 7, 23 => 8, 24 => 12];

        foreach ($byBook as $book => $questions) {
            $cells = [];
            foreach ($header as $c => $h) {
                $cells[0][$c] = ['v' => $h, 's' => $head];
            }
            $r = 1;
            foreach ($questions as $q) {
                $st = $stats[$q->id] ?? null;
                $choices = $q->choices ?: [];
                $row = [
                    (string) $q->no,
                    $q->chapter,
                    $q->theme !== null ? (string) $q->theme : null,
                    $q->format_label ?: self::formatLabel($q),
                    $q->importance,
                    $q->format === 'image' || ($q->problem_image && ! $q->question) ? '○' : null,
                    $q->instruction,
                    self::plain($q->question),
                    self::plain($choices[0] ?? null), self::plain($choices[1] ?? null), self::plain($choices[2] ?? null), self::plain($choices[3] ?? null), self::plain($choices[4] ?? null),
                    self::answerLabel($q),
                    self::completed($q),
                    $q->unneeded ? implode('，', $q->unneeded) : null,
                    $q->missing ? implode('，', $q->missing) : null,
                    $q->answer_note,
                    $q->translation,
                    $q->source,
                    $q->note,
                    self::lastLabel($st),
                    $st ? (string) $st['correct'] : null,
                    $st ? (string) $st['incorrect'] : null,
                    $st && $st['lastAt'] ? substr($st['lastAt'], 0, 10) : null,
                ];
                foreach ($row as $c => $v) {
                    $cells[$r][$c] = ['v' => $v === null || $v === '' ? null : (string) $v, 's' => in_array($c, [0, 2, 4, 5, 21, 22, 23, 24], true) ? $num : $cell];
                }
                $r++;
            }
            $w->addSheet(GrammarQuestion::BOOKS[$book] ?? $book, $cells, ['colWidths' => $widths, 'freeze' => ['col' => 1, 'row' => 1]]);
        }

        return $w->build();
    }

    /**
     * 学習カード 1 冊（鉄緑会 英文法の表現・例文、合格るの例題）。
     *
     * @param  Collection<int, CardItem>  $items
     * @param  array<int, array>  $stats  カード id => 回答状況（CardController::stats）
     */
    public static function cards(CardDeck $deck, Collection $items, array $stats): string
    {
        $w = new XlsxStyledWriter;
        $head = $w->style(['bold' => true, 'fill' => 'F1F2F4', 'align' => 'center', 'valign' => 'center', 'border' => 'thin', 'size' => 10, 'wrap' => true]);
        $cell = $w->style(['border' => 'thin', 'size' => 10, 'valign' => 'top', 'wrap' => true]);
        $num = $w->style(['border' => 'thin', 'size' => 10, 'valign' => 'top', 'align' => 'center']);

        $header = ['No.', '章', '節', '種類', '画像', '問題', '解答', '選択肢（4 択）', '空所の語', '教材ページ', '最終結果', '正解数', '不正解数', '最後に解いた日'];
        $widths = [0 => 6, 1 => 18, 2 => 18, 3 => 9, 4 => 5, 5 => 52, 6 => 40, 7 => 36, 8 => 16, 9 => 9, 10 => 9, 11 => 7, 12 => 8, 13 => 12];
        $kinds = ['phrase' => '表現', 'example' => '例文', 'problem' => '例題'];

        $cells = [];
        foreach ($header as $c => $h) {
            $cells[0][$c] = ['v' => $h, 's' => $head];
        }
        $r = 1;
        foreach ($items as $it) {
            $st = $stats[$it->id] ?? null;
            $ex = $it->extra ?: [];
            [$front, $back, $options, $blanks] = self::cardTexts($it, $ex);
            $row = [
                (string) $it->no,
                $it->chapter,
                $it->section,
                $kinds[$it->kind] ?? $it->kind,
                $it->problem_image || $it->answer_image ? '○' : null,
                $front,
                $back,
                $options,
                $blanks,
                $it->book_page,
                self::lastLabel($st),
                $st ? (string) $st['correct'] : null,
                $st ? (string) $st['incorrect'] : null,
                $st && $st['lastAt'] ? substr($st['lastAt'], 0, 10) : null,
            ];
            foreach ($row as $c => $v) {
                $cells[$r][$c] = ['v' => $v === null || $v === '' ? null : (string) $v, 's' => in_array($c, [0, 4, 9, 10, 11, 12, 13], true) ? $num : $cell];
            }
            $r++;
        }
        $w->addSheet($deck->short_name ?: $deck->name, $cells, ['colWidths' => $widths, 'freeze' => ['col' => 1, 'row' => 1]]);

        return $w->build();
    }

    // ---------- 学習カードの文字 ----------

    /** @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string} 問題・解答・選択肢・空所の語 */
    private static function cardTexts(CardItem $it, array $ex): array
    {
        if ($it->kind === 'phrase' && ! empty($ex['rows'])) {
            $en = [];
            $ja = [];
            foreach ($ex['rows'] as $row) {
                $en[] = (string) ($row['en'] ?? '');
                $ja[] = (string) ($row['ja'] ?? '');
            }

            return [implode("\n", $en), implode("\n", $ja), null, null];
        }
        if ($it->kind === 'problem') {
            $label = isset($ex['label']) ? trim((string) $ex['label']) : '';
            $front = trim((string) $it->front);
            if ($label !== '' && $front !== $label && ! str_starts_with($front, $label)) {
                $front = trim($label.' '.$front);
            } elseif ($front === '') {
                $front = $label;
            }
            $ans = [];
            $opts = [];
            foreach ($ex['subs'] ?? [] as $sub) {
                $lab = isset($sub['label']) && $sub['label'] !== null && $sub['label'] !== '' ? $sub['label'].' ' : '';
                $ans[] = $lab.(string) ($sub['answer'] ?? '');
                if (! empty($sub['options'])) {
                    $opts[] = $lab.implode(' / ', array_map('strval', $sub['options']));
                }
            }
            $back = $ans ? implode("\n", $ans) : ($it->back ?: ($it->answer_image ? '解説の画像を参照' : null));

            return [$front !== '' ? $front : ($it->problem_image ? '問題の画像を参照' : null), $back, $opts ? implode("\n", $opts) : null, null];
        }
        // 例文: 空所にする語を列に
        $blanks = null;
        if (! empty($ex['blanks']) && $it->front) {
            $words = preg_split('/\s+/', trim($it->front)) ?: [];
            $parts = [];
            foreach ($ex['blanks'] as $b) {
                if (is_array($b) && count($b) >= 2) {
                    $parts[] = implode(' ', array_slice($words, (int) $b[0], (int) $b[1] - (int) $b[0] + 1));
                }
            }
            $blanks = $parts ? implode('，', $parts) : null;
        }

        return [$it->front, $it->back, null, $blanks];
    }

    // ---------- スクランブルの文字 ----------

    /** 下線の印 ⟨ ⟩ は残す（発音の問題で意味を持つ）。空白を整える */
    private static function plain(?string $t): ?string
    {
        if ($t === null) {
            return null;
        }
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;

        return trim($t) === '' ? null : trim($t);
    }

    private static function formatLabel(GrammarQuestion $q): string
    {
        return ['choice' => '選択', 'order' => '整序', 'fill' => '空所補充', 'error' => '誤り指摘', 'image' => '画像'][$q->format] ?? $q->format;
    }

    /** 画面の一覧と同じ表記の正解（GrammarView の answerLabel と合わせる） */
    private static function answerLabel(GrammarQuestion $q): ?string
    {
        $circ = fn (?int $i) => $i !== null ? (self::CIRCLED[$i] ?? (string) ($i + 1)) : '';
        $answers = fn () => implode('，', array_map(fn ($a) => implode(' / ', (array) $a), $q->answers ?: []));
        switch ($q->format) {
            case 'choice':
            case 'error':
                return trim($circ($q->answer_index).' '.self::plain($q->answer_text));
            case 'order':
                return str_replace(['(', ')'], '', (string) $q->answer_text) ?: null;
            case 'image':
                if ($q->answer_index !== null && $q->choice_count) {
                    return ($q->choices ?: [])[$q->answer_index] ?? $circ($q->answer_index);
                }
                if ($q->answers) {
                    return $answers();
                }

                return $q->answer_text ?: ($q->answer_image ? '解答欄の画像を参照' : null);
            default:
                return $answers() ?: $q->answer_text;
        }
    }

    /** 空所に正解を入れた文（選択・空所補充で空所と答えの数が合うときだけ。整序は答えの文） */
    private static function completed(GrammarQuestion $q): ?string
    {
        $text = self::plain($q->question) ?? '';
        if ($text === '' || ! in_array($q->format, ['choice', 'fill', 'order'], true)) {
            return null;
        }
        if ($q->format === 'order') {
            $s = str_replace(['(', ')'], '', (string) $q->answer_text);

            return $s !== '' ? self::tidy($s) : null;
        }
        $pieces = preg_split('/\(\s*(?:[A-Za-z]{1,2}-)?\s*\)/u', $text) ?: [$text];
        $n = count($pieces) - 1;
        if ($n === 0) {
            return null;
        }
        if ($q->format === 'choice') {
            if (! $q->answer_text) {
                return null;
            }
            $parts = $n === 1 ? [$q->answer_text] : preg_split('/\s*[—–]\s*/u', $q->answer_text);
        } else {
            $parts = array_map(fn ($a) => (string) (((array) $a)[0] ?? ''), $q->answers ?: []);
            if (count($parts) === 1 && $n > 1) {
                $parts = array_fill(0, $n, $parts[0]);
            }
        }
        if (count($parts) !== $n) {
            return null;
        }
        $out = '';
        foreach ($pieces as $i => $p) {
            $out .= $p;
            if ($i < $n) {
                $out .= str_replace(['⟨', '⟩'], '', $parts[$i]);
            }
        }

        return self::tidy($out);
    }

    private static function tidy(string $s): string
    {
        $s = preg_replace('/〔.*?〕|（.*?）/u', '', $s) ?? $s;
        $s = str_replace(['⟨', '⟩'], '', $s);

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    private static function lastLabel(?array $st): ?string
    {
        if (! $st || $st['last'] === null) {
            return null;
        }
        if (! $st['last']) {
            return '不正解';
        }

        return ! empty($st['lastUnsure']) ? 'あいまい' : '正解';
    }
}
