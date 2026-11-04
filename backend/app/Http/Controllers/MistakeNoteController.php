<?php

namespace App\Http\Controllers;

use App\Models\MajorCategory;
use App\Models\MidCategory;
use App\Models\MistakeDeck;
use App\Models\MistakeNote;
use App\Models\MistakeNoteImage;
use App\Models\MistakeReview;
use App\Models\ResourceBook;
use App\Models\StudyItem;
use App\Models\Subject;
use App\Support\ImageTools;
use App\Support\MistakeReviewScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 間違いノート（生徒）。1 問 = 1 ページで、対象の問題の写真（必須）と解答の画像（任意）・説明用の画像・手書き・入力の配置を layout(JSON) に持つ。
 * 一覧は 科目 → 分野 → 並び順 の順（バインダーのページ順）で返す。PDF はブラウザ側で組み立てるのでサーバーは画像の保管と配信のみ。
 * 科目「その他」は subjectId = OTHER_SUBJECT（-1）で指定し subject_id = NULL・分類なしで保存する（本棚ではデッキ別の参照のみ）。
 * 問題解答モードの評価（review）は間隔反復（MistakeReviewScheduler）で次回の出題時刻を決める。
 */
class MistakeNoteController extends Controller
{
    private const MAX_LAYOUT_BYTES = 2_000_000;

    /** 科目「その他」を表す subjectId（subjects の行は無い） */
    public const OTHER_SUBJECT = -1;

    private const IMAGE_ROLES = 'in:problem,extra,answer';

    public function index(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $notes = MistakeNote::with(self::WITH)->where('user_id', $userId)->get();

        return response()->json(['data' => $this->sortForBinder($notes)->map(fn (MistakeNote $n) => $this->summary($n))->values()]);
    }

    private const WITH = ['subject', 'major', 'mid', 'item', 'book', 'deck', 'images'];

