<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 間違いノートの 1 ページ（1 問）。問題の写真・説明用の画像・手書き・入力した解説を持つ */
class MistakeNote extends Model
{
    protected $fillable = ['user_id', 'subject_id', 'field', 'title', 'source', 'noted_on', 'layout', 'hidden', 'sort_order'];

    protected $casts = ['noted_on' => 'date', 'layout' => 'array', 'hidden' => 'boolean', 'sort_order' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(MistakeNoteImage::class)->orderBy('id');
    }
}
