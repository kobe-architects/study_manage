<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 質問事項のメッセージ（body は簡易タグ付きの文字。添付は QuestionAttachment） */
class QuestionMessage extends Model
{
    protected $fillable = ['thread_id', 'user_id', 'body', 'line_notified'];

    protected $casts = ['line_notified' => 'boolean'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(QuestionThread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(QuestionAttachment::class, 'message_id')->orderBy('id');
    }
}
