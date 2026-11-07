<?php

namespace Database\Seeders\Demo;

use App\Models\Hr\LeaveType;
use App\Models\Hr\PayrollRun;
use App\Models\Payments\OnlinePayment;
use App\Models\Purchasing\PurchasePlan;
use App\Models\Shifts\Shift;
use App\Services\Notes\NoteService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * The routines around the trading: uwakala days, attendance, shifts,
 * leave, salary advances, payroll, depreciation, purchase plans, and the
 * open items left for today (notes, announcements, waiting payments...).
 */
trait SeedsDailyLife
{
    /** @var array{cash: float, floats: array<int, float>, day: ?int} */
    private array $agency = ['cash' => 3000000, 'floats' => [], 'day' => null];

    /** @var array<string, array<string, bool>> username => dates on approved leave */
    private array $onLeave = [];

    private function dailyLifeMorning(Carbon $d, int $index): void
    {
        $this->agencyOpen($d);
        if ($d->day === 3) {
            $this->agencyCommissions($d);
        }
        if ($d->day === 7) {
            $this->remitStatutory($d);
        }
        $this->leaveAndAdvances($d, $index);
        $this->purchasePlans($d, $index);
        if ($index === self::DAYS - 15) {
            $this->scheduleShifts($d);
        }
        $this->runShifts($d, $index);
    }

    private function dailyLifeEvening(Carbon $d, int $index): void
    {
        $this->agencyClose($d);
        $this->attendance($d);
        match ($d->day) {
            25 => $this->preparePayroll($d),
            26 => $this->approvePayroll($d),
            27 => $this->payPayroll($d),
            default => null,
        };
        if ($d->copy()->addDay()->month !== $d->month) {
            $this->at($d, 21, 0);
            $this->as('mhasibu', 'MAIN')->try('admin/accounting/fixed-assets/depreciate', ['period' => $d->format('Y-m')]);
        }
    }

    // ------------------------------------------------------------------ uwakala

    private function agencyOpen(Carbon $d, ?Carbon $at = null): void
    {
        if (! $this->agency['floats']) {
            foreach ($this->agencyLines as $line) {
                $this->agency['floats'][$line['id']] = $line['float'];
            }
        }
        if ($d->dayOfWeekIso === 7) {
            return; // closed on Sundays
        }
        $at ? $this->api->at($at) : $this->at($d, 7, 50);
        $this->as('super_cashier', 'MAIN');
        $day = $this->api->try('admin/agency/days', [
            'cash' => $this->agency['cash'],
            'balances' => collect($this->agency['floats'])->map(fn ($amount, $id) => ['account_id' => $id, 'amount' => $amount])->values()->all(),
        ]);
        $this->agency['day'] = $day['data']['id'] ?? null;
    }

    private function agencyClose(Carbon $d): void
    {
        if (! $this->agency['day']) {
            return;
        }
        // Customers deposit and withdraw all day: cash and floats move against each other.
        foreach ($this->agency['floats'] as $id => $float) {
            $move = mt_rand(-40, 40) * 10000;
            $move = max(-$float + 50000, min($move, $this->agency['cash'] - 100000));
            $this->agency['floats'][$id] = $float + $move;
            $this->agency['cash'] -= $move;
        }
        $added = $d->day === 1 ? 1000000 : 0;
        $this->agency['cash'] += $added;
        $difference = $this->chance(12) ? $this->pick([-5000, -2000, -1000, 1000, 2000]) : 0;
        $this->agency['cash'] += $difference;

        $this->at($d, 20, mt_rand(30, 45));
        $this->as('super_cashier2', 'MAIN');
        $this->api->try("admin/agency/days/{$this->agency['day']}/close", array_filter([
            'cash' => $this->agency['cash'],
            'balances' => collect($this->agency['floats'])->map(fn ($amount, $id) => ['account_id' => $id, 'amount' => $amount])->values()->all(),
            'capital_added' => $added ?: null,
            'note' => $difference < 0 ? 'Pesa taslimu zimepungua kidogo, tunafuatilia.' : null,
        ]));
        $this->agency['day'] = null;
    }

