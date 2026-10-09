<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ガントチャートの項目（期間つきのバー、または 1 日のマイルストーン） */
class GanttTask extends Model
{
    protected $fillable = ['gantt_chart_id', 'title', 'kind', 'start_on', 'end_on', 'color', 'progress', 'note', 'sort_order'];

    protected $casts = ['start_on' => 'date', 'end_on' => 'date', 'progress' => 'integer', 'sort_order' => 'integer'];

    public function chart(): BelongsTo
    {
        return $this->belongsTo(GanttChart::class, 'gantt_chart_id');
    }
}
