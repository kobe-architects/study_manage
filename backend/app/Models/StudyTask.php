<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * タスク（科目ごとの「やるべきこと」）。期間あたりの量（140 words / week など）で登録し、
 * 日別・週別の計画への展開は画面側（lib/tasks.ts）で行う。
 */
class StudyTask extends Model
{
    protected $fillable = [
        'user_id', 'subject_id', 'title', 'amount', 'unit', 'period', 'weekdays',
        'start_on', 'end_on', 'note', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'amount' => 'integer',
        'weekdays' => 'array',
        'start_on' => 'date',
        'end_on' => 'date',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** 日ごとの消化量 */
    public function logs(): HasMany
    {
        return $this->hasMany(StudyTaskLog::class);
    }
}
