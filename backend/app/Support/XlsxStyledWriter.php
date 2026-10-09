<?php

namespace App\Support;

/**
 * 書式つき xlsx の書き出し（複数シート・セルの塗り／文字色／罫線／配置・セル結合・列幅・行高・ウィンドウ枠の固定）。
 * ZIP の組み立てや文字列のエスケープは XlsxHelper を使う（本環境は zip 拡張が無い）。
 *
 * 使い方:
 *   $w = new XlsxStyledWriter();
 *   $head = $w->style(['bold' => true, 'fill' => 'EEEEEE', 'align' => 'center', 'border' => 'thin']);
 *   $w->addSheet('一覧', [0 => [0 => ['v' => '見出し', 's' => $head]]], ['colWidths' => [0 => 20]]);
 *   $binary = $w->build();
 */
final class XlsxStyledWriter
{
    /** @var array<int, array<string, mixed>> */
    private array $styles = [[]];

    /** @var array<string, int> */
    private array $styleKeys = ['[]' => 0];

    /** @var array<int, array{name: string, cells: array, opts: array}> */
    private array $sheets = [];

    /**
     * 書式を登録して番号を返す（同じ内容は同じ番号）。
     *
     * @param  array{fill?: ?string, color?: ?string, bold?: bool, size?: int, align?: string, valign?: string, wrap?: bool, border?: ?string, borderTop?: ?string, borderBottom?: ?string}  $spec
     */
    public function style(array $spec): int
    {
        ksort($spec);
        $key = json_encode($spec);
        if (! isset($this->styleKeys[$key])) {
            $this->styles[] = $spec;
            $this->styleKeys[$key] = count($this->styles) - 1;
        }

        return $this->styleKeys[$key];
    }

    /**
     * @param  array<int, array<int, array{v?: ?string, s?: int}>>  $cells  [行番号(0始まり) => [列番号(0始まり) => セル]]
     * @param  array{merges?: string[], colWidths?: array<int, float>, rowHeights?: array<int, float>, freeze?: array{col: int, row: int}}  $opts
     */
    public function addSheet(string $name, array $cells, array $opts = []): void
    {
        $this->sheets[] = ['name' => $name, 'cells' => $cells, 'opts' => $opts];
    }

    public function build(): string
    {
        $files = [
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelsXml(),
            'xl/styles.xml' => $this->stylesXml(),
        ];
        foreach ($this->sheets as $i => $sheet) {
            $files['xl/worksheets/sheet'.($i + 1).'.xml'] = $this->sheetXml($sheet['cells'], $sheet['opts']);
        }

        return XlsxHelper::zip($files);
    }

    // ====================== XML パーツ ======================

    private function contentTypesXml(): string
    {
        $s = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach (array_keys($this->sheets) as $i) {
            $s .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $s.'</Types>';
    }

    private function workbookXml(): string
    {
        $s = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($this->sheets as $i => $sheet) {
            $name = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '-', $sheet['name']), 0, 31);
            $s .= '<sheet name="'.XlsxHelper::esc($name).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return $s.'</sheets></workbook>';
    }

