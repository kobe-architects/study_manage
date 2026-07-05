<?php

namespace App\Http\Controllers;

use App\Http\Resources\VocabularyResource;
use App\Models\StudyResource;
use App\Models\StudyResourceSection;
use App\Models\Vocabulary;
use App\Support\XlsxHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VocabularyController extends Controller
{
    /** 教材配下の単語一覧（自ユーザーの learningStat 付き、sort_order 順） */
    public function indexByResource(Request $request, StudyResource $studyResource): JsonResponse
    {
        $this->authorizeResource($request, $studyResource);
        $userId = $request->user()->id;

        $vocab = Vocabulary::query()
            ->whereHas('section', fn ($q) => $q->where('study_resource_id', $studyResource->id))
            ->with(['userStat' => fn ($q) => $q->where('user_id', $userId)])
            ->orderBy('study_resource_section_id')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => VocabularyResource::collection($vocab)]);
    }

    public function store(Request $request, StudyResourceSection $section): JsonResponse
    {
        $this->authorizeSection($request, $section);
        $data = $this->validateVocabulary($request);

        if (! isset($data['sort_order'])) {
            $data['sort_order'] = (int) $section->vocabularies()->max('sort_order') + 1;
        }
        $data['study_resource_section_id'] = $section->id;

        $vocab = Vocabulary::create($data);

        return response()->json(['data' => new VocabularyResource($vocab)], 201);
    }

    public function update(Request $request, Vocabulary $vocabulary): JsonResponse
    {
        $this->authorizeVocab($request, $vocabulary);
        $data = $this->validateVocabulary($request, false);
        $vocabulary->update($data);

        return response()->json(['data' => new VocabularyResource($vocabulary->fresh())]);
    }

    public function destroy(Request $request, Vocabulary $vocabulary): JsonResponse
    {
        $this->authorizeVocab($request, $vocabulary);
        $vocabulary->delete(); // booted() で画像も物理削除

        return response()->json(['message' => 'deleted']);
    }

    public function destroyAll(Request $request, StudyResource $studyResource): JsonResponse
    {
        $this->authorizeResource($request, $studyResource);

        Vocabulary::whereHas('section', fn ($q) => $q->where('study_resource_id', $studyResource->id))
            ->get()
            ->each
            ->delete();

        return response()->json(['message' => 'all deleted']);
    }

    // ---------- 画像 ----------

    public function showImage(Request $request, Vocabulary $vocabulary): StreamedResponse
    {
        $this->authorizeVocab($request, $vocabulary);
        abort_if(! $vocabulary->image_path || ! Storage::disk('public')->exists($vocabulary->image_path), 404);

        return Storage::disk('public')->response($vocabulary->image_path);
    }

    public function uploadImage(Request $request, Vocabulary $vocabulary): JsonResponse
    {
        $this->authorizeVocab($request, $vocabulary);
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:5120'],
        ]);

        if ($vocabulary->image_path && Storage::disk('public')->exists($vocabulary->image_path)) {
            Storage::disk('public')->delete($vocabulary->image_path);
        }

        $path = $request->file('image')->store('vocabulary', 'public');
        $vocabulary->update(['image_path' => $path]);

        return response()->json(['data' => new VocabularyResource($vocabulary->fresh())]);
    }

    public function deleteImage(Request $request, Vocabulary $vocabulary): JsonResponse
    {
        $this->authorizeVocab($request, $vocabulary);
        if ($vocabulary->image_path && Storage::disk('public')->exists($vocabulary->image_path)) {
            Storage::disk('public')->delete($vocabulary->image_path);
        }
        $vocabulary->update(['image_path' => null]);

        return response()->json(['message' => 'image deleted']);
    }

    // ---------- Excel 入出力 ----------

    /** エクスポート/テンプレートの見出し（日本語・12列） */
    private array $excelHeader = [
        'セクション', '単語', '意味', '意味の補足', '品詞', '重要度',
        'ラベル', '習熟度', 'メモ', '例文', '和訳', '例文説明',
    ];

    public function template(): StreamedResponse
    {
        $rows = [
            ['Unit 1', 'example', '例', '「名詞」としての意味の補足', '名詞', '★', '普', '低', '覚え方メモ', 'This is an example.', 'これは例文です。', '補足説明'],
        ];

        return $this->streamXlsx('vocabulary_template.xlsx', $rows);
    }

    public function export(Request $request, StudyResource $studyResource): StreamedResponse
    {
        $this->authorizeResource($request, $studyResource);

        $rows = Vocabulary::query()
            ->whereHas('section', fn ($q) => $q->where('study_resource_id', $studyResource->id))
            ->with('section')
            ->orderBy('study_resource_section_id')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Vocabulary $v) => [
                $v->section->name,
                $v->word,
                $v->meaning,
                $v->meaning_supplement,
                $v->part_of_speech,
                $this->importanceToLabel((int) $v->importance),
                $this->labelToJp($v->label),
                $this->profToJp($v->proficiency),
                $v->memo,
                $v->example_sentence,
                $v->example_translation,
                $v->example_explanation,
            ])->all();

        return $this->streamXlsx('vocabulary_export.xlsx', $rows);
    }

    public function import(Request $request, StudyResource $studyResource): JsonResponse
    {
        $this->authorizeResource($request, $studyResource);
        $request->validate(['file' => ['required', 'file']]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($ext, ['xlsx', 'xls', 'csv', 'txt'], true), 422, '対応していないファイル形式です。');

        $rows = in_array($ext, ['xlsx', 'xls'], true)
            ? XlsxHelper::read(file_get_contents($file->getRealPath()))
            : $this->readCsv($file->getRealPath());

        if (empty($rows)) {
            return response()->json(['data' => ['imported' => 0, 'skipped' => 0]]);
        }

        // 1 行目がヘッダなら列マップを構築（無ければ既定の固定順）
        $map = $this->resolveColumnMap($rows[0]);
        if ($map !== null) {
            array_shift($rows); // ヘッダ行を除去
        } else {
            $map = $this->defaultColumnMap();
        }

        $imported = 0;
        $skipped = 0;
        $sections = $studyResource->sections()->get()->keyBy('name');
        $sectionMaxSort = (int) $studyResource->sections()->max('sort_order');
        $nextSort = []; // section_id => 次の sort_order

        $get = fn (array $cols, string $key) => isset($map[$key]) ? trim((string) ($cols[$map[$key]] ?? '')) : '';

        DB::transaction(function () use ($rows, $get, $studyResource, &$sections, &$sectionMaxSort, &$nextSort, &$imported, &$skipped) {
            foreach ($rows as $cols) {
                if (! is_array($cols)) {
                    continue;
                }
                $word = $get($cols, 'word');
                $meaning = $get($cols, 'meaning');
                if ($word === '' || $meaning === '') {
                    $skipped++;

                    continue;
                }

                $sectionName = $get($cols, 'section');
                if ($sectionName === '') {
                    $sectionName = '未分類';
                }
                if (! isset($sections[$sectionName])) {
                    $sec = $studyResource->sections()->create([
                        'name' => $sectionName,
                        'sort_order' => ++$sectionMaxSort,
                    ]);
                    $sections[$sec->name] = $sec;
                }
                $section = $sections[$sectionName];

                // sort_order はセクションごとに連番をメモリで採番（クエリを毎回打たない）
                if (! isset($nextSort[$section->id])) {
                    $nextSort[$section->id] = (int) $section->vocabularies()->max('sort_order');
                }
                $nextSort[$section->id]++;

                Vocabulary::create([
                    'study_resource_section_id' => $section->id,
                    'word' => $word,
                    'meaning' => $meaning,
                    'meaning_supplement' => $get($cols, 'meaning_supplement') ?: null,
                    'part_of_speech' => $get($cols, 'part_of_speech') ?: null,
                    'importance' => $this->parseImportance($get($cols, 'importance')),
                    'label' => $this->parseLabel($get($cols, 'label')),
                    'proficiency' => $this->parseProficiency($get($cols, 'proficiency')),
                    'memo' => $get($cols, 'memo') ?: null,
                    'example_sentence' => $get($cols, 'example_sentence') ?: null,
                    'example_translation' => $get($cols, 'example_translation') ?: null,
                    'example_explanation' => $get($cols, 'example_explanation') ?: null,
                    'sort_order' => $nextSort[$section->id],
                ]);
                $imported++;
            }
        });

        return response()->json(['data' => ['imported' => $imported, 'skipped' => $skipped]]);
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
                    $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cols[0]); // BOM 除去
                }
            }
            $rows[] = $cols;
        }
        fclose($handle);

        return $rows;
    }

    private function streamXlsx(string $filename, array $rows): StreamedResponse
    {
        $binary = XlsxHelper::write($this->excelHeader, $rows);

        return response()->streamDownload(function () use ($binary) {
            echo $binary;
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ---------- 列マップ・値変換 ----------

    /** 見出し語 → 内部キー（日本語/英語の両対応） */
    private const HEADER_ALIASES = [
        'section' => ['section', 'セクション'],
        'word' => ['word', '単語'],
        'meaning' => ['meaning', '意味'],
        'meaning_supplement' => ['meaning_supplement', '意味の補足', '意味補足'],
        'part_of_speech' => ['part_of_speech', '品詞'],
        'importance' => ['importance', '重要度'],
        'label' => ['label', 'ラベル'],
        'proficiency' => ['proficiency', '習熟度', '習熟'],
        'memo' => ['memo', 'メモ'],
        'example_sentence' => ['example_sentence', '例文'],
        'example_translation' => ['example_translation', '和訳', '例文和訳'],
        'example_explanation' => ['example_explanation', '例文説明', '説明'],
    ];

    /**
     * ヘッダ行から列マップ（内部キー => 列番号）を構築。ヘッダでなければ null。
     *
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

        // 「単語」「意味」が見出しとして取れていれば、それはヘッダ行とみなす
        return isset($map['word'], $map['meaning']) ? $map : null;
    }

    /**
     * @return array<string, int>
     */
    private function defaultColumnMap(): array
    {
        return [
            'section' => 0, 'word' => 1, 'meaning' => 2, 'meaning_supplement' => 3,
            'part_of_speech' => 4, 'importance' => 5, 'label' => 6, 'proficiency' => 7,
            'memo' => 8, 'example_sentence' => 9, 'example_translation' => 10, 'example_explanation' => 11,
        ];
    }

    private function parseImportance(string $v): int
    {
        $v = trim($v);
        if (in_array($v, ['2', '★★', '**'], true)) {
            return 2;
        }
        if (in_array($v, ['1', '★', '*'], true)) {
            return 1;
        }
        if (in_array($v, ['0', '無印', '-', '–', ''], true)) {
            return $v === '' ? 1 : 0;
        }

        return is_numeric($v) ? max(0, min(2, (int) $v)) : 1;
    }

    private function parseLabel(string $v): string
    {
        $v = trim($v);
        $jp = ['易' => 'easy', '普' => 'normal', '難' => 'hard'];

        if (in_array($v, ['easy', 'normal', 'hard'], true)) {
            return $v;
        }

        return $jp[$v] ?? 'normal';
    }

    private function parseProficiency(string $v): string
    {
        $v = trim($v);
        $jp = ['高' => 'high', '中' => 'medium', '低' => 'low'];

        if (in_array($v, ['high', 'medium', 'low'], true)) {
            return $v;
        }

        return $jp[$v] ?? 'low';
    }

    private function importanceToLabel(int $v): string
    {
        return [0 => '無印', 1 => '★', 2 => '★★'][$v] ?? '★';
    }

    private function labelToJp(?string $v): string
    {
        return ['easy' => '易', 'normal' => '普', 'hard' => '難'][$v] ?? '普';
    }

    private function profToJp(?string $v): string
    {
        return ['high' => '高', 'medium' => '中', 'low' => '低'][$v] ?? '低';
    }

    // ---------- バリデーション・認可 ----------

    private function validateVocabulary(Request $request, bool $creating = true): array
    {
        $rules = [
            'word' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'meaning' => [$creating ? 'required' : 'sometimes', 'string', 'max:500'],
            'meaningSupplement' => ['nullable', 'string'],
            'partOfSpeech' => ['nullable', 'string', 'max:50'],
            'importance' => ['nullable', 'integer', 'in:0,1,2'],
            'label' => ['nullable', 'in:easy,normal,hard'],
            'proficiency' => ['nullable', 'in:high,medium,low'],
            'memo' => ['nullable', 'string'],
            'exampleSentence' => ['nullable', 'string'],
            'exampleTranslation' => ['nullable', 'string'],
            'exampleExplanation' => ['nullable', 'string'],
            'sortOrder' => ['nullable', 'integer'],
        ];
        $data = $request->validate($rules);

        $map = [
            'word' => 'word', 'meaning' => 'meaning', 'meaningSupplement' => 'meaning_supplement',
            'partOfSpeech' => 'part_of_speech',
            'importance' => 'importance', 'label' => 'label', 'proficiency' => 'proficiency',
            'memo' => 'memo', 'exampleSentence' => 'example_sentence',
            'exampleTranslation' => 'example_translation', 'exampleExplanation' => 'example_explanation',
            'sortOrder' => 'sort_order',
        ];
        $out = [];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $data)) {
                $out[$col] = $data[$in];
            }
        }

        return $out;
    }

    private function authorizeResource(Request $request, StudyResource $resource): void
    {
        abort_unless($resource->user_id === $request->user()->id, 403);
    }

    private function authorizeSection(Request $request, StudyResourceSection $section): void
    {
        abort_unless($section->resource->user_id === $request->user()->id, 403);
    }

    private function authorizeVocab(Request $request, Vocabulary $vocab): void
    {
        abort_unless($vocab->section->resource->user_id === $request->user()->id, 403);
    }
}