    /** 新規作成。対象の問題の写真（image）と 科目・大分類・中分類 が必須。layout は JSON 文字列 */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:12288'],
            'subjectId' => ['required', 'integer'],
            'majorId' => ['nullable', 'integer'],
            'midId' => ['nullable', 'integer'],
            'studyItemId' => ['nullable', 'integer'],
            'resourceBookId' => ['nullable', 'integer'],
            'deckId' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'notedOn' => ['nullable', 'date'],
            'layout' => ['nullable', 'string'],
        ]);
        $cats = $this->categoriesOf($userId, $data);
        $bookId = $this->bookIdOf($userId, $data['resourceBookId'] ?? null);
        $deckId = $this->deckIdOf($userId, $data['deckId'] ?? null);

        $note = DB::transaction(function () use ($request, $userId, $data, $cats, $bookId, $deckId) {
            $note = MistakeNote::create($cats + [
                'user_id' => $userId,
                'resource_book_id' => $bookId,
                'mistake_deck_id' => $deckId,
                'title' => $this->nullable($data['title'] ?? null),
                'source' => $this->nullable($data['source'] ?? null),
                'noted_on' => $data['notedOn'] ?? now()->toDateString(),
                'layout' => $this->parseLayout($data['layout'] ?? null),
                'sort_order' => ((int) MistakeNote::where('user_id', $userId)->max('sort_order')) + 1,
            ]);
            $this->saveImage($note, $request->file('image'), 'problem');

            return $note;
        });

        return response()->json(['data' => $this->detail($note->fresh(self::WITH))], 201);
    }

    public function show(Request $request, MistakeNote $note): JsonResponse
    {
        $this->authorizeNote($request, $note);

        return response()->json(['data' => $this->detail($note->load(self::WITH))]);
    }

    public function update(Request $request, MistakeNote $note): JsonResponse
    {
        $this->authorizeNote($request, $note);
        $data = $request->validate([
            'subjectId' => ['sometimes', 'required', 'integer'],
            'majorId' => ['sometimes', 'nullable', 'integer'],
            'midId' => ['sometimes', 'nullable', 'integer'],
            'studyItemId' => ['sometimes', 'nullable', 'integer'],
            'resourceBookId' => ['sometimes', 'nullable', 'integer'],
            'deckId' => ['sometimes', 'nullable', 'integer'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notedOn' => ['sometimes', 'required', 'date'],
            'layout' => ['sometimes', 'nullable', 'array'],
            'hidden' => ['sometimes', 'boolean'],
        ]);

        $attrs = [];
        if (array_key_exists('resourceBookId', $data)) {
            $attrs['resource_book_id'] = $this->bookIdOf($note->user_id, $data['resourceBookId']);
        }
        if (array_key_exists('deckId', $data)) {
            $attrs['mistake_deck_id'] = $this->deckIdOf($note->user_id, $data['deckId']);
        }
        // 分野はまとめて検証する（指定のない項目は今の値を使う）。先に入れた出典を消さないよう追加する
        if (array_key_exists('subjectId', $data) || array_key_exists('majorId', $data) || array_key_exists('midId', $data) || array_key_exists('studyItemId', $data)) {
            $attrs = array_merge($attrs, $this->categoriesOf($note->user_id, [
                'subjectId' => $data['subjectId'] ?? ($note->subject_id ?? self::OTHER_SUBJECT),
                'majorId' => $data['majorId'] ?? $note->major_category_id,
                'midId' => $data['midId'] ?? $note->mid_category_id,
                'studyItemId' => array_key_exists('studyItemId', $data) ? $data['studyItemId'] : $note->study_item_id,
            ]));
        }
        foreach (['title', 'source'] as $k) {
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

        return response()->json(['data' => $this->detail($note->fresh(self::WITH))]);
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
    // 問題解答モード（復習）
    // ================================================================

    /**
     * 評価を保存し、忘却曲線に合わせて次回の出題時刻を更新する。
     * grade: again（もう一度）/ hard（難しい）/ good（正解）/ easy（簡単）。履歴は mistake_reviews に残す。
     */
    public function review(Request $request, MistakeNote $note): JsonResponse
    {
        $this->authorizeNote($request, $note);
        $data = $request->validate(['grade' => ['required', 'string', 'in:'.implode(',', MistakeReview::GRADES)]]);
        $now = now();
        $next = MistakeReviewScheduler::next($note, $data['grade'], $now);
        DB::transaction(function () use ($note, $data, $now, $next) {
            $note->update([
                'review_interval' => $next['interval'],
                'review_ease' => $next['ease'],
                'review_reps' => $next['reps'],
                'review_lapses' => $next['lapses'],
                'review_due_at' => $next['due_at'],
                'review_last_at' => $now,
                'review_last_grade' => $data['grade'],
            ]);
            MistakeReview::create([
                'user_id' => $note->user_id,
                'mistake_note_id' => $note->id,
                'grade' => $data['grade'],
                'interval_after' => $next['interval'],
                'ease_after' => $next['ease'],
                'reviewed_at' => $now,
            ]);
        });

        return response()->json(['data' => $this->reviewPayload($note->fresh())]);
    }

    // ================================================================
    // 画像
    // ================================================================

    /** 画像の追加。role=problem（問題）/ answer（解答）のときは前の同じ役割の画像を差し替える（削除）。問題・解答は 1 枚ずつ */
    public function storeImage(Request $request, MistakeNote $note): JsonResponse
    {
        $this->authorizeNote($request, $note);
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:12288'],
            'role' => ['nullable', 'string', self::IMAGE_ROLES],
        ]);
        $role = $data['role'] ?? 'extra';
        if ($role !== 'extra') {
            foreach ($note->images()->where('role', $role)->get() as $old) {
                $this->deleteFiles($old);
                $old->delete();
            }
        }
        $image = $this->saveImage($note, $request->file('image'), $role);
        $note->touch();

        return response()->json(['data' => $this->imagePayload($image)], 201);
    }

    /**
     * 画像の役割の変更。role=problem にすると、その画像が対象の問題の写真になり、それまでの問題の写真は説明用（extra）になる
     * （role=answer も同様に、それまでの解答の画像は説明用になる）。
     * 問題の写真を extra / answer にすることはできない（問題の写真は必ず 1 枚）。解答の画像は extra に戻せる。
     */
    public function updateImage(Request $request, MistakeNoteImage $image): JsonResponse
    {
        $note = $image->note;
        $this->authorizeNote($request, $note);
        $data = $request->validate(['role' => ['required', 'string', self::IMAGE_ROLES]]);
        $role = $data['role'];
        if ($role !== 'problem') {
            abort_if($image->role === 'problem', 422, '対象の問題の写真は必ず 1 枚必要です。別の画像を「問題」にしてください。');
        }
        if ($image->role !== $role) {
            DB::transaction(function () use ($note, $image, $role) {
                if ($role !== 'extra') {
                    $note->images()->where('role', $role)->update(['role' => 'extra']);
                }
                $image->update(['role' => $role]);
            });
        }
        $note->touch();

        return response()->json(['data' => $note->fresh('images')->images->map(fn (MistakeNoteImage $i) => $this->imagePayload($i))->values()]);
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

    /**
     * 科目 → 大分類 → 中分類（→ 小分類）の指定を検証し、保存する列に変換する。
     * 大分類は科目のもの、中分類は大分類のもの、小分類は中分類のものに限る。
     *
     * @return array{subject_id: int, major_category_id: int, mid_category_id: int, study_item_id: ?int}
     */
    private function categoriesOf(int $userId, array $in): array
    {
        // 科目「その他」: 分類は持たない（デッキ別の参照のみ）
        if ((int) ($in['subjectId'] ?? 0) === self::OTHER_SUBJECT) {
            return ['subject_id' => null, 'major_category_id' => null, 'mid_category_id' => null, 'study_item_id' => null];
        }
        $subject = Subject::where('user_id', $userId)->whereKey((int) ($in['subjectId'] ?? 0))->first();
        abort_if($subject === null, 422, '科目を選んでください。');
        $major = MajorCategory::where('subject_id', $subject->id)->whereKey((int) ($in['majorId'] ?? 0))->first();
        abort_if($major === null, 422, '大分類を選んでください。');
        $mid = MidCategory::where('major_category_id', $major->id)->whereKey((int) ($in['midId'] ?? 0))->first();
        abort_if($mid === null, 422, '中分類を選んでください。');
        $item = null;
        if (! empty($in['studyItemId'])) {
            $item = StudyItem::where('mid_category_id', $mid->id)->whereKey((int) $in['studyItemId'])->first();
            abort_if($item === null, 422, '小分類が見つかりません。');
        }

        return [
            'subject_id' => $subject->id,
            'major_category_id' => $major->id,
            'mid_category_id' => $mid->id,
            'study_item_id' => $item?->id,
        ];
    }

    /** デッキの検証。生徒自身のデッキのみ。null は未選択（デッキなし） */
    private function deckIdOf(int $userId, mixed $id): ?int
    {
        if ($id === null || $id === '' || (int) $id === 0) {
            return null;
        }
        $deck = MistakeDeck::where('user_id', $userId)->whereKey((int) $id)->first();
        abort_if($deck === null, 422, 'デッキが見つかりません。');

        return $deck->id;
    }

    /** 出典の教材（個別学習一覧データ）の検証。生徒自身の教材のみ。null は未選択 */
    private function bookIdOf(int $userId, mixed $id): ?int
    {
        if ($id === null || $id === '' || (int) $id === 0) {
            return null;
        }
        $book = ResourceBook::where('user_id', $userId)->whereKey((int) $id)->first();
        abort_if($book === null, 422, '出典の教材が見つかりません。');

        return $book->id;
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
     * バインダーのページ順に並べる: 科目（並び順・未設定は最後）→ 大分類（並び順）→ 中分類（並び順）→ 並び順
     *
     * @param  Collection<int, MistakeNote>  $notes
     * @return Collection<int, MistakeNote>
     */
    private function sortForBinder($notes)
    {
        $key = fn (MistakeNote $n) => [
            $n->subject?->sort_order ?? PHP_INT_MAX,
            $n->subject_id ?? PHP_INT_MAX,
            $n->major?->sort_order ?? PHP_INT_MAX,
            $n->major_category_id ?? PHP_INT_MAX,
            $n->mid?->sort_order ?? PHP_INT_MAX,
            $n->mid_category_id ?? PHP_INT_MAX,
            (int) $n->sort_order,
            (int) $n->id,
        ];

        return $notes->sort(fn (MistakeNote $a, MistakeNote $b) => $key($a) <=> $key($b))->values();
    }

    /**
     * 置いたもの（画像・文字・手書き）全体の大きさ。ページ割り（1 ページに複数の問題を収める）の判定に使う。
     * 空の文字枠は数えない。何も無ければ 0×0。
     *
     * @return array{w: int, h: int}
     */
    private function contentSize(?array $layout): array
    {
        $x1 = INF;
        $y1 = INF;
        $x2 = -INF;
        $y2 = -INF;
        foreach ($layout['items'] ?? [] as $it) {
            if (! is_array($it)) {
                continue;
            }
            if (($it['type'] ?? '') === 'text' && trim((string) ($it['text'] ?? '')) === '') {
                continue;
            }
            $x = (float) ($it['x'] ?? 0);
            $y = (float) ($it['y'] ?? 0);
            $x1 = min($x1, $x);
            $y1 = min($y1, $y);
            $x2 = max($x2, $x + (float) ($it['w'] ?? 0));
            $y2 = max($y2, $y + (float) ($it['h'] ?? 0));
        }
        foreach ($layout['ink'] ?? [] as $st) {
            if (! is_array($st)) {
                continue;
            }
            $r = ((float) ($st['width'] ?? 0)) / 2;
            $pts = is_array($st['points'] ?? null) ? $st['points'] : [];
            $cnt = count($pts);
            for ($i = 0; $i + 1 < $cnt; $i += 2) {
                $x = (float) $pts[$i];
                $y = (float) $pts[$i + 1];
                $x1 = min($x1, $x - $r);
                $y1 = min($y1, $y - $r);
                $x2 = max($x2, $x + $r);
                $y2 = max($y2, $y + $r);
            }
        }
        if (! is_finite($x1) || ! is_finite($y1)) {
            return ['w' => 0, 'h' => 0];
        }

        return ['w' => max(1, (int) ceil($x2 - $x1)), 'h' => max(1, (int) ceil($y2 - $y1))];
    }

    private function summary(MistakeNote $n): array
    {
        $problem = $n->images->firstWhere('role', 'problem');
        $answer = $n->images->firstWhere('role', 'answer');
        $field = implode(' ／ ', array_filter([$n->major?->name, $n->mid?->name], fn ($v) => $v !== null && $v !== ''));
        $size = $this->contentSize($n->layout);
        $other = $n->subject_id === null;

        return [
            'contentW' => $size['w'],
            'contentH' => $size['h'],
            'id' => $n->id,
            // 科目「その他」は subjectId = -1・名前「その他」・固定の色
            'subjectId' => $other ? self::OTHER_SUBJECT : $n->subject_id,
            'subjectName' => $other ? 'その他' : $n->subject?->name,
            'colorSoft' => $other ? '#6b7280' : $n->subject?->color_soft,
            'colorVivid' => $other ? '#4b5563' : $n->subject?->color_vivid,
            'deckId' => $n->mistake_deck_id,
            'deckName' => $n->deck?->name,
            'deckColor' => $n->deck?->color,
            'majorId' => $n->major_category_id,
            'majorName' => $n->major?->name,
            'midId' => $n->mid_category_id,
            'midName' => $n->mid?->name,
            'studyItemId' => $n->study_item_id,
            'studyItemName' => $n->item?->name,
            'field' => $field !== '' ? $field : null,
            'title' => $n->title,
            'resourceBookId' => $n->resource_book_id,
            // 出典の表示: 教材が選ばれていればその名前、なければ旧来の自由入力
            'source' => $n->book?->title ?? $n->source,
            'notedOn' => $n->noted_on->toDateString(),
            'hidden' => (bool) $n->hidden,
            'sortOrder' => (int) $n->sort_order,
            'problemImageId' => $problem?->id,
            'problemThumbUrl' => $problem ? '/api/mistake-note-images/'.$problem->id.'?v=thumb' : null,
            'answerImageId' => $answer?->id,
            'imageCount' => $n->images->count(),
            'review' => $this->reviewPayload($n),
            'updatedAt' => $n->updated_at?->toDateTimeString(),
        ];
    }

    /** 復習（間隔反復）の状態。isNew = 未学習、isDue = 出題の期限が来ている。preview = 各評価を選んだときの次回までの間隔 */
    private function reviewPayload(MistakeNote $n): array
    {
        $now = now();
        $due = $n->review_due_at;

        return [
            'interval' => (float) $n->review_interval,
            'ease' => (float) ($n->review_ease ?: 2.5),
            'reps' => (int) $n->review_reps,
            'lapses' => (int) $n->review_lapses,
            'dueAt' => $due?->toIso8601String(),
            'lastAt' => $n->review_last_at?->toIso8601String(),
            'lastGrade' => $n->review_last_grade,
            'isNew' => $due === null,
            'isDue' => $due !== null && $due->lte($now),
            'preview' => MistakeReviewScheduler::preview($n, $now),
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
