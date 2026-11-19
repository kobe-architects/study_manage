<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 計画表（ガントチャート。数年分の学習計画）。1ユーザーが複数持てる。tutor_id が入っているものは講師側の計画表 */
class GanttChart extends Model
{
    protected $fillable = ['user_id', 'tutor_id', 'title', 'note', 'start_on', 'end_on'];

    protected $casts = ['start_on' => 'date', 'end_on' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 講師側の計画表のときの講師（生徒側の計画表は null） */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }

    /** 行（科目・学習分野） */
    public function rows(): HasMany
    {
        return $this->hasMany(GanttRow::class)->orderBy('sort_order')->orderBy('id');
    }

    /** 全区間（行をまたいだ一覧。件数の集計用） */
    public function tasks(): HasMany
    {
        return $this->hasMany(GanttTask::class)->orderBy('sort_order')->orderBy('id');
    }
}
