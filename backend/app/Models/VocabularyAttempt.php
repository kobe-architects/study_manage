<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VocabularyAttempt extends Model
{
    protected $fillable = ['vocabulary_id', 'user_id', 'is_correct', 'is_unsure', 'quiz_type', 'answered_at'];

    protected $casts = [
        'is_correct' => 'boolean',
        'is_unsure' => 'boolean', // 正解だったが自信がない（あいまい）。正解のときだけ true
        'answered_at' => 'datetime',
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
