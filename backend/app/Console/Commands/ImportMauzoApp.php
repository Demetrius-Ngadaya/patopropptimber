<?php

namespace App\Console\Commands;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\Unit;
use App\Models\Core\Branch;
use App\Models\Core\Role;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Finance\ExpenseCategory;
use App\Models\Purchasing\Supplier;
use App\Services\Catalog\ProductService;
use App\Services\Core\NumberGenerator;
use App\Services\Finance\PostingService;
use App\Services\Inventory\StockService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * One-time move from the old MauzoApp database (PHP/MySQL) into PatoPro.
 *
 * Reads the old database read-only and brings in: branches (stores), staff with
 * their existing passwords, products, current stock (as opening stock), customers
 * with unpaid debts, and the full history of sales, purchases and expenses.
 *
 * History is recorded as it happened (old invoice numbers, dates, who sold) but does
 * not move stock again: the old system's current stock becomes the opening stock.
 * The books start at the import date: opening stock, customer debts and supplier
 * debts are posted against opening-balance equity.
 *
 *   php artisan mauzo:import-mauzoapp --database=pptimber_mauzo --dry-run
 *   php artisan mauzo:import-mauzoapp --database=pptimber_mauzo
 */
class ImportMauzoApp extends Command
{
    protected $signature = 'mauzo:import-mauzoapp
        {--database= : Name of the old MauzoApp database (default: LEGACY_DB_DATABASE or pptimber_mauzo)}
        {--host= : Old database host (default: same as PatoPro)}
        {--username= : Old database user (default: same as PatoPro)}
        {--password= : Old database password (asked for when --username is given; avoid typing it here)}
        {--dry-run : Do everything, show the summary, then undo it}
        {--force : Import even though PatoPro already has sales or products}';

    protected $description = 'Bring products, stock, staff, customers, debts and history from the old MauzoApp database into PatoPro';

    private const NOTE = 'Imported from MauzoApp';

    /** Old store_id => new branch id */
    private array $branches = [];

    /** lower(old staff name) => new user id */
    private array $users = [];

    /** old stock.id => new product id */
    private array $products = [];

    /** branch|lower(name) => customer id */
    private array $customers = [];

    /** lower(name) => supplier id */
    private array $suppliers = [];

    private int $adminId;

    private array $warnings = [];

    private array $summary = [];

