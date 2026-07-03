<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\StudyItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $goals = Goal::with('subject')->where('user_id', $userId)->orderBy('deadline')->get();

        $data = $goals->map(fn (Goal $g) => [
            'id' => $g->id,
            'title' => $g->title,
            'subjectId' => $g->subject_id,
            'subjectName' => $g->subject?->name,
            'colorSoft' => $g->subject?->color_soft ?? '#475569',
            'colorVivid' => $g->subject?->color_vivid ?? '#475569',
            'scope' => $g->scope,
            'rangeLabel' => $g->range_label ?? $g->scope,
            'deadline' => $g->deadline->toDateString(),
            'target' => $g->target,
            'done' => $this->computeDone($g, $userId),
        ]);

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subjectId' => ['nullable', 'integer'],
            'scope' => ['nullable', 'string', 'max:150'],
            'rangeLabel' => ['nullable', 'string', 'max:150'],
            'deadline' => ['required', 'date'],
            'target' => ['required', 'integer', 'min:1'],
        ]);

        $goal = Goal::create([
            'user_id' => $userId,
            'subject_id' => $data['subjectId'] ?? null,
            'title' => $data['title'],
            'scope' => $data['scope'] ?? 'all',
            'range_label' => $data['rangeLabel'] ?? ($data['scope'] ?? 'all'),
            'deadline' => $data['deadline'],
            'target' => $data['target'],
        ]);

        return response()->json(['data' => ['id' => $goal->id]], 201);
    }

    public function destroy(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $goal->delete();

        return response()->json(['message' => 'deleted']);
    }

    /**
     * 達成項目数 = 範囲内で学習記録が1件以上ある小分類の数。
     */
    private function computeDone(Goal $goal, int $userId): int
    {
        if (! $goal->subject_id) {
            return 0;
        }

        $query = StudyItem::query()
            ->whereHas('mid.major', fn ($q) => $q->where('subject_id', $goal->subject_id))
            ->whereHas('records', fn ($q) => $q->where('user_id', $userId));

        if ($goal->scope && $goal->scope !== 'all') {
            $query->whereHas('mid.major', fn ($q) => $q->where('name', $goal->scope));
        }

        return $query->count();
    }
}
