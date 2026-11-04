<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 間違いノートのデッキ（本棚の「本」）。ページ（MistakeNote）を科目・分類とは別の軸でまとめる */
class MistakeDeck extends Model
{
    protected $fillable = ['user_id', 'name', 'color', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(MistakeNote::class, 'mistake_deck_id');
    }
}
