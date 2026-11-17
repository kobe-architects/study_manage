<?php

namespace App\Support;

use App\Models\GrammarQuestion;
use App\Models\MajorCategory;
use App\Models\MidCategory;
use App\Models\MistakeDeck;
use App\Models\ResourceBook;
use App\Models\Subject;

/**
 * スクランブル（英文法・語法）の問題から間違いノートのページを自動で作るときの「分類・デッキ・出典」の決め方。
 * - 科目: 生徒の科目のうち名前に「英語」を含むもの（無ければ科目「その他」＝分類なし）
 * - 大分類・中分類: 問題の章（「第1章 時制」）に合う中分類を、科目の中から 名前が一致 → 前方一致 → 部分一致 の順で探す。
 *   見つからなければ大分類「スクランブル英文法・語法」（無ければ作る）の下に、章名（語い以降は問題集名）の中分類を作る
 * - デッキ: 「スクランブル」（無ければ作る）
 * - 出典: 名前に「スクランブル」を含む教材（個別学習一覧データ）があればそれ、無ければ文字列
 */
class GrammarNoteClassifier
{
    public const DECK_NAME = 'スクランブル';

    public const MAJOR_NAME = 'スクランブル英文法・語法';

    /** 章名（第N章 を除いた部分）→ 学習項目データで使われていそうな中分類名の候補（優先順） */
    private const CHAPTER_KEYS = [
        '時制' => ['動詞と時制', '時制'],
        '受動態' => ['態', '受動態'],
        '助動詞' => ['助動詞'],
        '不定詞' => ['不定詞'],
        '動名詞' => ['動名詞'],
        '分詞' => ['分詞'],
        '仮定法' => ['仮定法'],
        '比較' => ['比較'],
        '疑問' => ['疑問詞と疑問文', '疑問文', '疑問'],
        '否定' => ['否定'],
        '代名詞' => ['代名詞'],
        '関係詞' => ['関係詞'],
        '接続詞' => ['接続詞'],
        '強調・倒置・省略・代用' => ['強調・倒置・挿入・省略・同格', '強調', '倒置'],
        '動詞の語法' => ['動詞と文型', '動詞の語法', '動詞'],
        '名詞・冠詞の語法' => ['名詞', '冠詞'],
        '形容詞の語法' => ['形容詞'],
        '副詞の語法' => ['副詞'],
        '前置詞の語法' => ['前置詞'],
    ];

    /**
     * @return array{subject_id: ?int, major_category_id: ?int, mid_category_id: ?int, study_item_id: ?int, mistake_deck_id: ?int, resource_book_id: ?int, source: ?string, title: string}
     */
    public static function resolve(int $userId, GrammarQuestion $q): array
    {
        $bookName = GrammarQuestion::BOOKS[$q->book] ?? $q->book;
        $chapterName = trim((string) preg_replace('/^第\d+章\s*/u', '', (string) $q->chapter));
        $out = [
            'subject_id' => null, 'major_category_id' => null, 'mid_category_id' => null, 'study_item_id' => null,
            'mistake_deck_id' => self::deck($userId)->id,
            'resource_book_id' => null,
            'source' => null,
            'title' => sprintf('No.%d %s', $q->no, $chapterName !== '' ? $chapterName : $bookName),
        ];

        $book = ResourceBook::where('user_id', $userId)->where('title', 'like', '%スクランブル%')->orderBy('id')->first();
        if ($book) {
            $out['resource_book_id'] = $book->id;
        } else {
            $out['source'] = 'スクランブル英文法・語法 '.$bookName;
        }

        $subject = Subject::where('user_id', $userId)->where('name', 'like', '%英語%')->orderBy('sort_order')->orderBy('id')->first();
        if ($subject === null) {
            return $out; // 科目「その他」（分類なし）
        }
        $out['subject_id'] = $subject->id;

        $mid = null;
        if (in_array($q->book, ['grammar', 'usage'], true)) {
            $mid = self::findMid($subject, $chapterName);
        }
        if ($mid === null) {
            // 合う中分類が無い: 大分類「スクランブル英文法・語法」の下に、章名（語い以降は問題集名）の中分類を用意する
            $midName = in_array($q->book, ['grammar', 'usage'], true) && $chapterName !== '' ? $chapterName : $bookName;
            $mid = self::ensureMid($subject, $midName);
        }
        $out['major_category_id'] = $mid->major_category_id;
        $out['mid_category_id'] = $mid->id;

        return $out;
    }

    /** 章名に合う中分類（科目の全大分類から）。名前の一致 → 前方一致 → 部分一致 */
    private static function findMid(Subject $subject, string $chapterName): ?MidCategory
    {
        if ($chapterName === '') {
            return null;
        }
        $mids = MidCategory::query()
            ->join('major_categories', 'major_categories.id', '=', 'mid_categories.major_category_id')
            ->where('major_categories.subject_id', $subject->id)
            ->where('major_categories.name', '<>', self::MAJOR_NAME)
            ->orderBy('major_categories.sort_order')->orderBy('mid_categories.sort_order')->orderBy('mid_categories.id')
            ->get(['mid_categories.*']);
        if ($mids->isEmpty()) {
            return null;
        }
        $keys = self::CHAPTER_KEYS[$chapterName] ?? [$chapterName];
        foreach ([fn ($n, $k) => $n === $k, fn ($n, $k) => str_starts_with($n, $k), fn ($n, $k) => str_contains($n, $k)] as $match) {
            foreach ($keys as $k) {
                foreach ($mids as $m) {
                    if ($match(trim((string) $m->name), $k)) {
                        return $m;
                    }
                }
            }
        }

        return null;
    }

    private static function ensureMid(Subject $subject, string $midName): MidCategory
    {
        $major = MajorCategory::firstOrCreate(
            ['subject_id' => $subject->id, 'name' => self::MAJOR_NAME],
            ['sort_order' => ((int) MajorCategory::where('subject_id', $subject->id)->max('sort_order')) + 1],
        );

        return MidCategory::firstOrCreate(
            ['major_category_id' => $major->id, 'name' => mb_substr($midName, 0, 150)],
            ['sort_order' => ((int) MidCategory::where('major_category_id', $major->id)->max('sort_order')) + 1],
        );
    }

    private static function deck(int $userId): MistakeDeck
    {
        return MistakeDeck::firstOrCreate(
            ['user_id' => $userId, 'name' => self::DECK_NAME],
            ['color' => '#3b50cc', 'sort_order' => ((int) MistakeDeck::where('user_id', $userId)->max('sort_order')) + 1],
        );
    }
}
