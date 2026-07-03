<?php

namespace App\Http\Controllers;

use App\Http\Resources\VocabularyResource;
use App\Models\StudyResource;
use App\Models\Vocabulary;
use App\Services\VocabularyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VocabularyQuizController extends Controller
{
    public function __construct(private VocabularyService $service) {}

    /** 出題取得（出題単語 + 4択） */
    public function quiz(Request $request, StudyResource $studyResource): JsonResponse
    {
        $this->authorizeResource($request, $studyResource);
        $userId = $request->user()->id;

        $params = [
            'count' => (int) $request->query('count', 20),
            'quizType' => $request->query('quizType', 'choice'),
            'sectionIds' => $this->csv($request->query('sectionIds')),
            'importances' => $this->csv($request->query('importances')),
            'labels' => $this->csv($request->query('labels'), false),
            'ordered' => filter_var($request->query('ordered', false), FILTER_VALIDATE_BOOLEAN),
            'vocabularyIds' => $this->csv($request->query('vocabularyIds')),
        ];

        $words = $this->service->getQuizWords($studyResource, $userId, $params);
        $isChoice = $params['quizType'] === 'choice';

        $data = $words->map(function (Vocabulary $w) use ($isChoice) {
            $entry = ['vocabulary' => new VocabularyResource($w)];
            if ($isChoice) {
                $entry['choices'] = $this->service->generateChoices($w);
            }

            return $entry;
        })->values();

        return response()->json(['data' => $data]);
    }

    /** 回答記録（SM-2 更新） */
    public function attempt(Request $request, Vocabulary $vocabulary): JsonResponse
    {
        $this->authorizeVocab($request, $vocabulary);
        $data = $request->validate([
            'is_correct' => ['required', 'boolean'],
            'quiz_type' => ['required', 'in:choice,input'],
        ]);

        $attempt = $this->service->recordAttempt(
            $vocabulary,
            $request->user()->id,
            $data['is_correct'],
            $data['quiz_type']
        );

        return response()->json(['data' => [
            'id' => $attempt->id,
            'isCorrect' => $attempt->is_correct,
            'quizType' => $attempt->quiz_type,
        ]]);
    }

    /** 進捗統計 */
    public function stats(Request $request, StudyResource $studyResource): JsonResponse
    {
        $this->authorizeResource($request, $studyResource);

        return response()->json([
            'data' => $this->service->getProgress($studyResource, $request->user()->id),
        ]);
    }

    /** トップページ用: 自ユーザーの全英単語教材の習得進捗（全体 + セクション別） */
    public function homeProgress(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $resources = StudyResource::where('user_id', $userId)->orderBy('id')->get();

        $data = $resources->map(fn (StudyResource $r) => $this->service->getProgressBySection($r, $userId));

        return response()->json(['data' => $data]);
    }

    /** 誤答抽出（復習） */
    public function incorrect(Request $request, StudyResource $studyResource): JsonResponse
    {
        $this->authorizeResource($request, $studyResource);
        $userId = $request->user()->id;

        $data = $request->validate([
            'since' => ['required', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:since'],
        ]);

        $since = Carbon::parse($data['since'])->startOfDay();
        $until = isset($data['until']) ? Carbon::parse($data['until'])->endOfDay() : Carbon::now();

        $vocabIds = Vocabulary::query()
            ->whereHas('section', fn ($q) => $q->where('study_resource_id', $studyResource->id))
            ->whereHas('attempts', function ($q) use ($userId, $since, $until) {
                $q->where('user_id', $userId)
                    ->where('is_correct', false)
                    ->whereBetween('answered_at', [$since, $until]);
            })
            ->pluck('id');

        $words = Vocabulary::query()
            ->whereIn('id', $vocabIds)
            ->with(['userStat' => fn ($q) => $q->where('user_id', $userId)])
            ->get();

        return response()->json([
            'data' => VocabularyResource::collection($words),
            'meta' => [
                'total' => $words->count(),
                'since' => $since->toDateString(),
                'until' => $until->toDateString(),
            ],
        ]);
    }

    private function csv(?string $value, bool $asInt = true): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parts = array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== '');

        return $asInt ? array_map('intval', $parts) : array_values($parts);
    }

    private function authorizeResource(Request $request, StudyResource $resource): void
    {
        abort_unless($resource->user_id === $request->user()->id, 403);
    }

    private function authorizeVocab(Request $request, Vocabulary $vocab): void
    {
        abort_unless($vocab->section->resource->user_id === $request->user()->id, 403);
    }
}
