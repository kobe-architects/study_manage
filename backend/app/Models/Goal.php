<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    protected $fillable = [
        'user_id', 'parent_id', 'subject_id', 'title', 'scope', 'range_label', 'deadline', 'target', 'achieved',
    ];

    protected $casts = ['deadline' => 'date', 'target' => 'integer', 'achieved' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'parent_id');
    }

    /** 中間目標（子目標） */
    public function children(): HasMany
    {
        return $this->hasMany(Goal::class, 'parent_id');
    }

    /** 紐づけた個別学習データ（教材の行）。pivot に手動 学習済みフラグ studied を持つ */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(ResourceBookItem::class, 'goal_resource_items')->withPivot('studied');
    }
}
