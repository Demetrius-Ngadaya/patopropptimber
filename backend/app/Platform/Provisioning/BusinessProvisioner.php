<?php

namespace App\Platform\Provisioning;

use App\Models\Core\User;
use App\Platform\Models\Business;
use App\Platform\Support\BusinessManager;
use App\Services\Core\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Gives a registered business its own working database:
 *   1. create the empty database (DirectAdmin, MySQL or already there),
 *   2. fill it: copy the ready-made template database (seconds), or migrate + seed when there is none,
 *   3. make the owner the administrator and set the business name,
 *   4. mark the business ready.
 * Safe to run again after a failure.
 */
class BusinessProvisioner
{
    public function __construct(private readonly BusinessManager $businesses) {}

    public static function databaseName(string $slug): string
    {
        return substr(config('patopro.platform.business_database.prefix').str_replace('-', '_', $slug), 0, 64);
    }

    public static function templateName(): string
    {
        return self::databaseName((string) config('patopro.platform.provisioning.template'));
    }

    public function creator(): DatabaseCreator
    {
        return match (config('patopro.platform.provisioning.driver')) {
            'directadmin' => app(DirectAdminDatabaseCreator::class),
            'mysql' => app(MysqlDatabaseCreator::class),
            default => app(ExistingDatabaseCreator::class),
        };
    }

    /**
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, password: string}  $owner  password plain or already hashed
     * @return bool false when another process is already setting this business up
     */
    public function provision(Business $business, array $owner, ?callable $log = null): bool
    {
        $log ??= fn (string $line) => null;
        $lock = Cache::lock('platform:provision:'.$business->id, 900);
        if (! $lock->get()) {
            return false;
        }

        try {
            $business->update(['status' => 'provisioning']);

            if (! $this->exists($business->db_name)) {
                $log("Creating database {$business->db_name}");
                $this->creator()->create($business->db_name);
            }

            if ($this->isEmpty($business) && $this->templateReady()) {
                $log('Copying the template database');
                $this->copyTemplate($business->db_name);
            }

            $this->businesses->run($business, function (Business $business) use ($owner, $log) {
                $log('Bringing tables up to date');
                Artisan::call('migrate', ['--force' => true]);
                if (! User::query()->where('username', 'admin')->exists()) {
                    $log('Adding base data');
                    Artisan::call('db:seed', ['--force' => true]);
                }
                $this->setUpOwner($business, $owner);
            });

            $business->update(['status' => 'ready', 'meta' => array_merge((array) $business->meta, ['ready_at' => now()->toIso8601String(), 'error' => null])]);
            $log('Ready');

            return true;
        } catch (Throwable $e) {
            $meta = (array) $business->meta;
            $business->update(['status' => 'failed', 'meta' => array_merge($meta, [
                'error' => mb_substr($e->getMessage(), 0, 500),
                'attempts' => (int) ($meta['attempts'] ?? 0) + 1,
            ])]);
            report($e);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** The seeded administrator becomes the owner. */
    private function setUpOwner(Business $business, array $owner): void
    {
        app(SettingsService::class)->update(['business_name' => $business->name]);

        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $admin->forceFill(array_filter([
            'name' => $owner['name'] ?? null,
            'phone' => $owner['phone'] ?? null,
            'email' => $owner['email'] ?? null,
        ]) + [
            'password' => $owner['password'], // the "hashed" cast leaves an existing hash as it is
            'must_change_password' => (bool) ($owner['must_change_password'] ?? false),
            'is_active' => true,
        ])->save();
    }

    // ---------------------------------------------------------------- template

    /** Create or refresh the template: a fully migrated and seeded business database, never used by anyone. */
    public function refreshTemplate(?callable $log = null): void
    {
        $log ??= fn (string $line) => null;
        $name = self::templateName();
        if (! $this->exists($name)) {
            $log("Creating template database $name");
            $this->creator()->create($name);
        }

        $this->businesses->run($this->templateBusiness(), function () use ($log) {
            $log('Migrating the template');
            Artisan::call('migrate', ['--force' => true]);
            if (! User::query()->where('username', 'admin')->exists()) {
                $log('Seeding the template');
                Artisan::call('db:seed', ['--force' => true]);
            }
        });
    }

    public function templateReady(): bool
    {
        return $this->isMysql() && $this->exists(self::templateName()) && $this->hasTables(self::templateName());
    }

    private function templateBusiness(): Business
    {
        $template = new Business(['slug' => '_template', 'name' => 'Template', 'db_name' => self::templateName()]);
        $template->id = 0;

        return $template;
    }

    /** Copy every table with its rows (keys and foreign keys included) from the template into an empty database. */
    private function copyTemplate(string $target): void
    {
        $source = self::templateName();
        $db = $this->admin();
        $tables = collect($db->select("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [$source]))->pluck('t');
        if ($tables->isEmpty()) {
            throw new RuntimeException('The template database is empty. Run php artisan platform:template.');
        }

        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $table) {
                $create = (array) $db->selectOne("SHOW CREATE TABLE `$source`.`$table`");
                $sql = (string) ($create['Create Table'] ?? array_values($create)[1]);
                $db->statement("USE `$target`"); // CREATE TABLE … from SHOW CREATE TABLE has no database name
                $db->statement($sql);
                $db->statement("INSERT INTO `$target`.`$table` SELECT * FROM `$source`.`$table`");
            }
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
            DB::purge('platform_business_admin');
        }
    }

    // ---------------------------------------------------------------- checks

    private function isMysql(): bool
    {
        return in_array(config('database.connections.'.config('database.default').'.driver'), ['mysql', 'mariadb'], true);
    }

    public function exists(string $name): bool
    {
        if (! $this->isMysql()) {
            return file_exists($name);
        }

        return (bool) $this->admin()->selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name]);
    }

    private function hasTables(string $name): bool
    {
        return (bool) $this->admin()->selectOne('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? LIMIT 1', [$name]);
    }

    private function isEmpty(Business $business): bool
    {
        return $this->isMysql() ? ! $this->hasTables($business->db_name) : filesize($business->db_name) === 0;
    }

    /** A connection as the shared business database user, which can see every business database and the template. */
    private function admin(): \Illuminate\Database\Connection
    {
        $base = config('database.connections.'.config('database.default'));
        config(['database.connections.platform_business_admin' => array_merge($base, array_filter([
            'username' => config('patopro.platform.business_database.username'),
            'password' => config('patopro.platform.business_database.password'),
        ], fn ($v) => $v !== null && $v !== ''), ['database' => 'information_schema'])]);

        return DB::connection('platform_business_admin');
    }
}
