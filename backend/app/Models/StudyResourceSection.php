<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyResourceSection extends Model
{
    protected $fillable = ['study_resource_id', 'name', 'sort_order'];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(StudyResource::class, 'study_resource_id');
    }

    public function vocabularies(): HasMany
    {
        return $this->hasMany(Vocabulary::class)->orderBy('sort_order');
    }
}
