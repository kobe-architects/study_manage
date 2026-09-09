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
        $pdf = new Fpdi();

        return $pdf->setSourceFile($absPath);
    }

    /**
     * 複数 PDF の指定ページを1つの PDF に抽出する（小テストの出題 PDF）。
     *
     * @param  array<int, array{path: string, page: int}>  $pages
     */
    public static function extractPages(array $pages, string $outAbsPath): void
    {
        $pdf = new Fpdi();
        $counts = [];
        foreach ($pages as $p) {
            // 同じファイルは FPDI 内部でパーサが再利用される
            $counts[$p['path']] = $pdf->setSourceFile($p['path']);
            $page = max(1, min($counts[$p['path']], (int) $p['page']));
            $tpl = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
        }
        self::ensureDir($outAbsPath);
        $pdf->Output('F', $outAbsPath);
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
