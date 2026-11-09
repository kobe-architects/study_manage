<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 学習カードの回答履歴（生徒ごと・1 回答 1 行） */
class CardAttempt extends Model
{
    protected $fillable = ['user_id', 'quiz_session_id', 'card_item_id', 'mode', 'is_correct', 'is_unsure', 'answer', 'answered_at', 'elapsed_ms', 'review_prev'];

    protected $casts = [
        'is_correct' => 'boolean',
        'is_unsure' => 'boolean',
        'answered_at' => 'datetime',
        'elapsed_ms' => 'integer',
        'review_prev' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(CardItem::class, 'card_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