    private function agencyCommissions(Carbon $d): void
    {
        $period = $d->copy()->subMonth()->format('Y-m');
        if ($d->copy()->subMonth()->endOfMonth()->lt($this->start)) {
            return;
        }
        foreach ($this->agencyLines as $line) {
            $this->at($d, 12, mt_rand(0, 59));
            $this->as('meneja', 'MAIN')->try('admin/agency/commissions', [
                'agency_account_id' => $line['id'], 'period' => $period, 'amount' => mt_rand(8, 45) * 5000,
                'received_on' => $d->toDateString(), 'reference' => $this->ref('COM'), 'notes' => 'Kamisheni ya mwezi '.$period,
            ]);
        }
    }

    // ------------------------------------------------------------------ attendance

    private function attendance(Carbon $d): void
    {
        $rows = [];
        foreach ($this->employees as $username => $employeeId) {
            $date = $d->toDateString();
            $status = match (true) {
                isset($this->onLeave[$username][$date]) => 'on_leave',
                $d->dayOfWeekIso === 7 => 'off',
                $this->chance(3) => 'absent',
                $this->chance(9) => 'late',
                default => 'present',
            };
            $rows[] = array_filter([
                'employee_id' => $employeeId, 'status' => $status,
                'clock_in' => in_array($status, ['present', 'late'], true) ? sprintf('%02d:%02d', $status === 'late' ? 8 : 7, $status === 'late' ? mt_rand(20, 55) : mt_rand(30, 59)) : null,
                'clock_out' => in_array($status, ['present', 'late'], true) ? sprintf('17:%02d', mt_rand(0, 45)) : null,
                'notes' => $status === 'absent' ? 'Hakuja bila taarifa.' : null,
            ]);
        }
        $this->at($d, 18, 10);
        $this->as('hrmanager', 'MAIN')->try('admin/hr/attendance', ['date' => $d->toDateString(), 'rows' => $rows]);
    }

    // ------------------------------------------------------------------ leave and advances

    private function leaveAndAdvances(Carbon $d, int $index): void
    {
        $annual = LeaveType::query()->where('code', 'ANNUAL')->value('id');
        $compassion = LeaveType::query()->where('code', 'COMPASSION')->value('id');
        $leave = function (string $username, int $type, int $from, int $to, string $reason, ?bool $approve) use ($d) {
            $this->at($d, 9, 15);
            $created = $this->as('hrmanager', 'MAIN')->try('admin/hr/leave', [
                'employee_id' => $this->employees[$username], 'leave_type_id' => $type,
                'start_date' => $d->copy()->addDays($from)->toDateString(), 'end_date' => $d->copy()->addDays($to)->toDateString(), 'reason' => $reason,
            ]);
            if ($created && $approve !== null) {
                $this->at($d, 11, 0);
                $approve
                    ? $this->api->try("admin/hr/leave/{$created['data']['id']}/approve", ['note' => 'Imeidhinishwa. Mapumziko mema.'])
                    : $this->api->try("admin/hr/leave/{$created['data']['id']}/reject", ['note' => 'Tuna upungufu wa wafanyakazi wiki hiyo; chagua tarehe nyingine.']);
                if ($approve) {
                    for ($i = $from; $i <= $to; $i++) {
                        $this->onLeave[$username][$d->copy()->addDays($i)->toDateString()] = true;
                    }
                }
            }
        };

        match ($index) {
            20 => $leave('vinywaji_cashier', $annual, 3, 9, 'Likizo ya mwaka: kwenda nyumbani Moshi.', true),
            40 => $leave('famasia_cashier', $annual, 1, 3, 'Likizo fupi: sherehe ya familia.', true),
            48 => $leave('rider_petro', $compassion, 0, 2, 'Msiba wa ndugu Moshi.', true),
            61 => $leave('muuzaji_daudi', $annual, 2, 6, 'Harusi ya ndugu.', false),
            default => null,
        };

        if ($index === 35) {
            // A salary advance requested by the employee, approved by HR and paid by the accountant.
            $this->at($d, 8, 40);
            $advance = $this->as('stoo', 'MAIN')->try('admin/hr/me/advances', ['amount' => 200000, 'installments' => 2, 'reason' => 'Ada ya shule ya mtoto.']);
            if ($advance) {
                $this->at($d, 10, 0);
                $this->as('hrmanager', 'MAIN')->try("admin/hr/advances/{$advance['data']['id']}/decide", ['approve' => true, 'note' => 'Itakatwa kwa miezi miwili.']);
                $this->at($d, 14, 0);
                $this->as('mhasibu', 'MAIN')->try("admin/hr/advances/{$advance['data']['id']}/pay", ['method' => 'mobile_money', 'reference' => $this->ref('ADV')]);
            }
        }
    }

