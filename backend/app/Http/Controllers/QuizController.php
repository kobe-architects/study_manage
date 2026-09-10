<?php

namespace App\Http\Controllers;

use App\Models\Quiz;
use App\Models\QuizPage;
use App\Models\ResourceBook;
use App\Models\ResourceBookItem;
use App\Models\ResourceBookPdf;
use App\Models\StudyRecord;
use App\Models\StudyResource;
use App\Support\ImageTools;
use App\Support\PdfTools;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * 小テスト。
 *   講師(tutor): 出題（教材の PDF ページ / 英単語テストを組み合わせて出題 PDF を生成）・添削・採点
 *   生徒(owner): 出題 PDF のダウンロード・回答写真の提出・結果閲覧
 *   両方: 一覧・詳細・分析
 * targetUserId により生徒スコープで動作する（公開ルートは routes/api.php で制限）。
 *
 * 英単語テストのページは、画面側（講師のブラウザ）で描画した問題用紙画像・解答用紙画像を
 * multipart（payload=JSON, renders[i], answerRenders[i]）で受け取り、出題 PDF に画像として組み込む。
 */
class QuizController extends Controller
{
    public const VOCAB_TEST_TYPES = ['meaning', 'spelling', 'fill_spelling'];

    public const VOCAB_TEST_FORMATS = ['free', 'choice'];

    // ====================== 出題用データ（講師） ======================

    /** 教材一覧（PDF 紐づけ数付き）。pdfCount > 0 の教材が出題可能 */
    public function books(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $books = ResourceBook::with('subject')
            ->withCount(['pdfs', 'items'])
            ->where('user_id', $userId)
            ->orderBy('type')
            ->orderByDesc('pinned')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $data = $books->map(fn (ResourceBook $b) => [
            'id' => $b->id,
            'type' => $b->type,
            'title' => $b->title,
            'subjectName' => $b->subject?->name,
            'colorVivid' => $b->subject?->color_vivid ?? '#475569',
            'pdfCount' => (int) $b->pdfs_count,
            'rowCount' => (int) $b->items_count,
        ]);

        return response()->json(['data' => $data]);
    }

    /** 出題ページ選択用の行一覧（学習状況・出題履歴・PDF ごとの対応ページ付き） */
    public function bookRows(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $userId = $this->targetUserId($request);
        abort_unless($resourceBook->user_id === $userId, 403);

        $pdfs = $resourceBook->pdfs()->get();
        $rows = $resourceBook->items()->get();
        $ids = $rows->pluck('id');

        $records = StudyRecord::where('user_id', $userId)
            ->whereIn('resource_book_item_id', $ids)
            ->selectRaw('resource_book_item_id, COUNT(*) as c, MAX(studied_on) as last_on')
            ->groupBy('resource_book_item_id')
            ->get()
            ->keyBy('resource_book_item_id');

        // 出題履歴（過去に出題した回数と最新の判定）
        $history = QuizPage::query()
            ->join('quizzes', 'quizzes.id', '=', 'quiz_pages.quiz_id')
            ->where('quizzes.user_id', $userId)
            ->whereIn('quiz_pages.resource_book_item_id', $ids)
            ->orderBy('quiz_pages.id')
            ->get(['quiz_pages.resource_book_item_id as item_id', 'quiz_pages.mark', 'quiz_pages.score'])
            ->groupBy('item_id');

        $data = $rows->map(function (ResourceBookItem $r) use ($pdfs, $records, $history) {
            $pages = [];
            foreach ($pdfs as $p) {
                $pg = $p->pageForSeq($r->seq_no);
                if ($pg !== null) {
                    $pages[(string) $p->id] = $pg;
                }
            }
            $rec = $records->get($r->id);
            $hist = $history->get($r->id);

            return [
                'id' => $r->id,
                'chapter' => $r->chapter,
                'seqNo' => $r->seq_no,
                'title' => $r->title,
                'difficulty' => $r->difficulty,
                'checkFlag' => $r->check_flag,
                'important' => (bool) $r->important,
                'recordCount' => (int) ($rec->c ?? 0),
                'lastDate' => $rec?->last_on,
                'quizCount' => $hist ? $hist->count() : 0,
                'lastMark' => $hist ? $hist->last()->mark : null,
                'pages' => (object) $pages,
            ];
        });

        return response()->json(['data' => $data]);
    }

    // ====================== 小テスト CRUD ======================

    public function index(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $quizzes = Quiz::with(['creator:id,name', 'book:id,title'])
            ->withCount(['pages', 'pages as answered_count' => fn ($q) => $q->whereNotNull('answer_path')])
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get();
        $scores = $this->scoreSums($quizzes->pluck('id')->all());
        $today = Carbon::today();

        return response()->json([
            'data' => $quizzes->map(fn (Quiz $q) => $this->summary($q, $scores->get($q->id), $today))->values(),
        ]);
    }

    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorizeQuiz($request, $quiz);
        $quiz->load(['creator:id,name', 'book:id,title', 'pages.pdf:id,title', 'pages.refPdf:id,title', 'pages.item']);
        $quiz->loadCount(['pages', 'pages as answered_count' => fn ($q) => $q->whereNotNull('answer_path')]);

