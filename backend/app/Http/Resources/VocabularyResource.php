<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Vocabulary
 */
class VocabularyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $stat = $this->relationLoaded('userStat')
            ? $this->userStat
            : null;

        return [
            'id' => $this->id,
            'sectionId' => $this->study_resource_section_id,
            'word' => $this->word,
            'meaning' => $this->meaning,
            'partOfSpeech' => $this->part_of_speech,
            'importance' => $this->importance,
            'label' => $this->label,
            'proficiency' => $this->proficiency,
            'memo' => $this->memo,
            'exampleSentence' => $this->example_sentence,
            'exampleTranslation' => $this->example_translation,
            'exampleExplanation' => $this->example_explanation,
            'imageUrl' => $this->image_path
                ? url('/api/vocabularies/'.$this->id.'/image')
                : null,
            'sortOrder' => $this->sort_order,
            'learningStat' => $stat ? [
                'correctCount' => $stat->correct_count,
                'incorrectCount' => $stat->incorrect_count,
                'lastAttemptedAt' => $stat->last_attempted_at?->toIso8601String(),
                'nextReviewAt' => $stat->next_review_at?->toIso8601String(),
                'easeFactor' => (float) $stat->ease_factor,
                'intervalDays' => $stat->interval_days,
                'repetitionCount' => $stat->repetition_count,
            ] : null,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