    // ------------------------------------------------------------------ payroll

    private function preparePayroll(Carbon $d): void
    {
        $this->at($d, 15, 0);
        $this->as('hrmanager', 'MAIN')->try('admin/hr/payroll', ['period' => $d->format('Y-m'), 'notes' => 'Mishahara ya '.$d->translatedFormat('F Y')]);
    }

    private function approvePayroll(Carbon $d): void
    {
        $run = PayrollRun::query()->where('period', $d->format('Y-m'))->where('status', PayrollRun::DRAFT)->first();
        if ($run) {
            $this->at($d, 10, 30);
            $this->as('meneja', 'MAIN')->try("admin/hr/payroll/{$run->id}/approve", []);
        }
    }

    private function payPayroll(Carbon $d): void
    {
        $run = PayrollRun::query()->where('period', $d->format('Y-m'))->where('status', PayrollRun::APPROVED)->first();
        if ($run) {
            $this->at($d, 11, 0);
            $this->as('mhasibu', 'MAIN')->try("admin/hr/payroll/{$run->id}/pay", ['method' => 'bank', 'reference' => 'NMB-SAL-'.$d->format('ym'), 'paid_on' => $d->toDateString()]);
        }
    }

    /** By the 7th: PAYE, NSSF, SDL and WCF of last month go to TRA, NSSF and WCF. */
    private function remitStatutory(Carbon $d): void
    {
        $run = PayrollRun::query()->where('period', $d->copy()->subMonth()->format('Y-m'))->where('status', PayrollRun::PAID)->first();
        if (! $run) {
            return;
        }
        $this->at($d, 10, 0);
        $this->as('mhasibu', 'MAIN');
        foreach (['paye' => (float) $run->paye, 'nssf' => (float) $run->nssf_employee + (float) $run->nssf_employer, 'sdl' => (float) $run->sdl, 'wcf' => (float) $run->wcf] as $type => $amount) {
            if ($amount > 0) {
                $this->api->try('admin/hr/payroll/remittances', ['type' => $type, 'amount' => $amount, 'method' => 'bank', 'reference' => strtoupper($type).'-'.$d->format('ym'), 'date' => $d->toDateString()]);
            }
        }
    }

    // ------------------------------------------------------------------ shifts at Kariakoo

    private function scheduleShifts(Carbon $d): void
    {
        $this->at($d, 6, 30);
        $this->as('hrmanager', 'MAIN');
        $until = $this->today->copy()->addDays(7)->toDateString();
        foreach (['super_cashier' => 'Asubuhi', 'super_cashier2' => 'Jioni'] as $username => $type) {
            $this->api->try('admin/shifts', ['user_ids' => [$this->user($username)->id], 'shift_type_id' => $this->shiftTypes[$type], 'date_from' => $d->toDateString(), 'date_to' => $until, 'notes' => 'Ratiba ya wiki mbili.']);
        }
    }

