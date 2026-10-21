<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id', 'name', 'school', 'exam_date', 'default_type',
        'reminder', 'weekly_report', 'hide_empty', 'start_screen', 'tutor_quiz_enabled', 'tutor_subjects_enabled', 'prefs',
    ];

    protected $casts = [
        'exam_date' => 'date',
        'reminder' => 'boolean',
        'weekly_report' => 'boolean',
        'hide_empty' => 'boolean',
        'tutor_quiz_enabled' => 'boolean',
        'tutor_subjects_enabled' => 'boolean',
        'prefs' => 'array', // 画面側の好み（間違いノートのペン設定など）
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