    private Connection $old;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StockService $stock,
        private readonly PostingService $posting,
        private readonly ProductService $productService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->old = $this->connect();
        } catch (Throwable $e) {
            $this->error('Could not open the old MauzoApp database: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach (['stores', 'users', 'stock', 'sales', 'purchases_report', 'expenditure'] as $table) {
            if (! $this->old->getSchemaBuilder()->hasTable($table)) {
                $this->error("The database \"{$this->old->getDatabaseName()}\" has no `{$table}` table. Is it the MauzoApp database?");

                return self::FAILURE;
            }
        }

        if (! $this->option('force') && (DB::table('sales')->exists() || DB::table('products')->exists())) {
            $this->error('PatoPro already has products or sales. The import is meant for a fresh system.');
            $this->line('Run it on an empty PatoPro, or add --force if you are sure (data would be added twice if it was imported before).');

            return self::FAILURE;
        }

        $admin = User::query()->where('username', 'admin')->first() ?? User::query()->where('type', 'staff')->orderBy('id')->first();
        if (! $admin) {
            $this->error('PatoPro has no admin account yet. Run "php artisan migrate --seed --force" first.');

            return self::FAILURE;
        }
        $this->adminId = $admin->id;
        auth()->setUser($admin);

        $this->info('Importing from "'.$this->old->getDatabaseName().'"'.($this->option('dry-run') ? ' (dry run: nothing will be kept)' : '').' ...');

        DB::beginTransaction();
        try {
            $this->step('Branches', fn () => $this->importBranches());
            $this->step('Staff', fn () => $this->importUsers());
            $this->step('Products and stock', fn () => $this->importProducts());
            $this->step('Customers', fn () => $this->importCustomers());
            $this->step('Sales', fn () => $this->importSales());
            $this->step('Purchases', fn () => $this->importPurchases());
            $this->step('Expenses', fn () => $this->importExpenses());
            $this->step('Opening balances', fn () => $this->postOpeningBalances());

            if ($this->option('dry-run')) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->newLine();
            $this->error('Import stopped, nothing was saved: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        $this->report();

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- steps

    private function importBranches(): void
    {
        $stores = $this->old->table('stores')->orderBy('id')->get();
        $main = Branch::query()->where('is_main', true)->first() ?? Branch::query()->orderBy('id')->first();
        $first = true;

        foreach ($stores as $store) {
            $name = trim((string) $store->name);
            // Store 0 ("Matawi yote") only meant "all branches" in the old system.
            if ((int) $store->id === 0 || Str::contains(Str::lower($name), ['matawi yote', 'all branches', 'all stores'])) {
                continue;
            }
            $existing = Branch::query()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();
            if ($existing) {
                $branch = $existing;
            } elseif ($first && $main && ! DB::table('sales')->where('branch_id', $main->id)->exists() && $main->code === 'MAIN') {
                // The fresh system's "Main Branch" becomes the first old store (the head office).
                $main->forceFill(['name' => $name, 'location' => $store->location ?: $main->location, 'is_main' => true])->save();
                $branch = $main;
            } else {
                $branch = Branch::create([
                    'name' => $name,
                    'code' => $this->branchCode($name),
                    'location' => $store->location,
                    'is_main' => false,
                    'is_active' => true,
                ]);
            }
            $first = false;
            $this->branches[(int) $store->id] = $branch->id;
        }

        if (! $this->branches) {
            throw new RuntimeException('The old database has no stores.');
        }
        $this->summary['Branches'] = collect($this->branches)->map(fn ($id) => Branch::query()->whereKey($id)->value('name'))->implode(', ');
    }

    private function importUsers(): void
    {
        $roles = Role::query()->pluck('id', 'slug');
        $count = 0;
        $logins = [];

        foreach ($this->old->table('users')->orderBy('id')->get() as $old) {
            $name = trim(preg_replace('/\s+/', ' ', (string) $old->user_name));
            if ($name === '') {
                continue;
            }
            $allStores = (int) $old->can_access_all_stores === 1 || (int) $old->store_id === 0;
            $roleSlug = match (Str::lower((string) $old->role)) {
                'admin' => $allStores ? 'admin' : 'branch-admin',
                'cashier' => 'cashier',
                default => 'cashier',
            };

            $existing = User::query()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->where('type', 'staff')->first();
            if ($existing) {
                $this->users[Str::lower($name)] = $existing->id;

                continue;
            }

            $username = $this->uniqueUsername($name);
            $user = new User;
            $user->forceFill([
                'type' => 'staff',
                'name' => Str::title(Str::lower($name)) === $name ? $name : $name,
                'username' => $username,
                'password' => Str::random(40), // replaced below with the old hash
                'role_id' => $roles[$roleSlug] ?? null,
                'branch_id' => $this->branches[(int) $old->store_id] ?? $this->mainBranchId(),
                'all_branches' => $allStores,
                'is_active' => true,
                'must_change_password' => false,
            ])->save();

            // Keep the old password: MauzoApp stored bcrypt hashes, which PatoPro checks the same way.
            if (Str::startsWith((string) $old->password, ['$2y$', '$2a$', '$2b$'])) {
                DB::table('users')->where('id', $user->id)->update(['password' => $old->password]);
            } else {
                DB::table('users')->where('id', $user->id)->update(['must_change_password' => true]);
                $this->warnings[] = "Staff \"{$name}\" had no usable password: log in as admin and set one.";
            }

            $this->users[Str::lower($name)] = $user->id;
            $logins[] = "{$name} → {$username}";
            $count++;
        }

        $this->summary['Staff'] = "{$count} added (same passwords as before). Usernames: ".implode(', ', $logins);
    }

    private function importProducts(): void
    {
        $unit = Unit::query()->where('short_name', 'pc')->first();
        $rows = $this->old->table('stock')->orderBy('id')->get();
        $mainOld = $this->mainOldStoreId();

        // One PatoPro product per name; the head office row gives the price.
        $groups = $rows->groupBy(fn ($r) => $this->key($r->medicine_name));
        $created = 0;
        $stockLines = 0;
        $stockValue = 0.0;

        foreach ($groups as $rowsOfProduct) {
            $priceRow = $rowsOfProduct->firstWhere('store_id', $mainOld) ?? $rowsOfProduct->sortByDesc('id')->first();
            $name = $this->clean($priceRow->medicine_name);
            $category = $this->category($priceRow->category);
            $unitId = $this->unitId($priceRow->sell_type, $unit?->id);

            $product = $this->productService->create([
                'name' => $name,
                'barcode' => $this->usableBarcode($priceRow->bar_code),
                'category_id' => $category?->id,
                'unit_id' => $unitId,
                'cost_price' => (float) $priceRow->actual_price,
                'selling_price' => (float) $priceRow->selling_price,
                'reorder_level' => max(0, (int) $priceRow->stock_alert),
                'track_expiry' => false,
                'is_active' => true,
                'brand' => null,
            ], null);
            $created++;

            foreach ($rowsOfProduct as $row) {
                $this->products[(int) $row->id] = $product->id;
                $branchId = $this->branches[(int) $row->store_id] ?? null;
                $qty = (float) $row->remain_quantity;
                if (! $branchId || $qty <= 0) {
                    continue;
                }
                $cost = (float) $row->actual_price;
                $this->posting->openingStock($product, $branchId, round($qty * $cost, 2));
                $this->stock->increase($product, $branchId, $qty, $cost, 'opening', $product, ['note' => 'Opening stock '.self::NOTE]);
                $stockLines++;
                $stockValue += $qty * $cost;
            }

            if ($rowsOfProduct->pluck('selling_price')->unique()->count() > 1) {
                $this->warnings[] = "\"{$name}\" had different prices per branch in MauzoApp; PatoPro uses one price: ".number_format((float) $priceRow->selling_price).'.';
            }
        }

        $this->summary['Products'] = "{$created} products; opening stock in {$stockLines} branch lines worth ".number_format($stockValue).' at cost';
    }

    private function importCustomers(): void
    {
        // Customers with credit sales become customer records (they owe or owed money).
        $rows = $this->old->table('sales')->where('payment_method', 'credit')
            ->select('customer_name', 'phone_number', 'store_id', 'id')->orderBy('id')->get();
        $count = 0;
        foreach ($rows->groupBy(fn ($r) => $this->customerKey($r->store_id, $r->customer_name)) as $key => $group) {
            $first = $group->first();
            $name = $this->clean($first->customer_name);
            if ($name === '' || $this->isTransferCustomer($name) || isset($this->customers[$key])) {
                continue;
            }
            $phone = $this->phone($group->pluck('phone_number')->filter()->last());
            $customer = Customer::create([
                'code' => $this->numbers->next('customer'),
                'branch_id' => $this->branches[(int) $first->store_id] ?? $this->mainBranchId(),
                'name' => $name,
                'phone' => $phone,
                'customer_type' => 'retail',
                'allow_credit' => true,
                'credit_limit' => 0,
                'is_active' => true,
                'notes' => self::NOTE,
            ]);
            $this->customers[$key] = $customer->id;
            $count++;
        }
        $this->summary['Customers'] = "{$count} credit customers";
    }

    private function importSales(): void
    {
        $rows = $this->old->table('sales')->orderBy('id')->get();
        $now = now();
        $sales = 0;
        $lines = 0;
        $total = 0.0;
        $unpaid = 0.0;
        $usedNumbers = [];
        $paymentNo = 0;
        $balances = [];

        // In MauzoApp one invoice could mix customers and cash/credit lines. In PatoPro each
        // (invoice, customer, payment) becomes its own sale so debts stay with the right person.
        $groups = $rows->groupBy(fn ($r) => implode('|', [
            $r->store_id, $r->invoice_number, $r->Date, $this->key($r->customer_name), Str::lower($r->payment_method), Str::lower($r->hali_ya_malipo),
        ]));

        foreach ($groups as $group) {
            $first = $group->first();
            $branchId = $this->branches[(int) $first->store_id] ?? $this->mainBranchId();
            $credit = Str::lower((string) $first->payment_method) === 'credit';
            $paid = Str::lower((string) $first->hali_ya_malipo) === 'paid';
            $customerName = $this->clean($first->customer_name);
            $customerId = $this->customers[$this->customerKey($first->store_id, $first->customer_name)] ?? null;
            $soldAt = $this->dateTime($first->Date, 12);
            $cashierId = $this->userId($first->created_by);

            $number = $this->uniqueNumber($this->clean($first->invoice_number) ?: 'MA-'.$first->id, $usedNumbers);

            $sum = 0.0;
            $cost = 0.0;
            $items = [];
            foreach ($group as $r) {
                $qty = (float) preg_replace('/[^0-9.]/', '', (string) $r->quantity);
                if ($qty <= 0) {
                    $qty = 1;
                }
                $amount = (float) $r->total_amount;
                $lineCost = max(0, $amount - (float) $r->total_profit);
                $productId = $this->products[(int) $r->medicine_id] ?? $this->productByName($r->medicines, $r->category);
                $items[] = [
                    'product_id' => $productId,
                    'product_name' => $this->clean($r->medicines),
                    'unit_name' => null,
                    'unit_factor' => 1,
                    'unit_quantity' => $qty,
                    'price_level' => 'retail',
                    'quantity' => $qty,
                    'returned_quantity' => 0,
                    'unit_price' => round($amount / $qty, 2),
                    'list_price' => round($amount / $qty, 2),
                    'discount' => 0,
                    'tax_rate' => 0,
                    'tax' => 0,
                    'line_total' => $amount,
                    'unit_cost' => round($lineCost / $qty, 2),
                    'created_at' => $soldAt,
                    'updated_at' => $soldAt,
                ];
                $sum += $amount;
                $cost += $lineCost;
            }

            $balance = ($credit && ! $paid) ? $sum : 0.0;
            $paidAt = $credit && $paid ? ($this->dateTime($group->pluck('paid_date')->filter()->first(), 12) ?? $soldAt) : $soldAt;

            $saleId = DB::table('sales')->insertGetId([
                'number' => $number,
                'branch_id' => $branchId,
                'customer_id' => $customerId,
                'customer_name' => $customerId ? null : ($customerName ?: null),
                'customer_phone' => $customerId ? null : $this->phone($first->phone_number),
                'cashier_id' => $cashierId,
                'channel' => 'pos',
                'price_level' => 'retail',
                'status' => 'completed',
                'subtotal' => $sum,
                'item_discount' => 0,
                'discount' => 0,
                'promotion_discount' => 0,
                'tax' => 0,
                'delivery_fee' => 0,
                'total' => $sum,
                'cost_total' => round($cost, 2),
                'amount_paid' => $sum - $balance,
                'balance' => $balance,
                'returned_amount' => 0,
                'change_given' => 0,
                'payment_status' => $balance > 0 ? 'unpaid' : 'paid',
                'is_credit' => $credit,
                'credit_amount' => $credit ? $sum : 0,
                'due_date' => $credit ? ($group->pluck('date_to_pay')->filter()->first() ?: null) : null,
                'fully_paid_at' => $balance > 0 ? null : $paidAt,
                'sold_at' => $soldAt,
                'notes' => self::NOTE.' (invoice '.$this->clean($first->invoice_number).')',
                'created_by' => $cashierId,
                'updated_by' => $cashierId,
                'created_at' => $soldAt,
                'updated_at' => $now,
            ]);
            DB::table('sale_items')->insert(array_map(fn ($i) => $i + ['sale_id' => $saleId], $items));

            if ($sum - $balance > 0) {
                $receivedBy = $credit ? ($this->userId($group->pluck('credit_payment_recorder')->filter()->first(), null) ?? $cashierId) : $cashierId;
                DB::table('payments')->insert([
                    'number' => sprintf('MA-RCP-%06d', ++$paymentNo),
                    'branch_id' => $branchId,
                    'customer_id' => $customerId,
                    'sale_id' => $saleId,
                    'type' => $credit ? 'debt' : 'sale',
                    'amount' => $sum - $balance,
                    'method' => 'cash',
                    'paid_at' => $paidAt,
                    'notes' => self::NOTE,
                    'received_by' => $receivedBy,
                    'created_by' => $receivedBy,
                    'created_at' => $paidAt,
                    'updated_at' => $now,
                ]);
            }

            if ($balance > 0) {
                if (! $customerId) {
                    $this->warnings[] = "Unpaid sale {$number} ({$customerName}) has no customer record.";
                } else {
                    $balances[$customerId] = ($balances[$customerId] ?? 0) + $balance;
                }
                $unpaid += $balance;
            }
            $sales++;
            $lines += count($items);
            $total += $sum;
        }

        foreach ($balances as $customerId => $amount) {
            DB::table('customers')->where('id', $customerId)->update(['balance' => round($amount, 2)]);
        }

        $this->summary['Sales'] = "{$sales} sales ({$lines} lines) worth ".number_format($total).'; unpaid customer debts '.number_format($unpaid);
    }

    private function importPurchases(): void
    {
        $rows = $this->old->table('purchases_report')->orderBy('id')->get();
        $now = now();
        $count = 0;
        $total = 0.0;
        $owed = 0.0;
        $lastGoodDate = null;
        $supplierBalances = [];

        // Repair 1970-01-01 dates (empty dates in MauzoApp) with the previous good date.
        foreach ($rows as $r) {
            if ($r->received_date && $r->received_date > '2000-01-01') {
                $lastGoodDate = $r->received_date;
            } else {
                $r->received_date = $lastGoodDate ?? Carbon::parse($r->expire_date)->subYears(30)->toDateString();
                $r->date_repaired = true;
            }
        }

        $groups = $rows->groupBy(fn ($r) => implode('|', [
            $r->store_id, $r->received_date, $this->key($r->company), $this->key($r->created_by), Str::lower($r->status), $r->paid_date, $r->receipt_number,
        ]));

        foreach ($groups as $group) {
            $first = $group->first();
            $branchId = $this->branches[(int) $first->store_id] ?? $this->mainBranchId();
            $supplierId = $this->supplierId($first->company);
            $status = Str::lower((string) $first->status);
            $unpaid = in_array($status, ['credit', 'not paid'], true);
            $userId = $this->userId($first->created_by);
            $date = $first->received_date;

            $subtotal = 0.0;
            $extras = ['tax_cost' => 0.0, 'loading_cost' => 0.0, 'unloading_cost' => 0.0, 'transport_cost' => 0.0, 'other_cost' => 0.0];
            $items = [];
            foreach ($group as $r) {
                $qty = max(0, (float) $r->received_quantity);
                $unitCost = (float) ($r->actual_price ?: $r->products_cost);
                // MauzoApp saved costs two ways: per unit (products_cost = actual_price) on most
                // lines, but whole-line totals (products_cost = qty × price) on some.
                $lineTotals = $qty > 1 && abs((float) $r->products_cost - $unitCost) > 0.01
                    && abs((float) $r->products_cost - $qty * $unitCost) < 1;
                $per = $lineTotals ? 1 : $qty;
                $landed = (float) $r->total_cost > 0 ? ($lineTotals ? (float) $r->total_cost / $qty : (float) $r->total_cost) : $unitCost;
                $extras['tax_cost'] += $per * (float) $r->tax_amount;
                $extras['loading_cost'] += $per * (float) $r->cost_kupakia;
                $extras['unloading_cost'] += $per * (float) $r->cost_kushusha;
                $extras['transport_cost'] += $per * (float) $r->transport_cost;
                $extras['other_cost'] += $per * (float) $r->cost_kupanga;
                $subtotal += $qty * $unitCost;
                $items[] = [
                    'product_id' => $this->products[(int) $r->medicine_id] ?? $this->productByName($r->medicine_name, $r->category),
                    'unit_name' => null,
                    'unit_factor' => 1,
                    'unit_quantity' => $qty,
                    'quantity' => $qty,
                    'returned_quantity' => 0,
                    'unit_cost' => $unitCost,
                    'extra_cost_per_unit' => round(max(0, $landed - $unitCost), 2),
                    'landed_unit_cost' => round($landed, 2),
                    'line_total' => round($qty * $unitCost, 2),
                    'selling_price' => (float) $r->selling_price ?: null,
                    'batch_no' => $this->clean($r->batch_number) ?: null,
                    'expiry_date' => null,
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
            }
            $extras = array_map(fn ($v) => round($v, 2), $extras);
            $grand = round($subtotal + array_sum($extras), 2);
            $supplierTotal = round($subtotal, 2);
            $balance = $unpaid ? $supplierTotal : 0.0;

            $purchaseId = DB::table('purchases')->insertGetId([
                'number' => sprintf('MA-PUR-%05d', ++$count),
                'branch_id' => $branchId,
                'supplier_id' => $supplierId,
                'supplier_invoice_no' => $this->clean($first->receipt_number) ?: null,
                'purchase_date' => $date,
                'subtotal' => $supplierTotal,
                'cost_allocation' => 'value',
                'total' => $grand,
                'supplier_total' => $supplierTotal,
                'amount_paid' => $supplierTotal - $balance,
                'balance' => $balance,
                'returned_amount' => 0,
                'payment_status' => $balance > 0 ? 'unpaid' : 'paid',
                'due_date' => $unpaid ? ($first->date_to_pay ?: null) : null,
                'notes' => self::NOTE.($group->contains('date_repaired', true) ? ' (date missing in MauzoApp, estimated)' : ''),
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $date,
                'updated_at' => $now,
            ] + $extras);
            DB::table('purchase_items')->insert(array_map(fn ($i) => $i + ['purchase_id' => $purchaseId], $items));

            if ($balance > 0) {
                if ($supplierId) {
                    $supplierBalances[$supplierId] = ($supplierBalances[$supplierId] ?? 0) + $balance;
                } else {
                    $this->warnings[] = 'Unpaid purchase of '.number_format($balance).' on '.$date.' has no supplier name.';
                }
                $owed += $balance;
            }
            $total += $grand;
        }

        foreach ($supplierBalances as $supplierId => $amount) {
            DB::table('suppliers')->where('id', $supplierId)->update(['balance' => round($amount, 2)]);
        }
        $repaired = $rows->where('date_repaired', true)->count();
        if ($repaired) {
            $this->warnings[] = "{$repaired} purchase lines had no date in MauzoApp (1970-01-01); they were given the date of the purchase before them.";
        }

        $this->summary['Purchases'] = "{$count} purchases from ".count($this->suppliers).' suppliers worth '.number_format($total).'; unpaid to suppliers '.number_format($owed);
    }

    private function importExpenses(): void
    {
        $categories = ExpenseCategory::query()->pluck('id', 'name');
        $now = now();
        $count = 0;
        $total = 0.0;
        foreach ($this->old->table('expenditure')->orderBy('expenditure_id')->get() as $r) {
            $title = $this->clean($r->expenditure_name) ?: 'Matumizi';
            $lower = Str::lower($title);
            $category = match (true) {
                Str::contains($lower, ['kodi']) => 'Rent',
                Str::contains($lower, ['nauli', 'usafiri', 'safari']) => 'Transport',
                Str::contains($lower, ['umeme', 'luku']) => 'Electricity',
                Str::contains($lower, ['maji']) => 'Water',
                Str::contains($lower, ['mshahara', 'posho', 'mlinzi']) => 'Salaries & wages',
                Str::contains($lower, ['mafuta']) => 'Fuel',
                default => 'Other',
            };
            $date = $this->date($r->created_at) ?? $now->toDateString();
            $userId = $this->userId($r->created_by);
            DB::table('expenses')->insert([
                'number' => sprintf('MA-EXP-%05d', $r->expenditure_id),
                'branch_id' => $this->branches[(int) $r->store_id] ?? $this->mainBranchId(),
                'expense_category_id' => $categories[$category] ?? $categories['Other'] ?? null,
                'title' => Str::limit($title, 250, ''),
                'description' => $this->clean($r->expenditure_description) ?: null,
                'amount' => (float) $r->expenditure_amount,
                'payment_method' => 'cash',
                'expense_date' => $date,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $date.' 12:00:00',
                'updated_at' => $now,
            ]);
            $count++;
            $total += (float) $r->expenditure_amount;
        }
        $this->summary['Expenses'] = "{$count} expenses worth ".number_format($total);
    }

    /** Customer and supplier debts brought forward into the books (opening stock is posted per product). */
    private function postOpeningBalances(): void
    {
        if (! $this->posting->enabled()) {
            $this->summary['Accounting'] = 'automatic posting is off: no opening entries made';

            return;
        }
        $date = now()->toDateString();
        foreach (Customer::query()->where('balance', '>', 0)->get() as $c) {
            $this->posting->post([
                'date' => $date,
                'description' => "Opening debt of {$c->name} (MauzoApp)",
                'event' => 'opening_receivable',
                'branch_id' => $c->branch_id,
            ], [
                ['account' => 'receivable', 'debit' => (float) $c->balance, 'customer_id' => $c->id],
                ['account' => 'opening_equity', 'credit' => (float) $c->balance],
            ], $c);
        }
        foreach (Supplier::query()->where('balance', '>', 0)->get() as $s) {
            $this->posting->post([
                'date' => $date,
                'description' => "Opening balance owed to {$s->name} (MauzoApp)",
                'event' => 'opening_payable',
                'branch_id' => null,
            ], [
                ['account' => 'opening_equity', 'debit' => (float) $s->balance],
                ['account' => 'payable', 'credit' => (float) $s->balance, 'supplier_id' => $s->id],
            ], $s);
        }
        $this->summary['Accounting'] = 'opening stock, customer debts and supplier debts posted to the books on '.$date;
    }

    // -------------------------------------------------------------- helpers

    private function connect(): Connection
    {
        $base = config('database.connections.'.config('database.default'));
        $config = array_merge($base, array_filter([
            'host' => $this->option('host'),
            'username' => $this->option('username'),
            'password' => $this->option('password') ?? ($this->option('username') ? (string) $this->secret('Password of the old database user') : null),
            'database' => $this->option('database') ?: env('LEGACY_DB_DATABASE', 'pptimber_mauzo'),
        ], fn ($v) => $v !== null && $v !== ''));
        $config['strict'] = false; // old data has zero dates and loose types
        config(['database.connections.mauzoapp_legacy' => $config]);
        DB::purge('mauzoapp_legacy');
        $connection = DB::connection('mauzoapp_legacy');
        $connection->getPdo();

        return $connection;
    }

    private function step(string $label, callable $fn): void
    {
        $this->output->write(str_pad("  {$label} ", 26, '.').' ');
        $started = microtime(true);
        $fn();
        $this->line('<info>done</info> ('.round(microtime(true) - $started, 1).'s)');
    }

    private function report(): void
    {
        $this->newLine();
        $this->table(['What', 'Result'], collect($this->summary)->map(fn ($v, $k) => [$k, $v])->values()->all());
        if ($this->warnings) {
            $this->newLine();
            $this->warn('Please check:');
            foreach (array_unique($this->warnings) as $w) {
                $this->line('  - '.$w);
            }
        }
        $this->newLine();
        $this->info($this->option('dry-run')
            ? 'Dry run finished: nothing was saved. Run again without --dry-run to import.'
            : 'Import finished. The old database was only read, never changed.');
    }

    private function mainOldStoreId(): int
    {
        $mainNew = $this->mainBranchId();

        return (int) (array_search($mainNew, $this->branches, true) ?: array_key_first($this->branches));
    }

    private function mainBranchId(): int
    {
        return (int) (Branch::query()->where('is_main', true)->value('id') ?? reset($this->branches));
    }

    private function userId(?string $name, ?int $default = -1): ?int
    {
        $key = Str::lower($this->clean($name));
        if ($key !== '' && isset($this->users[$key])) {
            return $this->users[$key];
        }

        return $default === -1 ? $this->adminId : $default;
    }

    private function category(?string $name): ?Category
    {
        $name = Str::ucfirst(Str::lower($this->clean($name)));
        if ($name === '') {
            return null;
        }

        return Category::query()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first()
            ?? Category::create(['name' => $name, 'slug' => $this->uniqueSlug('categories', $name), 'is_active' => true, 'show_online' => true]);
    }

    private function unitId(?string $sellType, ?int $default): ?int
    {
        $short = Str::lower($this->clean($sellType));
        if ($short === '' || in_array($short, ['pc', 'pcs', 'piece', 'pieces'], true)) {
            return $default;
        }

        return Unit::query()->where('short_name', $short)->value('id')
            ?? Unit::query()->create(['name' => Str::ucfirst($short), 'short_name' => Str::limit($short, 20, ''), 'allow_decimal' => false, 'is_active' => true])->id;
    }

    private function supplierId(?string $company): ?int
    {
        $name = $this->clean($company);
        if ($name === '' || in_array(Str::lower($name), ['null', 'none', 'n/a', '-', 'hakuna'], true)) {
            return null;
        }
        $key = Str::lower($name);
        if (! isset($this->suppliers[$key])) {
            $this->suppliers[$key] = Supplier::query()->whereRaw('LOWER(name) = ?', [$key])->value('id')
                ?? Supplier::create([
                    'code' => $this->numbers->next('supplier'),
                    'name' => $name,
                    'is_active' => true,
                    'notes' => self::NOTE,
                ])->id;
        }

        return $this->suppliers[$key];
    }

    /** A product sold or bought in the past that is no longer in the stock table. */
    private function productByName(?string $name, ?string $category): int
    {
        $key = $this->key($name);
        $id = Product::query()->whereRaw('LOWER(name) = ?', [Str::lower($this->clean($name))])->value('id');
        if ($id) {
            return $id;
        }
        $product = $this->productService->create([
            'name' => $this->clean($name) ?: 'Bidhaa ya zamani',
            'category_id' => $this->category($category)?->id,
            'unit_id' => Unit::query()->where('short_name', 'pc')->value('id'),
            'cost_price' => 0,
            'selling_price' => 0,
            'is_active' => false,
        ], null);
        $this->warnings[] = "\"{$product->name}\" appears in old sales or purchases but not in stock; added as an inactive product.";

        return $product->id;
    }

    private function customerKey(mixed $storeId, ?string $name): string
    {
        return ((int) $storeId).'|'.$this->key($name);
    }

    private function isTransferCustomer(string $name): bool
    {
        return Str::contains(Str::lower($name), ['hamisha', 'branch', 'tawi']);
    }

    private function uniqueNumber(string $base, array &$used): string
    {
        $base = Str::limit($base, 24, '');
        $number = $base;
        $n = 1;
        while (isset($used[$number]) || DB::table('sales')->where('number', $number)->exists()) {
            $number = $base.'-'.(++$n);
        }
        $used[$number] = true;

        return $number;
    }

    private function uniqueUsername(string $name): string
    {
        $base = Str::slug($name, '.') ?: 'user';
        $username = Str::limit($base, 50, '');
        $n = 1;
        while (User::withTrashed()->where('username', $username)->exists()) {
            $username = Str::limit($base, 46, '').(++$n);
        }

        return $username;
    }

    private function uniqueSlug(string $table, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $n = 1;
        while (DB::table($table)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    private function branchCode(string $name): string
    {
        $base = Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3)) ?: 'BR';
        $code = $base;
        $n = 1;
        while (Branch::withTrashed()->where('code', $code)->exists()) {
            $code = $base.(++$n);
        }

        return $code;
    }

    private function usableBarcode(?string $code): ?string
    {
        $code = $this->clean($code);
        if ($code === '' || Product::withTrashed()->where('barcode', $code)->exists()) {
            return null;
        }

        return Str::limit($code, 64, '');
    }

    private function phone(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        if ($digits === '' || (int) $digits === 0) {
            return null;
        }
        if (strlen($digits) === 9) {
            $digits = '0'.$digits; // the old column was a number, so the leading 0 was lost
        }

        return Str::limit($digits, 30, '');
    }

    private function date(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            $date = Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }

        return $date->year > 2000 ? $date->toDateString() : null;
    }

    private function dateTime(mixed $value, int $hour): ?string
    {
        $date = $this->date($value);

        return $date ? "{$date} ".sprintf('%02d:00:00', $hour) : null;
    }

    private function clean(?string $value): string
    {
        $value = (string) $value;
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private function key(?string $value): string
    {
        return Str::lower($this->clean($value));
    }
}
