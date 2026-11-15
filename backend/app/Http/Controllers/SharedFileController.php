<?php

namespace App\Http\Controllers;

use App\Models\SharedFile;
use App\Models\SharedFolder;
use App\Support\LineNotify;
use App\Support\PdfTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * ファイル共有（Google Drive 風）。生徒(owner)と講師(tutor)が同じ階層フォルダを見て、
 * 学習データの PDF などを置く・見る・探す。データはすべて生徒に属し、講師は targetUserId で生徒スコープで操作する。
 * 大きいファイルは 4MB ずつの分割アップロード（chunk）→ 確定（store）。
 * 検索はファイル名・フォルダ名と、画面側（pdf.js）で抜き出した PDF の本文（search_text）。
 */
class SharedFileController extends Controller
{
    /** 1 ファイルの上限（バイト）。共有サーバーのディスクを考えて 300MB */
    private const MAX_BYTES = 300 * 1024 * 1024;

    /** フォルダの中身（フォルダ・ファイル）と道しるべ。folder 未指定ならルート */
    public function browse(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $folder = $this->folderOrNull($request, $userId);
        $folders = SharedFolder::query()->where('user_id', $userId)
            ->where('parent_id', $folder?->id)
            ->withCount(['children', 'files'])
            ->orderBy('name')->get();
        $files = SharedFile::query()->with('creator:id,name')->where('user_id', $userId)
            ->where('folder_id', $folder?->id)
            ->orderBy('name')->get();

        return response()->json(['data' => [
            'folder' => $folder ? ['id' => $folder->id, 'name' => $folder->name, 'parentId' => $folder->parent_id] : null,
            'breadcrumbs' => $folder ? $folder->breadcrumbs() : [],
            'folders' => $folders->map(fn (SharedFolder $f) => $this->folderPayload($f))->values(),
            'files' => $files->map(fn (SharedFile $f) => $this->filePayload($f))->values(),
        ]]);
    }

    /** 全フォルダ（移動先の選択用。平らな配列で親 ID を持つ） */
    public function folders(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $list = SharedFolder::query()->where('user_id', $userId)->orderBy('name')->get(['id', 'parent_id', 'name']);

        return response()->json(['data' => $list->map(fn (SharedFolder $f) => ['id' => $f->id, 'parentId' => $f->parent_id, 'name' => $f->name])->values()]);
    }

    /** 検索: 名前（フォルダ・ファイル）と PDF の本文。語を空白で区切るとすべてを含むもの */
    public function search(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $q = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($q) < 1, 422, '検索語を入力してください。');
        $terms = array_values(array_filter(preg_split('/[\s　]+/u', $q) ?: []));
        $terms = array_slice($terms, 0, 5);

        $folders = SharedFolder::query()->where('user_id', $userId)
            ->where(function ($w) use ($terms) {
                foreach ($terms as $t) {
                    $w->where('name', 'like', '%'.$this->like($t).'%');
                }
            })
            ->withCount(['children', 'files'])->orderBy('name')->limit(50)->get();
        $files = SharedFile::query()->with(['creator:id,name', 'folder'])->where('user_id', $userId)
            ->where(function ($w) use ($terms) {
                foreach ($terms as $t) {
                    $l = '%'.$this->like($t).'%';
                    $w->where(fn ($x) => $x->where('name', 'like', $l)->orWhere('note', 'like', $l)->orWhere('search_text', 'like', $l));
                }
            })
            ->orderBy('name')->limit(100)->get();

        return response()->json(['data' => [
            'folders' => $folders->map(fn (SharedFolder $f) => $this->folderPayload($f) + ['path' => $this->pathOf($f->parent)])->values(),
            'files' => $files->map(fn (SharedFile $f) => $this->filePayload($f) + [
                'path' => $this->pathOf($f->folder),
                'snippet' => $this->snippet($f, $terms),
            ])->values(),
        ]]);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate([
            'parentId' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $parent = ! empty($data['parentId']) ? SharedFolder::where('user_id', $userId)->findOrFail($data['parentId']) : null;
        $folder = SharedFolder::create([
            'user_id' => $userId,
            'parent_id' => $parent?->id,
            'name' => $this->cleanName($data['name']),
            'created_by' => $request->user()->id,
        ]);
        $folder->loadCount(['children', 'files']);

        return response()->json(['data' => $this->folderPayload($folder)], 201);
    }

    /** 名前の変更・移動 */
    public function updateFolder(Request $request, SharedFolder $folder): JsonResponse
    {
        $userId = $this->targetUserId($request);
        abort_unless($folder->user_id === $userId, 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'parentId' => ['sometimes', 'nullable', 'integer'],
        ]);
        $payload = [];
        if (array_key_exists('name', $data)) {
            $payload['name'] = $this->cleanName($data['name']);
        }
        if (array_key_exists('parentId', $data)) {
            $pid = $data['parentId'] !== null ? (int) $data['parentId'] : null;
            if ($pid !== null) {
                $parent = SharedFolder::where('user_id', $userId)->findOrFail($pid);
                abort_if(in_array($parent->id, $folder->descendantIds(), true), 422, 'フォルダを自分自身（またはその中）には移動できません。');
            }
            $payload['parent_id'] = $pid;
        }
        $folder->update($payload);
        $folder->loadCount(['children', 'files']);

        return response()->json(['data' => $this->folderPayload($folder)]);
    }

