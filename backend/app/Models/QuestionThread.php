<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 質問事項のスレッド（user_id は生徒。タイトル・分類は任意。status は open=質問中 / resolved=解決済み） */
class QuestionThread extends Model
{
    public const STATUSES = ['open', 'resolved'];

    protected $fillable = ['user_id', 'title', 'subject_id', 'major_category_id', 'mid_category_id', 'status', 'created_by', 'last_message_at', 'message_count'];

    protected $casts = ['last_message_at' => 'datetime', 'message_count' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function major(): BelongsTo
    {
        return $this->belongsTo(MajorCategory::class, 'major_category_id');
    }

    public function mid(): BelongsTo
    {
        return $this->belongsTo(MidCategory::class, 'mid_category_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(QuestionMessage::class, 'thread_id')->orderBy('id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(QuestionRead::class, 'thread_id');
    }
}