        $data = $this->summary($quiz, $this->scoreSums([$quiz->id])->get($quiz->id), Carbon::today());
        // 解答（英単語テスト）は講師、または添削済みの場合のみ返す
        $withAnswers = $request->user()->isTutor() || $quiz->status === Quiz::STATUS_GRADED;
        $data['pages'] = $quiz->pages->map(fn (QuizPage $p) => $this->pagePayload($p, $quiz, $withAnswers))->values();

        return response()->json(['data' => $data]);
    }

    /** 出題（tutor）: ページ指定から小テストを作成し、出題 PDF を生成する */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $this->validatePayload($request, true);
        // 教材は任意（英単語テストのみの小テストは教材なし）
        $book = ! empty($data['bookId']) ? ResourceBook::where('user_id', $userId)->findOrFail($data['bookId']) : null;
        $pages = $this->normalizePages($data['pages'], $userId);
        $this->requireRenders($request, $pages);

        $quiz = DB::transaction(function () use ($request, $userId, $data, $book, $pages) {
            $quiz = Quiz::create([
                'user_id' => $userId,
                'created_by' => $request->user()->id,
                'group_key' => $data['groupKey'] ?? null,
                'resource_book_id' => $book?->id,
                'title' => $this->titleOf($data['title'] ?? null, $book),
                'note' => $data['note'] ?? null,
                'due_on' => $data['dueOn'] ?? null,
                'max_score_per_page' => $data['maxScore'] ?? 10,
            ]);
            $this->createPages($quiz, $pages, $request);

            return $quiz;
        });
        $this->generatePdf($quiz);

        return response()->json(['data' => ['id' => $quiz->id]], 201);
    }

    public function update(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorizeQuiz($request, $quiz);
        $data = $this->validatePayload($request, false);

        $payload = [];
        if (array_key_exists('title', $data)) {
            $payload['title'] = $this->titleOf($data['title'], $quiz->book);
        }
        if (array_key_exists('note', $data)) {
            $payload['note'] = $data['note'];
        }
        if (array_key_exists('dueOn', $data)) {
            $payload['due_on'] = $data['dueOn'];
        }
        if (! empty($data['maxScore'])) {
            $payload['max_score_per_page'] = $data['maxScore'];
        }
        if ($payload !== []) {
            $quiz->update($payload);
        }

        if (array_key_exists('pages', $data)) {
            abort_unless($quiz->status === Quiz::STATUS_ASSIGNED, 422, '提出後は出題ページを変更できません。');
            $pages = $this->normalizePages($data['pages'], $quiz->user_id);
            $this->requireRenders($request, $pages);
            DB::transaction(function () use ($quiz, $pages, $request) {
                Storage::disk('local')->deleteDirectory($quiz->dir().'/vocab');
                $quiz->pages()->delete();
                $this->createPages($quiz, $pages, $request);
            });
            $this->generatePdf($quiz);
        }

        return response()->json(['data' => ['id' => $quiz->id]]);
    }

    public function destroy(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorizeQuiz($request, $quiz);
        $quiz->delete(); // booted() でファイル一式も削除

        return response()->json(['message' => 'deleted']);
    }

    // ====================== ファイル ======================

    /** 出題 PDF（教材ページ + 英単語テスト）。講師は ?answers=1 で英単語テストの解答用紙を末尾に付けて取得できる */
    public function download(Request $request, Quiz $quiz): BinaryFileResponse
    {
        $this->authorizeQuiz($request, $quiz);
        abort_if($quiz->file_path === null, 404);

        if ($request->boolean('answers') && $request->user()->isTutor()) {
            $withAnswers = $this->buildPdfWithAnswers($quiz);
            if ($withAnswers !== null) {
                return response()->file($withAnswers, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $this->safeName($quiz->title).'_解答つき.pdf', 'quiz-'.$quiz->id.'-answers.pdf'),
                ]);
            }
        }

        $abs = Storage::disk('local')->path($quiz->file_path);
        abort_unless(is_file($abs), 404);

        return response()->file($abs, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $this->safeName($quiz->title).'.pdf', 'quiz-'.$quiz->id.'.pdf'),
        ]);
    }

    /**
     * 英単語テストの解答用紙を末尾に付けた出題 PDF を生成して絶対パスを返す（講師用）。
     * 英単語テストのページが無い場合は null（通常の出題 PDF を返す）。
     */
    private function buildPdfWithAnswers(Quiz $quiz): ?string
    {
        $disk = Storage::disk('local');
        $sources = [];
        $answers = [];
        foreach ($quiz->pages()->with('pdf')->get() as $p) {
            if ($p->isVocab()) {
                if ($p->render_path === null) {
                    return null;
                }
                $sources[] = ['image' => $disk->path($p->render_path)];
                if ($p->answer_render_path && $disk->exists($p->answer_render_path)) {
                    $answers[] = ['image' => $disk->path($p->answer_render_path)];
                }
            } else {
                if ($p->pdf === null) {
                    return null;
                }
                $sources[] = ['path' => $p->pdf->absolutePath(), 'page' => $p->pdf_page];
            }
        }
        if ($answers === []) {
            return null;
        }
        $rel = $quiz->dir().'/quiz_with_answers.pdf';
        PdfTools::extractPages([...$sources, ...$answers], $disk->path($rel));

        return $disk->path($rel);
    }

    /** 英単語テストの解答 PDF（講師用） */
    public function answersPdf(Request $request, Quiz $quiz): BinaryFileResponse
    {
        $this->authorizeQuiz($request, $quiz);
        $disk = Storage::disk('local');
        $images = [];
        foreach ($quiz->pages()->get() as $p) {
            if ($p->answer_render_path && $disk->exists($p->answer_render_path)) {
                $images[] = ['path' => $disk->path($p->answer_render_path), 'header' => "Page {$p->page_no}  (answer key)"];
            }
        }
        abort_if($images === [], 404, '英単語テストのページがありません。');
        $rel = $quiz->dir().'/answers.pdf';
        PdfTools::imagesToPdf($images, $disk->path($rel));

        return response()->file($disk->path($rel), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $this->safeName($quiz->title).'_解答.pdf', 'quiz-'.$quiz->id.'-answers.pdf'),
        ]);
    }

    /** 添削結果 PDF（注釈を合成した回答画像を1ページ1枚で出力） */
    public function resultPdf(Request $request, Quiz $quiz): BinaryFileResponse
    {
        $this->authorizeQuiz($request, $quiz);
        $disk = Storage::disk('local');
        $pages = $quiz->pages()->with('item')->get();
        $n = $pages->count();
        $images = [];
        foreach ($pages as $p) {
            $rel = $p->annotated_path ?? $p->answer_path;
            if ($rel === null || ! $disk->exists($rel)) {
                continue;
            }
            $markText = match ($p->mark) {
                'o' => 'O',
                'tri' => 'TRIANGLE',
                'x' => 'X',
                default => '-',
            };
            $scoreText = $p->score !== null ? $p->score.'/'.$p->maxScore($quiz) : '-';
            $no = $p->item?->seq_no !== null && preg_match('/^[0-9A-Za-z.\-]+$/', (string) $p->item->seq_no) ? '  No.'.$p->item->seq_no : '';
            $kind = $p->isVocab() ? '  (vocabulary test)' : '';
            $images[] = [
                'path' => $disk->path($rel),
                'header' => "Page {$p->page_no}/{$n}{$no}{$kind}   Mark: {$markText}   Score: {$scoreText}",
            ];
        }
        abort_if($images === [], 404, '回答画像がありません。');

        $rel = $quiz->dir().'/result.pdf';
        PdfTools::imagesToPdf($images, $disk->path($rel));

        return response()->file($disk->path($rel), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $this->safeName($quiz->title).'_添削.pdf', 'quiz-'.$quiz->id.'-result.pdf'),
        ]);
    }

    public function answerImage(Request $request, Quiz $quiz, QuizPage $page): BinaryFileResponse
    {
        $this->authorizePage($request, $quiz, $page);

        return $this->imageResponse($page->answer_path);
    }

    public function annotatedImage(Request $request, Quiz $quiz, QuizPage $page): BinaryFileResponse
    {
        $this->authorizePage($request, $quiz, $page);

        return $this->imageResponse($page->annotated_path);
    }

    /** 英単語テストの問題用紙画像 */
    public function renderImage(Request $request, Quiz $quiz, QuizPage $page): BinaryFileResponse
    {
        $this->authorizePage($request, $quiz, $page);

        return $this->imageResponse($page->render_path);
    }

    // ====================== 生徒: 提出 ======================

    /** 回答写真のアップロード（ページ単位）。差し替え時は以前の添削・採点を無効化する */
    public function uploadAnswer(Request $request, Quiz $quiz, QuizPage $page): JsonResponse
    {
        $this->authorizePage($request, $quiz, $page);
        abort_if($quiz->status === Quiz::STATUS_GRADED, 422, '採点・添削済みの小テストには提出できません。');
        $request->validate(['image' => ['required', 'file', 'max:25600']]);

        try {
            $jpeg = ImageTools::normalizeJpeg((string) file_get_contents($request->file('image')->getRealPath()));
        } catch (\Throwable) {
            abort(422, '対応していない画像形式です。');
        }

        $disk = Storage::disk('local');
        $rel = $quiz->dir().'/answers/p'.$page->page_no.'.jpg';
        $disk->put($rel, $jpeg);
        if ($page->annotated_path && $disk->exists($page->annotated_path)) {
            $disk->delete($page->annotated_path);
        }
        $page->update([
            'answer_path' => $rel,
            'answer_uploaded_at' => now(),
            'annotations' => null,
            'annotated_path' => null,
            'mark' => null,
            'score' => null,
            'comment' => null,
        ]);

        return response()->json(['data' => $this->pagePayload($page->fresh(['pdf', 'refPdf', 'item']), $quiz, false)]);
    }

    /** 全ページ撮影済みで提出（status: submitted） */
    public function submit(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorizeQuiz($request, $quiz);
        abort_if($quiz->status === Quiz::STATUS_GRADED, 422, '採点・添削済みの小テストは再提出できません。');
        $missing = $quiz->pages()->whereNull('answer_path')->count();
        abort_if($missing > 0, 422, "未撮影のページが {$missing} ページあります。");

        $quiz->update(['status' => Quiz::STATUS_SUBMITTED, 'submitted_at' => now()]);

        return response()->json(['data' => ['id' => $quiz->id, 'status' => $quiz->status]]);
    }

    // ====================== 講師: 添削・採点 ======================

    /** 添削注釈（ベクター JSON）と合成画像の保存 */
    public function saveAnnotations(Request $request, Quiz $quiz, QuizPage $page): JsonResponse
    {
        $this->authorizePage($request, $quiz, $page);
        $data = $request->validate([
            'annotations' => ['nullable', 'string', 'max:4000000'],
            'image' => ['nullable', 'file', 'max:25600'],
        ]);

        $ann = null;
        if (! empty($data['annotations'])) {
            $ann = json_decode($data['annotations'], true);
            abort_if(! is_array($ann), 422, '注釈データが不正です。');
        }
        $disk = Storage::disk('local');
        $payload = ['annotations' => $ann];
        if ($request->hasFile('image')) {
            $rel = $quiz->dir().'/annotated/p'.$page->page_no.'.jpg';
            $disk->put($rel, ImageTools::normalizeJpeg((string) file_get_contents($request->file('image')->getRealPath()), 2400));
            $payload['annotated_path'] = $rel;
        } elseif ($ann === null || ($ann['items'] ?? []) === []) {
            if ($page->annotated_path && $disk->exists($page->annotated_path)) {
                $disk->delete($page->annotated_path);
            }
            $payload['annotated_path'] = null;
        }
        $page->update($payload);

        return response()->json(['data' => $this->pagePayload($page->fresh(['pdf', 'refPdf', 'item']), $quiz, true)]);
    }

    /** 採点（○△× / 点数 / コメント）。点数省略時は ○=満点 △=半分 ×=0 */
    public function grade(Request $request, Quiz $quiz, QuizPage $page): JsonResponse
    {
        $this->authorizePage($request, $quiz, $page);
        $max = $page->maxScore($quiz);
        $data = $request->validate([
            'mark' => ['nullable', 'in:o,tri,x'],
            'score' => ['nullable', 'integer', 'min:0', 'max:'.$max],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $score = $data['score'] ?? null;
        if ($score === null && ! empty($data['mark'])) {
            $score = match ($data['mark']) {
                'o' => $max,
                'tri' => (int) ceil($max / 2),
                default => 0,
            };
        }
        $page->update(['mark' => $data['mark'] ?? null, 'score' => $score, 'comment' => $data['comment'] ?? null]);

        return response()->json(['data' => $this->pagePayload($page->fresh(['pdf', 'refPdf', 'item']), $quiz, true)]);
    }

    /** 添削完了（全ページ採点済みで status: graded） */
    public function finish(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorizeQuiz($request, $quiz);
        $missing = $quiz->pages()->whereNull('score')->count();
        abort_if($missing > 0, 422, "未採点のページが {$missing} ページあります。");

        $quiz->update(['status' => Quiz::STATUS_GRADED, 'graded_at' => now()]);

        return response()->json(['data' => ['id' => $quiz->id, 'status' => $quiz->status]]);
    }

    /** 添削のやり直し（graded → submitted） */
    public function reopen(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorizeQuiz($request, $quiz);
        abort_unless($quiz->status === Quiz::STATUS_GRADED, 422, '採点・添削済みの小テストではありません。');
        $quiz->update(['status' => Quiz::STATUS_SUBMITTED, 'graded_at' => null]);

        return response()->json(['data' => ['id' => $quiz->id, 'status' => $quiz->status]]);
    }

    // ====================== 分析 ======================

    /** 採点結果の集計（全体・推移・章別・中分類別・難易度別・弱点例題） */
    public function stats(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $all = Quiz::where('user_id', $userId)->get(['id', 'title', 'status', 'graded_at', 'max_score_per_page']);
        $graded = $all->where('status', Quiz::STATUS_GRADED)
            ->sortBy(fn (Quiz $q) => ($q->graded_at?->timestamp ?? 0) * 100000 + $q->id)
            ->values();
        $defaultMax = $graded->pluck('max_score_per_page', 'id');
        $order = $graded->pluck('id')->flip();

        $pages = QuizPage::with(['item.studyItem.mid.major.subject', 'item.book:id,title'])
            ->whereIn('quiz_id', $graded->pluck('id'))
            ->whereNotNull('score')
            ->get()
            ->sortBy(fn (QuizPage $p) => ($order[$p->quiz_id] ?? 0) * 1000 + $p->page_no)
            ->values();
        $maxOf = fn (QuizPage $p) => (int) ($p->max_score ?? $defaultMax[$p->quiz_id]);

        $sum = 0;
        $max = 0;
        $marks = ['o' => 0, 'tri' => 0, 'x' => 0];
        $perQuiz = [];
        foreach ($pages as $p) {
            $m = $maxOf($p);
            $sum += $p->score;
            $max += $m;
            if ($p->mark && isset($marks[$p->mark])) {
                $marks[$p->mark]++;
            }
            $perQuiz[$p->quiz_id]['s'] = ($perQuiz[$p->quiz_id]['s'] ?? 0) + $p->score;
            $perQuiz[$p->quiz_id]['m'] = ($perQuiz[$p->quiz_id]['m'] ?? 0) + $m;
        }

        $timeline = $graded->map(function (Quiz $q) use ($perQuiz) {
            $s = $perQuiz[$q->id]['s'] ?? 0;
            $m = $perQuiz[$q->id]['m'] ?? 0;

            return [
                'id' => $q->id,
                'title' => $q->title,
                'gradedOn' => $q->graded_at?->toDateString(),
                'score' => $s,
                'max' => $m,
                'rate' => $m > 0 ? (int) round($s / $m * 100) : null,
            ];
        })->values();

        $group = function (callable $keyFn) use ($pages, $maxOf) {
            $g = [];
            foreach ($pages as $p) {
                $k = $keyFn($p);
                if ($k === null) {
                    continue;
                }
                [$key, $label, $sub] = $k;
                $g[$key] ??= ['key' => $key, 'label' => $label, 'sub' => $sub, 'pages' => 0, 's' => 0, 'm' => 0, 'o' => 0, 'tri' => 0, 'x' => 0];
                $g[$key]['pages']++;
                $g[$key]['s'] += $p->score;
                $g[$key]['m'] += $maxOf($p);
                if ($p->mark && isset($g[$key][$p->mark])) {
                    $g[$key][$p->mark]++;
                }
            }

            return collect(array_values($g))->map(function (array $x) {
                $x['rate'] = $x['m'] > 0 ? (int) round($x['s'] / $x['m'] * 100) : null;
                $x['score'] = $x['s'];
                $x['max'] = $x['m'];
                unset($x['s'], $x['m']);

                return $x;
            })->values();
        };

        $byChapter = $group(function (QuizPage $p) {
            if ($p->isVocab()) {
                $spec = $p->vocab_spec ?? [];

                return ['vocab:'.($spec['resourceId'] ?? 0), '英単語テスト', $spec['resourceName'] ?? null];
            }
            $i = $p->item;
            if (! $i || ! $i->chapter) {
                return null;
            }

            return [$i->resource_book_id.':'.$i->chapter, $i->chapter, $i->book?->title];
        });
        $byMid = $group(function (QuizPage $p) {
            $mid = $p->item?->studyItem?->mid;
            if (! $mid) {
                return null;
            }
            $subject = $mid->major?->subject?->name;

            return ['mid:'.$mid->id, $mid->name, trim(($subject ? $subject.' / ' : '').($mid->major?->name ?? ''))];
        });
        $byDifficulty = $group(function (QuizPage $p) {
            $d = $p->item?->difficulty;
            if (! $d) {
                return null;
            }

            return ['d:'.$d, $d, null];
        })->sortBy(fn (array $x) => mb_strlen($x['label']))->values();

        // 弱点: 例題ごとの得点率が 60% 未満、または最新の判定が × / △（英単語テストは除く）
        $items = [];
        foreach ($pages as $p) {
            if ($p->isVocab()) {
                continue;
            }
            $key = $p->resource_book_item_id ? 'i:'.$p->resource_book_item_id : 'l:'.$p->label;
            $items[$key] ??= [
                'itemId' => $p->resource_book_item_id,
                'label' => $p->label,
                'chapter' => $p->item?->chapter,
                'seqNo' => $p->item?->seq_no,
                'title' => $p->item?->title ?? $p->label,
                'difficulty' => $p->item?->difficulty,
                'attempts' => 0, 's' => 0, 'm' => 0, 'lastMark' => null, 'lastOn' => null,
            ];
            $items[$key]['attempts']++;
            $items[$key]['s'] += $p->score;
            $items[$key]['m'] += $maxOf($p);
            $items[$key]['lastMark'] = $p->mark;
            $items[$key]['lastOn'] = $graded->firstWhere('id', $p->quiz_id)?->graded_at?->toDateString();
        }
        $weak = collect(array_values($items))
            ->map(function (array $x) {
                $x['rate'] = $x['m'] > 0 ? (int) round($x['s'] / $x['m'] * 100) : null;
                $x['score'] = $x['s'];
                $x['max'] = $x['m'];
                unset($x['s'], $x['m']);

                return $x;
            })
            ->filter(fn (array $x) => ($x['rate'] !== null && $x['rate'] < 60) || in_array($x['lastMark'], ['x', 'tri'], true))
            ->sortBy([['rate', 'asc'], ['attempts', 'desc']])
            ->take(40)
            ->values();

        return response()->json(['data' => [
            'summary' => [
                'quizCount' => $all->count(),
                'assignedCount' => $all->where('status', Quiz::STATUS_ASSIGNED)->count(),
                'submittedCount' => $all->where('status', Quiz::STATUS_SUBMITTED)->count(),
                'gradedCount' => $graded->count(),
                'pageCount' => $pages->count(),
                'score' => $sum,
                'max' => $max,
                'avgRate' => $max > 0 ? (int) round($sum / $max * 100) : null,
                'marks' => $marks,
            ],
            'timeline' => $timeline,
            'byChapter' => $byChapter,
            'byMid' => $byMid,
            'byDifficulty' => $byDifficulty,
            'weak' => $weak,
        ]]);
    }

    // ====================== 内部処理 ======================

    /**
     * JSON または multipart（payload=JSON 文字列 + renders[i] / answerRenders[i]）を受け取り検証する。
     */
    private function validatePayload(Request $request, bool $create): array
    {
        $input = $request->has('payload') ? json_decode((string) $request->input('payload'), true) : $request->all();
        abort_if(! is_array($input), 422, '入力データが不正です。');
        $req = $create ? 'required' : 'sometimes';

        return Validator::make($input, [
            'title' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'dueOn' => ['nullable', 'date'],
            'maxScore' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'bookId' => ['nullable', 'integer'],
            'groupKey' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'pages' => [$req, 'array', 'min:1', 'max:50'],
            'pages.*.kind' => ['nullable', 'in:pdf,vocab'],
            'pages.*.pdfId' => ['nullable', 'integer'],
            'pages.*.page' => ['nullable', 'integer', 'min:1'],
            'pages.*.itemId' => ['nullable', 'integer'],
            'pages.*.label' => ['nullable', 'string', 'max:255'],
            'pages.*.refPdfId' => ['nullable', 'integer'],
            'pages.*.refPage' => ['nullable', 'integer', 'min:1'],
            'pages.*.vocab' => ['nullable', 'array'],
            'pages.*.vocab.resourceId' => ['required_with:pages.*.vocab', 'integer'],
            'pages.*.vocab.testType' => ['required_with:pages.*.vocab', 'in:meaning,spelling,fill_spelling'],
            'pages.*.vocab.testFormat' => ['required_with:pages.*.vocab', 'in:free,choice'],
            'pages.*.vocab.sectionNames' => ['nullable', 'array'],
            'pages.*.vocab.words' => ['required_with:pages.*.vocab', 'array', 'min:1', 'max:60'],
            'pages.*.vocab.words.*.id' => ['required', 'integer'],
            'pages.*.vocab.words.*.question' => ['required', 'string', 'max:1000'],
            'pages.*.vocab.words.*.answer' => ['required', 'string', 'max:1000'],
            'pages.*.vocab.words.*.choices' => ['nullable', 'array', 'max:4'],
            'pages.*.vocab.words.*.choices.*' => ['string', 'max:500'],
            'pages.*.vocab.words.*.extra' => ['nullable', 'string', 'max:1000'],
        ])->validate();
    }

    private function titleOf(?string $title, ?ResourceBook $book): string
    {
        $t = trim((string) $title);
        if ($t !== '') {
            return $t;
        }
        $now = now();

        return ($book ? $book->title.' ' : '').'小テスト '.$now->format('n').'月'.$now->format('j').'日';
    }

    /** ページ指定を検証（生徒の教材に属する PDF / 行 / 単語帳のみ許可し、ページ範囲を確認） */
    private function normalizePages(array $pages, int $userId): array
    {
        $pdfs = ResourceBookPdf::whereHas('book', fn ($q) => $q->where('user_id', $userId))->get()->keyBy('id');
        $itemIds = array_values(array_filter(array_map(fn ($p) => $p['itemId'] ?? null, $pages)));
        $items = $itemIds === []
            ? collect()
            : ResourceBookItem::whereIn('id', $itemIds)->whereHas('book', fn ($q) => $q->where('user_id', $userId))->get()->keyBy('id');
        // 出題は講師のみのため、生徒専用の単語帳（鉄壁）は選択不可
        $resources = StudyResource::where('user_id', $userId)
            ->whereNotIn('name', StudyResourceController::TUTOR_HIDDEN_NAMES)
            ->get()
            ->keyBy('id');

        $out = [];
        foreach (array_values($pages) as $i => $p) {
            $kind = $p['kind'] ?? (isset($p['vocab']) ? QuizPage::KIND_VOCAB : QuizPage::KIND_PDF);

            if ($kind === QuizPage::KIND_VOCAB) {
                $v = $p['vocab'] ?? null;
                abort_if(! is_array($v), 422, '英単語テストの設定がありません。');
                $resource = $resources->get((int) $v['resourceId']);
                abort_if($resource === null, 422, '単語帳が見つかりません。');
                $words = array_values(array_map(fn ($w) => [
                    'id' => (int) $w['id'],
                    'question' => (string) $w['question'],
                    'answer' => (string) $w['answer'],
                    'choices' => array_values(array_map('strval', $w['choices'] ?? [])),
                    'extra' => isset($w['extra']) ? (string) $w['extra'] : null,
                ], $v['words']));
                $label = trim((string) ($p['label'] ?? '')) ?: '英単語テスト（'.$resource->name.'・'.count($words).'問）';
                $out[] = [
                    'index' => $i,
                    'page_no' => $i + 1,
                    'kind' => QuizPage::KIND_VOCAB,
                    'resource_book_pdf_id' => null,
                    'pdf_page' => null,
                    'resource_book_item_id' => null,
                    'label' => mb_substr($label, 0, 255),
                    'vocab_spec' => [
                        'resourceId' => $resource->id,
                        'resourceName' => $resource->name,
                        'sectionNames' => array_values(array_map('strval', $v['sectionNames'] ?? [])),
                        'testType' => $v['testType'],
                        'testFormat' => $v['testFormat'],
                        'count' => count($words),
                    ],
                    'vocab_words' => $words,
                    'max_score' => count($words),
                ];

                continue;
            }

            $pdf = ! empty($p['pdfId']) ? $pdfs->get((int) $p['pdfId']) : null;
            abort_if($pdf === null, 422, 'PDF が見つかりません。');
            $pageNo = (int) ($p['page'] ?? 0);
            abort_if($pageNo < 1 || $pageNo > $pdf->page_count, 422, "ページ番号が範囲外です（{$pdf->title} p.{$pageNo}）。");
            $refPdf = ! empty($p['refPdfId']) ? $pdfs->get((int) $p['refPdfId']) : null;
            $item = ! empty($p['itemId']) ? $items->get((int) $p['itemId']) : null;

            $label = trim((string) ($p['label'] ?? ''));
            if ($label === '') {
                $label = $item
                    ? trim(($item->seq_no !== null && $item->seq_no !== '' ? 'No.'.$item->seq_no.' ' : '').(string) $item->title)
                    : $pdf->title.' p.'.$pageNo;
            }

            $out[] = [
                'index' => $i,
                'page_no' => $i + 1,
                'kind' => QuizPage::KIND_PDF,
                'resource_book_pdf_id' => $pdf->id,
                'pdf_page' => $pageNo,
                'resource_book_item_id' => $item?->id,
                'label' => mb_substr($label, 0, 255),
                'ref_pdf_id' => $refPdf?->id,
                'ref_page' => $refPdf && ! empty($p['refPage']) ? min((int) $p['refPage'], $refPdf->page_count) : null,
            ];
        }

        return $out;
    }

    /** 英単語テストのページには問題用紙画像（renders[index]）が必要 */
    private function requireRenders(Request $request, array $pages): void
    {
        foreach ($pages as $p) {
            if ($p['kind'] === QuizPage::KIND_VOCAB) {
                abort_unless($request->hasFile('renders.'.$p['index']), 422, '英単語テストの問題用紙画像がありません（ページ '.$p['page_no'].'）。');
            }
        }
    }

    private function createPages(Quiz $quiz, array $pages, Request $request): void
    {
        $disk = Storage::disk('local');
        foreach ($pages as $p) {
            $index = $p['index'];
            unset($p['index']);
            $page = $quiz->pages()->create($p);
            if ($page->isVocab()) {
                $rel = $quiz->dir().'/vocab/p'.$page->page_no.'.jpg';
                $disk->put($rel, ImageTools::normalizeJpeg((string) file_get_contents($request->file('renders.'.$index)->getRealPath()), 2500, 90));
                $payload = ['render_path' => $rel];
                if ($request->hasFile('answerRenders.'.$index)) {
                    $arel = $quiz->dir().'/vocab/p'.$page->page_no.'-answer.jpg';
                    $disk->put($arel, ImageTools::normalizeJpeg((string) file_get_contents($request->file('answerRenders.'.$index)->getRealPath()), 2500, 90));
                    $payload['answer_render_path'] = $arel;
                }
                $page->update($payload);
            }
        }
    }

    /** 出題 PDF を生成する（教材ページは抽出、英単語テストは画像を A4 に配置） */
    private function generatePdf(Quiz $quiz): void
    {
        $disk = Storage::disk('local');
        $sources = [];
        foreach ($quiz->pages()->with('pdf')->get() as $p) {
            if ($p->isVocab()) {
                abort_if($p->render_path === null, 422, '英単語テストの問題用紙がありません。');
                $sources[] = ['image' => $disk->path($p->render_path)];
            } else {
                abort_if($p->pdf === null, 422, 'PDF が見つかりません。');
                $sources[] = ['path' => $p->pdf->absolutePath(), 'page' => $p->pdf_page];
            }
        }
        $rel = $quiz->dir().'/quiz.pdf';
        PdfTools::extractPages($sources, $disk->path($rel));
        $quiz->update(['file_path' => $rel]);
    }

    /** 小テストごとの採点合計（SUM(score), COUNT(score), 満点合計） */
    private function scoreSums(array $quizIds)
    {
        if ($quizIds === []) {
            return collect();
        }

        return QuizPage::query()
            ->join('quizzes', 'quizzes.id', '=', 'quiz_pages.quiz_id')
            ->whereIn('quiz_pages.quiz_id', $quizIds)
            ->selectRaw('quiz_pages.quiz_id, SUM(quiz_pages.score) as s, COUNT(quiz_pages.score) as n, SUM(COALESCE(quiz_pages.max_score, quizzes.max_score_per_page)) as max_total')
            ->groupBy('quiz_pages.quiz_id')
            ->get()
            ->keyBy('quiz_id');
    }

    private function summary(Quiz $q, $score, Carbon $today): array
    {
        $pageCount = (int) ($q->pages_count ?? 0);
        $answered = (int) ($q->answered_count ?? 0);
        $max = $score !== null ? (int) $score->max_total : $pageCount * $q->max_score_per_page;
        $sum = $score !== null && (int) $score->n > 0 ? (int) $score->s : null;
        $graded = $q->status === Quiz::STATUS_GRADED;

        return [
            'id' => $q->id,
            'title' => $q->title,
            'groupKey' => $q->group_key,
            'note' => $q->note,
            'dueOn' => $q->due_on?->toDateString(),
            'createdOn' => $q->created_at->toDateString(),
            'status' => $q->status,
            'pageCount' => $pageCount,
            'answeredCount' => $answered,
            'maxScorePerPage' => $q->max_score_per_page,
            'maxScore' => $max,
            'score' => $graded ? $sum : null,
            'rate' => $graded && $max > 0 && $sum !== null ? (int) round($sum / $max * 100) : null,
            'submittedAt' => $q->submitted_at?->toDateTimeString(),
            'gradedAt' => $q->graded_at?->toDateTimeString(),
            'bookId' => $q->resource_book_id,
            'bookTitle' => $q->book?->title,
            'createdByName' => $q->creator?->name,
            'overdue' => $q->status === Quiz::STATUS_ASSIGNED && $q->due_on !== null && $q->due_on->lt($today),
        ];
    }

    private function pagePayload(QuizPage $p, Quiz $quiz, bool $withAnswers): array
    {
        $item = $p->item;

        return [
            'id' => $p->id,
            'pageNo' => $p->page_no,
            'kind' => $p->kind,
            'label' => $p->label,
            'pdfId' => $p->resource_book_pdf_id,
            'pdfTitle' => $p->pdf?->title,
            'pdfPage' => $p->pdf_page,
            'itemId' => $p->resource_book_item_id,
            'chapter' => $item?->chapter,
            'seqNo' => $item?->seq_no,
            'itemTitle' => $item?->title,
            'difficulty' => $item?->difficulty,
            'refPdfId' => $p->ref_pdf_id,
            'refPdfTitle' => $p->refPdf?->title,
            'refPage' => $p->ref_page,
            'vocabSpec' => $p->vocab_spec,
            'vocabWords' => $withAnswers ? $p->vocab_words : null,
            'hasRender' => $p->render_path !== null,
            'hasAnswer' => $p->answer_path !== null,
            'answerUploadedAt' => $p->answer_uploaded_at?->toDateTimeString(),
            'answerVersion' => $p->answer_uploaded_at?->timestamp,
            'annotations' => $p->annotations,
            'hasAnnotated' => $p->annotated_path !== null,
            'annotatedVersion' => $p->updated_at?->timestamp,
            'mark' => $p->mark,
            'score' => $p->score,
            'maxScore' => $p->maxScore($quiz),
            'comment' => $p->comment,
        ];
    }

    private function imageResponse(?string $rel): BinaryFileResponse
    {
        abort_if($rel === null, 404);
        $abs = Storage::disk('local')->path($rel);
        abort_unless(is_file($abs), 404);

        return response()->file($abs, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    private function safeName(string $title): string
    {
        return str_replace(['/', '\\', '%', '"'], '-', $title);
    }

    private function authorizeQuiz(Request $request, Quiz $quiz): void
    {
        abort_unless($quiz->user_id === $this->targetUserId($request), 403);
    }

    private function authorizePage(Request $request, Quiz $quiz, QuizPage $page): void
    {
        $this->authorizeQuiz($request, $quiz);
        abort_unless($page->quiz_id === $quiz->id, 404);
    }
}
