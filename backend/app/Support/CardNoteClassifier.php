<?php

namespace App\Support;

use App\Models\CardDeck;
use App\Models\CardItem;
use App\Models\MajorCategory;
use App\Models\MidCategory;
use App\Models\MistakeDeck;
use App\Models\ResourceBook;
use App\Models\Subject;

/**
 * 学習カード（数学学習の例題: 合格る確率・合格る計算・スタディサプリ ベーシックレベル数学）から間違いノートのページを自動で作るときの
 * 「分類・デッキ・出典」の決め方（スクランブルの GrammarNoteClassifier と同じ考え方）。
 * - 科目: 生徒の科目のうち名前に「数学」を含むもの（無ければ科目「その他」＝分類なし）
 * - 大分類・中分類: カードの章名（「第2章 2次関数」「Stage1 原理原則編」）と節名（「PART1 単項式と多項式」「ITEM 1 数え上げ」）から
 *   番号を除いた語で、科目の中分類を 名前の一致 → 前方一致 → 部分一致 の順で探す。
 *   見つからなければ大分類「束の名前」（無ければ作る）の下に、章名の中分類を作る
 * - デッキ: 束の短い名前（「合格る確率」「スタサプ 数Ⅰ」。無ければ作る）
 * - 出典: 束に登録した教材名（resource_book_title）に一致する教材（個別学習一覧データ）があればそれ、無ければ文字列
 */
class CardNoteClassifier
{
    /**
     * @return array{subject_id: ?int, major_category_id: ?int, mid_category_id: ?int, study_item_id: ?int, mistake_deck_id: ?int, resource_book_id: ?int, source: ?string, title: string}
     */
    public static function resolve(int $userId, CardItem $item): array
    {
        /** @var CardDeck $deck */
        $deck = $item->deck;
        $chapterName = self::stripNo((string) $item->chapter);
        $sectionName = self::stripNo((string) $item->section);
        $label = trim((string) ($item->extra['label'] ?? '')) ?: ('No.'.$item->no);
        $out = [
            'subject_id' => null, 'major_category_id' => null, 'mid_category_id' => null, 'study_item_id' => null,
            'mistake_deck_id' => self::deck($userId, $deck)->id,
            'resource_book_id' => null,
            'source' => null,
            'title' => mb_substr(trim($label.' '.($sectionName !== '' ? $sectionName : $chapterName)), 0, 150),
        ];

        $book = null;
        if ($deck->resource_book_title) {
            $book = ResourceBook::where('user_id', $userId)->where('title', $deck->resource_book_title)->orderBy('id')->first();
        }
        if ($book) {
            $out['resource_book_id'] = $book->id;
        } else {
            $out['source'] = $deck->name;
        }

        $subject = Subject::where('user_id', $userId)->where('name', 'like', '%数学%')->orderBy('sort_order')->orderBy('id')->first();
        if ($subject === null) {
            return $out; // 科目「その他」（分類なし）
        }
        $out['subject_id'] = $subject->id;

        // 節名 → 章名 → 束の名前の語（「合格る確率＋場合の数」→ 確率・場合の数）の順で合う中分類を探す
        $hints = array_values(array_filter(preg_split('/[＋+・\/／\s]+/u', (string) preg_replace('/^合格る|^スタディサプリ.*数学|例題$/u', '', $deck->name)) ?: []));
        $mid = self::findMid($subject, $deck, [$sectionName, $chapterName, ...$hints]);
        if ($mid === null) {
            $mid = self::ensureMid($subject, $deck, $chapterName !== '' ? $chapterName : $deck->name);
        }
        $out['major_category_id'] = $mid->major_category_id;
        $out['mid_category_id'] = $mid->id;

        return $out;
    }

    /** 「第2章 2次関数」「PART1 単項式と多項式」「ITEM 12 順列」「Stage1 原理原則編」→ 番号を除いた名前 */
    private static function stripNo(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/^(第\s*\d+\s*[章講部節]|PART\s*\d+|ITEM\s*\d+|Stage\s*\d+|\d+(\.\d+)*)\s*/iu', '', $s) ?? $s;

        return trim($s);
    }

    /** 候補の名前（節名 → 章名の順）に合う中分類（科目の全大分類から。束の名前の大分類は除く）。名前の一致 → 前方一致 → 部分一致 */
    private static function findMid(Subject $subject, CardDeck $deck, array $names): ?MidCategory
    {
        $names = array_values(array_filter(array_map('trim', $names), fn ($n) => $n !== '' && mb_strlen($n) >= 2));
        if (! $names) {
            return null;
        }
        $mids = MidCategory::query()
            ->join('major_categories', 'major_categories.id', '=', 'mid_categories.major_category_id')
            ->where('major_categories.subject_id', $subject->id)
            ->where('major_categories.name', '<>', $deck->name)
            ->orderBy('major_categories.sort_order')->orderBy('mid_categories.sort_order')->orderBy('mid_categories.id')
            ->get(['mid_categories.*']);
        if ($mids->isEmpty()) {
            return null;
        }
        foreach ([fn ($n, $k) => $n === $k, fn ($n, $k) => str_starts_with($n, $k), fn ($n, $k) => str_contains($n, $k) || str_contains($k, $n)] as $match) {
            foreach ($names as $k) {
                foreach ($mids as $m) {
                    if ($match(trim((string) $m->name), $k)) {
                        return $m;
                    }
                }
            }
        }

        return null;
    }

    private static function ensureMid(Subject $subject, CardDeck $deck, string $midName): MidCategory
    {
        $major = MajorCategory::firstOrCreate(
            ['subject_id' => $subject->id, 'name' => mb_substr($deck->name, 0, 150)],
            ['sort_order' => ((int) MajorCategory::where('subject_id', $subject->id)->max('sort_order')) + 1],
        );

        return MidCategory::firstOrCreate(
            ['major_category_id' => $major->id, 'name' => mb_substr($midName, 0, 150)],
            ['sort_order' => ((int) MidCategory::where('major_category_id', $major->id)->max('sort_order')) + 1],
        );
    }

    private static function deck(int $userId, CardDeck $deck): MistakeDeck
    {
        return MistakeDeck::firstOrCreate(
            ['user_id' => $userId, 'name' => mb_substr($deck->short_name ?: $deck->name, 0, 100)],
            ['color' => '#b7681a', 'sort_order' => ((int) MistakeDeck::where('user_id', $userId)->max('sort_order')) + 1],
        );
    }
}
