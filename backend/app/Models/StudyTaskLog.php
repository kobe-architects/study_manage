<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** タスクの日ごとの消化量（1 タスク × 1 日に 1 行） */
class StudyTaskLog extends Model
{
    protected $fillable = ['study_task_id', 'date', 'amount'];

    protected $casts = ['date' => 'date', 'amount' => 'integer'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(StudyTask::class, 'study_task_id');
    }
}
