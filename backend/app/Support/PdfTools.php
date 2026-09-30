<?php

namespace App\Support;

use FPDF;
use setasign\Fpdi\Fpdi;

/**
 * PDF 操作（純 PHP: FPDI / FPDF）。共有サーバーでも外部ツール不要で動作する。
 */
class PdfTools
{
    /** PDF のページ数を返す（対応していない形式は例外） */
    public static function pageCount(string $absPath): int
    {
        $pdf = new Fpdi;

        return $pdf->setSourceFile($absPath);
    }

    /**
     * 複数 PDF の指定ページ、または画像（英単語テストの問題用紙など）を1つの PDF にまとめる（小テストの出題 PDF）。
     *
     * blank: true のページは白紙（両面印刷で章の表紙を必ず表面から始めるための調整用）。
     *
     * @param  array<int, array{path?: string, page?: int, image?: string, blank?: bool}>  $pages
     */
    public static function extractPages(array $pages, string $outAbsPath): void
    {
        $pdf = new Fpdi;
        $counts = [];
        foreach ($pages as $p) {
            if (! empty($p['blank'])) {
                $pdf->AddPage('P', 'A4');

                continue;
            }
            if (! empty($p['image'])) {
                self::addImagePage($pdf, $p['image']);

                continue;
            }
            // 同じファイルは FPDI 内部でパーサが再利用される
            $counts[$p['path']] = $pdf->setSourceFile($p['path']);
            $page = max(1, min($counts[$p['path']], (int) $p['page']));
            $tpl = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
            // 目安時間などのスタンプ画像（透過 PNG）をページ右上に置く
            if (! empty($p['stamp']) && is_file($p['stamp'])) {
                self::addStamp($pdf, $p['stamp'], (float) $size['width']);
            }
        }
        self::ensureDir($outAbsPath);
        $pdf->Output('F', $outAbsPath);
    }

    /**
     * スタンプ画像を現在のページの右上に置く（幅 34mm・上 4mm・右 5mm）。
     * 教材のスキャンは余白が少なく、ページ上端の章見出し帯には重なるが、その下の例題の枠には掛からない大きさにしている。
     */
    private static function addStamp(FPDF $pdf, string $imagePath, float $pageWidth): void
    {
        $info = @getimagesize($imagePath);
        if (! $info) {
            return;
        }
        $w = 34.0;
        $type = ($info[2] ?? null) === IMAGETYPE_PNG ? 'PNG' : 'JPG';
        $pdf->Image($imagePath, $pageWidth - $w - 5, 4, $w, 0, $type);
    }

    /** 画像を A4 いっぱい（余白 6mm）に配置したページを追加する */
    private static function addImagePage(FPDF $pdf, string $imagePath): void
    {
        $info = @getimagesize($imagePath);
        if (! $info) {
            return;
        }
        [$w, $h] = $info;
        $pdf->AddPage($w > $h ? 'L' : 'P', 'A4');
        $pw = $pdf->GetPageWidth();
        $ph = $pdf->GetPageHeight();
        $margin = 6;
        $scale = min(($pw - 2 * $margin) / $w, ($ph - 2 * $margin) / $h);
        $dw = $w * $scale;
        $dh = $h * $scale;
        // アップロードされた一時ファイルは拡張子が .tmp なので、種類は中身から判定して渡す
        $type = match ($info[2] ?? null) {
            IMAGETYPE_PNG => 'PNG',
            IMAGETYPE_GIF => 'GIF',
            default => 'JPG',
        };
        $pdf->Image($imagePath, ($pw - $dw) / 2, ($ph - $dh) / 2, $dw, $dh, $type);
    }

    /**
     * 画像（JPEG/PNG）を1ページ1枚で A4 に収めた PDF を生成する（添削結果 PDF）。
     * ヘッダー文字は FPDF 標準フォントのため ASCII のみ。
     *
     * @param  array<int, array{path: string, header?: string}>  $images
     */
    public static function imagesToPdf(array $images, string $outAbsPath): void
    {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle('Quiz result');

        foreach ($images as $img) {
            $info = @getimagesize($img['path']);
            if (! $info) {
                continue;
            }
            [$w, $h] = $info;
            $pdf->AddPage($w > $h ? 'L' : 'P');
            $pw = $pdf->GetPageWidth();
            $ph = $pdf->GetPageHeight();
            $margin = 8;
            $top = 8;
            if (! empty($img['header'])) {
                $pdf->SetFont('Helvetica', '', 9);
                $pdf->SetTextColor(110, 110, 110);
                $pdf->SetXY($margin, 5);
                $pdf->Cell($pw - 2 * $margin, 5, $img['header'], 0, 0, 'L');
                $top = 12;
            }
            $maxW = $pw - 2 * $margin;
            $maxH = $ph - $top - $margin;
            $scale = min($maxW / $w, $maxH / $h);
            $dw = $w * $scale;
            $dh = $h * $scale;
            $pdf->Image($img['path'], ($pw - $dw) / 2, $top + ($maxH - $dh) / 2, $dw, $dh);
        }
        self::ensureDir($outAbsPath);
        $pdf->Output('F', $outAbsPath);
    }

    private static function ensureDir(string $absPath): void
    {
        $dir = dirname($absPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
