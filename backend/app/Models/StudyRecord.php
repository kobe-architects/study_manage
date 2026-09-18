<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyRecord extends Model
{
    protected $fillable = ['user_id', 'study_item_id', 'subject_id', 'resource_book_item_id', 'type', 'title', 'studied_on', 'color', 'review_on', 'reviewed_at'];

    /** 自由入力の記録の種別 */
    public const TYPE_FREE = '自由';

    protected $casts = ['studied_on' => 'date', 'review_on' => 'date', 'reviewed_at' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 自由入力の記録の科目 */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StudyItem::class, 'study_item_id');
    }

    public function bookItem(): BelongsTo
    {
        return $this->belongsTo(ResourceBookItem::class, 'resource_book_item_id');
    }
}
