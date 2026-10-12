<?php

namespace App\Http\Controllers;

use App\Models\MajorCategory;
use App\Models\MistakeNote;
use App\Models\MistakeNoteImage;
use App\Models\Subject;
use App\Support\ImageTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 間違いノート（生徒）。1 問 = 1 ページで、対象の問題の写真（必須）と説明用の画像・手書き・入力の配置を layout(JSON) に持つ。
 * 一覧は 科目 → 分野 → 並び順 の順（バインダーのページ順）で返す。PDF はブラウザ側で組み立てるのでサーバーは画像の保管と配信のみ。
 */
class MistakeNoteController extends Controller
{
    private const MAX_LAYOUT_BYTES = 2_000_000;

    public function index(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $notes = MistakeNote::with(['subject', 'images'])->where('user_id', $userId)->get();

        return response()->json(['data' => $this->sortForBinder($notes, $userId)->map(fn (MistakeNote $n) => $this->summary($n))->values()]);
    }

    /** 新規作成。対象の問題の写真（image）が必須。layout は JSON 文字列 */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:12288'],
            'subjectId' => ['nullable', 'integer'],
            'field' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'notedOn' => ['nullable', 'date'],
            'layout' => ['nullable', 'string'],
        ]);

        $note = DB::transaction(function () use ($request, $userId, $data) {
            $note = MistakeNote::create([
                'user_id' => $userId,
                'subject_id' => $this->subjectIdOf($userId, $data['subjectId'] ?? null),
                'field' => $this->nullable($data['field'] ?? null),
                'title' => $this->nullable($data['title'] ?? null),
                'source' => $this->nullable($data['source'] ?? null),
                'noted_on' => $data['notedOn'] ?? now()->toDateString(),
                'layout' => $this->parseLayout($data['layout'] ?? null),
                'sort_order' => ((int) MistakeNote::where('user_id', $userId)->max('sort_order')) + 1,
            ]);
            $this->saveImage($note, $request->file('image'), 'problem');

            return $note;
        });

        return response()->json(['data' => $this->detail($note->fresh(['subject', 'images']))], 201);
    }

    public function show(Request $request, MistakeNote $note): JsonResponse
    {
        $this->authorizeNote($request, $note);

        return response()->json(['data' => $this->detail($note->load(['subject', 'images']))]);
    }

    public function update(Request $request, MistakeNote $note): JsonResponse
    {
        $this->authorizeNote($request, $note);
        $data = $request->validate([
            'subjectId' => ['sometimes', 'nullable', 'integer'],
            'field' => ['sometimes', 'nullable', 'string', 'max:100'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notedOn' => ['sometimes', 'required', 'date'],
            'layout' => ['sometimes', 'nullable', 'array'],
            'hidden' => ['sometimes', 'boolean'],
        ]);

        $attrs = [];
        if (array_key_exists('subjectId', $data)) {
            $attrs['subject_id'] = $this->subjectIdOf($note->user_id, $data['subjectId']);
        }
        foreach (['field', 'title', 'source'] as $k) {
            if (array_key_exists($k, $data)) {
                $attrs[$k] = $this->nullable($data[$k]);
            }
        }
        if (array_key_exists('notedOn', $data)) {
            $attrs['noted_on'] = $data['notedOn'];
        }
        if (array_key_exists('layout', $data)) {
            abort_if(strlen(json_encode($data['layout'])) > self::MAX_LAYOUT_BYTES, 422, 'ページの内容が大きすぎます。');
            $attrs['layout'] = $this->normalizeLayout($data['layout'] ?? []);
        }
        if (array_key_exists('hidden', $data)) {
            $attrs['hidden'] = (bool) $data['hidden'];
        }
        $note->update($attrs);

        return response()->json(['data' => $this->detail($note->fresh(['subject', 'images']))]);
    }

    public function destroy(Request $request, MistakeNote $note): JsonResponse
    {
        $this->authorizeNote($request, $note);
        Storage::disk('local')->deleteDirectory($this->dirOf($note));
        $note->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** 並び順の保存（ids の順に sort_order を振り直す。指定のないものは末尾に元の順で） */
    public function reorder(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        $own = MistakeNote::where('user_id', $userId)->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $data['ids']), $own));
        $rest = array_values(array_diff($own, $ids));
        DB::transaction(function () use ($ids, $rest) {
            foreach (array_merge($ids, $rest) as $i => $id) {
                MistakeNote::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });

        return $this->index($request);
    }

    // ================================================================
    // 画像
    // ================================================================

    /** 画像の追加。role=problem のときは対象の問題の写真を差し替える（前の写真は削除） */
    public function storeImage(Request $request, MistakeNote $note): JsonResponse
    {
        $this->authorizeNote($request, $note);
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:12288'],
            'role' => ['nullable', 'string', 'in:problem,extra'],
        ]);
        $role = $data['role'] ?? 'extra';
        if ($role === 'problem') {
            foreach ($note->images()->where('role', 'problem')->get() as $old) {
                $this->deleteFiles($old);
                $old->delete();
            }
        }
        $image = $this->saveImage($note, $request->file('image'), $role);
        $note->touch();

        return response()->json(['data' => $this->imagePayload($image)], 201);
    }

    public function destroyImage(Request $request, MistakeNoteImage $image): JsonResponse
    {
        $note = $image->note;
        $this->authorizeNote($request, $note);
        abort_if($image->role === 'problem', 422, '対象の問題の写真は削除できません（差し替えてください）。');
        $this->deleteFiles($image);
        $image->delete();
        $note->touch();

        return response()->json(['message' => 'deleted']);
    }

    /** 画像の配信（?v=thumb でサムネイル） */
    public function image(Request $request, MistakeNoteImage $image): StreamedResponse
    {
        $this->authorizeNote($request, $image->note);
        $path = $request->query('v') === 'thumb' && $image->thumb_path ? $image->thumb_path : $image->path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    // ================================================================
    // 内部
    // ================================================================

    private function authorizeNote(Request $request, MistakeNote $note): void
    {
        abort_unless($note->user_id === $this->targetUserId($request), 403);
    }

    private function subjectIdOf(int $userId, mixed $id): ?int
    {
        if ($id === null || $id === '' || (int) $id === 0) {
            return null;
        }
        $subject = Subject::where('user_id', $userId)->whereKey((int) $id)->first();
        abort_if($subject === null, 422, '科目が見つかりません。');

        return $subject->id;
    }

    private function nullable(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);

        return $v === '' ? null : $v;
    }

    private function parseLayout(?string $json): array
    {
        if ($json === null || $json === '') {
            return ['items' => [], 'ink' => []];
        }
        abort_if(strlen($json) > self::MAX_LAYOUT_BYTES, 422, 'ページの内容が大きすぎます。');
        $decoded = json_decode($json, true);
        abort_unless(is_array($decoded), 422, 'ページの内容の形式が正しくありません。');

        return $this->normalizeLayout($decoded);
    }

    /** layout は { items: [], ink: [] } の形に揃える（中身の検証は最小限） */
    private function normalizeLayout(array $layout): array
    {
        return [
            'items' => array_values(array_filter($layout['items'] ?? [], 'is_array')),
            'ink' => array_values(array_filter($layout['ink'] ?? [], 'is_array')),
        ];
    }

    private function dirOf(MistakeNote $note): string
    {
        return 'mistake-notes/'.$note->user_id.'/'.$note->id;
    }

    private function saveImage(MistakeNote $note, UploadedFile $file, string $role): MistakeNoteImage
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $full = ImageTools::normalizeJpeg($bytes, 1800, 86);
        $thumb = ImageTools::normalizeJpeg($bytes, 480, 78);
        $size = @getimagesizefromstring($full);
        $name = (string) Str::uuid();
        $dir = $this->dirOf($note);
        $disk = Storage::disk('local');
        $disk->put($dir.'/'.$name.'.jpg', $full);
        $disk->put($dir.'/'.$name.'_t.jpg', $thumb);

        return $note->images()->create([
            'role' => $role,
            'path' => $dir.'/'.$name.'.jpg',
            'thumb_path' => $dir.'/'.$name.'_t.jpg',
            'width' => (int) ($size[0] ?? 0),
            'height' => (int) ($size[1] ?? 0),
        ]);
    }

    private function deleteFiles(MistakeNoteImage $image): void
    {
        $disk = Storage::disk('local');
        foreach ([$image->path, $image->thumb_path] as $p) {
            if ($p && $disk->exists($p)) {
                $disk->delete($p);
            }
        }
    }

    /**
     * バインダーのページ順に並べる: 科目（科目の並び順・未設定は最後）→ 分野（大分類の並び順に一致すればその順、それ以外は名前順・未設定は最後）→ 並び順
     *
     * @param  \Illuminate\Support\Collection<int, MistakeNote>  $notes
     * @return \Illuminate\Support\Collection<int, MistakeNote>
     */
    private function sortForBinder($notes, int $userId)
    {
        $fieldOrder = [];
        $majors = MajorCategory::whereIn('subject_id', Subject::where('user_id', $userId)->pluck('id'))->get(['subject_id', 'name', 'sort_order']);
        foreach ($majors as $m) {
            $fieldOrder[$m->subject_id][$m->name] = (int) $m->sort_order;
        }
        $key = fn (MistakeNote $n) => [
            $n->subject?->sort_order ?? PHP_INT_MAX,
            $n->subject_id ?? PHP_INT_MAX,
            $n->field === null ? 1 : 0,
            $fieldOrder[$n->subject_id][$n->field] ?? PHP_INT_MAX,
            (string) $n->field,
            (int) $n->sort_order,
            (int) $n->id,
        ];

        return $notes->sort(fn (MistakeNote $a, MistakeNote $b) => $key($a) <=> $key($b))->values();
    }

    private function summary(MistakeNote $n): array
    {
        $problem = $n->images->firstWhere('role', 'problem');

        return [
            'id' => $n->id,
            'subjectId' => $n->subject_id,
            'subjectName' => $n->subject?->name,
            'colorSoft' => $n->subject?->color_soft,
            'colorVivid' => $n->subject?->color_vivid,
            'field' => $n->field,
            'title' => $n->title,
            'source' => $n->source,
            'notedOn' => $n->noted_on->toDateString(),
            'hidden' => (bool) $n->hidden,
            'sortOrder' => (int) $n->sort_order,
            'problemImageId' => $problem?->id,
            'problemThumbUrl' => $problem ? '/api/mistake-note-images/'.$problem->id.'?v=thumb' : null,
            'imageCount' => $n->images->count(),
            'updatedAt' => $n->updated_at?->toDateTimeString(),
        ];
    }

    private function detail(MistakeNote $n): array
    {
        $layout = $n->layout ?? [];

        return $this->summary($n) + [
            'layout' => ['items' => array_values($layout['items'] ?? []), 'ink' => array_values($layout['ink'] ?? [])],
            'images' => $n->images->map(fn (MistakeNoteImage $i) => $this->imagePayload($i))->values()->all(),
        ];
    }

    private function imagePayload(MistakeNoteImage $i): array
    {
        return [
            'id' => $i->id,
            'role' => $i->role,
            'width' => (int) $i->width,
            'height' => (int) $i->height,
            'url' => '/api/mistake-note-images/'.$i->id,
            'thumbUrl' => '/api/mistake-note-images/'.$i->id.'?v=thumb',
        ];
    }
}