    /** Start, hand over and end both shifts of the day; one cover request and one missed shift along the way. */
    private function runShifts(Carbon $d, int $index, ?Carbon $until = null): void
    {
        $shifts = Shift::query()->where('branch_id', $this->branches['MAIN'])->whereDate('starts_at', $d->toDateString())
            ->where('status', 'scheduled')->orderBy('starts_at')->get();
        foreach ($shifts as $i => $shift) {
            if ($index === self::DAYS - 9 && $i === 1) {
                continue; // nobody came for the evening shift: marked as missed later
            }
            if ($index === self::DAYS - 6 && $i === 1) {
                // An emergency: the evening cashier asks the morning cashier to cover; she accepts.
                $this->api->at($shift->starts_at->copy()->subHours(6));
                $request = $this->as($shift->user->username, 'MAIN')->try("admin/shifts/{$shift->id}/delegate", ['to_user_id' => $this->user('super_cashier')->id, 'reason' => 'Mtoto wangu anaumwa, naomba unishikie zamu ya jioni.', 'emergency' => true]);
                if ($request) {
                    $this->api->at($shift->starts_at->copy()->subHours(5));
                    $this->as('super_cashier', 'MAIN')->try("admin/shifts/delegations/{$request['data']['id']}/respond", ['accept' => true, 'note' => 'Sawa, nitaishika. Pole sana.']);
                    $shift->refresh();
                }
            }
            $start = $shift->starts_at->copy()->addMinutes(mt_rand(-5, 12));
            if ($until && $start->gt($until)) {
                return;
            }
            $this->api->at($start);
            $this->as($shift->user->username, 'MAIN')->try("admin/shifts/{$shift->id}/start", []);
            // Accept what the previous person handed over.
            $handover = \App\Models\Shifts\ShiftHandover::query()->where('to_user_id', $shift->user_id)->where('status', 'pending')->latest('id')->first();
            if ($handover) {
                $this->api->at($start->copy()->addMinutes(3));
                $this->api->try("admin/shifts/handovers/{$handover->id}/accept", ['comment' => $this->pick(['Nimepokea, kila kitu kiko sawa.', 'Nimepokea. Printa ya risiti inahitaji karatasi.', 'Sawa, nimehesabu pesa.']), 'cash_confirmed' => $handover->cash_counted]);
            }
            $end = $shift->ends_at->copy()->addMinutes(mt_rand(0, 10));
            if ($until && $end->gt($until)) {
                return;
            }
            $next = Shift::query()->where('branch_id', $this->branches['MAIN'])->where('starts_at', '>=', $shift->ends_at)->where('status', 'scheduled')->orderBy('starts_at')->first();
            $this->api->at($end);
            $this->as($shift->user->username, 'MAIN')->try("admin/shifts/{$shift->id}/end", array_filter([
                'to_user_id' => $next?->user_id !== $shift->user_id ? $next?->user_id : null,
                'notes' => $this->pick(['Mteja Mama Ntilie atakuja kuchukua oda yake kesho asubuhi.', 'Soda za Pepsi zimebaki kreti 2 tu, mjulishe stoo.', 'Hakuna tatizo, siku nzuri.', 'Deni la Juma Hassan limelipwa nusu, risiti iko kwenye droo.']),
                'cash_counted' => round(mt_rand(150, 900) * 1000, -3),
            ]));
        }
    }

    // ------------------------------------------------------------------ purchase plans

