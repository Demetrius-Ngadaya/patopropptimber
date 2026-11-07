<?php

namespace Database\Seeders;

use App\Models\Core\Branch;
use App\Models\Core\Role;
use App\Models\Core\User;
use App\Models\Sales\Sale;
use App\Services\Core\SettingsService;
use Database\Seeders\Demo\DemoApi;
use Database\Seeders\Demo\SeedsBackOffice;
use Database\Seeders\Demo\SeedsMasterData;
use Database\Seeders\Demo\SeedsTrading;
use Illuminate\Database\Seeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A complete demo business for testing every part of the system:
 *
 *   php artisan migrate:fresh --seed --seeder=FullDemoSeeder
 *
 * "Mwamba Group": one owner, seven branches of different kinds: a supermarket
 * (the main branch, with uwakala and shifts), two pharmacies (Kariakoo buys the
 * medicine and supplies Mbezi by branch transfers), a hardware, a butchery, a
 * drinks depot (which also supplies the supermarket) and a timber yard.
 * 24 staff in every role, about 80 products and services, 17 suppliers and 21
 * customers, then about three months of day-by-day trading: till sales (cash,
 * mobile money, split, credit, savings, wholesale, promotions, payments by
 * phone), returns and cancellations, debt collections, purchases on cash and
 * credit with reminders, purchase plans in every stage, transfers (regular,
 * urgent, short-received, cancelled and one still on the road), seller
 * allocations, stock adjustments, online-shop orders with deliveries,
 * quotations / proformas / invoices, expenses, uwakala days and commissions,
 * shifts with handovers and cover requests, attendance, leave, advances,
 * payroll, fixed assets, budgets, journals, notes, staff announcements and
 * notification rules.
 *
 * Everything is entered through the system's own API as the right person on
 * the right day, so stock, debts and the books stay consistent.
 * It refuses to run on a database that already has sales. All demo logins use
 * the password Demo12345x (admin too, unless SEED_ADMIN_PASSWORD is set).
 */
class FullDemoSeeder extends Seeder
{
    use SeedsBackOffice, SeedsMasterData, SeedsTrading;

    /** How many days of trading history to create (up to today). */
    private const DAYS = 90;

    private DemoApi $api;

    private Carbon $start;

    private Carbon $today;

    /** @var array<string, int> branch code => id */
    private array $branches = [];

    /** @var array<string, User> username => user */
    private array $users = [];

    private User $admin;

    /** Real time when seeding started (the clock is moved while seeding). */
    private int $startedAt;

    private bool $ownAdminPassword = false;

