<?php

namespace App\Support;

/**
 * 回答写真の正規化（GD）。EXIF の向きを反映し、長辺を上限まで縮小して JPEG 化する。
 * GD が使えない環境では元データをそのまま返す。
 */
class ImageTools
{
    /**
     * @return string JPEG バイナリ
     */
    public static function normalizeJpeg(string $bytes, int $maxSide = 2000, int $quality = 85): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }
        $prev = ini_get('memory_limit');
        if ($prev !== false && $prev !== '-1' && self::toBytes($prev) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new \RuntimeException('unsupported image');
        }

        // EXIF の回転（JPEG のみ）
        if (function_exists('exif_read_data') && str_starts_with($bytes, "\xFF\xD8")) {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
            $orientation = (int) ($exif['Orientation'] ?? 1);
            $rotated = match ($orientation) {
                3 => imagerotate($img, 180, 0),
                6 => imagerotate($img, -90, 0),
                8 => imagerotate($img, 90, 0),
                default => null,
            };
            if ($rotated !== null && $rotated !== false) {
                imagedestroy($img);
                $img = $rotated;
            }
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $long = max($w, $h);
        if ($long > $maxSide) {
            $scale = $maxSide / $long;
            $nw = (int) round($w * $scale);
            $nh = (int) round($h * $scale);
            $dst = imagecreatetruecolor($nw, $nh);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        } elseif (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        ob_start();
        imagejpeg($img, null, $quality);
        $out = (string) ob_get_clean();
        imagedestroy($img);

        return $out;
    }

    private static function toBytes(string $v): int
    {
        $v = trim($v);
        $unit = strtolower(substr($v, -1));
        $n = (int) $v;

        return match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
