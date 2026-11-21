<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 質問事項の添付（kind = image / file。本体は storage/app/private/questions/{生徒ID}/{スレッドID}/） */
class QuestionAttachment extends Model
{
    protected $fillable = ['message_id', 'kind', 'name', 'mime', 'path', 'thumb_path', 'size_bytes', 'width', 'height'];

    protected $casts = ['size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(QuestionMessage::class, 'message_id');
    }
}
