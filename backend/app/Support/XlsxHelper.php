<?php

namespace App\Support;

/**
 * 依存ライブラリ不要の最小限 xlsx 読み書きヘルパ。
 *
 * 本環境では zip 拡張（ZipArchive）が無効なため、zlib（gzdeflate/gzinflate）と
 * crc32 を用いて ZIP コンテナを自前で組み立て／分解する。
 * セルはすべて inlineStr（文字列）として扱う簡易実装。
 */
class XlsxHelper
{
    /**
     * ヘッダ＋行データから xlsx バイナリを生成する。
     *
     * @param  array<int, string>  $header
     * @param  array<int, array<int, scalar|null>>  $rows
     */
    public static function write(array $header, array $rows): string
    {
        $all = array_merge([$header], $rows);

        $files = [
            '[Content_Types].xml' => self::contentTypesXml(),
            '_rels/.rels' => self::rootRelsXml(),
            'xl/workbook.xml' => self::workbookXml(),
            'xl/_rels/workbook.xml.rels' => self::workbookRelsXml(),
            'xl/styles.xml' => self::stylesXml(),
            'xl/worksheets/sheet1.xml' => self::sheetXml($all),
        ];

        return self::zip($files);
    }

    /**
     * xlsx バイナリを 2 次元配列（行×セル文字列）に変換する。ヘッダ行も含む。
     *
     * @return array<int, array<int, string>>
     */
    public static function read(string $binary): array
    {
        $entries = self::unzip($binary);

        // 共有文字列
        $shared = [];
        if (isset($entries['xl/sharedStrings.xml'])) {
            $shared = self::parseSharedStrings($entries['xl/sharedStrings.xml']);
        }

        // 最初のワークシートを探す
        $sheetXml = null;
        if (isset($entries['xl/worksheets/sheet1.xml'])) {
            $sheetXml = $entries['xl/worksheets/sheet1.xml'];
        } else {
            foreach ($entries as $name => $content) {
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $sheetXml = $content;
                    break;
                }
            }
        }
        if ($sheetXml === null) {
            return [];
        }

