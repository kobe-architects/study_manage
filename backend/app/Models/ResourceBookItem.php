<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResourceBookItem extends Model
{
    protected $fillable = [
        'resource_book_id', 'study_item_id', 'chapter', 'seq_no',
        'check_flag', 'title', 'difficulty', 'meta', 'included', 'important', 'sort_order',
    ];

    protected $casts = ['meta' => 'array', 'included' => 'boolean', 'important' => 'boolean'];

    public function book(): BelongsTo
    {
        return $this->belongsTo(ResourceBook::class, 'resource_book_id');
    }

    public function studyItem(): BelongsTo
    {
        return $this->belongsTo(StudyItem::class, 'study_item_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(StudyRecord::class, 'resource_book_item_id');
    }
}
