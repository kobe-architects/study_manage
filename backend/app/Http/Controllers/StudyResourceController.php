<?php

namespace App\Http\Controllers;

use App\Models\StudyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudyResourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // 生徒(owner)は自分の単語帳、家庭教師(tutor)は担当生徒の単語帳（小テストの英単語テスト出題用）
        $resources = StudyResource::with(['sections' => fn ($q) => $q->withCount('vocabularies')->orderBy('sort_order')->orderBy('id')])
            ->where('user_id', $this->targetUserId($request))
            ->orderBy('id')
            ->get();

        $data = $resources->map(fn (StudyResource $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'wordCount' => (int) $r->sections->sum('vocabularies_count'),
            'sections' => $r->sections->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'sortOrder' => $s->sort_order,
                'wordCount' => (int) $s->vocabularies_count,
            ])->values(),
        ]);

        return response()->json(['data' => $data]);
    }
}
