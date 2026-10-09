<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ガントチャート（数年分の学習計画）。1ユーザーが複数持てる */
class GanttChart extends Model
{
    protected $fillable = ['user_id', 'title', 'note', 'start_on', 'end_on'];

    protected $casts = ['start_on' => 'date', 'end_on' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(GanttTask::class)->orderBy('sort_order')->orderBy('id');
    }
}
