<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyItem extends Model
{
    protected $fillable = ['mid_category_id', 'name', 'sort_order', 'included'];

    protected $casts = ['included' => 'boolean'];

    public function mid(): BelongsTo
    {
        return $this->belongsTo(MidCategory::class, 'mid_category_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(StudyRecord::class);
    }

    public function bookItems(): HasMany
    {
        return $this->hasMany(ResourceBookItem::class);
    }
}
