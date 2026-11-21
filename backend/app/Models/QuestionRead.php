<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 質問事項の既読位置（スレッド × ユーザー。読んだ最後のメッセージ ID） */
class QuestionRead extends Model
{
    protected $fillable = ['thread_id', 'user_id', 'last_read_message_id', 'read_at'];

    protected $casts = ['last_read_message_id' => 'integer', 'read_at' => 'datetime'];
}
