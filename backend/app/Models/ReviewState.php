<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 忘却曲線（間隔反復）の復習状態。生徒 × 問題（kind = grammar / vocab / card）ごとに 1 行。
 * 回答のたびに App\Services\ReviewService が更新する。due_at が今以前なら「今日の復習」の対象。
 */
class ReviewState extends Model
{
    public const KIND_GRAMMAR = 'grammar';

    public const KIND_VOCAB = 'vocab';

    public const KIND_CARD = 'card';

    public const KINDS = [self::KIND_GRAMMAR, self::KIND_VOCAB, self::KIND_CARD];

    protected $fillable = ['user_id', 'kind', 'item_id', 'interval', 'ease', 'reps', 'lapses', 'due_at', 'last_at', 'last_grade'];

    protected $casts = [
        'item_id' => 'integer',
        'interval' => 'float',
        'ease' => 'float',
        'reps' => 'integer',
        'lapses' => 'integer',
        'due_at' => 'datetime',
        'last_at' => 'datetime',
    ];

    /** 復習計算用の配列（App\Support\ReviewScheduler の入力） */
    public function toState(): array
    {
        return [
            'interval' => (float) $this->interval,
            'ease' => (float) ($this->ease ?: 2.5),
            'reps' => (int) $this->reps,
            'lapses' => (int) $this->lapses,
            'due_at' => $this->due_at?->toIso8601String(),
            'last_at' => $this->last_at?->toIso8601String(),
            'last_grade' => $this->last_grade,
        ];
    }
}
