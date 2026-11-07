<?php

namespace Tests\Feature;

use App\Models\Core\AppRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class AppReleaseTest extends TestCase
{
    use RefreshDatabase;

    private function fakeApk(int $padding = 9000): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apk');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('AndroidManifest.xml', 'manifest');
        $zip->addFromString('classes.dex', random_bytes($padding));
        $zip->close();

        return file_get_contents($path);
    }

    /** Uploads bytes in pieces the way the web page does; returns the upload id. */
    private function upload(string $bytes, string $name = 'PatoPro.apk', int $piece = 4000): string
    {
        $id = $this->postJson('/api/v1/admin/mobile/app-releases/uploads', ['file_name' => $name, 'file_size' => strlen($bytes)])
            ->assertOk()->json('data.upload_id');
        foreach (str_split($bytes, $piece) as $i => $chunk) {
            $this->post("/api/v1/admin/mobile/app-releases/uploads/{$id}/chunks", [
                'index' => $i,
                'chunk' => UploadedFile::fake()->createWithContent('chunk', $chunk),
            ], ['Accept' => 'application/json'])->assertOk();
        }

        return $id;
    }

    public function test_admin_uploads_an_apk_in_pieces_and_anyone_can_download_it(): void
    {
        Storage::fake('local');
        $this->actingAsStaff('admin');
        $apk = $this->fakeApk();
        $id = $this->upload($apk);

        $this->postJson('/api/v1/admin/mobile/app-releases', [
            'version' => '1.2.0', 'build_number' => 5, 'upload_id' => $id, 'notes' => 'Oda kwa sauti', 'publish' => true,
        ])->assertCreated()->assertJsonPath('data.version', '1.2.0')->assertJsonPath('data.file_size', strlen($apk))
            ->assertJsonPath('data.sha256', hash('sha256', $apk));

        $this->app['auth']->forgetGuards();
        $info = $this->getJson('/api/v1/public/app')->assertOk();
        $info->assertJsonPath('data.version', '1.2.0')->assertJsonPath('data.build_number', 5)->assertJsonPath('data.notes', 'Oda kwa sauti');
        $this->assertStringEndsWith('/api/v1/public/app/download', $info->json('data.download_url'));
        $this->assertArrayNotHasKey('downloads', $info->json('data'));

        $res = $this->get('/api/v1/public/app/download');
        $res->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('PatoPro-1.2.0.apk', $res->headers->get('Content-Disposition'));
        $this->assertSame($apk, $res->streamedContent());
        $this->assertSame(1, AppRelease::query()->first()->downloads);
    }

    public function test_newest_published_build_is_offered_and_unpublished_ones_are_not(): void
    {
        Storage::fake('local');
        $this->actingAsStaff('admin');
        $this->postJson('/api/v1/admin/mobile/app-releases', ['version' => '1.0.0', 'build_number' => 1, 'upload_id' => $this->upload($this->fakeApk()), 'publish' => true])->assertCreated();
        $draft = $this->postJson('/api/v1/admin/mobile/app-releases', ['version' => '1.1.0', 'build_number' => 2, 'upload_id' => $this->upload($this->fakeApk()), 'publish' => false])->assertCreated()->json('data.id');

        $this->getJson('/api/v1/public/app')->assertJsonPath('data.version', '1.0.0');
        $this->putJson("/api/v1/admin/mobile/app-releases/{$draft}", ['is_published' => true, 'is_required' => true])->assertOk();
        $this->getJson('/api/v1/public/app')->assertJsonPath('data.version', '1.1.0')->assertJsonPath('data.is_required', true);

        // The same build number cannot be released twice.
        $this->postJson('/api/v1/admin/mobile/app-releases', ['version' => '1.1.1', 'build_number' => 2, 'external_url' => 'https://expo.dev/artifacts/x.apk'])
            ->assertStatus(422);

        $this->deleteJson("/api/v1/admin/mobile/app-releases/{$draft}")->assertOk();
        $this->getJson('/api/v1/public/app')->assertJsonPath('data.version', '1.0.0');
    }

    public function test_a_file_that_is_not_an_android_app_is_refused(): void
    {
        Storage::fake('local');
        $this->actingAsStaff('admin');
        $this->postJson('/api/v1/admin/mobile/app-releases/uploads', ['file_name' => 'virus.exe', 'file_size' => 100])->assertStatus(422);

        $id = $this->upload(str_repeat('not a zip ', 500), 'fake.apk');
        $this->postJson('/api/v1/admin/mobile/app-releases', ['version' => '1.0.0', 'build_number' => 1, 'upload_id' => $id])
            ->assertStatus(422)->assertJsonPath('code', 'BUSINESS_RULE');
        $this->assertSame(0, AppRelease::query()->count());
    }

    public function test_a_release_can_point_to_an_outside_link(): void
    {
        $this->actingAsStaff('admin');
        $this->postJson('/api/v1/admin/mobile/app-releases', ['version' => '2.0.0', 'build_number' => 9, 'external_url' => 'https://expo.dev/artifacts/eas/abc.apk'])
            ->assertCreated();
        $this->getJson('/api/v1/public/app')->assertJsonPath('data.download_url', 'https://expo.dev/artifacts/eas/abc.apk');
        $this->get('/api/v1/public/app/download')->assertRedirect('https://expo.dev/artifacts/eas/abc.apk');
    }

    public function test_only_permitted_staff_manage_releases_and_nothing_is_offered_before_the_first_upload(): void
    {
        $this->getJson('/api/v1/public/app')->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/v1/public/app/download')->assertNotFound();

        $this->actingAsStaff('cashier');
        $this->getJson('/api/v1/admin/mobile/app-releases')->assertForbidden();
        $this->postJson('/api/v1/admin/mobile/app-releases/uploads', ['file_name' => 'a.apk', 'file_size' => 10])->assertForbidden();
    }
}
