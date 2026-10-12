<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 間違いノートの画像（problem=対象の問題の写真 / extra=説明用）。実体は storage/app/private/mistake-notes 配下 */
class MistakeNoteImage extends Model
{
    protected $fillable = ['mistake_note_id', 'role', 'path', 'thumb_path', 'width', 'height'];

    protected $casts = ['width' => 'integer', 'height' => 'integer'];

    public function note(): BelongsTo
    {
        return $this->belongsTo(MistakeNote::class, 'mistake_note_id');
    }
}
