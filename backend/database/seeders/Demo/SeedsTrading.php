<?php

namespace Database\Seeders\Demo;

use App\Models\Crm\Customer;
use App\Models\Finance\ExpenseCategory;
use App\Models\Inventory\Allocation;
use App\Models\Inventory\Transfer;
use App\Models\Marketing\Promotion;
use App\Models\Payments\OnlinePayment;
use App\Models\Purchasing\Purchase;
use App\Models\Sales\Order;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleItem;
use App\Models\Sales\SalesDocument;
use App\Services\Core\NumberGenerator;
use App\Services\Crm\SavingsService;
use Illuminate\Support\Carbon;

/**
 * Day-by-day trading: till sales, purchases, transfers, debts, savings,
 * returns, online orders with deliveries, documents, seller allocations,
 * stock adjustments and expenses.
 */
trait SeedsTrading
{
    use SeedsDailyLife;

    /** @var array<string, int> customer key => savings account id */
    private array $savings = [];

    private const NETWORKS = ['M-Pesa', 'Mixx by Yas', 'Airtel Money', 'HaloPesa'];

    private function seedHistory(): void
    {
        for ($d = $this->start->copy()->addDay(); $d->lt($this->today); $d->addDay()) {
            $this->tradingDay($d->copy());
        }
    }

    private function tradingDay(Carbon $d): void
    {
        $dow = $d->dayOfWeekIso;
        $index = (int) $this->start->diffInDays($d);

        $this->dailyLifeMorning($d, $index);
        if ($index === 2) {
            $this->openSavingsAccounts($d);
        }
        if ($dow === 1) {
            $this->weeklyPurchases($d, array_keys(DemoCatalogue::BRANCHES));
            $this->issueAllocations($d);
        }
        if ($dow === 4) {
            // Meat and fresh food are bought twice a week.
            $this->weeklyPurchases($d, ['BUTCHER', 'MAIN'], ['Meat & Poultry', 'Dairy & Fresh']);
        }
        $this->receiveTransfers($d);
        if ($dow === 2) {
            $this->restockBranches($d);
        }
        if ($index % 11 === 6) {
            $this->urgentPharmacyTransfer($d);
        }
        if ($index === 50) {
            $this->cancelledTransfer($d);
        }
        $this->monthlyExpenses($d);
        $this->progressOrders($d);

        foreach (DemoCatalogue::DAILY_SALES as $branch => [$min, $max]) {
            $count = mt_rand($min, $max) + ($dow >= 6 ? 2 : 0);
            $times = [];
            for ($i = 0; $i < $count; $i++) {
                $times[] = mt_rand(8 * 60 + 30, 19 * 60 + 45);
            }
            sort($times);
            foreach ($times as $minutes) {
                $this->sell($branch, $d->copy()->setTime(intdiv($minutes, 60), $minutes % 60, mt_rand(0, 59)));
            }
        }
        if (in_array($dow, [3, 6], true)) {
            $this->phoneSale($d->copy()->setTime(mt_rand(10, 18), mt_rand(0, 59)));
        }
        if ($this->chance(45)) {
            $this->collectDebts($d);
        }
        if ($dow === 5) {
            $this->savingsDeposits($d);
        }
        if ($this->chance(25)) {
            $this->returnOrCancel($d);
        }
        if ($this->chance(55)) {
            $this->placeShopOrder($d);
        }
        if ($dow === 4) {
            $this->staffOrder($d);
            $this->paySuppliers($d);
        }
        if ($index % 6 === 4) {
            $this->documentsCycle($d);
        }
        $this->progressDocuments($d);
        if ($dow === 6) {
            $this->reconcileAllocations($d);
            $this->stockAdjustments($d);
        }
        $this->dailyExpenses($d);
        $this->dailyLifeEvening($d, $index);
    }

    // ------------------------------------------------------------------ till

    private function cashierFor(string $branch, Carbon $time): string
    {
        $cashiers = DemoCatalogue::BRANCH_PEOPLE[$branch][1];

        return count($cashiers) > 1 && $time->hour >= 15 ? $cashiers[1] : $cashiers[0];
    }

    /** @return array<int, string> customer keys who buy at a branch */
    private function branchCustomers(string $branch): array
    {
        return array_keys(array_filter(DemoCatalogue::CUSTOMERS, fn ($c) => in_array($branch, $c[10], true)));
    }

    /** How much one retail customer takes of one product at this kind of shop. */
    private function retailQty(string $branch, bool $decimal): float
    {
        return match ($branch) {
            'PHARMA1', 'PHARMA2' => $this->pick([1, 1, 1, 2, 2, 3]),
            'BUTCHER' => $decimal ? $this->pick([0.5, 1, 1, 1.5, 2, 3]) : $this->pick([1, 1, 2]),
            'DRINKS' => $this->pick([1, 2, 3, 6, 12, 24]),
            'HARDWARE' => $decimal ? $this->pick([1, 2, 3, 5, 10, 20]) : $this->pick([1, 1, 2, 3, 5, 10]),
            'TIMBER' => $this->pick([1, 2, 4, 6, 10, 12, 20]),
            default => $decimal ? $this->pick([0.5, 1, 1, 1.5, 2, 3]) : $this->pick([1, 1, 1, 2, 2, 3, 4, 6]),
        };
    }

    private function wholesaleQty(string $branch, bool $decimal): float
    {
        return match ($branch) {
            'PHARMA1', 'PHARMA2' => mt_rand(10, 30),
            'BUTCHER' => $decimal ? mt_rand(8, 30) : mt_rand(10, 25),
            'TIMBER' => mt_rand(20, 80),
            'HARDWARE' => $decimal ? mt_rand(10, 50) : mt_rand(10, 40),
            default => $decimal ? mt_rand(5, 30) : mt_rand(6, 40),
        };
    }

    /** A basket of what is in stock at the branch (online: only what the shop shows). */
    private function basket(string $branch, bool $wholesale, bool $online = false): array
    {
        $skus = array_values(array_filter($this->branchSkus[$branch] ?? [], fn ($sku) => ! $online
            || (! str_contains($this->products[$sku]['flags'], 'o') && DemoCatalogue::CATEGORIES[$this->products[$sku]['category']][1])));
        if (! $skus) {
            return [];
        }
        $lines = [];
        $wanted = $wholesale ? mt_rand(2, 4) : mt_rand(1, 4);
        $tries = 0;
        while (count($lines) < $wanted && $tries++ < 12) {
            $sku = $this->pick($skus);
            if (isset($lines[$sku])) {
                continue;
            }
            $p = $this->products[$sku];
            $decimal = str_contains($p['flags'], 'd');
            $unit = null;
            $factor = 1.0;
            if ($wholesale && $p['packs'] && $this->chance(60)) {
                $pack = $p['packs'][0];
                $unit = $pack['id'];
                $factor = $pack['factor'];
                $qty = mt_rand(1, 4);
            } else {
                $qty = $wholesale ? $this->wholesaleQty($branch, $decimal) : $this->retailQty($branch, $decimal);
            }
            if ($this->onHand($sku, $branch) < $qty * $factor) {
                continue;
            }
            $lines[$sku] = array_filter(['product_id' => $p['id'], 'product_unit_id' => $unit, 'quantity' => $qty]);
        }
        // A service with the goods now and then (cutting, mincing, planing, a BP check, delivery).
        if (! $online && ! empty($this->branchServices[$branch]) && $this->chance($lines ? 15 : 0)) {
            $service = $this->pick($this->branchServices[$branch]);
            $lines[$service] = ['product_id' => $this->products[$service]['id'], 'quantity' => match ($service) {
                'SVC-RAN' => mt_rand(2, 12), 'SVC-SAGA' => $this->pick([1, 1.5, 2]), default => 1,
            }];
        }

        return array_values($lines);
    }