    public function destroyFolder(Request $request, SharedFolder $folder): JsonResponse
    {
        abort_unless($folder->user_id === $this->targetUserId($request), 403);
        $folder->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** 分割アップロード: index 順に受け取って一時ファイルへ追記する */
    public function uploadChunk(Request $request): JsonResponse
    {
        $this->targetUserId($request);
        $data = $request->validate([
            'uploadId' => ['required', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:4000'],
            'chunk' => ['required', 'file', 'max:8192'],
        ]);
        $disk = Storage::disk('local');
        $disk->makeDirectory('tmp-uploads');
        $abs = $disk->path('tmp-uploads/'.$data['uploadId'].'.part');
        if ((int) $data['index'] === 0 && is_file($abs)) {
            unlink($abs);
        }
        $in = fopen($request->file('chunk')->getRealPath(), 'rb');
        $out = fopen($abs, 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        abort_if(filesize($abs) > self::MAX_BYTES, 422, 'ファイルが大きすぎます（上限 300MB）。');

        return response()->json(['data' => ['received' => (int) $data['index'], 'size' => filesize($abs)]]);
    }

    /** アップロード確定: 一時ファイルをフォルダに登録する。searchText は画面側で抜き出した PDF の本文（任意） */
    public function storeFile(Request $request): JsonResponse
    {
        $userId = $this->targetUserId($request);
        $data = $request->validate([
            'uploadId' => ['required', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'folderId' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'mime' => ['nullable', 'string', 'max:120'],
            'searchText' => ['nullable', 'string', 'max:2000000'],
            'pageCount' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $folder = ! empty($data['folderId']) ? SharedFolder::where('user_id', $userId)->findOrFail($data['folderId']) : null;
        $disk = Storage::disk('local');
        $tmpRel = 'tmp-uploads/'.$data['uploadId'].'.part';
        abort_unless($disk->exists($tmpRel), 422, 'アップロードデータが見つかりません。もう一度アップロードしてください。');
        $tmpAbs = $disk->path($tmpRel);

        $name = $this->cleanName($data['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = $data['mime'] ?? null;
        $isPdf = $ext === 'pdf' || $mime === 'application/pdf';
        $pageCount = isset($data['pageCount']) ? (int) $data['pageCount'] : null;
        if ($isPdf) {
            if (file_get_contents($tmpAbs, false, null, 0, 5) !== '%PDF-') {
                $disk->delete($tmpRel);
                abort(422, 'PDF ファイルではありません。');
            }
            $mime = 'application/pdf';
            if (! $pageCount) {
                try {
                    $pageCount = PdfTools::pageCount($tmpAbs);
                } catch (\Throwable) {
                    $pageCount = null; // 数えられない形式でも登録はできる（閲覧は画面側の pdf.js に任せる）
                }
            }
        }
        $rel = 'shared/'.$userId.'/'.Str::uuid().($ext !== '' ? '.'.preg_replace('/[^a-z0-9]/', '', $ext) : '');
        $disk->makeDirectory('shared/'.$userId);
        $disk->move($tmpRel, $rel);

        $file = SharedFile::create([
            'user_id' => $userId,
            'folder_id' => $folder?->id,
            'name' => $name,
            'mime' => $mime ? mb_substr($mime, 0, 120) : null,
            'file_path' => $rel,
            'size_bytes' => filesize($disk->path($rel)),
            'page_count' => $pageCount,
            'search_text' => isset($data['searchText']) && $data['searchText'] !== '' ? $this->normalizeText($data['searchText']) : null,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $file->load('creator:id,name');

        // 相手（生徒が置けば講師たち、講師が置けば生徒）へ LINE で知らせる
        $this->notifyOthers($request, $userId, "ファイル共有に「{$file->name}」が追加されました".($folder ? "（{$folder->name}）" : '').'。');

        return response()->json(['data' => $this->filePayload($file)], 201);
    }

    /** 名前の変更・移動・メモ */
    public function updateFile(Request $request, SharedFile $file): JsonResponse
    {
        $userId = $this->targetUserId($request);
        abort_unless($file->user_id === $userId, 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'folderId' => ['sometimes', 'nullable', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $payload = [];
        if (array_key_exists('name', $data)) {
            $payload['name'] = $this->cleanName($data['name']);
        }
        if (array_key_exists('folderId', $data)) {
            $fid = $data['folderId'] !== null ? (int) $data['folderId'] : null;
            if ($fid !== null) {
                SharedFolder::where('user_id', $userId)->findOrFail($fid);
            }
            $payload['folder_id'] = $fid;
        }
        if (array_key_exists('note', $data)) {
            $payload['note'] = $data['note'];
        }
        $file->update($payload);
        $file->load('creator:id,name');

        return response()->json(['data' => $this->filePayload($file)]);
    }

    public function destroyFile(Request $request, SharedFile $file): JsonResponse
    {
        abort_unless($file->user_id === $this->targetUserId($request), 403);
        $file->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** ファイル本体（閲覧・ダウンロード。Range に対応し pdf.js が必要な部分だけ読める） */
    public function file(Request $request, SharedFile $file): BinaryFileResponse
    {
        abort_unless($file->user_id === $this->targetUserId($request), 403);
        $abs = $file->absolutePath();
        abort_unless(is_file($abs), 404);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        $fallback = 'file-'.$file->id.($file->isPdf() ? '.pdf' : '');

        return response()->file($abs, [
            'Content-Type' => $file->mime ?: 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $file->name, $fallback),
            'Cache-Control' => 'private, max-age=86400',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    // ====================== 内部処理 ======================

    private function folderOrNull(Request $request, int $userId): ?SharedFolder
    {
        $id = $request->query('folder');
        if ($id === null || $id === '' || (int) $id === 0) {
            return null;
        }

        return SharedFolder::where('user_id', $userId)->findOrFail((int) $id);
    }

    private function folderPayload(SharedFolder $f): array
    {
        return [
            'id' => $f->id,
            'parentId' => $f->parent_id,
            'name' => $f->name,
            'folderCount' => (int) ($f->children_count ?? 0),
            'fileCount' => (int) ($f->files_count ?? 0),
            'updatedAt' => $f->updated_at?->toDateTimeString(),
        ];
    }

    private function filePayload(SharedFile $f): array
    {
        return [
            'id' => $f->id,
            'folderId' => $f->folder_id,
            'name' => $f->name,
            'mime' => $f->mime,
            'isPdf' => $f->isPdf(),
            'sizeBytes' => $f->size_bytes,
            'pageCount' => $f->page_count,
            'note' => $f->note,
            'hasText' => $f->search_text !== null && $f->search_text !== '',
            'createdByName' => $f->creator?->name,
            'createdAt' => $f->created_at?->toDateTimeString(),
            'updatedAt' => $f->updated_at?->toDateTimeString(),
        ];
    }

    /** ルートからのフォルダの道（表示用） */
    private function pathOf(?SharedFolder $folder): array
    {
        return $folder ? $folder->breadcrumbs() : [];
    }

    /** 検索語の前後を少し切り出す（本文にヒットしたときの表示用） */
    private function snippet(SharedFile $f, array $terms): ?string
    {
        $text = $f->search_text;
        if ($text === null || $text === '') {
            return null;
        }
        foreach ($terms as $t) {
            $pos = mb_stripos($text, $t);
            if ($pos !== false) {
                $from = max(0, $pos - 40);
                $s = mb_substr($text, $from, 120);

                return ($from > 0 ? '…' : '').trim($s).(mb_strlen($text) > $from + 120 ? '…' : '');
            }
        }

        return null;
    }

    private function like(string $s): string
    {
        return addcslashes($s, '%_\\');
    }

    private function cleanName(string $name): string
    {
        $n = trim(preg_replace('/[\/\\\\:*?"<>|\x00-\x1f]/u', '-', $name) ?? $name);

        return mb_substr($n === '' ? '無題' : $n, 0, 255);
    }

    /** 空白を詰めて検索しやすくする（上限 1MB） */
    private function normalizeText(string $text): string
    {
        $t = preg_replace('/[ \t\x{3000}]+/u', ' ', $text) ?? $text;
        $t = preg_replace('/\s*\n\s*/u', "\n", $t) ?? $t;

        return mb_substr(trim($t), 0, 1000000);
    }

    /** 自分以外の関係者（生徒 ⇄ 講師たち）に通知 */
    private function notifyOthers(Request $request, int $studentId, string $message): void
    {
        $actor = $request->user();
        $student = $actor->isTutor() ? $actor->student : $actor;
        if ($student === null) {
            return;
        }
        if ($actor->isTutor()) {
            LineNotify::push($student, $message);
        } else {
            foreach ($student->tutors()->get() as $tutor) {
                LineNotify::push($tutor, $message);
            }
        }
    }
}
