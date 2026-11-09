<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 学習カード 1 枚。
 * kind: phrase（囲み 1 つ。extra.rows = 行ごとの英語の表現と意味。front/back は一覧・検索用に行をつないだもの）
 *       example（例文。front = 英文、back = 和訳、extra.blanks = 空所にする語の位置）
 *       problem（例題。problem_image = 例題の画像、answer_image = 解説の画像、extra.subs = 小問ごとの答えと誤答（4 択））
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
