<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MidCategory extends Model
{
    protected $fillable = ['major_category_id', 'name', 'sort_order'];

    public function major(): BelongsTo
    {
        return $this->belongsTo(MajorCategory::class, 'major_category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StudyItem::class)->orderBy('sort_order');
    }
}
