<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResourceBookItem extends Model
{
    protected $fillable = [
        'resource_book_id', 'study_item_id', 'chapter', 'seq_no',
        'check_flag', 'title', 'difficulty', 'meta', 'included', 'important', 'sort_order',
    ];

    protected $casts = ['meta' => 'array', 'included' => 'boolean', 'important' => 'boolean'];

    public function book(): BelongsTo
    {
        return $this->belongsTo(ResourceBook::class, 'resource_book_id');
    }

    public function studyItem(): BelongsTo
    {
        return $this->belongsTo(StudyItem::class, 'study_item_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(StudyRecord::class, 'resource_book_item_id');
    }

    /**
     * 出題用ページの定義（meta.quiz_pages = [[冊子ページ, ラベル, 表示番号(任意)], ...]）。
     * 学習記録は行（章・UNIT）単位、出題はページ単位にするための展開情報。無ければ空配列。
     *
     * @return array<int, array{0: string, 1: string, 2: string|null}>
     */
    public function quizPageSpecs(): array
    {
        $specs = $this->meta['quiz_pages'] ?? null;
        if (! is_array($specs)) {
            return [];
        }
        $out = [];
        foreach (array_values($specs) as $spec) {
            if (! is_array($spec)) {
                continue;
            }
            $out[] = [(string) ($spec[0] ?? ''), (string) ($spec[1] ?? ''), isset($spec[2]) ? (string) $spec[2] : null];
        }

        return $out;
    }

    /**
     * 目安時間（分）。教材に目標解答時間がある行は meta.目標時間（「20分」など）を、
     * なければ難易度の★の数 × 5 分を使う。どちらも無ければ null。
     */
    public function guideMinutes(): ?int
    {
        $target = $this->meta['目標時間'] ?? null;
        if ($target !== null && preg_match('/\d+/', (string) $target, $m)) {
            return (int) $m[0] > 0 ? (int) $m[0] : null;
        }

        return self::starMinutes($this->difficulty);
    }

    /**
     * 複数ページに展開される行（quiz_pages）では、目標時間は先頭ページにだけ付ける（表紙の合計が行の時間になるように）。
     * $pageIndex は quiz_pages の添字。展開しない行は常に先頭扱い。
     */
    public function guideMinutesForQuizPage(int $pageIndex): ?int
    {
        return $pageIndex === 0 || $this->quizPageSpecs() === [] ? $this->guideMinutes() : null;
    }

    /** PDF のページが、この行の quiz_pages の何番目か（展開しない行・該当しないページは 0） */
    public function quizPageIndexOf(ResourceBookPdf $pdf, int $page): int
    {
        foreach ($this->quizPageSpecs() as $i => $spec) {
            if ($pdf->pageForSeq($spec[0]) === $page) {
                return $i;
            }
        }

        return 0;
    }

    /** 難易度（'***' や '★★★'）から目安時間（分）を求める。★1つ = 5分。★が無ければ null */
    public static function starMinutes(?string $difficulty): ?int
    {
        if ($difficulty === null) {
            return null;
        }
        $stars = preg_match_all('/[*★]/u', $difficulty);

        return $stars > 0 ? $stars * 5 : null;
    }
}
