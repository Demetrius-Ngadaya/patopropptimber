<?php

namespace App\Services\Mobile;

use App\Exceptions\BusinessException;
use App\Models\Core\AppRelease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Mobile app releases offered for download from the system.
 *
 * The APK (often 50–100 MB) is uploaded in small pieces, so the upload works
 * behind proxies with small body limits (the web app's server, shared hosting
 * PHP limits). Pieces are appended in order to a temporary file on the private
 * disk; publishing checks the size, that it really is an Android package, and
 * stores it with its SHA-256.
 */
class AppReleaseService
{
    public const MAX_BYTES = 300 * 1024 * 1024;

    public const CHUNK_DIR = 'app-releases/uploads';

    public const FILE_DIR = 'app-releases';

    private function disk()
    {
        return Storage::disk('local');
    }

    /** Starts a piece-by-piece upload; returns its id. */
    public function startUpload(string $fileName, int $size): array
    {
        if (! Str::endsWith(Str::lower($fileName), '.apk')) {
            throw new BusinessException(__('Choose the Android app file (.apk).'));
        }
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new BusinessException(__('The app file must be smaller than :size MB.', ['size' => self::MAX_BYTES / 1024 / 1024]));
        }
        $this->cleanOldUploads();
        $id = (string) Str::uuid();
        $this->disk()->put(self::CHUNK_DIR."/{$id}.json", json_encode(['name' => $fileName, 'size' => $size, 'received' => 0, 'chunks' => 0, 'user_id' => auth()->id()]));
        $this->disk()->put(self::CHUNK_DIR."/{$id}.part", '');