    private function purchasePlans(Carbon $d, int $index): void
    {
        $plan = function (string $supplier, string $title, string $payment, int $creditDays = 0, int $expectedIn = 3) use ($d): ?array {
            $items = [];
            $branch = 'MAIN';
            foreach ($this->products as $p) {
                if ($p['supplier'] === $supplier) {
                    $items[] = ['product_id' => $p['id'], 'quantity' => max(10, round($p['opening'] * 0.3)), 'unit_cost' => $p['cost']];
                    $branch = $p['home'];
                }
            }
            $this->at($d, 8, 20);
            $created = $this->as('stoo', $branch)->try('admin/purchase-plans', array_filter([
                'supplier_id' => $this->suppliers[$supplier], 'title' => $title, 'expected_date' => $d->copy()->addDays($expectedIn)->toDateString(),
                'payment_type' => $payment, 'credit_days' => $payment === 'credit' ? $creditDays : null,
                'reminder_days_before' => $payment === 'credit' ? 3 : null, 'reminder_channels' => $payment === 'credit' ? ['sms', 'email'] : null,
                'items' => $items, 'notes' => 'Mpango wa manunuzi ya '.$d->translatedFormat('F'),
            ], fn ($v) => $v !== null));

            return isset($created['data']) ? $created['data'] + ['_branch' => $branch] : null;
        };
        $submit = function (array $plan) use ($d) {
            $this->at($d, 9, 45);
            $this->as('meneja', $plan['_branch'])->try("admin/purchase-plans/{$plan['id']}/submit", []);
        };
        $receive = function (array $plan, Carbon $day, float $share) {
            $planId = $plan['id'];
            $p = PurchasePlan::query()->with('items')->find($planId);
            $lines = [];
            foreach ($p->items as $item) {
                $left = (float) $item->quantity - (float) $item->received_quantity;
                $qty = $share >= 1 ? $left : floor($left * $share);
                if ($qty > 0) {
                    $tracked = collect($this->products)->firstWhere('id', $item->product_id);
                    $lines[] = array_filter([
                        'plan_item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => (float) $item->unit_cost,
                        'batch_no' => $tracked && str_contains($tracked['flags'], 'e') ? 'PL'.$day->format('ymd') : null,
                        'expiry_date' => $tracked && str_contains($tracked['flags'], 'e') ? $day->copy()->addDays(str_contains($tracked['category'], 'Medic') || str_contains($tracked['category'], 'Health') ? 500 : 150)->toDateString() : null,
                    ]);
                }
            }
            $this->at($day, 13, 30);
            $this->as('stoo', $plan['_branch'])->try("admin/purchase-plans/{$planId}/receive", [
                'purchase_date' => $day->toDateString(), 'supplier_invoice_no' => 'PL-'.mt_rand(10000, 99999), 'items' => $lines, 'transport_cost' => $plan['_branch'] === 'TIMBER' ? 300000 : 20000,
            ]);
        };

        static $plans = [];
        match ($index) {
            30 => ($plans['azam'] = $plan('AZAM', 'Bidhaa za Azam za mwezi (supermarket)', 'cash')) && $submit($plans['azam']),
            33 => isset($plans['azam']) ? $receive($plans['azam'], $d, 1) : null,
            45 => ($plans['mafinga'] = $plan('MAFINGA', 'Lori la mbao kutoka Mafinga', 'credit', 30, 4)) && $submit($plans['mafinga']),
            49 => isset($plans['mafinga']) ? $receive($plans['mafinga'], $d, 1) : null,
            55 => ($plans['bakh'] = $plan('BAKH', 'Sukari, mchele na mafuta (mkopo)', 'credit', 30)) && $submit($plans['bakh']),
            58 => isset($plans['bakh']) ? $receive($plans['bakh'], $d, 0.5) : null,
            62 => isset($plans['bakh']) ? $receive($plans['bakh'], $d, 1) : null,
            70 => ($plans['twiga'] = $plan('TWIGA', 'Saruji na tiles kwa mradi wa Jumanne Construction', 'credit', 30)) && $submit($plans['twiga']),
            72 => isset($plans['twiga']) ? $this->as('meneja', 'HARDWARE')->try("admin/purchase-plans/{$plans['twiga']['id']}/cancel", ['reason' => 'Mteja ameahirisha mradi.']) : null,
            75 => ($plans['alaf'] = $plan('ALAF', 'Bati, nondo na misumari', 'credit', 30)) && $submit($plans['alaf']),
            77 => isset($plans['alaf']) ? $receive($plans['alaf'], $d, 0.6) : null,
            80 => isset($plans['alaf']) ? $this->as('meneja', 'HARDWARE')->try("admin/purchase-plans/{$plans['alaf']['id']}/close", ['note' => 'Msambazaji hana mzigo uliobaki; tumefunga mpango.']) : null,
            self::DAYS - 6 => ($plans['kapi'] = $plan('KAPI', 'Bidhaa za usafi za mwezi ujao', 'credit', 21)) && $submit($plans['kapi']),
            self::DAYS - 3 => isset($plans['kapi']) ? $receive($plans['kapi'], $d, 0.4) : null,
            self::DAYS - 4 => ($plans['shelys'] = $plan('SHELYS', 'Dawa za pharmacy (Kariakoo na Mbezi)', 'credit', 30, 5)) && $submit($plans['shelys']),
            self::DAYS - 2 => $plan('TBL', 'Bia na vinywaji vikali vya sikukuu', 'credit', 14, 6),
            default => null,
        };
    }

    // ------------------------------------------------------------------ today

