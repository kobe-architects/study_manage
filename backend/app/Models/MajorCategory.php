<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MajorCategory extends Model
{
    protected $fillable = ['subject_id', 'name', 'sort_order'];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function mids(): HasMany
    {
        return $this->hasMany(MidCategory::class)->orderBy('sort_order');
    }
}