        return ['upload_id' => $id, 'chunk_size' => self::chunkSize()];
    }

    /**
     * Size of each piece: 3 MB, or less when PHP accepts less in one request
     * (shared hosting often keeps upload_max_filesize at 2M).
     */
    public static function chunkSize(): int
    {
        $limits = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ]);
        $fits = $limits ? (int) floor(min($limits) * 0.9) : 3 * 1024 * 1024;

        return max(256 * 1024, min(3 * 1024 * 1024, $fits));
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0' || $value === '-1') {
            return 0;
        }
        $number = (float) $value;

        return (int) match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** Appends one piece; pieces must arrive in order (index 0, 1, 2 ...). */
    public function appendChunk(string $id, int $index, UploadedFile $chunk): array
    {
        $meta = $this->meta($id);
        if ($index !== $meta['chunks']) {
            // A repeated piece (the connection dropped after it arrived) is accepted silently.
            if ($index === $meta['chunks'] - 1) {
                return $this->progress($meta);
            }
            throw new BusinessException(__('Upload pieces arrived out of order. Start the upload again.'));
        }
        $bytes = $chunk->getSize();
        if ($meta['received'] + $bytes > $meta['size']) {
            throw new BusinessException(__('The upload is larger than the file. Start the upload again.'));
        }
        $in = fopen($chunk->getRealPath(), 'rb');
        $out = fopen($this->disk()->path(self::CHUNK_DIR."/{$id}.part"), 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $meta['received'] += $bytes;
        $meta['chunks']++;
        $this->disk()->put(self::CHUNK_DIR."/{$id}.json", json_encode($meta));

        return $this->progress($meta);
    }

    /** Creates a release from a finished upload or an outside link. */
    public function create(array $data): AppRelease
    {
        $this->assertNewBuild($data['platform'] ?? 'android', (int) $data['build_number']);

        $file = [];
        if (! empty($data['upload_id'])) {
            $file = $this->finishUpload($data['upload_id'], $data['version']);
        } elseif (empty($data['external_url'])) {
            throw new BusinessException(__('Upload the app file or give a download link.'));
        }

        return DB::transaction(function () use ($data, $file) {
            $release = AppRelease::query()->create([
                'platform' => $data['platform'] ?? 'android',
                'version' => $data['version'],
                'build_number' => (int) $data['build_number'],
                'external_url' => $file ? null : $data['external_url'],
                'notes' => $data['notes'] ?? null,
                'is_required' => (bool) ($data['is_required'] ?? false),
                'is_published' => (bool) ($data['publish'] ?? true),
                'published_at' => ($data['publish'] ?? true) ? now() : null,
            ] + $file);

            return $release;
        });
    }

    public function update(AppRelease $release, array $data): AppRelease
    {
        if (array_key_exists('is_published', $data)) {
            $release->published_at = $data['is_published'] ? ($release->published_at ?? now()) : $release->published_at;
        }
        $release->fill(collect($data)->only(['version', 'notes', 'is_required', 'is_published'])->all())->save();

        return $release->refresh();
    }

    public function delete(AppRelease $release): void
    {
        // The file stays until the release is purged, so a deleted release can be restored.
        $release->update(['is_published' => false]);
        $release->delete();
    }

    /** What the public download page, the login page and the app show. */
    public function present(?AppRelease $r, bool $admin = false): ?array
    {
        if (! $r) {
            return null;
        }
        $out = [
            'id' => $r->id,
            'platform' => $r->platform,
            'version' => $r->version,
            'build_number' => $r->build_number,
            'file_name' => $r->file_name ?: 'PatoPro-'.$r->version.'.apk',
            'file_size' => $r->file_size,
            'sha256' => $r->sha256,
            'notes' => $r->notes,
            'is_required' => $r->is_required,
            'released_at' => ($r->published_at ?? $r->created_at)?->toIso8601String(),
            'download_url' => $r->hasFile() ? url('/api/v1/public/app/download') : $r->external_url,
        ];
        if ($admin) {
            $out += [
                'is_published' => $r->is_published,
                'is_current' => AppRelease::current($r->platform)?->id === $r->id,
                'downloads' => $r->downloads,
                'source' => $r->hasFile() ? 'upload' : 'link',
                'external_url' => $r->external_url,
                'created_at' => $r->created_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------ internals
    private function finishUpload(string $id, string $version): array
    {
        $meta = $this->meta($id);
        if ($meta['received'] !== $meta['size']) {
            throw new BusinessException(__('The upload is not complete (:got of :total MB). Try again.', [
                'got' => round($meta['received'] / 1048576, 1), 'total' => round($meta['size'] / 1048576, 1),
            ]));
        }
        $part = self::CHUNK_DIR."/{$id}.part";
        $path = $this->disk()->path($part);
        if (! $this->isApk($path)) {
            $this->discard($id);
            throw new BusinessException(__('This file is not an Android app (.apk). Build the app with EAS and upload the APK it gives you.'));
        }
        $name = 'PatoPro-'.preg_replace('/[^0-9A-Za-z.\-]/', '', $version).'.apk';
        $target = self::FILE_DIR.'/'.Str::random(12).'-'.$name;
        $this->disk()->move($part, $target);
        $this->disk()->delete(self::CHUNK_DIR."/{$id}.json");

        return [
            'file_path' => $target,
            'file_name' => $name,
            'file_size' => $meta['size'],
            'sha256' => hash_file('sha256', $this->disk()->path($target)),
        ];
    }

    /** An APK is a ZIP holding AndroidManifest.xml and classes.dex. */
    private function isApk(string $path): bool
    {
        $fh = fopen($path, 'rb');
        $magic = $fh ? fread($fh, 4) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($magic !== "PK\x03\x04") {
            return false;
        }
        if (! class_exists(ZipArchive::class)) {
            return true;
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return false;
        }
        $ok = $zip->locateName('AndroidManifest.xml') !== false;
        $zip->close();

        return $ok;
    }

    private function assertNewBuild(string $platform, int $build): void
    {
        if (AppRelease::query()->where('platform', $platform)->where('build_number', $build)->exists()) {
            throw new BusinessException(__('A release with build number :n already exists. Each new app build has a higher number.', ['n' => $build]));
        }
    }

    private function meta(string $id): array
    {
        if (! Str::isUuid($id) || ! $this->disk()->exists(self::CHUNK_DIR."/{$id}.json")) {
            throw new BusinessException(__('This upload was not found or has expired. Start the upload again.'), 'UPLOAD_NOT_FOUND', 404);
        }
        $meta = json_decode($this->disk()->get(self::CHUNK_DIR."/{$id}.json"), true);
        if (($meta['user_id'] ?? null) !== auth()->id()) {
            throw new BusinessException(__('This upload was not found or has expired. Start the upload again.'), 'UPLOAD_NOT_FOUND', 404);
        }

        return $meta;
    }

    private function progress(array $meta): array
    {
        return ['received' => $meta['received'], 'size' => $meta['size'], 'chunks' => $meta['chunks']];
    }

    private function discard(string $id): void
    {
        $this->disk()->delete([self::CHUNK_DIR."/{$id}.json", self::CHUNK_DIR."/{$id}.part"]);
    }

    /** Unfinished uploads older than a day are removed. */
    private function cleanOldUploads(): void
    {
        foreach ($this->disk()->files(self::CHUNK_DIR) as $f) {
            if ($this->disk()->lastModified($f) < now()->subDay()->getTimestamp()) {
                $this->disk()->delete($f);
            }
        }
    }
}
