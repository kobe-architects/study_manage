<?php

namespace App\Http\Controllers;

use App\Http\Resources\VocabularyResource;
use App\Models\StudyResource;
use App\Models\Vocabulary;
use App\Models\QuizSession;
use App\Models\ReviewState;
use App\Models\VocabularyAttempt;
use App\Services\ReviewService;
use App\Services\VocabularyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VocabularyQuizController extends Controller
{
    public function __construct(private VocabularyService $service, private ReviewService $review) {}

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
            'offset' => max(0, (int) $request->query('offset', 0)),
            'vocabularyIds' => $this->csv($request->query('vocabularyIds')),
            'rangeFrom' => (int) $request->query('rangeFrom', 0),
            'rangeTo' => (int) $request->query('rangeTo', 0),
            'due' => filter_var($request->query('due', false), FILTER_VALIDATE_BOOLEAN), // 忘却曲線の復習（期限が来ている単語だけ）
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
            'elapsed_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'session_id' => ['nullable', 'integer'],
        ]);
        $userId = $request->user()->id;
        $sessionId = ! empty($data['session_id']) && QuizSession::where('id', $data['session_id'])->where('user_id', $userId)->exists()
            ? (int) $data['session_id'] : null;

        $attempt = $this->service->recordAttempt(
            $vocabulary,
            $userId,
            $data['is_correct'],
            $data['quiz_type'],
            $data['elapsed_ms'] ?? null,
            $sessionId,
        );
        $state = ReviewState::where(['user_id' => $userId, 'kind' => ReviewState::KIND_VOCAB, 'item_id' => $vocabulary->id])->first();

        return response()->json(['data' => [
            'id' => $attempt->id,
            'isCorrect' => $attempt->is_correct,
            'quizType' => $attempt->quiz_type,
            'review' => $state ? $this->review->payload($state) : null,
        ]]);
    }

    /** 「あいまい」（正解だったが自信がない）の付け外し。正解の回答にだけ付く */
    public function updateAttempt(Request $request, VocabularyAttempt $attempt): JsonResponse
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);
        $data = $request->validate(['is_unsure' => ['required', 'boolean']]);
        $attempt->update(['is_unsure' => $attempt->is_correct && $data['is_unsure']]);
        // 復習状態を、この回答の前の状態に戻してから付け替え後の結果（あいまい=難しい）で進め直す
        $prev = $attempt->review_prev;
        $state = $this->review->reapply($attempt->user_id, ReviewState::KIND_VOCAB, $attempt->vocabulary_id, $prev['state'] ?? null, is_array($prev), $attempt->is_correct, $attempt->is_unsure);

        return response()->json(['data' => [
            'id' => $attempt->id,
            'isCorrect' => $attempt->is_correct,
            'isUnsure' => $attempt->is_unsure,
            'review' => $this->review->payload($state),
        ]]);
    }

    /**
     * チェックシート用: 単語帳 1 冊の単語ごとの日ごとの結果。
     * 同じ日に何度か解いたときは 1 日 1 つにまとめ、1 度でも不正解なら不正解（0）、正解だけであいまいがあれば あいまい（2）、それ以外は正解（1）。
     * 日付は端末のタイムゾーン（tz = UTC からのずれ・分。既定は日本時間）で区切る。
     * 返り値: { marks: { 単語ID: [[YYYY-MM-DD, 0|1|2], ...] } }
     */
    public function checksheet(Request $request, StudyResource $studyResource): JsonResponse
    {
        $this->authorizeResource($request, $studyResource);
        $userId = $request->user()->id;
        $tz = max(-840, min(840, (int) $request->query('tz', 540)));
        $rows = VocabularyAttempt::query()
            ->join('vocabularies', 'vocabularies.id', '=', 'vocabulary_attempts.vocabulary_id')
            ->join('study_resource_sections', 'study_resource_sections.id', '=', 'vocabularies.study_resource_section_id')
            ->where('vocabulary_attempts.user_id', $userId)
            ->where('study_resource_sections.study_resource_id', $studyResource->id)
            ->selectRaw(
                'vocabulary_attempts.vocabulary_id AS vid, DATE(DATE_ADD(vocabulary_attempts.answered_at, INTERVAL ? MINUTE)) AS d, MIN(vocabulary_attempts.is_correct) AS ok, MAX(vocabulary_attempts.is_unsure) AS unsure',
                [$tz],
            )
            ->groupByRaw('vocabulary_attempts.vocabulary_id, d')
            ->orderByRaw('vocabulary_attempts.vocabulary_id, d')
            ->get();

        $marks = [];
        foreach ($rows as $r) {
            $marks[(int) $r->vid][] = [(string) $r->d, (int) $r->ok === 0 ? 0 : ((int) $r->unsure ? 2 : 1)];
        }

        return response()->json(['data' => ['marks' => (object) $marks]]);
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