    private function sell(string $branch, Carbon $time): ?array
    {
        $this->api->at($time);
        $customerKey = null;
        $regulars = $this->branchCustomers($branch);
        if ($regulars && $this->chance(30)) {
            $customerKey = $this->pick($regulars);
        }
        $customer = $customerKey ? DemoCatalogue::CUSTOMERS[$customerKey] : null;
        $wholesale = $customer && in_array($customer[2], ['wholesale', 'institution'], true);
        $items = $this->basket($branch, $wholesale);
        if (! $items && in_array($branch, ['PHARMA1', 'PHARMA2'], true) && $this->chance(30)) {
            // Someone only comes for a blood pressure or malaria test.
            $items = [['product_id' => $this->products[$this->pick(['SVC-BP', 'SVC-MAL'])]['id'], 'quantity' => 1]];
        }
        if (! $items) {
            return null;
        }
        // Wholesale sales are entered by the branch manager, who may sell at wholesale prices.
        $seller = $wholesale ? $this->manager($branch) : $this->cashierFor($branch, $time);
        $this->as($seller, $branch);

        $body = ['items' => $items, 'customer_id' => $customerKey ? $this->customers[$customerKey] : null];
        if (! $customerKey && $this->chance(25)) {
            $body['customer_name'] = $this->pick(['Mama Asha', 'Baba Juma', 'Fundi Ali', 'Mwalimu Rose', 'Dada Neema', 'Kaka Sele', 'Mzee Shabani']);
        }
        if ($promotion = $this->activePromotion($time, $customerKey, $items)) {
            $body['promotion_id'] = $promotion;
        }
        $quote = $this->api->try('admin/pos/quote', $body);
        if (! $quote && isset($body['promotion_id'])) {
            unset($body['promotion_id']);
            $quote = $this->api->try('admin/pos/quote', $body);
        }
        if (! $quote) {
            return null;
        }
        if (empty($quote['data']['promotion'])) {
            unset($body['promotion_id']);
        }
        $total = (float) $quote['data']['total'];
        if ($total <= 0) {
            return null;
        }

        $creditLimit = $customer[3] ?? 0;
        $savingsBalance = $customerKey && isset($this->savings[$customerKey]) ? app(SavingsService::class)->availableBalance(Customer::query()->find($this->customers[$customerKey])) : 0;
        $headroom = $creditLimit > 0 ? $creditLimit - (float) Sale::query()->where('customer_id', $this->customers[$customerKey])->where('status', 'completed')->sum('balance') : 0;
        if ($headroom >= $total && $this->chance($wholesale ? 45 : 22)) {
            // Credit sale: nothing or part paid now, the rest by a date.
            $paidNow = $this->chance(40) ? round($total * mt_rand(20, 50) / 100, -2) : 0;
            $body['payments'] = $paidNow > 0 ? [['method' => 'cash', 'amount' => $paidNow]] : [];
            $body['due_date'] = $time->copy()->addDays($this->pick([7, 14, 14, 21, 30]))->toDateString();
        } elseif ($savingsBalance > 0 && $this->chance(45)) {
            $rest = max(0, round($total - $savingsBalance, 2));
            $body['use_savings'] = true;
            $body['payments'] = $rest > 0 ? [['method' => 'cash', 'amount' => $rest]] : [];
        } else {
            $body['payments'] = $this->payments($total, $customer[2] ?? 'retail');
        }
        if ($this->chance(8) && ($customerKey && $customer[1])) {
            $body['receipt_channels'] = ['sms'];
        }

        $sale = $this->api->try('admin/pos/checkout', $body);

        return $sale['data'] ?? null;
    }

    /** How a customer pays a total at the till. */
    private function payments(float $total, string $type): array
    {
        $roll = mt_rand(1, 100);
        if (($type === 'institution' || $total > 1000000) && $roll <= 50) {
            return [['method' => 'bank', 'amount' => $total, 'provider' => $this->pick(['NMB', 'CRDB', 'NBC']), 'reference' => $this->ref('TRF')]];
        }
        if ($roll <= 55) {
            // Cash, usually with change.
            $step = $total > 20000 ? 5000 : ($total > 5000 ? 1000 : 500);
            $given = $this->chance(60) ? ceil($total / $step) * $step : $total;

            return [['method' => 'cash', 'amount' => $given]];
        }
        if ($roll <= 80) {
            return [['method' => 'mobile_money', 'amount' => $total, 'provider' => $this->pick(self::NETWORKS), 'reference' => $this->ref('MP')]];
        }
        if ($roll <= 87) {
            return [['method' => 'lipa_namba', 'amount' => $total, 'provider' => $this->pick(self::NETWORKS), 'reference' => $this->ref('LN')]];
        }
        if ($roll <= 91 && $total >= 10000) {
            return [['method' => 'card', 'amount' => $total, 'provider' => 'Visa', 'reference' => $this->ref('POS')]];
        }
        if ($total >= 4000) {
            $cash = round($total * mt_rand(30, 70) / 100, -2);

            return [
                ['method' => 'cash', 'amount' => $cash],
                ['method' => 'mobile_money', 'amount' => round($total - $cash, 2), 'provider' => $this->pick(self::NETWORKS), 'reference' => $this->ref('MP')],
            ];
        }

        return [['method' => 'cash', 'amount' => $total]];
    }

    /** A running promotion that fits the customer and the goods in the basket. */
    private function activePromotion(Carbon $time, ?string $customerKey, array $items): ?int
    {
        if (! $this->chance(70)) {
            return null;
        }
        $categories = \App\Models\Catalog\Product::query()->whereIn('id', array_column($items, 'product_id'))->pluck('category_id')->all();
        $promotions = Promotion::query()->where('is_active', true)
            ->whereDate('starts_on', '<=', $time->toDateString())->whereDate('ends_on', '>=', $time->toDateString())
            ->get(['id', 'audience', 'applies_to', 'category_ids', 'customer_ids']);
        $customerId = $customerKey ? $this->customers[$customerKey] : null;
        $promotions = $promotions->filter(fn ($p) => ($p->audience === 'all' || ($p->audience === 'customers' && in_array($customerId, $p->customer_ids ?? [], true)))
            && ($p->applies_to !== 'categories' || array_intersect($p->category_ids ?? [], $categories)));

        return $promotions->isEmpty() ? null : $promotions->random()->id;
    }

