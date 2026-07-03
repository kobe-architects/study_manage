<?php

namespace App\Http\Controllers;

use App\Models\StudyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudyResourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $resources = StudyResource::with('sections')
            ->where('user_id', $request->user()->id)
            ->orderBy('id')
            ->get();

        $data = $resources->map(fn (StudyResource $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'sections' => $r->sections->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'sortOrder' => $s->sort_order,
            ])->values(),
        ]);

        return response()->json(['data' => $data]);
    }
}
