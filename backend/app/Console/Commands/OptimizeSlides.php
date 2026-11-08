<?php

namespace App\Console\Commands;

use App\Models\Core\LoginSlide;
use App\Services\Site\SlideImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Makes pictures uploaded before the speed-up small and quick to load (safe to repeat). */
class OptimizeSlides extends Command
{
    protected $signature = 'mauzo:optimize-slides';

    protected $description = 'Resize and compress login / shop pictures that were uploaded at full size';

    public function handle(SlideImages $images): int
    {
        $done = 0;
        $before = 0;
        $after = 0;
        $disk = Storage::disk('public');
        foreach (LoginSlide::withTrashed()->whereNull('image_small_path')->get() as $slide) {
            if (! $slide->image_path || ! $disk->exists($slide->image_path)) {
                continue;
            }
            $before += $disk->size($slide->image_path);
            $new = $images->store($disk->path($slide->image_path));
            if (! $new['image_small_path']) {
                $this->warn("Picture #{$slide->id} could not be read; left as it is.");

                continue;
            }
            $old = $slide->image_path;
            $slide->forceFill($new)->saveQuietly();
            $disk->delete($old);
            $after += $disk->size($new['image_path']);
            $done++;
        }
        $this->info($done
            ? "{$done} pictures made smaller: ".round($before / 1048576, 1).' MB → '.round($after / 1048576, 1).' MB (plus phone-size copies).'
            : 'All pictures are already optimised.');

        return self::SUCCESS;
    }
}