    /** This morning: the till, the uwakala day and the shifts are open; things wait for people to act. */
    private function seedToday(): void
    {
        $d = $this->today->copy();
        $realNow = Carbon::createFromTimestamp($this->startedAt);
        $until = $d->copy()->setTime(12, 0)->min($realNow->copy()->subMinutes(10));
        $index = self::DAYS;

        // Yesterday's draft plan, waiting for the manager.
        $this->at($d->copy()->subDay(), 17, 0);
        $this->as('stoo', 'MAIN')->try('admin/purchase-plans', ['supplier_id' => $this->suppliers['TANGA'], 'title' => 'Maziwa na mtindi ya wiki ijayo', 'expected_date' => $d->copy()->addDays(2)->toDateString(), 'payment_type' => 'cash', 'items' => [
            ['product_id' => $this->products['MAZ-500']['id'], 'quantity' => 120, 'unit_cost' => 1100], ['product_id' => $this->products['MTI-500']['id'], 'quantity' => 80, 'unit_cost' => 1200],
        ]]);
        $this->selfServiceRequests($d);

        if ($until->gt($d->copy()->setTime(7, 55))) {
            $this->agencyOpen($d, $d->copy()->setTime(7, 50));
            $this->runShifts($d, $index, $until);
            $this->receiveTransfers($d->copy()->setTime(8, 0));
            foreach (DemoCatalogue::DAILY_SALES as $branch => [$min]) {
                for ($i = 0; $i < intdiv($min + 1, 2); $i++) {
                    $minutes = mt_rand(8 * 60 + 15, $until->hour * 60 + $until->minute);
                    $this->sell($branch, $d->copy()->setTime(intdiv($minutes, 60), $minutes % 60, mt_rand(0, 59)));
                }
            }
        }
        $this->at($d, 7, 0);
        $this->placeShopOrder($d);
        $this->placeShopOrder($d);

        // This morning's restock for Mbezi is on the road: Mbezi still has to receive it.
        $this->at($d, 7, 40);
        $this->as('meneja_famasia', 'PHARMA1')->try('admin/transfers', ['to_branch_id' => $this->branches['PHARMA2'], 'type' => 'free', 'items' => [
            ['product_id' => $this->products['ALU-24']['id'], 'quantity' => 30], ['product_id' => $this->products['PAR-500']['id'], 'quantity' => 60],
            ['product_id' => $this->products['MRDT-1']['id'], 'quantity' => 50], ['product_id' => $this->products['ORS-1']['id'], 'quantity' => 40],
        ], 'notes' => 'Mzigo wa leo: dawa za malaria na kuharisha zinahitajika sana Mbezi.']);

        $this->phonePaymentsToday($d);
        $this->notesAndMessages($d);

        // Shifts nobody started are marked as missed, as the scheduler would.
        Carbon::setTestNow($until->copy()->addMinutes(5));
        Artisan::call('mauzo:personal-reminders');
    }

    private function selfServiceRequests(Carbon $d): void
    {
        // Self-service requests still waiting for HR.
        $this->at($d->copy()->subDays(2), 18, 30);
        $this->as('super_cashier2', 'MAIN')->try('admin/hr/me/leave', ['leave_type_id' => LeaveType::query()->where('code', 'ANNUAL')->value('id'), 'start_date' => $d->copy()->addDays(10)->toDateString(), 'end_date' => $d->copy()->addDays(14)->toDateString(), 'reason' => 'Kumtembelea mama Tanga.']);
        $this->at($d->copy()->subDay(), 19, 0);
        $this->as('mlinzi', 'MAIN')->try('admin/hr/me/advances', ['amount' => 100000, 'installments' => 1, 'reason' => 'Matibabu ya mtoto.']);
    }

    /** Payments by phone that need someone's attention: waiting, failed, and paid but never used. */
    private function phonePaymentsToday(Carbon $d): void
    {
        $this->at($d, 9, 5);
        $this->as('super_cashier', 'MAIN');
        $this->phonePayment('pos', 12500, '255754555111', null, $d->copy()->setTime(9, 5));
        $this->phonePayment('pos', 6500, '255713777999', null, $d->copy()->setTime(9, 20), 'failed');
        $late = $this->phonePayment('pos', 21000, '255688123456', null, $d->copy()->subDay()->setTime(18, 40), 'cancelled');
        $this->completePhonePayment($late, $d->copy()->subDay()->setTime(18, 52));
        OnlinePayment::query()->whereKey($late->id)->update(['failure_reason' => null]);
    }

