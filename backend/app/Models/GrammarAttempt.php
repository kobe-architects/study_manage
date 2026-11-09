<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 英文法・語法の回答履歴（生徒ごと・1 回答 1 行） */
class GrammarAttempt extends Model
{
    protected $fillable = ['user_id', 'quiz_session_id', 'grammar_question_id', 'is_correct', 'is_unsure', 'answer', 'answered_at', 'elapsed_ms', 'review_prev'];

    protected $casts = [
        'is_correct' => 'boolean',
        'is_unsure' => 'boolean', // 正解だったが自信がない（あいまい）。正解のときだけ true
        'answered_at' => 'datetime',
        'elapsed_ms' => 'integer', // 問題を見てから解答するまで（ミリ秒）
        'review_prev' => 'array', // この回答で更新する前の復習状態（正誤・あいまいの付け替えでやり直すため）
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(GrammarQuestion::class, 'grammar_question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
