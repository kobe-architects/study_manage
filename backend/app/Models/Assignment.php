<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Assignment extends Model
{
    protected $fillable = [
        'user_id', 'created_by', 'title', 'note', 'due_on', 'achieved',
    ];

    protected $casts = ['due_on' => 'date', 'achieved' => 'boolean'];

    /** 課題の対象（生徒） */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 作成者（家庭教師） */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** 課題に含めた個別学習データ（教材の行） */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(ResourceBookItem::class, 'assignment_items');
    }
}