    /**
     * A customer pays by phone: the cashier sends the request, the customer
     * confirms with their PIN (the gateway's signed webhook), then the sale is
     * completed with that payment.
     */
    private function phoneSale(Carbon $time): void
    {
        $branch = $this->pick(['MAIN', 'MAIN', 'PHARMA1', 'HARDWARE', 'DRINKS', 'BUTCHER']);
        $this->api->at($time);
        $this->as($this->cashierFor($branch, $time), $branch);
        $items = $this->basket($branch, false);
        $quote = $items ? $this->api->try('admin/pos/quote', ['items' => $items]) : null;
        $total = (float) ($quote['data']['total'] ?? 0);
        if ($total < 500 || abs($total - round($total)) > 0.001) {
            return;
        }
        $payment = $this->phonePayment('pos', $total, '2557'.mt_rand(10000000, 99999999), null, $time, 'pending', $branch);
        $this->at($time, $time->hour, min(59, $time->minute + 1));
        $this->completePhonePayment($payment, $time->copy()->addSeconds(40));
        $this->api->try('admin/pos/checkout', ['items' => $items, 'payments' => [['method' => 'online', 'amount' => $total, 'online_payment_id' => $payment->id]]]);
    }

    /** A payment request as the gateway would have recorded it (no real gateway while seeding). */
    private function phonePayment(string $purpose, float $amount, string $phone, ?Order $order, Carbon $time, string $status = 'pending', string $branch = 'MAIN'): OnlinePayment
    {
        $number = app(NumberGenerator::class)->next('online_payment');

        return OnlinePayment::query()->create([
            'number' => $number, 'purpose' => $purpose, 'branch_id' => $order?->branch_id ?? $this->branches[$branch],
            'order_id' => $order?->id, 'customer_id' => $order?->customer_id,
            'amount' => $amount, 'phone' => $phone, 'payer_name' => $order?->customer?->name,
            'status' => $status, 'reference' => 'demo-'.strtolower($number), 'idempotency_key' => 'mz-'.$number,
            'expires_at' => $time->copy()->addHours(4), 'last_checked_at' => $time,
            'requested_by' => $purpose === 'pos' ? $this->api->user()?->id : null,
            'failure_reason' => $status === 'failed' ? 'Transaction declined by user' : null,
            'created_at' => $time, 'updated_at' => $time,
        ]);
    }

    /** The gateway's signed "payment.completed" webhook. */
    private function completePhonePayment(OnlinePayment $payment, Carbon $time): void
    {
        $this->api->at($time);
        $secret = (string) config('mauzo.snippe.webhook_secret');
        config(['mauzo.snippe.webhook_secret' => $secret ?: 'demo-webhook-secret']);
        $body = json_encode(['id' => 'evt_'.$payment->number, 'type' => 'payment.completed', 'api_version' => '2026-01-25', 'data' => [
            'reference' => $payment->reference, 'external_reference' => 'MP'.strtoupper(substr(md5($payment->number), 0, 10)), 'status' => 'completed',
            'amount' => ['value' => (int) round((float) $payment->amount), 'currency' => 'TZS'],
            'settlement' => ['fees' => ['value' => (int) max(50, round((float) $payment->amount * 0.015)), 'currency' => 'TZS']],
            'channel' => ['type' => 'mobile_money', 'provider' => $this->pick(['mpesa', 'mpesa', 'airtel', 'mixx', 'halotel'])],
            'completed_at' => $time->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]]);
        // The gateway signs with the real clock (the system accepts five minutes of drift).
        $ts = (string) time();
        app(\App\Services\Payments\OnlinePaymentService::class)->handleWebhook($body, [
            'timestamp' => $ts, 'signature' => hash_hmac('sha256', $ts.'.'.$body, (string) config('mauzo.snippe.webhook_secret')), 'event' => 'payment.completed',
        ]);
        config(['mauzo.snippe.webhook_secret' => $secret]);
    }

    // ------------------------------------------------------------------ debts and savings

    private function collectDebts(Carbon $d): void
    {
        $open = Sale::query()->where('balance', '>', 0)->where('status', 'completed')
            ->whereNotNull('customer_id')->whereDate('sold_at', '<', $d->copy()->subDays(2)->toDateString())
            ->orderBy('sold_at')->limit(6)->get(['id', 'branch_id', 'balance', 'customer_id']);
        foreach ($open as $sale) {
            if ($this->chance(25)) {
                continue; // some customers are late
            }
            $branch = array_search($sale->branch_id, $this->branches, true) ?: 'MAIN';
            $time = $this->at($d, mt_rand(10, 17), mt_rand(0, 59));
            $this->as($this->cashierFor($branch, $time), $branch);
            $full = $this->chance(55) || (float) $sale->balance <= 2000;
            $method = $this->pick(['cash', 'cash', 'mobile_money']);
            $this->api->try("admin/credit-sales/{$sale->id}/payments", array_filter([
                'mode' => $full ? 'full' : 'partial',
                'amount' => $full ? null : max(1000, round((float) $sale->balance * mt_rand(25, 60) / 100, -2)),
                'method' => $method,
                'provider' => $method === 'mobile_money' ? $this->pick(self::NETWORKS) : null,
                'reference' => $method === 'mobile_money' ? $this->ref('MP') : null,
                'notes' => $full ? 'Amelipa deni lote.' : 'Malipo ya sehemu.',
            ]));
        }
    }

    private function openSavingsAccounts(Carbon $d): void
    {
        foreach (DemoCatalogue::CUSTOMERS as $key => $c) {
            if (! $c[9]) {
                continue;
            }
            $this->at($d, mt_rand(9, 16), mt_rand(0, 59));
            $this->as($this->cashierFor($c[4], Carbon::now()), $c[4]);
            $account = $this->api->post('admin/savings', ['customer_id' => $this->customers[$key], 'initial_deposit' => $this->pick([20000, 50000, 100000]), 'method' => 'cash', 'notes' => 'Akaunti ya akiba ya kununulia bidhaa.']);
            $this->savings[$key] = $account['data']['id'];
        }
    }

    private function savingsDeposits(Carbon $d): void
    {
        foreach ($this->savings as $key => $account) {
            if (! $this->chance(70)) {
                continue;
            }
            $branch = DemoCatalogue::CUSTOMERS[$key][4];
            $this->at($d, mt_rand(9, 18), mt_rand(0, 59));
            $this->as($this->cashierFor($branch, Carbon::now()), $branch);
            $mobile = $this->chance(40);
            $this->api->try("admin/savings/{$account}/deposits", array_filter([
                'amount' => $this->pick([5000, 10000, 10000, 20000, 30000]), 'method' => $mobile ? 'mobile_money' : 'cash',
                'provider' => $mobile ? $this->pick(self::NETWORKS) : null, 'reference' => $mobile ? $this->ref('MP') : null,
            ]));
        }
        if ($d->day <= 7 && $this->savings) {
            // Once a month someone takes money out.
            $key = $this->pick(array_keys($this->savings));
            $this->at($d, 15, 30);
            $saver = Customer::query()->find($this->customers[$key]);
            if (app(SavingsService::class)->availableBalance($saver) >= 10000) {
                $this->as('mhasibu', DemoCatalogue::CUSTOMERS[$key][4]);
                $this->api->try("admin/savings/{$this->savings[$key]}/withdrawals", ['amount' => 10000, 'method' => 'cash', 'notes' => 'Ametoa sehemu ya akiba kwa matumizi ya nyumbani.']);
            }
        }
    }

