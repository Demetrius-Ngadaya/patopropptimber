<?php

namespace App\Platform\Support;

use App\Platform\Models\Business;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Knows which business is current and points the app at its data:
 * the default database connection, the cache folder and the file folders.
 *
 * Single mode: there is only ever the one business and nothing is switched.
 */
final class BusinessManager
{
    private ?Business $record = null;

    private ?CurrentBusiness $current = null;

    /** Settings as they were before any business was chosen, to switch back. */
    private array $original;

    public function __construct(private readonly Application $app)
    {
        $default = config('database.default');
        $this->original = [
            'connection' => $default,
            'database' => config("database.connections.$default"),
            'cache_prefix' => config('cache.prefix'),
            'cache_file_path' => config('cache.stores.file.path'),
            'local_root' => config('filesystems.disks.local.root'),
            'public_root' => config('filesystems.disks.public.root'),
            'public_url' => config('filesystems.disks.public.url'),
        ];
    }

    public static function platformMode(): bool
    {
        return config('patopro.mode') === 'platform';
    }

    public function current(): CurrentBusiness
    {
        return $this->current ??= new CurrentBusiness($this->record);
    }

    public function hasBusiness(): bool
    {
        return $this->record !== null;
    }

    public function findBySlug(string $slug): ?Business
    {
        return Business::query()->with('plan')->where('slug', mb_strtolower(trim($slug)))->first();
    }

    /** Point everything at this business's own data. */
    public function switchTo(Business $business): void
    {
        if ($this->record?->is($business)) {
            return;
        }

        $name = $this->original['connection'];
        $db = $this->original['database'];
        $db['database'] = $business->db_name;
        if (($db['driver'] ?? null) !== 'sqlite') {
            // Server, user and password only where there is a value: an empty "host" makes Laravel refuse to connect.
            $login = array_filter([
                'host' => $business->db_host,
                'username' => $business->db_username ?: config('patopro.platform.business_database.username'),
                'password' => $business->db_username ? $business->db_password : config('patopro.platform.business_database.password'),
            ], fn ($v) => $v !== null && $v !== '');
            $db = array_merge($db, $login);
        }
        config(["database.connections.$name" => $db]);
        DB::purge($name);

        $folder = 'businesses/'.$business->id;
        config([
            'cache.prefix' => 'pp_b'.$business->id.'_',
            'cache.stores.file.path' => rtrim((string) $this->original['cache_file_path'], '/').'/'.$folder,
            'filesystems.disks.local.root' => rtrim((string) $this->original['local_root'], '/').'/'.$folder,
            'filesystems.disks.public.root' => rtrim((string) $this->original['public_root'], '/').'/'.$folder,
            'filesystems.disks.public.url' => rtrim((string) $this->original['public_url'], '/').'/'.$folder,
        ]);
        $this->forgetResolved();

        $this->record = $business;
        $this->current = null;
    }

    /** Work out the current business and its package again (after a change to its record or licence). */
    public function refresh(): void
    {
        $this->record?->refresh();
        $this->current = null;
    }

    /** Back to no business (the central database only). */
    public function forget(): void
    {
        $this->current = null;
        if (! $this->record) {
            return;
        }
        $name = $this->original['connection'];
        config([
            "database.connections.$name" => $this->original['database'],
            'cache.prefix' => $this->original['cache_prefix'],
            'cache.stores.file.path' => $this->original['cache_file_path'],
            'filesystems.disks.local.root' => $this->original['local_root'],
            'filesystems.disks.public.root' => $this->original['public_root'],
            'filesystems.disks.public.url' => $this->original['public_url'],
        ]);
        DB::purge($name);
        $this->forgetResolved();
        $this->record = null;
        $this->current = null;
    }

    /** Run something for one business, then switch back to whatever was current. */
    public function run(Business $business, callable $callback): mixed
    {
        $previous = $this->record;
        $this->switchTo($business);
        try {
            return $callback($business);
        } finally {
            $previous ? $this->switchTo($previous) : $this->forget();
        }
    }

    private function forgetResolved(): void
    {
        // Cached settings, stores and disks were built for the previous business.
        Cache::forgetDriver(array_keys((array) config('cache.stores')));
        Storage::forgetDisk(['local', 'public']);
        $this->app->forgetScopedInstances();

        // Controllers are kept on their routes and may hold services made for the previous business.
        if ($this->app->resolved('router')) {
            foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
                $route->flushController();
            }
        }
    }
}
