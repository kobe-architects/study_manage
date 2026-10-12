<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 間違いノートの 1 ページ（1 問）。科目 → 大分類 → 中分類（→ 小分類）に紐づき、問題の写真・説明用の画像・手書き・入力した解説を持つ */
class MistakeNote extends Model
{
    protected $fillable = ['user_id', 'subject_id', 'major_category_id', 'mid_category_id', 'study_item_id', 'title', 'source', 'noted_on', 'layout', 'hidden', 'sort_order'];

    protected $casts = ['noted_on' => 'date', 'layout' => 'array', 'hidden' => 'boolean', 'sort_order' => 'integer'];

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

    public function images(): HasMany
    {
        return $this->hasMany(MistakeNoteImage::class)->orderBy('id');
    }
}
