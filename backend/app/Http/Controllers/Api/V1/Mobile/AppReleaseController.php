<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Core\AppRelease;
use App\Services\Mobile\AppReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Download the app": the PatoPro Android app is offered from the system itself.
 * Admins upload each new APK here; anyone can see the current version and download it.
 */
class AppReleaseController extends Controller
{
    public function __construct(private readonly AppReleaseService $releases) {}

    // ------------------------------------------------------------ public
    /** The version on offer (download page, login page, in-app update check). */
    public function current(): JsonResponse
    {
        return $this->ok($this->releases->present(AppRelease::current()));
    }

    /** Sends the current APK (or forwards to its outside link). */
    public function download(): StreamedResponse|RedirectResponse|JsonResponse
    {
        $release = AppRelease::current();
        if (! $release) {
            return response()->json(['success' => false, 'message' => __('The mobile app is not available for download yet.')], 404);
        }
        AppRelease::query()->whereKey($release->id)->increment('downloads');
        if (! $release->hasFile()) {
            return redirect()->away($release->external_url);
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($release->file_path)) {
            return response()->json(['success' => false, 'message' => __('The app file is missing on the server. Ask the administrator to upload it again.')], 404);
        }

        return $disk->download($release->file_path, $release->file_name ?: 'PatoPro.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ------------------------------------------------------------ admin
    public function index(Request $request): JsonResponse
    {
        $rows = AppRelease::query()->orderByDesc('build_number')->orderByDesc('id')->get();

        return $this->ok([
            'current' => $this->releases->present(AppRelease::current(), true),
            'releases' => $rows->map(fn ($r) => $this->releases->present($r, true))->values(),
            'max_mb' => AppReleaseService::MAX_BYTES / 1024 / 1024,
            'public_page' => rtrim((string) config('mauzo.frontend_url', config('app.url')), '/').'/app',
        ]);
    }

    public function startUpload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file_name' => ['required', 'string', 'max:200'],
            'file_size' => ['required', 'integer', 'min:1'],
        ]);

        return $this->ok($this->releases->startUpload($data['file_name'], (int) $data['file_size']));
    }

    public function uploadChunk(Request $request, string $uploadId): JsonResponse
    {
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:6144'],
        ]);

        return $this->ok($this->releases->appendChunk($uploadId, (int) $data['index'], $request->file('chunk')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:20', 'regex:/^\d+(\.\d+){0,3}$/'],
            'build_number' => ['required', 'integer', 'min:1', 'max:2100000000'],
            'upload_id' => ['nullable', 'uuid', 'required_without:external_url'],
            'external_url' => ['nullable', 'url:https', 'max:500', 'required_without:upload_id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_required' => ['boolean'],
            'publish' => ['boolean'],
        ], [
            'version.regex' => __('Write the version as numbers with dots, for example 1.2.0.'),
        ]);
        $release = $this->releases->create($data);

        return $this->created($this->releases->present($release, true), $release->is_published
            ? __('Version :v is now offered for download.', ['v' => $release->version])
            : __('Version :v saved. Publish it when ready.', ['v' => $release->version]));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $release = AppRelease::query()->findOrFail($id);
        $data = $request->validate([
            'version' => ['sometimes', 'string', 'max:20', 'regex:/^\d+(\.\d+){0,3}$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_required' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        return $this->ok($this->releases->present($this->releases->update($release, $data), true), __('Saved.'));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->releases->delete(AppRelease::query()->findOrFail($id));

        return $this->ok(null, __('Release removed from download.'));
    }
}
