<?php

namespace App\Services\Site;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Makes slide pictures quick to load: every upload is turned into a 1920 px wide
 * picture (big screens) and a 960 px one (phones), saved as compressed WebP, or
 * JPEG where the server cannot write WebP. A 5 MB camera photo becomes ~150-300 KB.
 */
class SlideImages
{
    public const LARGE = 1920;

    public const SMALL = 960;

    /** @return array{image_path: string, image_small_path: string|null} */
    public function store(UploadedFile|string $source, string $dir = 'slides'): array
    {
        $path = $source instanceof UploadedFile ? $source->getRealPath() : $source;
        $image = $this->open($path);
        if (! $image) {
            // A format GD cannot read: keep the original file as it is.
            $stored = $source instanceof UploadedFile ? $source->store($dir, 'public') : $this->copyOriginal($path, $dir);

            return ['image_path' => $stored, 'image_small_path' => null];
        }
        $name = $dir.'/'.Str::uuid();
        $large = $this->save($this->resize($image, self::LARGE, 1280), $name);
        $small = $this->save($this->resize($image, self::SMALL, 640), $name.'-sm');
        imagedestroy($image);

        return ['image_path' => $large, 'image_small_path' => $small];
    }

    private function open(string $path): ?\GdImage
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        try {
            $info = @getimagesize($path);
            $image = match ($info[2] ?? null) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                IMAGETYPE_PNG => @imagecreatefrompng($path),
                IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
                default => false,
            };
            if (! $image) {
                return null;
            }
            // Phone photos are often stored sideways with a rotation note: apply it.
            if (($info[2] ?? null) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
                $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
                if ($angle) {
                    $image = imagerotate($image, $angle, 0) ?: $image;
                }
            }

            return $image;
        } catch (Throwable) {
            return null;
        }
    }

    private function resize(\GdImage $image, int $maxW, int $maxH): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, $maxW / $w, $maxH / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $out;
    }

    private function save(\GdImage $image, string $name): string
    {
        $webp = function_exists('imagewebp');
        $file = $name.($webp ? '.webp' : '.jpg');
        $tmp = tempnam(sys_get_temp_dir(), 'slide');
        $webp ? imagewebp($image, $tmp, 78) : imagejpeg($image, $tmp, 80);
        imagedestroy($image);
        Storage::disk('public')->put($file, (string) file_get_contents($tmp));
        @unlink($tmp);

        return $file;
    }

    private function copyOriginal(string $path, string $dir): string
    {
        $file = $dir.'/'.Str::uuid().'.'.(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');
        Storage::disk('public')->put($file, (string) file_get_contents($path));

        return $file;
    }
}
