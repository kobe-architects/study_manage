<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyRecord extends Model
{
    protected $fillable = ['user_id', 'study_item_id', 'resource_book_item_id', 'type', 'studied_on'];

    protected $casts = ['studied_on' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StudyItem::class, 'study_item_id');
    }

    public function bookItem(): BelongsTo
    {
        return $this->belongsTo(ResourceBookItem::class, 'resource_book_item_id');
    }
}
