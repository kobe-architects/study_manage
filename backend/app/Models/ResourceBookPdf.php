<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * 教材（個別学習データ）に紐づく PDF。小テストの出題元になる。
 * page_map=seq: 行の番号(seq_no) + page_offset = PDF ページ / none: 手動でページ選択
 */
class ResourceBookPdf extends Model
{
    protected $fillable = [
        'resource_book_id', 'title', 'file_path', 'page_count', 'size_bytes',
        'page_map', 'page_offset', 'sort_order', 'created_by',
    ];

    protected $casts = ['page_count' => 'integer', 'size_bytes' => 'integer', 'page_offset' => 'integer'];

    protected static function booted(): void
    {
        static::deleting(function (ResourceBookPdf $pdf) {
            if ($pdf->file_path && Storage::disk('local')->exists($pdf->file_path)) {
                Storage::disk('local')->delete($pdf->file_path);
            }
            // ページ単位キャッシュも削除
            Storage::disk('local')->deleteDirectory('book-pdfs/'.$pdf->resource_book_id.'/pages/'.$pdf->id);
        });
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(ResourceBook::class, 'resource_book_id');
    }

    /** 行の番号から対応する PDF ページを求める（対応しない場合は null） */
    public function pageForSeq(?string $seqNo): ?int
    {
        if ($this->page_map !== 'seq' || $seqNo === null || ! preg_match('/^\d+$/', trim($seqNo))) {
            return null;
        }
        $page = (int) trim($seqNo) + $this->page_offset;

        return ($page >= 1 && $page <= $this->page_count) ? $page : null;
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->file_path);
    }
}
