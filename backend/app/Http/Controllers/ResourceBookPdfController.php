<?php

namespace App\Http\Controllers;

use App\Models\ResourceBook;
use App\Models\ResourceBookPdf;
use App\Support\PdfTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 教材（個別学習データ）への PDF 紐づけ。生徒(owner)・家庭教師(tutor)の両方から操作できる
 * （tutor は担当生徒の教材に対して操作する）。
 * 大容量 PDF に備えて分割アップロード（chunk）→ 確定（store）の2段階で登録する。
 */
class ResourceBookPdfController extends Controller
{
    public const MAPS = ['seq', 'none'];

    public function index(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);

        return response()->json([
            'data' => $resourceBook->pdfs()->get()->map(fn (ResourceBookPdf $p) => $this->payload($p))->values(),
        ]);
    }

    /** 分割アップロード: index 順に受け取って一時ファイルへ追記する */
    public function uploadChunk(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);
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

        return response()->json(['data' => ['received' => (int) $data['index'], 'size' => filesize($abs)]]);
    }

    /** アップロード確定: PDF を検証してページ数を数え、教材に紐づける */
    public function store(Request $request, ResourceBook $resourceBook): JsonResponse
    {
        $this->authorizeBook($request, $resourceBook);
        $data = $request->validate([
            'uploadId' => ['required', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'title' => ['required', 'string', 'max:255'],
            'pageMap' => ['required', 'in:seq,none'],
            'pageOffset' => ['nullable', 'integer', 'min:-10000', 'max:10000'],
        ]);

        $disk = Storage::disk('local');
        $tmpRel = 'tmp-uploads/'.$data['uploadId'].'.part';
        abort_unless($disk->exists($tmpRel), 422, 'アップロードデータが見つかりません。もう一度アップロードしてください。');
        $tmpAbs = $disk->path($tmpRel);

        if (file_get_contents($tmpAbs, false, null, 0, 5) !== '%PDF-') {
            $disk->delete($tmpRel);
            abort(422, 'PDF ファイルではありません。');
        }
        try {
            $count = PdfTools::pageCount($tmpAbs);
        } catch (\Throwable) {
            $disk->delete($tmpRel);
            abort(422, 'この PDF は対応していない形式です（暗号化・圧縮方式など）。別の形式で保存し直してください。');
        }

        $rel = 'book-pdfs/'.$resourceBook->id.'/'.Str::uuid().'.pdf';
        $disk->makeDirectory('book-pdfs/'.$resourceBook->id);
        $disk->move($tmpRel, $rel);

        $pdf = $resourceBook->pdfs()->create([
            'title' => $data['title'],
            'file_path' => $rel,
            'page_count' => $count,
            'size_bytes' => filesize($disk->path($rel)),
            'page_map' => $data['pageMap'],
            'page_offset' => (int) ($data['pageOffset'] ?? 0),
            'sort_order' => (int) $resourceBook->pdfs()->max('sort_order') + 1,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($pdf)], 201);
    }

    public function update(Request $request, ResourceBookPdf $pdf): JsonResponse
    {
        $this->authorizePdf($request, $pdf);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'pageMap' => ['sometimes', 'in:seq,none'],
            'pageOffset' => ['sometimes', 'nullable', 'integer', 'min:-10000', 'max:10000'],
        ]);

        $payload = [];
        if (array_key_exists('title', $data)) {
            $payload['title'] = $data['title'];
        }
        if (array_key_exists('pageMap', $data)) {
            $payload['page_map'] = $data['pageMap'];
        }
        if (array_key_exists('pageOffset', $data)) {
            $payload['page_offset'] = (int) ($data['pageOffset'] ?? 0);
        }
        $pdf->update($payload);

        return response()->json(['data' => $this->payload($pdf)]);
    }

    public function destroy(Request $request, ResourceBookPdf $pdf): JsonResponse
    {
        $this->authorizePdf($request, $pdf);
        $pdf->delete(); // booted() でファイルも削除

        return response()->json(['message' => 'deleted']);
    }

    /** PDF 本体（pdf.js 用。Range リクエストに対応し必要部分だけ取得できる） */
    public function file(Request $request, ResourceBookPdf $pdf): BinaryFileResponse
    {
        $this->authorizePdf($request, $pdf);
        $abs = $pdf->absolutePath();
        abort_unless(is_file($abs), 404);

        return response()->file($abs, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * 1ページだけを抜き出した小さな PDF（サムネイル・プレビュー描画用）。
     * 初回アクセス時に FPDI で抽出してキャッシュし、以後は 1 リクエストで返す
     * （57MB などの元 PDF を Range で少しずつ読むより大幅に速い）。
     */
    public function page(Request $request, ResourceBookPdf $pdf, int $page): BinaryFileResponse
    {
        $this->authorizePdf($request, $pdf);
        abort_if($page < 1 || $page > $pdf->page_count, 404);

        $disk = Storage::disk('local');
        $rel = 'book-pdfs/'.$pdf->resource_book_id.'/pages/'.$pdf->id.'/p'.$page.'.pdf';
        if (! $disk->exists($rel)) {
            $src = $pdf->absolutePath();
            abort_unless(is_file($src), 404);
            PdfTools::extractPages([['path' => $src, 'page' => $page]], $disk->path($rel));
        }

        return response()->file($disk->path($rel), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    // ====================== 内部処理 ======================

    private function authorizeBook(Request $request, ResourceBook $book): void
    {
        abort_unless($book->user_id === $this->targetUserId($request), 403);
    }

    private function authorizePdf(Request $request, ResourceBookPdf $pdf): void
    {
        abort_unless($pdf->book?->user_id === $this->targetUserId($request), 403);
    }

    private function payload(ResourceBookPdf $p): array
    {
        return [
            'id' => $p->id,
            'bookId' => $p->resource_book_id,
            'title' => $p->title,
            'pageCount' => $p->page_count,
            'sizeBytes' => $p->size_bytes,
            'pageMap' => $p->page_map,
            'pageOffset' => $p->page_offset,
            'sortOrder' => $p->sort_order,
            'createdOn' => $p->created_at?->toDateString(),
        ];
    }
}
