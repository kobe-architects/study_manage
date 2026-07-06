<?php

namespace App\Http\Controllers;

use App\Models\MajorCategory;
use App\Models\MidCategory;
use App\Models\ResourceBook;
use App\Models\ResourceBookItem;
use App\Models\StudyItem;
use App\Models\StudyRecord;
use App\Models\Subject;
use App\Support\XlsxHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResourceBookController extends Controller
{
    public const TYPES = ['講義', '問題集', '教科書'];

    /** Excel 見出し（固定 10 列） */
    private array $excelHeader = ['章', '番号', 'Check', 'タイトル', '難易度', '科目名', '大分類', '中分類', '小分類', '種別'];

    // ====================== 教材 CRUD ======================

    /** 種別ごとの教材一覧（各教材の行数 / 達成行数の集計付き） */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $type = $request->query('type');

        $books = ResourceBook::query()
            ->with('subject')
            ->where('user_id', $userId)
            ->when(in_array($type, self::TYPES, true), fn ($q) => $q->where('type', $type))
            ->orderBy('type')
            ->orderByDesc('pinned')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // 教材ごとの 総行数 / 1件以上記録のある行数
        $summary = DB::table('resource_book_items as rbi')
            ->leftJoin('study_records as sr', 'sr.resource_book_item_id', '=', 'rbi.id')
            ->whereIn('rbi.resource_book_id', $books->pluck('id'))
            ->groupBy('rbi.resource_book_id')
            ->selectRaw('rbi.resource_book_id as book_id, COUNT(DISTINCT rbi.id) as total_rows, COUNT(DISTINCT CASE WHEN sr.id IS NOT NULL THEN rbi.id END) as done_rows, COUNT(DISTINCT CASE WHEN rbi.included = 1 AND rbi.study_item_id IS NOT NULL THEN rbi.id END) as target_rows')
            ->get()
            ->keyBy('book_id');

        $data = $books->map(function (ResourceBook $b) use ($summary) {
            $s = $summary->get($b->id);

            return [
                'id' => $b->id,
                'type' => $b->type,
                'title' => $b->title,
                'subjectId' => $b->subject_id,
                'subjectName' => $b->subject?->name,
                'colorSoft' => $b->subject?->color_soft ?? '#475569',
                'colorVivid' => $b->subject?->color_vivid ?? '#475569',
                'imageUrl' => $b->image_path ? url('/api/resource-books/'.$b->id.'/image').'?v='.$b->updated_at?->timestamp : null,
                'sortOrder' => $b->sort_order,
                'pinned' => (bool) $b->pinned,
                'totalRows' => (int) ($s->total_rows ?? 0),
                'doneRows' => (int) ($s->done_rows ?? 0),
                'targetRows' => (int) ($s->target_rows ?? 0),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'type' => ['required', 'in:講義,問題集,教科書'],
            'title' => ['required', 'string', 'max:255'],
            'subjectId' => ['nullable', 'integer'],
        ]);

        $subjectId = $this->resolveSubjectId($userId, $data['subjectId'] ?? null);
        $sortOrder = (int) ResourceBook::where('user_id', $userId)->where('type', $data['type'])->max('sort_order') + 1;

        $book = ResourceBook::create([
            'user_id' => $userId,
            'type' => $data['type'],
            'title' => $data['title'],
            'subject_id' => $subjectId,
            'sort_order' => $sortOrder,
        ]);

        return response()->json(['data' => ['id' => $book->id]], 201);
    }

    public function update(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'subjectId' => ['nullable', 'integer'],
            'pinned' => ['sometimes', 'boolean'],
        ]);

        $payload = [];
        if (array_key_exists('title', $data)) {
            $payload['title'] = $data['title'];
        }
        if (array_key_exists('subjectId', $data)) {
            $payload['subject_id'] = $this->resolveSubjectId($request->user()->id, $data['subjectId']);
        }
        if (array_key_exists('pinned', $data)) {
            $payload['pinned'] = $data['pinned'];
        }
        $resourceBook->update($payload);

        return response()->json(['data' => ['id' => $resourceBook->id]]);
    }

    /** 教材カードの並び替え（同一ユーザーの id 配列を受け取り、その順で sort_order を振り直す） */
    public function reorder(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        // 自分の教材だけに限定（他人の id が混ざっても無視）
        $owned = ResourceBook::where('user_id', $userId)
            ->whereIn('id', $data['ids'])
            ->pluck('id')
            ->all();
        $ownedSet = array_flip($owned);

        DB::transaction(function () use ($data, $ownedSet) {
            $order = 0;
            foreach ($data['ids'] as $id) {
                if (! isset($ownedSet[$id])) {
                    continue;
                }
                ResourceBook::where('id', $id)->update(['sort_order' => $order++]);
            }
        });

        return response()->json(['message' => 'reordered']);
    }

    public function destroy(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);
        $resourceBook->delete(); // booted() で画像も削除

        return response()->json(['message' => 'deleted']);
    }

    // ====================== タイトル画像 ======================

    public function showImage(Request $request, ResourceBook $resourceBook): StreamedResponse
    {
        $this->authorizeBook($request, $resourceBook);
        abort_if(! $resourceBook->image_path || ! Storage::disk('public')->exists($resourceBook->image_path), 404);

        return Storage::disk('public')->response($resourceBook->image_path);
    }

    public function uploadImage(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);
        $request->validate(['image' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:5120']]);

        if ($resourceBook->image_path && Storage::disk('public')->exists($resourceBook->image_path)) {
            Storage::disk('public')->delete($resourceBook->image_path);
        }
        $path = $request->file('image')->store('resource-books', 'public');
        $resourceBook->update(['image_path' => $path]);

        return response()->json(['data' => ['imageUrl' => url('/api/resource-books/'.$resourceBook->id.'/image')]]);
    }

    public function deleteImage(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);
        if ($resourceBook->image_path && Storage::disk('public')->exists($resourceBook->image_path)) {
            Storage::disk('public')->delete($resourceBook->image_path);
        }
        $resourceBook->update(['image_path' => null]);

        return response()->json(['message' => 'image deleted']);
    }

    // ====================== 行（resource_book_items） ======================

    /** 教材配下の行一覧（小分類パス + 自ユーザーの学習記録集計付き） */
    public function rowsIndex(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);
        $userId = $request->user()->id;

        $rows = $resourceBook->items()->with('studyItem.mid.major.subject')->get();

        // 行ごとの学習記録（全学習日を昇順で保持）
        $records = StudyRecord::query()
            ->where('user_id', $userId)
            ->whereIn('resource_book_item_id', $rows->pluck('id'))
            ->orderBy('studied_on')
            ->orderBy('id')
            ->get(['resource_book_item_id', 'studied_on'])
            ->groupBy('resource_book_item_id');

        $data = $rows->map(function (ResourceBookItem $r) use ($records) {
            $item = $r->studyItem;
            $subject = $item?->mid?->major?->subject;
            $dates = ($records->get($r->id) ?? collect())
                ->map(fn ($x) => $x->studied_on->toDateString())
                ->all();

            return [
                'id' => $r->id,
                'bookId' => $r->resource_book_id,
                'chapter' => $r->chapter,
                'seqNo' => $r->seq_no,
                'checkFlag' => $r->check_flag,
                'title' => $r->title,
                'difficulty' => $r->difficulty,
                'meta' => $r->meta ?: null,
                'studyItemId' => $r->study_item_id,
                'included' => (bool) $r->included,
                'important' => (bool) $r->important,
                'subjectName' => $subject?->name,
                'colorSoft' => $subject?->color_soft ?? '#475569',
                'colorVivid' => $subject?->color_vivid ?? '#475569',
                'major' => $item?->mid?->major?->name,
                'mid' => $item?->mid?->name,
                'sub' => $item?->name,
                'sortOrder' => $r->sort_order,
                'recordCount' => count($dates),
                'lastDate' => $dates ? end($dates) : null,
                'dates' => $dates,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function rowStore(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);
        $userId = $request->user()->id;
        $data = $this->validateRow($request);

        $studyItemId = $this->resolveStudyItem(
            $userId, $data['subject'] ?? '', $data['major'] ?? '',
            $data['mid'] ?? '', $data['sub'] ?? '', $cache, true
        );

        $sortOrder = (int) $resourceBook->items()->max('sort_order') + 1;
        $row = $resourceBook->items()->create([
            'study_item_id' => $studyItemId,
            'chapter' => $data['chapter'] ?? null,
            'seq_no' => $data['seqNo'] ?? null,
            'check_flag' => $data['checkFlag'] ?? null,
            'title' => $data['title'] ?? null,
            'difficulty' => $data['difficulty'] ?? null,
            'sort_order' => $sortOrder,
        ]);

        return response()->json(['data' => ['id' => $row->id]], 201);
    }

    public function rowUpdate(Request $request, ResourceBookItem $row): JsonResponse
    {
        $this->authorizeRow($request, $row);
        $userId = $request->user()->id;
        $data = $this->validateRow($request);

        $payload = [];
        foreach (['chapter' => 'chapter', 'seqNo' => 'seq_no', 'checkFlag' => 'check_flag', 'title' => 'title', 'difficulty' => 'difficulty'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $payload[$col] = $data[$in];
            }
        }
        // 進捗対象フラグ
        if (array_key_exists('included', $data)) {
            $payload['included'] = $data['included'];
        }
        // 重要フラグ
        if (array_key_exists('important', $data)) {
            $payload['important'] = $data['important'];
        }
        // 紐づけ先: studyItemId 直接指定（null で解除）または 小分類パスで再解決
        if (array_key_exists('studyItemId', $data)) {
            $payload['study_item_id'] = $data['studyItemId']
                ? $this->ownStudyItemId($userId, (int) $data['studyItemId'])
                : null;
        } elseif (isset($data['sub']) && $data['sub'] !== '') {
            $payload['study_item_id'] = $this->resolveStudyItem(
                $userId, $data['subject'] ?? '', $data['major'] ?? '',
                $data['mid'] ?? '', $data['sub'], $cache, true
            );
        }
        $row->update($payload);

        return response()->json(['data' => ['id' => $row->id]]);
    }

    /**
     * 行の進捗対象(included)の一括更新。進捗対象の設定ツリーのチェック操作で使用。
     */
    public function updateIncluded(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'included' => ['required', 'boolean'],
        ]);

        $resourceBook->items()->whereIn('id', $data['ids'])->update(['included' => $data['included']]);

        return response()->json(['message' => 'updated']);
    }

    public function rowDestroy(Request $request, ResourceBookItem $row): JsonResponse
    {
        $this->authorizeRow($request, $row);
        $row->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** 行に紐づく学習記録の一覧（削除UI用、studied_on 降順） */
    public function rowRecords(Request $request, ResourceBookItem $row): JsonResponse
    {
        $this->authorizeRow($request, $row);

        $records = StudyRecord::query()
            ->where('user_id', $request->user()->id)
            ->where('resource_book_item_id', $row->id)
            ->orderByDesc('studied_on')
            ->orderByDesc('id')
            ->get(['id', 'studied_on'])
            ->map(fn ($r) => ['id' => $r->id, 'studiedOn' => $r->studied_on->toDateString()]);

        return response()->json(['data' => $records]);
    }

    /** 行に対する学習記録の登録（1行=1問/1回） */
    public function recordRow(Request $request, ResourceBookItem $row): JsonResponse
    {
        $this->authorizeRow($request, $row);
        $data = $request->validate(['studiedOn' => ['required', 'date']]);

        abort_if($row->study_item_id === null, 422, 'この行は学習項目に紐づいていません。');

        $record = StudyRecord::create([
            'user_id' => $request->user()->id,
            'study_item_id' => $row->study_item_id,
            'resource_book_item_id' => $row->id,
            'type' => $row->book->type,
            'studied_on' => $data['studiedOn'],
        ]);

        return response()->json(['data' => ['id' => $record->id]], 201);
    }

    /**
     * 講義教材に関連する問題（=同じ小分類に紐づく「問題集」教材の行）を一覧で返す。
     * 印刷/画面出力用。講義以外の教材でも動作するが、UIでは講義でのみ提供する。
     */
    public function relatedProblems(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);
        $userId = $request->user()->id;

        // この教材(講義)が紐づく小分類の集合
        $itemIds = $resourceBook->items()
            ->whereNotNull('study_item_id')
            ->pluck('study_item_id')
            ->unique()
            ->values();

        if ($itemIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        // 同じ小分類に紐づく、自ユーザーの「問題集」教材の行
        $rows = ResourceBookItem::query()
            ->whereIn('study_item_id', $itemIds)
            ->whereHas('book', fn ($q) => $q->where('user_id', $userId)->where('type', '問題集'))
            ->with(['book:id,title,type', 'studyItem.mid.major.subject'])
            ->get();

        // 行ごとの学習日
        $records = StudyRecord::query()
            ->where('user_id', $userId)
            ->whereIn('resource_book_item_id', $rows->pluck('id'))
            ->orderBy('studied_on')
            ->orderBy('id')
            ->get(['resource_book_item_id', 'studied_on'])
            ->groupBy('resource_book_item_id');

        $data = $rows->map(function (ResourceBookItem $r) use ($records) {
            $item = $r->studyItem;
            $subject = $item?->mid?->major?->subject;
            $dates = ($records->get($r->id) ?? collect())
                ->map(fn ($x) => $x->studied_on->toDateString())
                ->all();

            return [
                'id' => $r->id,
                'bookTitle' => $r->book?->title,
                'chapter' => $r->chapter,
                'seqNo' => $r->seq_no,
                'checkFlag' => $r->check_flag,
                'think' => $r->meta['Think'] ?? null,
                'title' => $r->title,
                'difficulty' => $r->difficulty,
                'important' => (bool) $r->important,
                'subjectName' => $subject?->name,
                'colorVivid' => $subject?->color_vivid ?? '#475569',
                'major' => $item?->mid?->major?->name,
                'mid' => $item?->mid?->name,
                'sub' => $item?->name,
                'recordCount' => count($dates),
                'dates' => $dates,
            ];
        })
            // 出典（問題集タイトル）→ 小分類 → 章 → 番号 の順で安定ソート
            ->sortBy(fn ($x) => sprintf(
                '%s|%s|%s|%s',
                $x['bookTitle'] ?? '',
                $x['sub'] ?? '',
                $x['chapter'] ?? '',
                str_pad($x['seqNo'] ?? '', 6, '0', STR_PAD_LEFT)
            ))
            ->values();

        return response()->json(['data' => $data]);
    }

    // ====================== Excel 入出力 ======================

    public function template(Request $request): StreamedResponse
    {
        $type = $request->query('type', '問題集');
        $rows = [
            ['第1章 数と式', '1', '◯', '整式の整理', '*', '数学', '数学I', '数と式', '整式の計算・因数分解', in_array($type, self::TYPES, true) ? $type : '問題集'],
        ];

        return $this->streamXlsx('resource_template.xlsx', $rows);
    }

    public function export(Request $request, ResourceBook $resourceBook): StreamedResponse
    {
        $this->authorizeBook($request, $resourceBook);

        $items = $resourceBook->items()->with('studyItem.mid.major.subject')->get();

        // 教材ごとに異なる追加項目(meta)を出現順に集約して列に追加
        $metaKeys = [];
        foreach ($items as $r) {
            foreach (($r->meta ?? []) as $k => $v) {
                if (! in_array($k, $metaKeys, true)) {
                    $metaKeys[] = $k;
                }
            }
        }

        $rows = $items->map(function (ResourceBookItem $r) use ($resourceBook, $metaKeys) {
            $item = $r->studyItem;
            $base = [
                $r->chapter,
                $r->seq_no,
                $r->check_flag,
                $r->title,
                $r->difficulty,
                $item?->mid?->major?->subject?->name,
                $item?->mid?->major?->name,
                $item?->mid?->name,
                $item?->name,
                $resourceBook->type,
            ];
            foreach ($metaKeys as $k) {
                $base[] = $r->meta[$k] ?? null;
            }

            return $base;
        })->all();

        return $this->streamXlsx('resource_export.xlsx', $rows, array_merge($this->excelHeader, $metaKeys));
    }

    public function import(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);
        $request->validate(['file' => ['required', 'file']]);
        $userId = $request->user()->id;

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($ext, ['xlsx', 'xls', 'csv', 'txt'], true), 422, '対応していないファイル形式です。');

        $rows = in_array($ext, ['xlsx', 'xls'], true)
            ? XlsxHelper::read(file_get_contents($file->getRealPath()))
            : $this->readCsv($file->getRealPath());

        if (empty($rows)) {
            return response()->json(['data' => ['imported' => 0, 'skipped' => 0]]);
        }

        $header = $rows[0] ?? [];
        $map = $this->resolveColumnMap($rows[0]);
        $hasHeader = $map !== null;
        if ($hasHeader) {
            array_shift($rows);
        } else {
            $map = $this->defaultColumnMap();
        }

        // 既知列以外のヘッダ列は meta（教材ごとに異なる追加項目）として取り込む
        $extraCols = [];
        if ($hasHeader) {
            $used = array_values($map);
            foreach ($header as $idx => $name) {
                $name = trim((string) $name);
                if ($name !== '' && ! in_array($idx, $used, true)) {
                    $extraCols[$idx] = $name;
                }
            }
        }

        $get = fn (array $cols, string $key) => isset($map[$key]) ? trim((string) ($cols[$map[$key]] ?? '')) : '';

        $imported = 0;
        $skipped = 0;
        $cache = [];
        $nextSort = (int) $resourceBook->items()->max('sort_order');

        DB::transaction(function () use ($rows, $get, $extraCols, $resourceBook, $userId, &$cache, &$nextSort, &$imported, &$skipped) {
            foreach ($rows as $cols) {
                if (! is_array($cols)) {
                    continue;
                }
                $title = $get($cols, 'title');
                $sub = $get($cols, 'sub');
                if ($title === '' && $sub === '') {
                    $skipped++;

                    continue;
                }

                $studyItemId = $this->resolveStudyItem(
                    $userId, $get($cols, 'subject'), $get($cols, 'major'),
                    $get($cols, 'mid'), $sub, $cache, true
                );

                $meta = [];
                foreach ($extraCols as $idx => $name) {
                    $v = trim((string) ($cols[$idx] ?? ''));
                    if ($v !== '') {
                        $meta[$name] = $v;
                    }
                }

                $resourceBook->items()->create([
                    'study_item_id' => $studyItemId,
                    'chapter' => $get($cols, 'chapter') ?: null,
                    'seq_no' => $get($cols, 'seqNo') ?: null,
                    'check_flag' => $get($cols, 'check') ?: null,
                    'title' => $title ?: null,
                    'difficulty' => $get($cols, 'difficulty') ?: null,
                    'meta' => $meta ?: null,
                    'sort_order' => ++$nextSort,
                ]);
                $imported++;
            }
        });

        return response()->json(['data' => ['imported' => $imported, 'skipped' => $skipped]]);
    }

    // ====================== 学習項目の解決 ======================

    /**
     * 科目→大分類→中分類→小分類 を名前で辿り study_item_id を返す。無ければ自動生成。
     * $cache は呼び出し間でキー "科目|大|中|小" の解決結果を保持する。
     */
    private function resolveStudyItem(int $userId, string $subject, string $major, string $mid, string $sub, ?array &$cache, bool $autoCreate): ?int
    {
        $cache ??= [];
        $subject = trim($subject);
        $major = trim($major);
        $mid = trim($mid);
        $sub = trim($sub);
        if ($sub === '') {
            return null;
        }
        $key = "$subject|$major|$mid|$sub";
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $subjectModel = Subject::where('user_id', $userId)->where('name', $subject)->first();
        if (! $subjectModel) {
            if (! $autoCreate || $subject === '') {
                return $cache[$key] = null;
            }
            $subjectModel = Subject::create([
                'user_id' => $userId, 'code' => 'imp_'.substr(md5($subject), 0, 8),
                'name' => $subject, 'group_name' => $subject,
                'sort_order' => (int) Subject::where('user_id', $userId)->max('sort_order') + 1,
            ]);
        }

        $majorModel = MajorCategory::where('subject_id', $subjectModel->id)->where('name', $major)->first();
        if (! $majorModel) {
            if (! $autoCreate) {
                return $cache[$key] = null;
            }
            $majorModel = MajorCategory::create([
                'subject_id' => $subjectModel->id, 'name' => $major !== '' ? $major : '未分類',
                'sort_order' => (int) MajorCategory::where('subject_id', $subjectModel->id)->max('sort_order') + 1,
            ]);
        }

        $midModel = MidCategory::where('major_category_id', $majorModel->id)->where('name', $mid)->first();
        if (! $midModel) {
            if (! $autoCreate) {
                return $cache[$key] = null;
            }
            $midModel = MidCategory::create([
                'major_category_id' => $majorModel->id, 'name' => $mid !== '' ? $mid : '未分類',
                'sort_order' => (int) MidCategory::where('major_category_id', $majorModel->id)->max('sort_order') + 1,
            ]);
        }

        $itemModel = StudyItem::where('mid_category_id', $midModel->id)->where('name', $sub)->first();
        if (! $itemModel) {
            $itemModel = StudyItem::create([
                'mid_category_id' => $midModel->id, 'name' => $sub, 'included' => true,
                'sort_order' => (int) StudyItem::where('mid_category_id', $midModel->id)->max('sort_order') + 1,
            ]);
        }

        return $cache[$key] = $itemModel->id;
    }

    private function resolveSubjectId(int $userId, ?int $subjectId): ?int
    {
        if (! $subjectId) {
            return null;
        }

        return Subject::where('user_id', $userId)->where('id', $subjectId)->value('id');
    }

    // ====================== 列マップ・CSV・XLSX ======================

    private const HEADER_ALIASES = [
        'chapter' => ['章', 'chapter'],
        'seqNo' => ['番号', 'no', 'seq', 'seq_no'],
        'check' => ['check', 'チェック'],
        'title' => ['タイトル', 'title'],
        'difficulty' => ['難易度', 'difficulty'],
        'subject' => ['科目名', '科目', 'subject'],
        'major' => ['大分類', 'major'],
        'mid' => ['中分類', 'mid'],
        'sub' => ['小分類', 'sub'],
        'type' => ['種別', 'type'],
    ];

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int>|null
     */
    private function resolveColumnMap(array $headerRow): ?array
    {
        $normalized = array_map(fn ($v) => strtolower(trim((string) $v)), $headerRow);
        $map = [];
        foreach (self::HEADER_ALIASES as $key => $aliases) {
            foreach ($aliases as $alias) {
                $idx = array_search(strtolower($alias), $normalized, true);
                if ($idx !== false) {
                    $map[$key] = (int) $idx;
                    break;
                }
            }
        }

        // 小分類とタイトルが取れていればヘッダ行とみなす
        return isset($map['sub'], $map['title']) ? $map : null;
    }

    /**
     * @return array<string, int>
     */
    private function defaultColumnMap(): array
    {
        return [
            'chapter' => 0, 'seqNo' => 1, 'check' => 2, 'title' => 3, 'difficulty' => 4,
            'subject' => 5, 'major' => 6, 'mid' => 7, 'sub' => 8, 'type' => 9,
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        $first = true;
        while (($cols = fgetcsv($handle)) !== false) {
            if ($first) {
                $first = false;
                if (isset($cols[0])) {
                    $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cols[0]);
                }
            }
            $rows[] = $cols;
        }
        fclose($handle);

        return $rows;
    }

    private function streamXlsx(string $filename, array $rows, ?array $header = null): StreamedResponse
    {
        $binary = XlsxHelper::write($header ?? $this->excelHeader, $rows);

        return response()->streamDownload(function () use ($binary) {
            echo $binary;
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ====================== バリデーション・認可 ======================

    private function validateRow(Request $request): array
    {
        return $request->validate([
            'chapter' => ['nullable', 'string', 'max:255'],
            'seqNo' => ['nullable', 'string', 'max:50'],
            'checkFlag' => ['nullable', 'string', 'max:20'],
            'title' => ['nullable', 'string', 'max:500'],
            'difficulty' => ['nullable', 'string', 'max:20'],
            'subject' => ['nullable', 'string', 'max:100'],
            'major' => ['nullable', 'string', 'max:150'],
            'mid' => ['nullable', 'string', 'max:150'],
            'sub' => ['nullable', 'string', 'max:255'],
            'included' => ['sometimes', 'boolean'],
            'important' => ['sometimes', 'boolean'],
            'studyItemId' => ['sometimes', 'nullable', 'integer'],
        ]);
    }

    /** 自ユーザー配下の study_item_id を検証して返す（他人のIDは弾く） */
    private function ownStudyItemId(int $userId, int $studyItemId): ?int
    {
        return StudyItem::whereHas('mid.major.subject', fn ($q) => $q->where('user_id', $userId))
            ->where('id', $studyItemId)
            ->value('id');
    }

    private function authorizeBook(Request $request, ResourceBook $book): void
    {
        abort_unless($book->user_id === $request->user()->id, 403);
    }

    private function authorizeRow(Request $request, ResourceBookItem $row): void
    {
        abort_unless($row->book->user_id === $request->user()->id, 403);
    }
}
