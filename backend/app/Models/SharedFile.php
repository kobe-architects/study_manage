<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** ファイル共有のファイル。本体は local(private) ディスクの shared/{生徒ID}/ 以下 */
class SharedFile extends Model
{
    protected $fillable = ['user_id', 'folder_id', 'name', 'mime', 'file_path', 'size_bytes', 'page_count', 'search_text', 'note', 'created_by'];

    protected $casts = ['size_bytes' => 'integer', 'page_count' => 'integer'];

    protected static function booted(): void
    {
        static::deleting(function (SharedFile $file) {
            if ($file->file_path && Storage::disk('local')->exists($file->file_path)) {
                Storage::disk('local')->delete($file->file_path);
            }
        });
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(SharedFolder::class, 'folder_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->file_path);
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf' || preg_match('/\.pdf$/i', $this->name) === 1;
    }
}
