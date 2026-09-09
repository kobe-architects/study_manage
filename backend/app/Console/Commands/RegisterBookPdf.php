<?php

namespace App\Console\Commands;

use App\Models\ResourceBook;
use App\Support\PdfTools;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * サーバー上のファイルパスから教材に PDF を紐づける（大容量 PDF を SFTP で配置した後の登録用）。
 *   php artisan quiz:register-pdf {教材ID} {PDFパス} --title="例題のみ" --map=seq --offset=0 [--move]
 */
class RegisterBookPdf extends Command
{
    protected $signature = 'quiz:register-pdf {bookId : 教材(resource_books)の ID} {path : PDF の絶対パス}
                            {--title= : 表示名（省略時はファイル名）}
                            {--map=seq : ページ対応 seq(番号=ページ+オフセット) / none(手動)}
                            {--offset=0 : ページオフセット}
                            {--move : コピーではなく移動する}';

    protected $description = '教材に PDF を紐づける（小テスト出題用）';

    public function handle(): int
    {
        $book = ResourceBook::find((int) $this->argument('bookId'));
        if ($book === null) {
            $this->error('教材が見つかりません: '.$this->argument('bookId'));

            return self::FAILURE;
        }
        $src = (string) $this->argument('path');
        if (! is_file($src)) {
            $this->error('ファイルが見つかりません: '.$src);

            return self::FAILURE;
        }
        $map = (string) $this->option('map');
        if (! in_array($map, ['seq', 'none'], true)) {
            $this->error('--map は seq か none を指定してください');

            return self::FAILURE;
        }

        try {
            $count = PdfTools::pageCount($src);
        } catch (\Throwable $e) {
            $this->error('この PDF は対応していない形式です: '.$e->getMessage());

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $rel = 'book-pdfs/'.$book->id.'/'.Str::uuid().'.pdf';
        $disk->makeDirectory('book-pdfs/'.$book->id);
        $dst = $disk->path($rel);
        if ($this->option('move')) {
            rename($src, $dst);
        } else {
            copy($src, $dst);
        }

        $pdf = $book->pdfs()->create([
            'title' => $this->option('title') ?: pathinfo($src, PATHINFO_FILENAME),
            'file_path' => $rel,
            'page_count' => $count,
            'size_bytes' => filesize($dst),
            'page_map' => $map,
            'page_offset' => (int) $this->option('offset'),
            'sort_order' => (int) $book->pdfs()->max('sort_order') + 1,
            'created_by' => null,
        ]);

        $this->info("登録しました: [{$book->title}] {$pdf->title} ({$count}ページ, id={$pdf->id})");

        return self::SUCCESS;
    }
}
