<?php

namespace App\Http\Controllers;

use App\Models\MistakeDeck;
use App\Models\MistakeNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 間違いノートのデッキ（本棚の「本」）。名前・色・並び順。ページは mistake_notes.mistake_deck_id で属する（1 ページ = 1 デッキ・任意）。
 * デッキを削除してもページは消えず「デッキなし」になる。
 */
class MistakeDeckController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->list($this->targetUserId($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $deck = MistakeDeck::create([
            'user_id' => $userId,
            'name' => trim($data['name']),
            'color' => $data['color'] ?? null,
            'sort_order' => ((int) MistakeDeck::where('user_id', $userId)->max('sort_order')) + 1,
        ]);

        return response()->json(['data' => $this->payload($deck->fresh(), 0)], 201);
    }

    public function update(Request $request, MistakeDeck $deck): JsonResponse
    {
        abort_unless($deck->user_id === $this->targetUserId($request), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $attrs = [];
        if (array_key_exists('name', $data)) {
            $attrs['name'] = trim($data['name']);
        }
        if (array_key_exists('color', $data)) {
            $attrs['color'] = $data['color'];
        }
        $deck->update($attrs);

        return response()->json(['data' => $this->payload($deck->fresh(), (int) $deck->notes()->count())]);
    }

    public function destroy(Request $request, MistakeDeck $deck): JsonResponse
    {
        abort_unless($deck->user_id === $this->targetUserId($request), 403);
        $deck->delete(); // ページは nullOnDelete で「デッキなし」になる

        return response()->json(['message' => 'deleted']);
    }

    /** 並び順（ids の順。指定のないものは末尾に元の順で） */
    public function reorder(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        $own = MistakeDeck::where('user_id', $userId)->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $data['ids']), $own));
        $rest = array_values(array_diff($own, $ids));
        DB::transaction(function () use ($ids, $rest) {
            foreach (array_merge($ids, $rest) as $i => $id) {
                MistakeDeck::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });

        return response()->json(['data' => $this->list($userId)]);
    }

    /** ページを別のデッキへまとめて移す（deckId = null でデッキなし） */
    public function assign(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'deckId' => ['nullable', 'integer'],
        ]);
        $deckId = null;
        if (! empty($data['deckId'])) {
            $deck = MistakeDeck::where('user_id', $userId)->whereKey((int) $data['deckId'])->first();
            abort_if($deck === null, 422, 'デッキが見つかりません。');
            $deckId = $deck->id;
        }
        MistakeNote::where('user_id', $userId)->whereIn('id', array_map('intval', $data['ids']))->update(['mistake_deck_id' => $deckId]);

        return response()->json(['data' => $this->list($userId)]);
    }

    private function list(int $userId): array
    {
        $counts = MistakeNote::where('user_id', $userId)->whereNotNull('mistake_deck_id')
            ->selectRaw('mistake_deck_id, count(*) as c')->groupBy('mistake_deck_id')->pluck('c', 'mistake_deck_id');

        return MistakeDeck::where('user_id', $userId)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (MistakeDeck $d) => $this->payload($d, (int) ($counts[$d->id] ?? 0)))->values()->all();
    }

    private function payload(MistakeDeck $d, int $count): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'color' => $d->color,
            'sortOrder' => (int) $d->sort_order,
            'count' => $count,
        ];
    }
}
