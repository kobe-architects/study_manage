<?php

namespace App\Support;

use App\Models\ResourceBook;
use App\Models\ResourceBookItem;
use App\Models\StudyRecord;

/**
 * 個別学習データで登録した学習記録（study_records: 学習日・色）を、英語学習・数学学習のチェックシート（学習カード・スクランブル・
 * ファーストステップ）の「回数欄」に重ねるための対応付け。
 *
 * 同じ教材（card_decks.resource_book_title や問題集名で探した resource_books）の行（resource_book_items）を、カード／問題に次の順で対応させる。
 * - 章モード: 行が章（週・章）に 1 対 1 で対応する教材（鉄緑会 英文法＝週ごとの行、ファーストステップ＝章ごとの行）。
 *   行の「章＋タイトル」「タイトル」「章」のどれかがカードの章名と一致し、一致した行の数が章の数とほぼ同じなら章モード。
 *   学習日は章の見出しの回数欄に出す（カード 1 枚ずつの正誤ではないので行には入れない）。
 * - 行モード（それ以外）: 行の番号（seq_no）をカードの番号に対応させる。
 *   「例題 9A」「例題 9B」のようなラベルは番号 9 の行に、ラベルが無いカードは通し番号＝seq_no。
 *   スタディサプリは「講（meta.講）＋PART 番号（seq_no）」をカードの「章＋節の先頭語」に対応させる。
 * 色は 個別学習データのチェックシートと同じ: 赤=0（不正解扱い）・青=2（あいまい）・それ以外=1。
 */
class StudyRecordMarks
{
    /**
     * @param int $userId 生徒
     * @param string[] $titles 教材名の候補（完全一致。先に見つかったもの）
     * @param string[] $keywords 教材名にどれか 1 つでも含まれていれば一致とみなす語（完全一致が無いとき）
     * @param array<int, array{no:int, chapterNo:int, chapter:string, section?:?string, label?:?string}> $items カード／問題
     * @return array{marks: array<int, array<int, array{0:string, 1:int}>>, chapterMarks: array<int, array<int, array{0:string, 1:int}>>, book: ?string}
     */
    public static function forItems(int $userId, array $titles, array $keywords, array $items): array
    {
        $empty = ['marks' => [], 'chapterMarks' => [], 'book' => null];
        $book = self::findBook($userId, $titles, $keywords);
        if ($book === null || ! $items) {
            return $empty;
        }
        $rows = ResourceBookItem::where('resource_book_id', $book->id)->orderBy('sort_order')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return $empty;
        }
        $records = StudyRecord::query()
            ->where('user_id', $userId)
            ->whereIn('resource_book_item_id', $rows->pluck('id'))
            ->orderBy('studied_on')->orderBy('id')
            ->get(['resource_book_item_id', 'studied_on', 'color']);
        if ($records->isEmpty()) {
            return ['marks' => [], 'chapterMarks' => [], 'book' => $book->title];
        }
        $byRow = [];
        foreach ($records as $r) {
            $byRow[(int) $r->resource_book_item_id][] = [$r->studied_on->toDateString(), self::status($r->color)];
        }

        // ---- カード側の索引 ----
        $chapterByName = []; // 正規化した章名 → chapter_no
        $nosByNum = [];      // 番号（ラベルの数字 or 通し番号）→ [no, ...]
        $nosBySection = [];  // 章｜節の先頭語 → [no, ...]
        $hasLabel = false;
        foreach ($items as $it) {
            if (! empty($it['label']) && preg_match('/(\d+)/u', (string) $it['label'])) {
                $hasLabel = true;
                break;
            }
        }
        foreach ($items as $it) {
            $no = (int) $it['no'];
            $chapterNo = (int) $it['chapterNo'];
            $cn = self::norm((string) $it['chapter']);
            if ($cn !== '' && ! isset($chapterByName[$cn])) {
                $chapterByName[$cn] = $chapterNo;
            }
            $num = null;
            if ($hasLabel) {
                if (! empty($it['label']) && preg_match('/(\d+)/u', self::toHalf((string) $it['label']), $m)) {
                    $num = (int) $m[1];
                }
            } else {
                $num = $no;
            }
            if ($num !== null) {
                $nosByNum[$num][] = $no;
            }
            $sec = trim((string) ($it['section'] ?? ''));
            if ($sec !== '' && preg_match('/^(\S+)/u', self::toHalf($sec), $m)) {
                $nosBySection[$cn.'|'.self::norm($m[1])][] = $no;
            }
        }

        // ---- 章モードか行モードか ----
        $chapterHit = []; // 行 ID → chapter_no
        foreach ($rows as $row) {
            $chapter = trim((string) $row->chapter);
            $title = trim((string) $row->title);
            foreach ([self::norm($chapter.' '.$title), self::norm($title), self::norm($chapter)] as $cand) {
                if ($cand !== '' && isset($chapterByName[$cand])) {
                    $chapterHit[$row->id] = $chapterByName[$cand];
                    break;
                }
            }
        }
        $hits = count($chapterHit);
        $distinct = count(array_unique($chapterHit));
        $chapterMode = $hits > 0 && $hits * 2 >= $rows->count() && $hits <= $distinct * 1.5;