    public function run(): void
    {
        if (Sale::query()->exists()) {
            throw new RuntimeException('FullDemoSeeder only runs on an empty database (it found sales). Use: php artisan migrate:fresh --seed --seeder=FullDemoSeeder');
        }
        mt_srand(20261027);

        // Nothing leaves the machine while seeding: no SMS, WhatsApp, email, AI or payment calls.
        Http::fake();
        config(['mail.default' => 'array', 'mauzo.sms.enabled' => false, 'mauzo.whatsapp.enabled' => false]);
        // Thousands of requests in a few minutes: the per-minute limits do not apply here.
        foreach ([ThrottleRequests::class, ThrottleRequestsWithRedis::class] as $throttle) {
            app()->bind($throttle, fn () => new class
            {
                public function handle($request, \Closure $next)
                {
                    return $next($request);
                }
            });
        }

        $this->startedAt = Carbon::now()->getTimestamp();
        $this->today = Carbon::now()->startOfDay();
        $this->start = $this->today->copy()->subDays(self::DAYS);
        $this->api = new DemoApi(app());

        Carbon::setTestNow($this->start->copy()->setTime(7, 0));
        $this->ownAdminPassword = $ownPassword = (bool) env('SEED_ADMIN_PASSWORD');
        if (! $ownPassword) {
            // The admin seeder then uses the demo password instead of printing a random one.
            putenv('SEED_ADMIN_PASSWORD='.Demo\DemoCatalogue::PASSWORD);
            $_ENV['SEED_ADMIN_PASSWORD'] = $_SERVER['SEED_ADMIN_PASSWORD'] = Demo\DemoCatalogue::PASSWORD;
        }
        // Without the console, so the admin seeder does not print a password that is replaced below.
        $this->container->make(DatabaseSeeder::class)->setContainer($this->container)->__invoke();
        $this->admin = User::query()->where('username', 'admin')->firstOrFail();
        if (! $ownPassword) {
            // A known password for the demo instead of the random one printed by the admin seeder.
            $this->admin->forceFill(['password' => Demo\DemoCatalogue::PASSWORD, 'must_change_password' => false])->save();
        }

        try {
            $this->step('Settings, branches and staff', fn () => $this->seedSetup());
            $this->step('Catalogue, suppliers and customers', fn () => $this->seedCatalogue());
            $this->step('HR records, accounts and promotions', fn () => $this->seedBackOfficeSetup());
            $this->step(self::DAYS.' days of trading', fn () => $this->seedHistory());
            $this->step('Open items for today', fn () => $this->seedToday());
        } finally {
            Carbon::setTestNow();
        }

        app(SettingsService::class)->flush();
        Artisan::call('cache:clear');
        $this->summary();
    }

    private function step(string $label, callable $work): void
    {
        $started = microtime(true);
        $this->command?->getOutput()->write("  <comment>{$label}</comment> ");
        $work();
        $this->command?->getOutput()->writeln(sprintf('<info>done</info> (%.0fs, %d requests so far)', microtime(true) - $started, $this->api->calls));
    }

    private function summary(): void
    {
        $out = $this->command?->getOutput();
        if (! $out) {
            return;
        }
        $out->writeln('');
        $out->writeln('<info>Demo business ready.</info> Log in with any of these (password <comment>Demo12345x</comment>'.($this->ownAdminPassword ? ', admin uses SEED_ADMIN_PASSWORD' : '').'):');
        $rows = [['admin', 'Super Admin', 'all branches']];
        foreach (Demo\DemoCatalogue::STAFF as $username => [$name, $role, $branch]) {
            $rows[] = [$username, $name.' ('.Role::query()->where('slug', $role)->value('name').')', $branch ? Branch::query()->where('code', $branch)->value('name') : 'all branches'];
        }
        $this->command->table(['Username', 'Who', 'Branch'], $rows);
        $out->writeln('Online-shop customers: phone 0716400001 … 0716400005, password Demo12345x');
        if ($this->api->skipped) {
            $out->writeln('');
            $out->writeln('Requests the rules refused (expected now and then, e.g. a product ran out at a branch):');
            foreach ($this->api->skipped as $endpoint => $info) {
                $out->writeln(sprintf('  %4d × %s — %s', $info['count'], $endpoint, $info['last']));
            }
        }
    }

    // ------------------------------------------------------------------ helpers shared by the steps

    private function user(string $username): User
    {
        return $this->users[$username] ??= User::query()->where('username', $username)->firstOrFail();
    }

    /** Act as this person at their branch (or at the given branch). */
    private function as(string $username, ?string $branch = null): DemoApi
    {
        $user = $username === 'admin' ? $this->admin : $this->user($username);

        return $this->api->as($user, $branch ? $this->branches[$branch] : ($user->branch_id ?? $this->branches['MAIN']));
    }

    private function at(Carbon $day, int $hour, int $minute = 0): Carbon
    {
        return $this->api->at($day->copy()->setTime($hour, $minute, mt_rand(0, 59)));
    }

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    /** @template T @param array<T> $items @return T */
    private function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    private function ref(string $prefix): string
    {
        return $prefix.strtoupper(substr(md5((string) mt_rand()), 0, 8));
    }
}
