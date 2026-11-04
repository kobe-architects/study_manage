<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 間違いノート 問題解答モードの 1 回の評価（もう一度 / 難しい / 正解 / 簡単） */
class MistakeReview extends Model
{
    public const GRADES = ['again', 'hard', 'good', 'easy'];

    protected $fillable = ['user_id', 'mistake_note_id', 'grade', 'interval_after', 'ease_after', 'reviewed_at'];

    protected $casts = ['reviewed_at' => 'datetime', 'interval_after' => 'float', 'ease_after' => 'float'];

    public function note(): BelongsTo
    {
        return $this->belongsTo(MistakeNote::class, 'mistake_note_id');
    }
}