        return self::parseSheet($sheetXml, $shared);
    }

    // ====================== xlsx XML パーツ ======================

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            .'</styleSheet>';
    }

    /**
     * @param  array<int, array<int, scalar|null>>  $rows
     */
    private static function sheetXml(array $rows): string
    {
        $sb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>';

        foreach ($rows as $ri => $row) {
            $rowNum = $ri + 1;
            $sb .= '<row r="'.$rowNum.'">';
            $ci = 0;
            foreach ($row as $cell) {
                $ref = self::colLetter($ci).$rowNum;
                $value = $cell === null ? '' : (string) $cell;
                $sb .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'
                    .self::esc($value)
                    .'</t></is></c>';
                $ci++;
            }
            $sb .= '</row>';
        }

        $sb .= '</sheetData></worksheet>';

        return $sb;
    }

    // ====================== パーサ ======================

    /**
     * @return array<int, string>
     */
    private static function parseSharedStrings(string $xml): array
    {
        $out = [];
        if (! preg_match_all('#<si\b[^>]*>(.*?)</si>#s', $xml, $m)) {
            return $out;
        }
        foreach ($m[1] as $si) {
            // <r><t>..</t></r> が複数あれば連結
            $text = '';
            if (preg_match_all('#<t\b[^>]*>(.*?)</t>#s', $si, $tm)) {
                foreach ($tm[1] as $t) {
                    $text .= self::unesc($t);
                }
            }
            $out[] = $text;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $shared
     * @return array<int, array<int, string>>
     */
    private static function parseSheet(string $xml, array $shared): array
    {
        $rows = [];
        $maxCol = 0;

        if (! preg_match_all('#<row\b[^>]*>(.*?)</row>#s', $xml, $rowMatches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        // 行番号は出現順に 0,1,2... とする（r 属性を厳密に追わない簡易版だが、
        // Excel 出力は通常連番のため実用上問題なし）。
        $rowIndex = 0;
        foreach ($rowMatches[1] as $rm) {
            $inner = $rm[0];
            $cells = [];
            // 通常セル
            if (preg_match_all('#<c\b([^>]*)>(.*?)</c>#s', $inner, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $c) {
                    $attr = $c[1];
                    $body = $c[2];
                    $col = self::colIndexFromAttr($attr);
                    $type = self::attrVal($attr, 't');
                    $cells[$col] = self::cellValue($type, $body, $shared);
                    if ($col + 1 > $maxCol) {
                        $maxCol = $col + 1;
                    }
                }
            }
            // 空セル <c .../>
            if (preg_match_all('#<c\b([^>]*)/>#s', $inner, $em, PREG_SET_ORDER)) {
                foreach ($em as $c) {
                    $col = self::colIndexFromAttr($c[1]);
                    if (! isset($cells[$col])) {
                        $cells[$col] = '';
                    }
                    if ($col + 1 > $maxCol) {
                        $maxCol = $col + 1;
                    }
                }
            }
            $rows[$rowIndex] = $cells;
            $rowIndex++;
        }

        // 連番・矩形化
        $result = [];
        foreach ($rows as $cells) {
            $line = [];
            for ($i = 0; $i < $maxCol; $i++) {
                $line[$i] = $cells[$i] ?? '';
            }
            $result[] = $line;
        }

        return $result;
    }

    private static function cellValue(?string $type, string $body, array $shared): string
    {
        if ($type === 's') {
            if (preg_match('#<v\b[^>]*>(.*?)</v>#s', $body, $vm)) {
                $idx = (int) self::unesc($vm[1]);

                return $shared[$idx] ?? '';
            }

            return '';
        }
        if ($type === 'inlineStr') {
            $text = '';
            if (preg_match_all('#<t\b[^>]*>(.*?)</t>#s', $body, $tm)) {
                foreach ($tm[1] as $t) {
                    $text .= self::unesc($t);
                }
            }

            return $text;
        }
        // t="str"（数式文字列）・数値・既定
        if (preg_match('#<v\b[^>]*>(.*?)</v>#s', $body, $vm)) {
            return self::unesc($vm[1]);
        }

        return '';
    }

    private static function attrVal(string $attr, string $name): ?string
    {
        if (preg_match('#\b'.preg_quote($name, '#').'="([^"]*)"#', $attr, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function colIndexFromAttr(string $attr): int
    {
        $ref = self::attrVal($attr, 'r');
        if ($ref === null) {
            return 0;
        }
        if (preg_match('#^([A-Z]+)#', $ref, $m)) {
            return self::colToIndex($m[1]);
        }

        return 0;
    }

    // ====================== 列記号 ⇔ インデックス ======================

    private static function colLetter(int $index): string
    {
        $s = '';
        $n = $index + 1;
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $s = chr(65 + $rem).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    private static function colToIndex(string $letters): int
    {
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }

        return $n - 1;
    }

    // ====================== XML エスケープ ======================

    private static function esc(string $s): string
    {
        // 制御文字（タブ・改行以外）を除去してから実体参照化
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);

        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function unesc(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // ====================== 自前 ZIP（deflate, zlib 使用） ======================

    /**
     * @param  array<string, string>  $files  パス => 内容
     */
    private static function zip(array $files): string
    {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $uncomp = strlen($content);
            $comp = gzdeflate($content, 6);
            if ($comp === false) {
                $comp = $content; // フォールバック（store）
                $method = 0;
            } else {
                $method = 8;
            }
            $compLen = strlen($comp);
            $nameBytes = $name;
            $nameLen = strlen($nameBytes);

            // ローカルファイルヘッダ
            $lfh = pack('V', 0x04034b50)
                .pack('v', 20)        // version needed
                .pack('v', 0)         // flags
                .pack('v', $method)   // compression
                .pack('v', 0)         // mod time
                .pack('v', 0)         // mod date
                .pack('V', $crc)
                .pack('V', $compLen)
                .pack('V', $uncomp)
                .pack('v', $nameLen)
                .pack('v', 0);        // extra len
            $local .= $lfh.$nameBytes.$comp;

            // 中央ディレクトリヘッダ
            $cdh = pack('V', 0x02014b50)
                .pack('v', 20)        // version made by
                .pack('v', 20)        // version needed
                .pack('v', 0)         // flags
                .pack('v', $method)
                .pack('v', 0)         // mod time
                .pack('v', 0)         // mod date
                .pack('V', $crc)
                .pack('V', $compLen)
                .pack('V', $uncomp)
                .pack('v', $nameLen)
                .pack('v', 0)         // extra len
                .pack('v', 0)         // comment len
                .pack('v', 0)         // disk number
                .pack('v', 0)         // internal attrs
                .pack('V', 0)         // external attrs
                .pack('V', $offset)   // local header offset
                .$nameBytes;
            $central .= $cdh;

            $offset += strlen($lfh) + $nameLen + $compLen;
        }

        $count = count($files);
        $eocd = pack('V', 0x06054b50)
            .pack('v', 0)
            .pack('v', 0)
            .pack('v', $count)
            .pack('v', $count)
            .pack('V', strlen($central))
            .pack('V', strlen($local))
            .pack('v', 0);

        return $local.$central.$eocd;
    }

    /**
     * @return array<string, string>  パス => 内容
     */
    private static function unzip(string $bin): array
    {
        $out = [];
        // EOCD を末尾から探索
        $eocdPos = strrpos($bin, pack('V', 0x06054b50));
        if ($eocdPos === false) {
            return $out;
        }
        $count = unpack('v', substr($bin, $eocdPos + 10, 2))[1];
        $cdOffset = unpack('V', substr($bin, $eocdPos + 16, 4))[1];

        $p = $cdOffset;
        for ($i = 0; $i < $count; $i++) {
            if (substr($bin, $p, 4) !== pack('V', 0x02014b50)) {
                break;
            }
            $method = unpack('v', substr($bin, $p + 10, 2))[1];
            $compLen = unpack('V', substr($bin, $p + 20, 4))[1];
            $nameLen = unpack('v', substr($bin, $p + 28, 2))[1];
            $extraLen = unpack('v', substr($bin, $p + 30, 2))[1];
            $commentLen = unpack('v', substr($bin, $p + 32, 2))[1];
            $lfhOffset = unpack('V', substr($bin, $p + 42, 4))[1];
            $name = substr($bin, $p + 46, $nameLen);

            // ローカルヘッダからデータ開始位置を求める
            $lfhNameLen = unpack('v', substr($bin, $lfhOffset + 26, 2))[1];
            $lfhExtraLen = unpack('v', substr($bin, $lfhOffset + 28, 2))[1];
            $dataStart = $lfhOffset + 30 + $lfhNameLen + $lfhExtraLen;
            $comp = substr($bin, $dataStart, $compLen);

            if ($method === 8) {
                $content = gzinflate($comp);
            } else {
                $content = $comp;
            }
            $out[$name] = $content === false ? '' : $content;

            $p += 46 + $nameLen + $extraLen + $commentLen;
        }

        return $out;
    }
}
