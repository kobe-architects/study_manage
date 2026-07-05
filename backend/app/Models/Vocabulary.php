<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Vocabulary extends Model
{
    protected $table = 'vocabularies';

    protected $fillable = [
        'study_resource_section_id', 'word', 'meaning', 'meaning_supplement', 'part_of_speech',
        'importance', 'label', 'proficiency', 'memo', 'image_path',
        'example_sentence', 'example_translation', 'example_explanation', 'sort_order',
    ];

    protected $casts = [
        'importance' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        // 単語削除時に添付画像を物理削除する
        static::deleting(function (Vocabulary $vocabulary) {
            if ($vocabulary->image_path && Storage::disk('public')->exists($vocabulary->image_path)) {
                Storage::disk('public')->delete($vocabulary->image_path);
            }
        });
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(StudyResourceSection::class, 'study_resource_section_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(VocabularyAttempt::class);
    }

    public function learningStats(): HasMany
    {
        return $this->hasMany(VocabularyLearningStat::class);
    }

    public function learningStatForUser(int $userId): HasOne
    {
        return $this->hasOne(VocabularyLearningStat::class)->where('user_id', $userId);
    }

    /**
     * 自ユーザー分の統計を eager load するための引数なしリレーション。
     * 呼び出し側で `with(['userStat' => fn ($q) => $q->where('user_id', $id)])` と絞り込む。
     */
    public function userStat(): HasOne
    {
        return $this->hasOne(VocabularyLearningStat::class);
    }
}
