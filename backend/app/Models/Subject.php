<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = [
        'user_id', 'code', 'name', 'group_name',
        'color_soft', 'color_vivid', 'sort_order',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function majors(): HasMany
    {
        return $this->hasMany(MajorCategory::class)->orderBy('sort_order');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }
}