    private function notesAndMessages(Carbon $d): void
    {
        // Notes on people's boards.
        $this->at($d->copy()->subDays(3), 16, 0);
        $note = $this->as('meneja', 'MAIN')->post('admin/notes', [
            'title' => 'Kikao na Bakhresa kuhusu bei mpya ya sukari', 'color' => 'yellow', 'pinned' => true,
            'body' => "Bei ya sukari inapanda kuanzia mwezi ujao (+150 kwa kilo).\n- Tuongeze oda ya wiki hii kabla bei haijapanda\n- Rehema aangalie mkopo wetu kwao (siku 30)\n- Hamisi ahakikishe stoo ina nafasi",
            'remind_at' => $d->copy()->addDay()->setTime(9, 0)->toDateTimeString(),
            'shares' => [['user_id' => $this->user('mhasibu')->id, 'access' => 'edit'], ['user_id' => $this->user('stoo')->id, 'access' => 'view']],
        ])['data'];
        $pdf = UploadedFile::fake()->createWithContent('bei-mpya-sukari.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
        app(NoteService::class)->attach(\App\Models\Notes\Note::query()->find($note['id']), $this->user('meneja'), $pdf);
        $this->api->post('admin/notes', ['title' => 'Malengo ya mauzo ya mwezi ujao', 'color' => 'green', 'body' => "Supermarket: 30m\nPharmacy Kariakoo: 12m\nPharmacy Mbezi: 7m\nHardware: 35m\nButchery: 15m\nDrinks: 25m\nTimber: 20m"]);
        $this->as('mhasibu', 'MAIN')->post('admin/notes', ['title' => 'Ritani ya VAT ifikapo tarehe 20', 'color' => 'pink', 'pinned' => true, 'body' => 'Kusanya ankara zote za manunuzi na mauzo ya mwezi uliopita.', 'remind_at' => $d->copy()->addDays(3)->setTime(8, 30)->toDateTimeString()]);
        $this->as('meneja_famasia', 'PHARMA1')->post('admin/notes', ['title' => 'Dawa za kuangalia muda wa matumizi', 'color' => 'blue', 'body' => "Dawa ya kikohozi - batch ya kwanza inaisha wiki mbili\nAmoxicillin\nMbezi wanahitaji mRDT zaidi", 'shares' => [['user_id' => $this->user('meneja_mbezi')->id, 'access' => 'edit']]]);
        $this->as('super_cashier', 'MAIN')->post('admin/notes', ['title' => 'Wateja wa kupigiwa simu', 'color' => 'purple', 'body' => "Juma Hassan - deni\nMama Ntilie - oda ya Ijumaa"]);

        // Announcements and reminders from the management.
        $this->at($d->copy()->subDay(), 17, 30);
        $all = $this->as('meneja', 'MAIN')->post('admin/staff-messages', [
            'kind' => 'announcement', 'priority' => 'high', 'title' => 'Hesabu ya stoo Jumamosi hii',
            'body' => "Wafanyakazi wote wa matawi saba mfike saa 1:30 asubuhi Jumamosi. Tutahesabu stoo ya kila tawi kabla ya kufungua.\nAsanteni.", 'audience' => 'all', 'requires_ack' => true,
        ])['data'];
        $cashiers = \App\Models\Core\Role::query()->where('slug', 'cashier')->value('id');
        $this->api->post('admin/staff-messages', ['kind' => 'announcement', 'priority' => 'normal', 'title' => 'Pharmacy: mizigo ya Mbezi', 'body' => 'Kuanzia wiki hii, mzigo wa Mbezi unatoka Kariakoo kila Jumanne. Mbezi, pokeeni transfer kwenye mfumo siku hiyo hiyo.', 'audience' => 'users', 'user_ids' => array_map(fn ($u) => $this->user($u)->id, ['meneja_famasia', 'famasia_cashier', 'meneja_mbezi', 'mbezi_cashier']), 'requires_ack' => true]);
        $this->api->post('admin/staff-messages', ['kind' => 'reminder', 'priority' => 'normal', 'title' => 'Funga uwakala kabla ya saa 2:30 usiku', 'body' => 'Hesabu pesa taslimu na floti ya kila laini kabla ya kufunga. Tofauti yoyote iandikwe kwenye maelezo.', 'audience' => 'roles', 'role_ids' => [$cashiers], 'requires_ack' => true]);
        $this->as('hrmanager', 'MAIN')->post('admin/staff-messages', ['kind' => 'message', 'priority' => 'normal', 'title' => 'Mishahara ya '.$d->translatedFormat('F'), 'body' => 'Mishahara ya mwezi huu imeandaliwa na inasubiri kuidhinishwa. Itaingia kwenye akaunti zenu tarehe 28.', 'audience' => 'all', 'requires_ack' => false]);
        $this->at($d, 8, 10);
        foreach (['super_cashier' => 'MAIN', 'stoo' => 'MAIN', 'mhasibu' => 'MAIN', 'meneja_famasia' => 'PHARMA1', 'meneja_mbao' => 'TIMBER'] as $username => $branch) {
            $this->as($username, $branch)->try("admin/staff-messages/{$all['id']}/acknowledge", []);
        }
    }
}
