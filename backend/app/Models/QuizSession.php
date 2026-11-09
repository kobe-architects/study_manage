<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1 回の出題（開始〜終了）。問題数・正解数・あいまい数・解答時間の合計を記録し、1 問あたりの解答時間を出す。
 * kind = grammar（スクランブル）/ vocab（英単語。ref_id = 単語帳）/ card（学習カード。ref_id = 束）
 */
class QuizSession extends Model
{
    protected $fillable = ['user_id', 'kind', 'ref_id', 'ref_key', 'mode', 'started_at', 'finished_at', 'total', 'correct', 'unsure', 'elapsed_ms'];

    protected $casts = [
        'ref_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'total' => 'integer',
        'correct' => 'integer',
        'unsure' => 'integer',
        'elapsed_ms' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 回答履歴のテーブル名（kind ごと） */
    public static function attemptTable(string $kind): string
    {
        return match ($kind) {
            'grammar' => 'grammar_attempts',
            'vocab' => 'vocabulary_attempts',
            default => 'card_attempts',
        };
    }
}