    private function workbookRelsXml(): string
    {
        $s = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach (array_keys($this->sheets) as $i) {
            $s .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
        }
        $s .= '<Relationship Id="rId'.(count($this->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return $s.'</Relationships>';
    }

    private function stylesXml(): string
    {
        $fonts = [];
        $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $borders = [];
        $xfs = [];
        $fontIds = [];
        $fillIds = [];
        $borderIds = [];

        foreach ($this->styles as $spec) {
            // フォント
            $fontXml = '<font>'.(! empty($spec['bold']) ? '<b/>' : '')
                .'<sz val="'.(int) ($spec['size'] ?? 11).'"/>'
                .(! empty($spec['color']) ? '<color rgb="FF'.strtoupper($spec['color']).'"/>' : '')
                .'<name val="Yu Gothic"/><family val="2"/></font>';
            if (! isset($fontIds[$fontXml])) {
                $fonts[] = $fontXml;
                $fontIds[$fontXml] = count($fonts) - 1;
            }
            // 塗り
            $fillId = 0;
            if (! empty($spec['fill'])) {
                $fillXml = '<fill><patternFill patternType="solid"><fgColor rgb="FF'.strtoupper($spec['fill']).'"/><bgColor indexed="64"/></patternFill></fill>';
                if (! isset($fillIds[$fillXml])) {
                    $fills[] = $fillXml;
                    $fillIds[$fillXml] = count($fills) - 1;
                }
                $fillId = $fillIds[$fillXml];
            }
            // 罫線
            $all = $spec['border'] ?? null;
            $top = $spec['borderTop'] ?? $all;
            $bottom = $spec['borderBottom'] ?? $all;
            $side = fn (string $tag, ?string $style) => $style
                ? '<'.$tag.' style="'.$style.'"><color rgb="FF'.($style === 'thin' ? 'C8CCD2' : '6B7280').'"/></'.$tag.'>'
                : '<'.$tag.'/>';
            $borderXml = '<border>'.$side('left', $all).$side('right', $all).$side('top', $top).$side('bottom', $bottom).'<diagonal/></border>';
            if (! isset($borderIds[$borderXml])) {
                $borders[] = $borderXml;
                $borderIds[$borderXml] = count($borders) - 1;
            }
            // 配置
            $alignAttrs = '';
            if (! empty($spec['align'])) {
                $alignAttrs .= ' horizontal="'.$spec['align'].'"';
            }
            $alignAttrs .= ' vertical="'.($spec['valign'] ?? 'center').'"';
            if (! empty($spec['wrap'])) {
                $alignAttrs .= ' wrapText="1"';
            }
            $xfs[] = '<xf numFmtId="0" fontId="'.$fontIds[$fontXml].'" fillId="'.$fillId.'" borderId="'.$borderIds[$borderXml].'" xfId="0"'
                .' applyFont="1" applyFill="'.($fillId ? 1 : 0).'" applyBorder="1" applyAlignment="1"><alignment'.$alignAttrs.'/></xf>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="'.count($fonts).'">'.implode('', $fonts).'</fonts>'
            .'<fills count="'.count($fills).'">'.implode('', $fills).'</fills>'
            .'<borders count="'.count($borders).'">'.implode('', $borders).'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.count($xfs).'">'.implode('', $xfs).'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function sheetXml(array $cells, array $opts): string
    {
        $s = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if (! empty($opts['freeze'])) {
            $c = (int) $opts['freeze']['col'];
            $r = (int) $opts['freeze']['row'];
            $s .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane xSplit="'.$c.'" ySplit="'.$r.'" topLeftCell="'.XlsxHelper::colLetter($c).($r + 1).'" activePane="bottomRight" state="frozen"/></sheetView></sheetViews>';
        } else {
            $s .= '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
        }
        $s .= '<sheetFormatPr defaultRowHeight="18"/>';

        if (! empty($opts['colWidths'])) {
            $s .= '<cols>';
            ksort($opts['colWidths']);
            foreach ($opts['colWidths'] as $ci => $w) {
                $s .= '<col min="'.($ci + 1).'" max="'.($ci + 1).'" width="'.$w.'" customWidth="1"/>';
            }
            $s .= '</cols>';
        }

        $s .= '<sheetData>';
        ksort($cells);
        foreach ($cells as $ri => $row) {
            $rowNum = $ri + 1;
            $ht = $opts['rowHeights'][$ri] ?? null;
            $s .= '<row r="'.$rowNum.'"'.($ht !== null ? ' ht="'.$ht.'" customHeight="1"' : '').'>';
            ksort($row);
            foreach ($row as $ci => $cell) {
                $ref = XlsxHelper::colLetter($ci).$rowNum;
                $sid = (int) ($cell['s'] ?? 0);
                $v = $cell['v'] ?? null;
                if ($v === null || $v === '') {
                    $s .= '<c r="'.$ref.'" s="'.$sid.'"/>';
                } else {
                    $s .= '<c r="'.$ref.'" s="'.$sid.'" t="inlineStr"><is><t xml:space="preserve">'.XlsxHelper::esc((string) $v).'</t></is></c>';
                }
            }
            $s .= '</row>';
        }
        $s .= '</sheetData>';

        if (! empty($opts['merges'])) {
            $s .= '<mergeCells count="'.count($opts['merges']).'">';
            foreach ($opts['merges'] as $m) {
                $s .= '<mergeCell ref="'.$m.'"/>';
            }
            $s .= '</mergeCells>';
        }

        return $s.'</worksheet>';
    }
}
