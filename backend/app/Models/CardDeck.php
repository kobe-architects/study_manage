<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 学習カードの束（教材 1 冊）。全ユーザー共通。subject = english / math。
 * student_only の束は生徒側にだけ表示する（講師の画面・API には出さない）。
 */
class CardDeck extends Model
{
    protected $fillable = ['key', 'subject', 'name', 'short_name', 'student_only', 'resource_book_title', 'structure', 'sort_order'];

    protected $casts = ['student_only' => 'boolean', 'structure' => 'array', 'sort_order' => 'integer'];

    public function items(): HasMany
    {
        return $this->hasMany(CardItem::class)->orderBy('sort_order')->orderBy('no');
    }
}
