<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VocabularyLearningStat extends Model
{
    protected $fillable = [
        'vocabulary_id', 'user_id', 'correct_count', 'incorrect_count',
        'last_attempted_at', 'next_review_at', 'ease_factor', 'interval_days', 'repetition_count',
    ];

    protected $casts = [
        'last_attempted_at' => 'datetime',
        'next_review_at' => 'datetime',
        'ease_factor' => 'decimal:2',
        'correct_count' => 'integer',
        'incorrect_count' => 'integer',
        'interval_days' => 'integer',
        'repetition_count' => 'integer',
    ];

    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(Vocabulary::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
