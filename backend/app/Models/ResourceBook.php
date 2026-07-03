<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ResourceBook extends Model
{
    protected $fillable = ['user_id', 'type', 'title', 'subject_id', 'image_path', 'sort_order', 'pinned'];

    protected $casts = ['pinned' => 'boolean'];

    protected static function booted(): void
    {
        // 教材削除時にタイトル画像も物理削除
        static::deleting(function (ResourceBook $book) {
            if ($book->image_path && Storage::disk('public')->exists($book->image_path)) {
                Storage::disk('public')->delete($book->image_path);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ResourceBookItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
