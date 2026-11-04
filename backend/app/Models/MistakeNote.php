<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 間違いノートの 1 ページ（1 問）。科目 → 大分類 → 中分類（→ 小分類）に紐づき（科目「その他」は subject_id = NULL で分類なし）、
 * 問題の写真・解答の画像・説明用の画像・手書き・入力した解説を持つ。デッキ（任意）と復習（間隔反復）の状態も持つ */
class MistakeNote extends Model
{
    protected $fillable = [
        'user_id', 'subject_id', 'major_category_id', 'mid_category_id', 'study_item_id', 'resource_book_id', 'mistake_deck_id',
        'title', 'source', 'noted_on', 'layout', 'hidden', 'sort_order',
        'review_interval', 'review_ease', 'review_reps', 'review_lapses', 'review_due_at', 'review_last_at', 'review_last_grade',
    ];

    protected $casts = [
        'noted_on' => 'date', 'layout' => 'array', 'hidden' => 'boolean', 'sort_order' => 'integer',
        'review_interval' => 'float', 'review_ease' => 'float', 'review_reps' => 'integer', 'review_lapses' => 'integer',
        'review_due_at' => 'datetime', 'review_last_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function major(): BelongsTo
    {
        return $this->belongsTo(MajorCategory::class, 'major_category_id');
    }

    public function mid(): BelongsTo
    {
        return $this->belongsTo(MidCategory::class, 'mid_category_id');
    }

    /** 小分類（任意） */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StudyItem::class, 'study_item_id');
    }

    /** 出典の教材（個別学習一覧データ・任意） */
    public function book(): BelongsTo
    {
        return $this->belongsTo(ResourceBook::class, 'resource_book_id');
    }

    /** デッキ（本棚の本・任意） */
    public function deck(): BelongsTo
    {
        return $this->belongsTo(MistakeDeck::class, 'mistake_deck_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(MistakeReview::class, 'mistake_note_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(MistakeNoteImage::class)->orderBy('id');
    }
}