        $marks = [];
        $chapterMarks = [];
        foreach ($rows as $row) {
            $recs = $byRow[$row->id] ?? null;
            if (! $recs) {
                continue;
            }
            if ($chapterMode) {
                if (isset($chapterHit[$row->id])) {
                    foreach ($recs as $m) {
                        $chapterMarks[$chapterHit[$row->id]][] = $m;
                    }
                }
                continue;
            }
            foreach (self::itemNos($row, $nosByNum, $nosBySection) as $no) {
                foreach ($recs as $m) {
                    $marks[$no][] = $m;
                }
            }
        }

        return ['marks' => self::squash($marks), 'chapterMarks' => self::squash($chapterMarks), 'book' => $book->title];
    }

    /**
     * 回答履歴から作った marks（no → [[日付, 状態], ...]）に学習記録の marks を重ねる。同じ日は悪い方（0 → 2 → 1）を採る。
     *
     * @param array<int|string, array<int, array{0:string, 1:int}>> $base
     * @param array<int|string, array<int, array{0:string, 1:int}>> $add
     * @return array<int|string, array<int, array{0:string, 1:int}>>
     */
    public static function merge(array $base, array $add): array
    {
        if (! $add) {
            return $base;
        }
        foreach ($add as $no => $list) {
            foreach ($list as $m) {
                $base[$no][] = $m;
            }
        }

        return self::squash($base);
    }

    /** 同じ番号・同じ日の印を 1 つにまとめ（悪い方を採る）、日付順に並べる */
    private static function squash(array $marks): array
    {
        foreach ($marks as $no => $list) {
            $byDate = [];
            foreach ($list as [$d, $s]) {
                $byDate[$d] = isset($byDate[$d]) ? self::worse($byDate[$d], (int) $s) : (int) $s;
            }
            ksort($byDate);
            $out = [];
            foreach ($byDate as $d => $s) {
                $out[] = [(string) $d, $s];
            }
            $marks[$no] = $out;
        }

        return $marks;
    }

    /** 0（不正解）＞ 2（あいまい）＞ 1（正解）の順に悪い */
    private static function worse(int $a, int $b): int
    {
        $rank = [0 => 0, 2 => 1, 1 => 2];

        return ($rank[$a] ?? 2) <= ($rank[$b] ?? 2) ? $a : $b;
    }

    /** 学習記録の色 → チェックシートの状態 */
    private static function status(?string $color): int
    {
        return $color === 'red' ? 0 : ($color === 'blue' ? 2 : 1);
    }

    /**
     * 行モードで、行に対応するカードの番号。
     * 1. 講（meta.講 か 章）＋ seq_no の先頭語（PART1 など）が「章｜節の先頭語」に合うもの
     * 2. seq_no の数字（「例題 12」「12」）がラベルの数字（または通し番号）に合うもの
     *
     * @return int[]
     */
    private static function itemNos(ResourceBookItem $row, array $nosByNum, array $nosBySection): array
    {
        $seq = trim(self::toHalf((string) $row->seq_no));
        if ($seq === '') {
            return [];
        }
        $meta = is_array($row->meta) ? $row->meta : [];
        $lecture = trim((string) ($meta['講'] ?? ''));
        foreach ([$lecture, (string) $row->chapter] as $ch) {
            $ch = self::norm($ch);
            if ($ch === '') {
                continue;
            }
            $key = $ch.'|'.self::norm($seq);
            if (isset($nosBySection[$key])) {
                return $nosBySection[$key];
            }
        }
        if (preg_match('/^(?:例題\s*)?(\d+)\s*[A-Za-z]?$/u', $seq, $m) && isset($nosByNum[(int) $m[1]])) {
            return $nosByNum[(int) $m[1]];
        }

        return [];
    }

    private static function findBook(int $userId, array $titles, array $keywords): ?ResourceBook
    {
        $books = ResourceBook::where('user_id', $userId)->orderBy('id')->get(['id', 'title']);
        if ($books->isEmpty()) {
            return null;
        }
        $titles = array_values(array_filter(array_map(fn ($t) => self::norm((string) $t), $titles), fn ($t) => $t !== ''));
        foreach ($titles as $t) {
            foreach ($books as $b) {
                if (self::norm((string) $b->title) === $t) {
                    return $b;
                }
            }
        }
        $keywords = array_values(array_filter(array_map(fn ($k) => self::norm((string) $k), $keywords), fn ($k) => $k !== ''));
        foreach ($keywords as $k) {
            foreach ($books as $b) {
                if (str_contains(self::norm((string) $b->title), $k)) {
                    return $b;
                }
            }
        }

        return null;
    }

    /** 全角英数・空白を半角に */
    private static function toHalf(string $s): string
    {
        return mb_convert_kana($s, 'asKV', 'UTF-8');
    }

    /** 比較用: 全角→半角、小文字化、空白と区切り記号（・，、,／/｜|）を除く。ローマ数字 Ⅰ〜Ⅲ は I・II・III に */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(self::toHalf(trim($s)), 'UTF-8');
        $s = str_replace(['Ⅰ', 'Ⅱ', 'Ⅲ', 'ⅰ', 'ⅱ', 'ⅲ'], ['i', 'ii', 'iii', 'i', 'ii', 'iii'], $s);

        return preg_replace('/[\s・，、,／\/｜|()（）]+/u', '', $s) ?? $s;
    }
}
