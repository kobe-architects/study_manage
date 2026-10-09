<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ガントチャートの行（科目・学習分野）。区間（GanttTask）を複数持つ */
class GanttRow extends Model
{
    protected $fillable = ['gantt_chart_id', 'title', 'group_name', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function chart(): BelongsTo
    {
        return $this->belongsTo(GanttChart::class, 'gantt_chart_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(GanttTask::class, 'gantt_row_id')->orderBy('start_on')->orderBy('sort_order')->orderBy('id');
    }
}