    // ------------------------------------------------------------------ returns and cancellations

    private function returnOrCancel(Carbon $d): void
    {
        $branch = $this->pick(['MAIN', 'MAIN', 'PHARMA1', 'HARDWARE', 'DRINKS', 'BUTCHER', 'TIMBER', 'PHARMA2']);
        $sale = Sale::query()->where('branch_id', $this->branches[$branch])->where('status', 'completed')->where('payment_status', 'paid')
            ->whereDate('sold_at', $d->toDateString())->whereDoesntHave('payments', fn ($q) => $q->whereIn('method', ['online', 'savings']))
            ->inRandomOrder()->first();
        if (! $sale) {
            return;
        }
        $this->at($d, 20, mt_rand(0, 20));
        $this->as($this->manager($branch), $branch);
        if ($this->chance(30)) {
            $this->api->try("admin/sales/{$sale->id}/cancel", ['reason' => $this->pick(['Mauzo yaliingizwa mara mbili.', 'Mteja amebadilisha mawazo kabla ya kuondoka.', 'Bei iliingizwa vibaya.'])]);

            return;
        }
        $item = SaleItem::query()->where('sale_id', $sale->id)->whereHas('product', fn ($q) => $q->where('is_service', false))->first();
        if (! $item) {
            return;
        }
        $qty = min((float) $item->quantity, 1); // base units
        $reasons = match ($branch) {
            'PHARMA1', 'PHARMA2' => ['Daktari amebadilisha dawa.', 'Kifungashio kilikuwa kimepasuka.'],
            'HARDWARE', 'TIMBER' => ['Fundi amepima upya, kimezidi.', 'Kipande kilikuwa na ufa.', 'Saizi si sahihi.'],
            'BUTCHER' => ['Nyama haikuwa na ubora uliotakiwa.'],
            default => ['Bidhaa ilikuwa imeharibika.', 'Mteja alinunua kwa makosa.', 'Kifungashio kilikuwa kimepasuka.'],
        };
        $this->api->try("admin/sales/{$sale->id}/returns", [
            'items' => [['sale_item_id' => $item->id, 'quantity' => $qty, 'condition' => $branch !== 'BUTCHER' && $this->chance(70) ? 'restock' : 'damaged']],
            'reason' => $this->pick($reasons),
            'refund_method' => $this->pick(['cash', 'cash', 'mobile_money']),
        ]);
    }

    // ------------------------------------------------------------------ purchases and suppliers

    /**
     * Each branch buys its own goods when they run low, one purchase per supplier
     * (the pharmacy in Kariakoo also buys for Mbezi, the drinks depot for the supermarket).
     *
     * @param  array<int, string>  $branches
     * @param  array<int, string>|null  $categories  only these categories
     */
    private function weeklyPurchases(Carbon $d, array $branches, ?array $categories = null): void
    {
        $hour = 8;
        foreach ($branches as $branch) {
            $bySupplier = [];
            foreach ($this->products as $sku => $p) {
                if ($p['home'] !== $branch || ! $p['supplier'] || $p['opening'] <= 0 || ($categories && ! in_array($p['category'], $categories, true))) {
                    continue;
                }
                $level = $this->onHand($sku, $branch);
                if ($level < $p['opening'] * 0.6) {
                    $qty = $p['opening'] - $level;
                    $bySupplier[$p['supplier']][$sku] = str_contains($p['flags'], 'd') ? round($qty, 1) : ceil($qty);
                }
            }
            foreach ($bySupplier as $supplier => $lines) {
                $this->purchase($d, $branch, $supplier, $lines, $hour);
                $hour = min(16, $hour + 1);
            }
        }
    }

    /** @param array<string, float> $lines sku => quantity */
    private function purchase(Carbon $d, string $branch, string $supplier, array $lines, int $hour): void
    {
        $terms = DemoCatalogue::SUPPLIERS[$supplier][5];
        $time = $this->at($d, $hour, mt_rand(0, 50));
        $items = [];
        $value = 0;
        foreach ($lines as $sku => $qty) {
            $p = $this->products[$sku];
            $cost = round($p['cost'] * (1 + mt_rand(-3, 4) / 100), -1);
            $value += $cost * $qty;
            $items[] = array_filter([
                'product_id' => $p['id'], 'quantity' => $qty, 'unit_cost' => $cost,
                'batch_no' => str_contains($p['flags'], 'e') ? 'B'.$time->format('ymd').'-'.mt_rand(10, 99) : null,
                // Medicine lasts years, milk and sausages weeks.
                'expiry_date' => str_contains($p['flags'], 'e') ? $time->copy()->addDays(match ($p['category']) {
                    'Medicines', 'Health & First Aid' => mt_rand(300, 700), 'Dairy & Fresh' => mt_rand(20, 45), 'Meat & Poultry' => mt_rand(20, 30), default => mt_rand(120, 300),
                })->toDateString() : null,
            ]);
        }
        $transport = match (true) {
            $branch === 'TIMBER' => 350000, // a lorry from Mafinga
            $value > 2000000 => 60000,
            $value > 500000 => 25000,
            default => 0,
        };
        $credit = $terms > 0 && $this->chance(70);
        $this->as($credit ? 'meneja' : 'stoo', $branch);
        $this->api->try('admin/purchases', array_filter([
            'supplier_id' => $this->suppliers[$supplier],
            'supplier_invoice_no' => strtoupper(substr($supplier, 0, 3)).'-'.mt_rand(10000, 99999),
            'purchase_date' => $time->toDateString(),
            'items' => $items,
            'transport_cost' => $transport ?: null,
            'unloading_cost' => $transport ? ($branch === 'TIMBER' ? 40000 : 10000) : null,
            'cost_allocation' => 'value',
            'amount_paid' => $credit ? ($this->chance(30) ? round($value * 0.3, -3) : 0) : round($value, 2),
            'payment_method' => $credit ? 'bank' : $this->pick(['cash', 'bank', 'mobile_money']),
            'payment_reference' => $this->ref('PAY'),
            'due_date' => $credit ? $time->copy()->addDays($terms)->toDateString() : null,
            'reminder_channels' => $credit && $this->chance(50) ? ['sms', 'email'] : null,
            'notes' => ($credit ? "Mkopo wa siku {$terms}. " : '').'Mzigo wa '.DemoCatalogue::BRANCHES[$branch][0].'.',
        ], fn ($v) => $v !== null));
    }

