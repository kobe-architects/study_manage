<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 学習カード 1 枚。
 * kind: phrase（囲みの中の覚える表現。front = 英語の表現、back = 意味）
 *       example（例文。front = 英文、back = 和訳、extra.blanks = 空所にする語の位置）
 *       problem（画像の問題。problem_image / answer_image。front = 見出し）
 */
class CardItem extends Model
{
    public const KINDS = ['phrase', 'example', 'problem'];

    protected $fillable = [
        'card_deck_id', 'no', 'chapter_no', 'chapter', 'section', 'kind', 'front', 'back', 'extra',
        'page', 'book_page', 'problem_image', 'answer_image', 'sort_order',
    ];

    protected $casts = ['no' => 'integer', 'chapter_no' => 'integer', 'extra' => 'array', 'page' => 'integer', 'sort_order' => 'integer'];

    public function deck(): BelongsTo
    {
        return $this->belongsTo(CardDeck::class, 'card_deck_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CardAttempt::class);
    }
}
