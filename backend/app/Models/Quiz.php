<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * 小テスト。user_id は生徒、created_by は出題した講師。
 * status: assigned(出題中) → submitted(回答提出済み) → graded(添削・採点済み)
 */
class Quiz extends Model
{
    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_GRADED = 'graded';

    protected $fillable = [
        'user_id', 'created_by', 'group_key', 'resource_book_id', 'title', 'note', 'due_on',
        'max_score_per_page', 'file_path', 'status', 'submitted_at', 'graded_at',
    ];

    protected $casts = [
        'due_on' => 'date',
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'max_score_per_page' => 'integer',
    ];

    protected static function booted(): void
    {
        // 小テスト削除時に出題 PDF・回答写真・添削画像をまとめて削除
        static::deleting(function (Quiz $quiz) {
            Storage::disk('local')->deleteDirectory('quizzes/'.$quiz->id);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(ResourceBook::class, 'resource_book_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(QuizPage::class)->orderBy('page_no');
    }

    /** 小テスト用ディレクトリ（local ディスク相対） */
    public function dir(): string
    {
        return 'quizzes/'.$this->id;
    }
}