    /** Thursday: the accountant pays suppliers whose bills fall due in the next days (some stay late). */
    private function paySuppliers(Carbon $d): void
    {
        $due = Purchase::query()->where('balance', '>', 0)->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $d->copy()->addDays(5)->toDateString())->orderBy('due_date')->get(['id', 'supplier_id', 'balance', 'branch_id']);
        foreach ($due as $purchase) {
            if ($this->chance(25)) {
                continue;
            }
            $this->at($d, mt_rand(11, 15), mt_rand(0, 59));
            $this->as('mhasibu', array_search($purchase->branch_id, $this->branches, true) ?: 'MAIN');
            $this->api->try("admin/suppliers/{$purchase->supplier_id}/payments", [
                'amount' => $this->chance(80) ? (float) $purchase->balance : round((float) $purchase->balance / 2, -3),
                'method' => $this->pick(['bank', 'bank', 'mobile_money', 'cheque']), 'reference' => $this->ref('SP'),
                'purchase_id' => $purchase->id, 'paid_on' => $d->toDateString(),
            ]);
        }
    }

    // ------------------------------------------------------------------ transfers between branches

    /** Tuesday: Pharmacy Kariakoo restocks Pharmacy Mbezi; the drinks depot restocks the supermarket. */
    private function restockBranches(Carbon $d): void
    {
        foreach (DemoCatalogue::SUPPLIED_BY_TRANSFER as $to => [$from, $type, $share]) {
            $items = [];
            foreach ($this->transferSkus($to) as $sku) {
                $p = $this->products[$sku];
                $target = $p['opening'] * $share;
                $have = $this->onHand($sku, $to);
                if ($have < $target * 0.6) {
                    $qty = min($target - $have, $this->onHand($sku, $from) * 0.5);
                    $qty = str_contains($p['flags'], 'd') ? round($qty, 1) : floor($qty);
                    if ($qty > 0) {
                        $items[] = ['product_id' => $p['id'], 'quantity' => $qty];
                    }
                }
            }
            if (! $items) {
                continue;
            }
            $this->at($d, 10, mt_rand(0, 40));
            $this->as($this->manager($from), $from)->try('admin/transfers', array_filter([
                'to_branch_id' => $this->branches[$to], 'type' => $type, 'items' => $items,
                'due_date' => $type === 'credit' ? $d->copy()->addDays(14)->toDateString() : null,
                'notes' => 'Kujaza stoo ya '.DemoCatalogue::BRANCHES[$to][0],
            ]));
        }
    }

    /** Pharmacy Kariakoo runs short of a medicine and borrows it from Mbezi for a few days. */
    private function urgentPharmacyTransfer(Carbon $d): void
    {
        $candidates = array_filter($this->transferSkus('PHARMA2'), fn ($sku) => $this->onHand($sku, 'PHARMA2') > 40);
        if (! $candidates) {
            return;
        }
        $sku = $this->pick(array_values($candidates));
        $this->at($d, 11, mt_rand(0, 50));
        $this->as('meneja_mbezi', 'PHARMA2')->try('admin/transfers', [
            'to_branch_id' => $this->branches['PHARMA1'], 'type' => 'free',
            'items' => [['product_id' => $this->products[$sku]['id'], 'quantity' => 20]],
            'notes' => 'Dharura: Kariakoo wameishiwa '.$this->products[$sku]['name'].'; tutarudishiwa kwenye mzigo ujao.',
        ]);
    }

    /** A transfer packed by mistake and cancelled before it left (the stock goes back). */
    private function cancelledTransfer(Carbon $d): void
    {
        $items = [];
        foreach (['VIT-C' => 5, 'SAN-500' => 10, 'GLO-100' => 3, 'PLA-10' => 10] as $sku => $qty) {
            if ($this->onHand($sku, 'PHARMA1') > $qty) {
                $items[] = ['product_id' => $this->products[$sku]['id'], 'quantity' => $qty];
            }
        }
        if (! $items) {
            return;
        }
        $this->at($d, 9, 30);
        $transfer = $this->as('meneja_famasia', 'PHARMA1')->try('admin/transfers', [
            'to_branch_id' => $this->branches['PHARMA2'], 'type' => 'free', 'items' => $items,
            'notes' => 'Vitamin C, sanitizer na plasta kwa Mbezi.',
        ]);
        if ($transfer) {
            $this->at($d, 9, 55);
            $this->api->try("admin/transfers/{$transfer['data']['id']}/cancel", ['reason' => 'Mbezi bado wana mzigo wa kutosha; tumesitisha.']);
        }
    }

    /** Transfers on the road arrive the next day (one in ten with a shortage); credit transfers are settled after two weeks. */
    private function receiveTransfers(Carbon $d): void
    {
        $arriving = Transfer::query()->where('status', 'in_transit')->whereDate('created_at', '<', $d->toDateString())->with('items')->get();
        foreach ($arriving as $transfer) {
            $branch = array_search($transfer->to_branch_id, $this->branches, true);
            $this->at($d, 9, mt_rand(0, 50));
            $lines = [];
            if ($this->chance(10) && $transfer->items->count() > 1) {
                // One box arrived damaged: received short, the difference is recorded on the transfer.
                foreach ($transfer->items as $i => $item) {
                    $sent = (float) $item->quantity;
                    $lines[] = ['transfer_item_id' => $item->id, 'received_quantity' => $i === 0 ? max(0, $sent - min(2, $sent)) : $sent];
                }
            }
            $this->as($this->manager($branch), $branch)->try("admin/transfers/{$transfer->id}/receive", array_filter([
                'items' => $lines ?: null,
                'receiving_costs' => match ($branch) { 'PHARMA2' => 15000, 'PHARMA1' => 5000, default => 8000 },
                'notes' => $lines ? 'Boksi moja limefika limeharibika; limepunguzwa.' : 'Mzigo umefika salama.',
            ]));
        }
        $owing = Transfer::query()->where('type', 'credit')->where('status', 'received')->where('payment_status', 'unpaid')
            ->whereDate('created_at', '<', $d->copy()->subDays(14)->toDateString())->get(['id']);
        foreach ($owing as $transfer) {
            $this->at($d, 11, 0);
            $this->as('mhasibu', 'MAIN');
            $this->api->try("admin/transfers/{$transfer->id}/mark-paid", []);
        }
    }

    // ------------------------------------------------------------------ sellers in the field

    /** Monday: drinks for Daudi from the depot, household goods for Saidi from the supermarket. */
    private function issueAllocations(Carbon $d): void
    {
        foreach (['muuzaji_daudi' => ['DRINKS', ['MAJ-500', 'SOD-500', 'ENE-250', 'MAJ-1.5']], 'muuzaji_saidi' => ['MAIN', ['SAB-MIL', 'CHU-1KG', 'MKA-BOX', 'SUK-1KG']]] as $seller => [$branch, $skus]) {
            $items = [];
            foreach ($skus as $sku) {
                $qty = $branch === 'DRINKS' ? mt_rand(48, 120) : mt_rand(12, 36);
                if ($this->onHand($sku, $branch) > $qty + 50) {
                    $items[] = ['product_id' => $this->products[$sku]['id'], 'quantity' => $qty, 'unit_price' => $this->products[$sku]['price']];
                }
            }
            if ($items) {
                $this->at($d, 7, mt_rand(30, 55));
                $this->as($this->manager($branch), $branch)->try('admin/allocations', ['seller_id' => $this->user($seller)->id, 'items' => $items, 'notes' => 'Mzigo wa wiki kwa muuzaji wa mtaani.']);
            }
        }
    }

    private function reconcileAllocations(Carbon $d): void
    {
        foreach (Allocation::query()->where('status', 'open')->with('items')->get() as $allocation) {
            $lines = [];
            $due = 0;
            foreach ($allocation->items as $item) {
                $returned = floor((float) $item->quantity * mt_rand(5, 30) / 100);
                $damaged = $this->chance(15) ? 1 : 0;
                $lines[] = ['allocation_item_id' => $item->id, 'quantity_returned' => $returned, 'quantity_damaged' => $damaged];
                $due += ((float) $item->quantity - $returned - $damaged) * (float) $item->unit_price;
            }
            $short = $this->chance(25) ? $this->pick([500, 1000, 2000]) : 0;
            $branch = array_search($allocation->branch_id, $this->branches, true) ?: 'MAIN';
            $this->at($d, 18, mt_rand(15, 50));
            $this->as($this->manager($branch), $branch)->try("admin/allocations/{$allocation->id}/reconcile", [
                'items' => $lines, 'amount_received' => max(0, $due - $short), 'payment_method' => $this->pick(['cash', 'mobile_money']),
                'payment_reference' => $this->ref('AL'), 'notes' => $short ? 'Amepungukiwa kidogo; atalipa wiki ijayo.' : 'Hesabu imekamilika.',
            ]);
        }
    }

    // ------------------------------------------------------------------ stock corrections

    /** Saturday evening: breakages, spoilage and meat loss; on the last Saturday a count of a few items. */
    private function stockAdjustments(Carbon $d): void
    {
        $losses = [
            'MAIN' => [['MAY-30', 'damage', 1, 'Trei moja ya mayai ilianguka na kuvunjika.'], ['MAZ-500', 'expired', 3, 'Maziwa yamepita muda wa matumizi.']],
            'BUTCHER' => [['NYA-MCH', 'damage', 1.5, 'Upotevu wa kukata na kukauka kwa nyama wiki hii.'], ['MIF-KG', 'damage', 2, 'Mifupa iliyoharibika baada ya umeme kukatika.']],
            'DRINKS' => [['SOD-500', 'damage', 3, 'Chupa tatu zimevunjika wakati wa kupakua.'], ['BIA-SAF', 'damage', 2, 'Chupa mbili za bia zimevunjika stoo.']],
            'PHARMA1' => [['PLA-10', 'damage', 1, 'Box limeloa maji.']],
            'HARDWARE' => [['CEM-50', 'damage', 1, 'Mfuko mmoja wa saruji umeganda kwa unyevu.']],
            'TIMBER' => [['MBA-2X4', 'damage', 2, 'Mbao mbili zimepasuka wakati wa kupanga.']],
        ];
        foreach ($losses as $branch => $lines) {
            $this->at($d, 19, mt_rand(30, 59));
            foreach ($lines as [$sku, $type, $qty, $reason]) {
                if ($this->chance(45) && $this->onHand($sku, $branch) > $qty) {
                    $this->as($this->manager($branch), $branch)->try('admin/stock/adjustments', ['product_id' => $this->products[$sku]['id'], 'type' => $type, 'quantity' => $qty, 'reason' => $reason]);
                }
            }
        }
        if ($d->copy()->addDays(7)->month !== $d->month) {
            // Last Saturday of the month: stock count of a few items, a small gain or loss each.
            foreach (['MAIN' => ['MCH-5KG', 'SUK-1KG'], 'HARDWARE' => ['MSU-3', 'NON-12'], 'TIMBER' => ['MBA-2X2', 'NGU-POL'], 'PHARMA2' => ['PAR-500']] as $branch => $skus) {
                foreach ($skus as $sku) {
                    $counted = $this->onHand($sku, $branch) + $this->pick([-2, -1, 1]);
                    $this->as('stoo', $branch)->try('admin/stock/adjustments', ['product_id' => $this->products[$sku]['id'], 'type' => 'count', 'quantity' => max(0, $counted), 'reason' => 'Hesabu ya stoo ya mwisho wa mwezi.']);
                }
            }
        }
    }

    // ------------------------------------------------------------------ expenses

    private function monthlyExpenses(Carbon $d): void
    {
        $plan = [
            1 => [['Rent', 'Kodi ya pango', ['MAIN' => 1500000, 'PHARMA1' => 900000, 'PHARMA2' => 600000, 'HARDWARE' => 800000, 'BUTCHER' => 500000, 'DRINKS' => 1000000, 'TIMBER' => 700000], 'bank']],
            10 => [['Electricity', 'LUKU umeme', ['MAIN' => 350000, 'PHARMA1' => 90000, 'PHARMA2' => 70000, 'HARDWARE' => 60000, 'BUTCHER' => 420000, 'DRINKS' => 150000, 'TIMBER' => 380000], 'mobile_money']],
            12 => [
                ['Water', 'Bili ya maji DAWASA', ['MAIN' => 45000, 'BUTCHER' => 60000, 'PHARMA1' => 20000, 'PHARMA2' => 15000], 'mobile_money'],
                ['Taxes & licences', 'Ada ya leseni ya biashara (Halmashauri)', ['MAIN' => 60000, 'HARDWARE' => 50000, 'DRINKS' => 80000, 'TIMBER' => 120000], 'bank'],
                ['Taxes & licences', 'Ada ya Baraza la Famasia na TMDA', ['PHARMA1' => 150000, 'PHARMA2' => 150000], 'bank'],
                ['Taxes & licences', 'Kibali cha afya ya mifugo (machinjio)', ['BUTCHER' => 40000], 'cash'],
            ],
            15 => [['Marketing', 'Matangazo redio na mitandao', ['MAIN' => 250000, 'DRINKS' => 120000], 'mobile_money'], ['Bank charges', 'Makato ya benki', ['MAIN' => 28500], 'bank']],
            20 => [['Office supplies', 'Karatasi za risiti na stationery', ['MAIN' => 85000, 'PHARMA1' => 30000, 'PHARMA2' => 25000, 'HARDWARE' => 25000, 'BUTCHER' => 20000, 'DRINKS' => 30000, 'TIMBER' => 25000], 'cash']],
        ];
        foreach ($plan[$d->day] ?? [] as [$category, $title, $byBranch, $method]) {
            foreach ($byBranch as $branch => $amount) {
                $this->expense($d, $branch, $category, $title.' — '.$d->translatedFormat('F Y'), $amount, $method);
            }
        }
    }

    private function dailyExpenses(Carbon $d): void
    {
        if ($this->chance(35)) {
            $branch = $this->pick(['MAIN', 'HARDWARE', 'TIMBER', 'BUTCHER']);
            $this->expense($d, $branch, 'Transport', $this->pick(['Usafiri wa mzigo', 'Bajaji ya kupeleka oda', 'Nauli ya mfanyakazi kazini', 'Kukodi kirikuu']), $this->pick([5000, 8000, 10000, 15000, 25000]), 'cash');
        }
        if (in_array($d->dayOfWeekIso, [2, 5], true)) {
            $this->expense($d, 'MAIN', 'Fuel', 'Mafuta ya pikipiki ya delivery', $this->pick([15000, 20000]), 'cash');
            $this->expense($d, 'DRINKS', 'Fuel', 'Dizeli ya gari la kusambaza vinywaji', $this->pick([60000, 80000, 100000]), 'cash');
        }
        if ($this->chance(7)) {
            $branch = $this->pick(array_keys(DemoCatalogue::BRANCHES));
            $what = match ($branch) {
                'BUTCHER' => 'Kutengeneza friza ya nyama',
                'TIMBER' => 'Kunoa msumeno wa mashine',
                'PHARMA1', 'PHARMA2' => 'Kutengeneza AC ya stoo ya dawa',
                default => $this->pick(['Fundi umeme', 'Kubadilisha kufuli ya mlango', 'Kutengeneza friji']),
            };
            $this->expense($d, $branch, 'Repairs & maintenance', $what, $this->pick([35000, 60000, 120000]), 'cash');
        }
    }

    private function expense(Carbon $d, string $branch, string $category, string $title, float $amount, string $method): void
    {
        $this->at($d, mt_rand(9, 17), mt_rand(0, 59));
        $this->as('mhasibu', $branch);
        $this->api->try('admin/expenses', [
            'title' => $title, 'expense_category_id' => ExpenseCategory::query()->where('name', $category)->value('id'),
            'amount' => $amount, 'payment_method' => $method, 'reference' => $method === 'cash' ? null : $this->ref('EXP'),
            'expense_date' => $d->toDateString(),
        ]);
    }

    // ------------------------------------------------------------------ online shop and deliveries

    private function rider(string $branch): string
    {
        return $branch === 'DRINKS' ? 'rider_petro' : 'rider_ally';
    }

    private function placeShopOrder(Carbon $d): void
    {
        $i = array_rand($this->shopUsers);
        $customer = $this->shopUsers[$i];
        if ($customer->created_at->gt($d)) {
            return;
        }
        [, $phone, , $address, $lat, $lng] = DemoCatalogue::SHOP_CUSTOMERS[$i];
        $branch = $this->weighted(['MAIN' => 50, 'BUTCHER' => 20, 'DRINKS' => 20, 'HARDWARE' => 10]);
        $items = [];
        foreach ($this->basket($branch, false, online: true) as $line) {
            $items[] = ['product_id' => $line['product_id'], 'quantity' => $line['quantity'] + mt_rand(1, 3)];
        }
        if (! $items) {
            return;
        }
        $delivery = $this->chance(60);
        $payNow = $this->chance(45);
        $time = $this->at($d, mt_rand(7, 21), mt_rand(0, 59));
        $this->api->as($customer, null);
        $order = $this->api->try('shop/account/orders', array_filter([
            'branch_id' => $this->branches[$branch], 'items' => $items,
            'delivery_method' => $delivery ? 'delivery' : 'pickup',
            'delivery_address' => $delivery ? $address : null,
            'delivery_latitude' => $delivery ? $lat : null, 'delivery_longitude' => $delivery ? $lng : null,
            'contact_phone' => $phone,
            'customer_notes' => $this->chance(30) ? $this->pick(['Nipigie ukifika getini.', 'Tafadhali leta kabla ya saa 12 jioni.', 'Nyama ikatwe vipande vidogo tafadhali.', 'Soda ziwe za baridi.']) : null,
        ]));
        if (! $order) {
            return;
        }
        $model = Order::query()->find($order['data']['id']);
        if ($payNow && $model && abs((float) $model->total - round((float) $model->total)) < 0.001) {
            // Paid from the shop by phone: the customer confirms with their PIN a minute later.
            $payment = $this->phonePayment('order', (float) $model->total, '255'.ltrim($phone, '0'), $model, $time);
            if ($this->chance(85)) {
                $this->completePhonePayment($payment, $time->copy()->addMinutes(1));
            } else {
                $payment->forceFill(['status' => 'failed', 'failure_reason' => 'Transaction declined by user'])->save();
            }
        }
    }

    /** Staff take an order on the phone for a regular customer. */
    private function staffOrder(Carbon $d): void
    {
        [$key, $branch] = $this->pick([['hotel.sea', 'BUTCHER'], ['hotel.sea', 'DRINKS'], ['shule.amani', 'MAIN'], ['kanisa', 'MAIN'], ['mama.ntilie', 'MAIN'], ['baa.kilele', 'DRINKS']]);
        $items = array_map(fn ($l) => ['product_id' => $l['product_id'], 'quantity' => $l['quantity']], array_filter($this->basket($branch, true), fn ($l) => ! isset($l['product_unit_id'])));
        if (! $items) {
            return;
        }
        $this->at($d, 9, mt_rand(0, 40));
        $this->as($this->cashierFor($branch, Carbon::now()), $branch);
        $this->api->try('admin/orders', [
            'customer_id' => $this->customers[$key], 'items' => array_values($items), 'delivery_method' => $this->chance(60) ? 'delivery' : 'pickup',
            'delivery_address' => DemoCatalogue::CUSTOMERS[$key][6], 'contact_phone' => DemoCatalogue::CUSTOMERS[$key][1],
            'preferred_date' => $d->copy()->addDay()->toDateString(), 'staff_notes' => 'Oda kwa simu.',
        ]);
    }

    /** Each morning: confirm new orders, prepare, deliver or hand over; a few are cancelled. */
    private function progressOrders(Carbon $d): void
    {
        $orders = Order::query()->whereNotIn('status', ['completed', 'cancelled'])->whereDate('created_at', '<', $d->toDateString())->orderBy('id')->get();
        foreach ($orders as $order) {
            $branch = array_search($order->branch_id, $this->branches, true) ?: 'MAIN';
            $this->at($d, mt_rand(8, 10), mt_rand(0, 59));
            $this->as($this->cashierFor($branch, Carbon::now()), $branch);
            $age = $order->created_at->diffInDays($d);
            if ($order->status === 'pending') {
                if ((float) $order->paid_online <= 0 && $this->chance(8)) {
                    $this->as($this->manager($branch), $branch);
                    $this->api->try("admin/orders/{$order->id}/status", ['status' => 'cancelled', 'note' => $this->pick(['Mteja hapatikani kwenye simu.', 'Bidhaa haipo kwa sasa.'])]);

                    continue;
                }
                $this->api->try("admin/orders/{$order->id}/status", ['status' => 'confirmed']);

                continue;
            }
            if ($order->status === 'confirmed') {
                $this->api->try("admin/orders/{$order->id}/status", ['status' => 'ready']);

                continue;
            }
            if ($order->status === 'ready' && $order->delivery_method === 'delivery') {
                $this->deliver($order, $d, $branch);

                continue;
            }
            if (in_array($order->status, ['ready', 'out_for_delivery'], true) && ($order->delivery_method === 'pickup' || $age >= 1)) {
                $this->completeOrder($order, $d, $branch);
            }
        }
    }

    private function deliver(Order $order, Carbon $d, string $branch): void
    {
        $rider = $this->rider($branch);
        $delivery = $this->api->try('admin/deliveries', ['order_id' => $order->id, 'courier_id' => $this->user($rider)->id, 'note' => 'Peleka kabla ya mchana.']);
        if (! $delivery) {
            return;
        }
        $id = $delivery['data']['id'];
        $time = $this->at($d, 11, mt_rand(0, 30));
        $this->as($rider, $branch);
        $this->api->try("admin/deliveries/{$id}/start", []);
        [$fromLat, $fromLng] = [DemoCatalogue::BRANCHES[$branch][4], DemoCatalogue::BRANCHES[$branch][5]];
        $toLat = (float) ($order->delivery_latitude ?: $fromLat + 0.02);
        $toLng = (float) ($order->delivery_longitude ?: $fromLng + 0.02);
        foreach ([0.3, 0.6, 0.9] as $step) {
            $this->api->at($time->addMinutes(6));
            $this->api->try("admin/deliveries/{$id}/location", ['latitude' => $fromLat + ($toLat - $fromLat) * $step, 'longitude' => $fromLng + ($toLng - $fromLng) * $step, 'accuracy' => 12]);
        }
        $this->api->try("admin/deliveries/{$id}/messages", ['body' => 'Habari, nipo njiani nakuja na mzigo wako.']);
        if ($this->chance(8)) {
            $this->api->at($time->addMinutes(20));
            $this->api->try("admin/deliveries/{$id}/fail", ['reason' => 'Mteja hakupatikana, nimerudisha mzigo dukani.']);

            return;
        }
        $this->api->at($time->addMinutes(15));
        $this->api->try("admin/deliveries/{$id}/delivered", ['note' => 'Imepokelewa na mteja.']);
        $this->completeOrder($order->fresh(), $d, $branch, $time->copy()->addMinutes(5));
    }

    private function completeOrder(Order $order, Carbon $d, string $branch, ?Carbon $time = null): void
    {
        $this->api->at($time ?? $d->copy()->setTime(mt_rand(12, 18), mt_rand(0, 59)));
        $this->as($this->cashierFor($branch, Carbon::now()), $branch);
        $rest = round((float) $order->total - (float) $order->paid_online, 2);
        $payments = [];
        if ($rest > 0) {
            $payments = $this->chance(55)
                ? [['method' => 'cash', 'amount' => $rest]]
                : [['method' => 'mobile_money', 'amount' => $rest, 'provider' => $this->pick(self::NETWORKS), 'reference' => $this->ref('MP')]];
        }
        $this->api->try("admin/orders/{$order->id}/complete", ['payments' => $payments, 'notes' => 'Imekabidhiwa.']);
    }

    // ------------------------------------------------------------------ quotations, proformas, invoices

    /** Every six days a bigger customer asks for prices: a quotation, a proforma or an invoice. */
    private function documentsCycle(Carbon $d): void
    {
        [$key, $branch, $note] = $this->pick([
            ['fundi.jumanne', 'HARDWARE', 'Bei ni pamoja na kushusha mzigo eneo la mradi.'],
            ['fundi.jumanne', 'TIMBER', 'Mbao zimekaushwa; usafiri hadi Mbezi Beach unatozwa tofauti.'],
            ['samani.bora', 'TIMBER', 'Bodi za mninga daraja la kwanza.'],
            ['hotel.sea', 'BUTCHER', 'Nyama inaletwa kila Jumatatu na Alhamisi.'],
            ['hotel.sea', 'DRINKS', 'Bei ni kwa kreti; chupa tupu zirudishwe.'],
            ['zahanati.uzima', 'PHARMA1', 'Dawa zote zina muda wa matumizi wa zaidi ya mwaka mmoja.'],
            ['kliniki.mbezi', 'PHARMA2', 'Dawa zinapatikana Mbezi; agizo kubwa linaletwa kutoka Kariakoo.'],
            ['shule.amani', 'MAIN', 'Bei hizi ni pamoja na usafiri ndani ya mji.'],
            ['baa.kilele', 'DRINKS', 'Bei ni kwa kreti; chupa tupu zirudishwe.'],
        ]);
        $items = [];
        foreach ($this->basket($branch, true) as $line) {
            $items[] = ['product_id' => $line['product_id'], 'product_unit_id' => $line['product_unit_id'] ?? null, 'quantity' => $line['quantity']];
        }
        if (! $items) {
            return;
        }
        $type = $this->pick(['quotation', 'quotation', 'quotation', 'proforma', 'invoice']);
        $this->at($d, 10, mt_rand(0, 59));
        $this->as('mhasibu', $branch);
        $doc = $this->api->try('admin/documents', [
            'type' => $type, 'customer_id' => $this->customers[$key], 'items' => $items,
            'issue_date' => $d->toDateString(), 'notes' => $note,
        ]);
        if (! $doc) {
            return;
        }
        $id = $doc['data']['id'];
        if ($this->chance(85)) {
            // Emailing a quotation or proforma marks it sent; emailing a draft invoice issues it (it becomes the customer's debt).
            $this->api->try("admin/documents/{$id}/send", ['channels' => ['email'], 'email' => DemoCatalogue::CUSTOMERS[$key][5]]);
        }
    }

    /** Customers answer, invoices get paid (or go overdue), proformas are paid. */
    private function progressDocuments(Carbon $d): void
    {
        $documents = SalesDocument::query()->whereNotIn('status', ['draft', 'cancelled', 'closed', 'converted', 'declined', 'paid'])
            ->whereDate('created_at', '<', $d->copy()->subDays(2)->toDateString())->get();
        foreach ($documents as $doc) {
            if (! $this->chance(30)) {
                continue;
            }
            $branch = array_search($doc->branch_id, $this->branches, true) ?: 'MAIN';
            $this->at($d, mt_rand(10, 16), mt_rand(0, 59));
            $this->as('mhasibu', $branch);
            if ($doc->type === 'quotation') {
                $accept = $doc->status === 'accepted' || $this->chance(70);
                $answered = $doc->status === 'accepted' || $this->api->try("admin/documents/{$doc->id}/respond", ['accept' => $accept, 'reason' => $accept ? null : 'Bei iko juu kuliko mshindani.']);
                if ($answered && $accept && $this->chance(80)) {
                    $invoice = $this->api->try("admin/documents/{$doc->id}/convert", ['to' => $this->chance(60) ? 'invoice' : 'proforma']);
                    if ($invoice && ($invoice['data']['status'] ?? 'draft') === 'draft') {
                        $this->api->try("admin/documents/{$invoice['data']['id']}/send", ['channels' => ['email'], 'email' => DemoCatalogue::CUSTOMERS[array_search($doc->customer_id, $this->customers, true)][5]]);
                    }
                }

                continue;
            }
            $balance = (float) ($doc->balance ?? 0);
            if ($balance > 0 && $this->chance(75)) {
                $full = $doc->type === 'proforma' || $this->chance(50);
                $this->api->try("admin/documents/{$doc->id}/payments", [
                    'amount' => $full ? $balance : round($balance / 2, -2), 'method' => $this->pick(['bank', 'mobile_money']),
                    'provider' => 'NMB', 'reference' => $this->ref('INV'),
                ]);
            }
        }
    }

    /** @param array<string, int> $weights */
    private function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }
}
