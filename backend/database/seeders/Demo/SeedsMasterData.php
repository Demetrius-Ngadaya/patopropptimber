<?php

namespace Database\Seeders\Demo;

use App\Models\Catalog\Category;
use App\Models\Catalog\Unit;
use App\Models\Core\Branch;
use App\Models\Core\Role;
use App\Models\Core\User;
use App\Services\Inventory\StockService;

/**
 * Settings, branches, staff, catalogue, suppliers, customers and the opening
 * stock of every branch.
 */
trait SeedsMasterData
{
    /** @var array<string, array{id: int, name: string, cost: float, price: float, category: string, supplier: ?string, flags: string, reorder: float, opening: float, home: string, packs: array<int, array{id: int, factor: float}>}> */
    private array $products = [];

    /** @var array<string, array<int, string>> branch => skus of goods sold there */
    private array $branchSkus = [];

    /** @var array<string, array<int, string>> branch => skus of services offered there */
    private array $branchServices = [];

    /** @var array<string, int> */
    private array $suppliers = [];

    /** @var array<string, int> */
    private array $customers = [];

    /** @var array<int, User> online-shop customers */
    private array $shopUsers = [];

    private function seedSetup(): void
    {
        $this->at($this->start, 7, 30);
        $main = Branch::query()->where('code', 'MAIN')->firstOrFail();
        $this->branches['MAIN'] = $main->id;
        $this->api->as($this->admin, $main->id);

        [$name, $location, $address, $phone, $lat, $lng] = DemoCatalogue::BRANCHES['MAIN'];
        $this->api->put('admin/settings', [
            'business_name' => 'Mwamba Group Ltd (Demo)',
            'business_type' => 'other',
            'business_phone' => '255222180001',
            'business_whatsapp' => '255754100001',
            'business_email' => 'info@mwamba-demo.co.tz',
            'business_website' => 'https://mwamba-demo.co.tz',
            'business_address' => 'Mtaa wa Msimbazi Na. 14, Kariakoo, Dar es Salaam',
            'business_po_box' => 'P.O. Box 12345, Dar es Salaam',
            'business_tin' => '123-987-654',
            'business_vrn' => '40-098765-Z',
            'business_bank_details' => "NMB Bank, Kariakoo branch\nAccount: 2210 0123 456\nName: Mwamba Group Ltd",
            'business_latitude' => $lat,
            'business_longitude' => $lng,
            'delivery_base_fee' => 1500,
            'delivery_fee_per_km' => 500,
            'delivery_min_fee' => 2000,
            'delivery_max_km' => 30,
            'shop_tagline' => 'Supermarket, nyama, vinywaji na vifaa vya ujenzi: vyote mlangoni kwako.',
            'shop_min_order_amount' => 5000,
            'optional_modules' => ['agency', 'shifts'],
            'online_payments_enabled' => true,
            'online_payments_pos' => true,
            'online_payments_shop' => true,
            'credit_default_days' => 14,
            'insights_vip_min_spend' => 1000000,
            'invoice_footer' => 'Asante kwa kufanya biashara na Mwamba Group.',
        ]);
        $this->api->put("admin/branches/{$main->id}", [
            'name' => $name, 'code' => 'MAIN', 'location' => $location, 'address' => $address, 'phone' => $phone,
            'email' => 'supermarket@mwamba-demo.co.tz', 'is_main' => true, 'is_active' => true, 'accepts_online_orders' => true, 'latitude' => $lat, 'longitude' => $lng,
        ]);
        foreach (DemoCatalogue::BRANCHES as $code => [$name, $location, $address, $phone, $lat, $lng, $online]) {
            if ($code === 'MAIN') {
                continue;
            }
            $this->branches[$code] = $this->api->post('admin/branches', [
                'name' => $name, 'code' => $code, 'location' => $location, 'address' => $address, 'phone' => $phone,
                'email' => strtolower($code).'@mwamba-demo.co.tz',
                'is_active' => true, 'accepts_online_orders' => $online, 'latitude' => $lat, 'longitude' => $lng,
            ])['data']['id'];
        }

        foreach (DemoCatalogue::STAFF as $username => [$name, $role, $branch, $phone, $email]) {
            $this->api->post('admin/users', [
                'name' => $name, 'username' => $username, 'email' => $email, 'phone' => $phone,
                'role_id' => Role::query()->where('slug', $role)->value('id'),
                'branch_id' => $branch ? $this->branches[$branch] : null,
                'all_branches' => $branch === null,
                'is_active' => true, 'must_change_password' => false,
                'password' => DemoCatalogue::PASSWORD, 'password_confirmation' => DemoCatalogue::PASSWORD,
            ]);
        }
    }

    private function seedCatalogue(): void
    {
        $this->at($this->start, 8, 0);
        $this->api->as($this->admin, $this->branches['MAIN']);
        $units = Unit::query()->pluck('id', 'short_name');

        foreach (DemoCatalogue::CATEGORIES as $name => [$sw, $online]) {
            $this->api->post('admin/categories', ['name' => $name, 'description' => $sw, 'show_online' => $online, 'is_active' => true]);
        }
        $categories = Category::query()->pluck('id', 'name');

        foreach (DemoCatalogue::SUPPLIERS as $code => [$name, $contact, $phone, $email, $tin, $terms]) {
            $this->suppliers[$code] = $this->api->post('admin/suppliers', [
                'name' => $name, 'contact_person' => $contact, 'phone' => $phone, 'email' => $email, 'tin' => $tin,
                'address' => 'Dar es Salaam', 'payment_terms_days' => $terms, 'is_active' => true,
            ])['data']['id'];
        }

        foreach (DemoCatalogue::PRODUCTS as $sku => [$name, $category, $unit, $cost, $price, $wholesale, $wholesaleMin, $reorder, $opening, $supplier, $flags]) {
            $homes = DemoCatalogue::homes($sku);
            $service = str_contains($flags, 's');
            // Opening stock is counted at the product's own branch.
            $this->api->branch($this->branches[$homes[0]]);
            $expiry = str_contains($flags, 'e');
            $openingExpiry = str_contains($flags, 'x')
                ? $this->today->copy()->addDays(12)
                : $this->start->copy()->addDays(mt_rand(150, 400));
            $product = $this->api->post('admin/products', [
                'name' => $name, 'sku' => $sku, 'category_id' => $categories[$category], 'unit_id' => $units[$unit] ?? null,
                'brand' => $service ? null : explode(' ', $name)[0],
                'description' => DemoCatalogue::CATEGORIES[$category][0].': '.$name.'.',
                'cost_price' => $cost, 'selling_price' => $price,
                'wholesale_price' => $wholesale, 'wholesale_min_qty' => $wholesaleMin,
                'min_selling_price' => $price > 0 ? round($price * 0.8, -1) : null,
                'reorder_level' => $reorder, 'expiry_alert_days' => $expiry ? 30 : null,
                'track_expiry' => $expiry, 'allow_decimal' => str_contains($flags, 'd'), 'is_service' => $service,
                'show_online' => ! str_contains($flags, 'o'), 'is_active' => true,
                'opening_quantity' => $opening ?: null,
                'opening_expiry_date' => $expiry && $opening ? $openingExpiry->toDateString() : null,
                'opening_batch_no' => $expiry && $opening ? 'OPN-'.$sku : null,
            ])['data'];
            $this->products[$sku] = [
                'id' => $product['id'], 'name' => $name, 'cost' => (float) $cost, 'price' => (float) $price, 'category' => $category,
                'supplier' => $supplier, 'flags' => $flags, 'reorder' => (float) $reorder, 'opening' => (float) $opening, 'home' => $homes[0], 'packs' => [],
            ];
            foreach ($homes as $home) {
                if ($service) {
                    $this->branchServices[$home][] = $sku;
                } else {
                    $this->branchSkus[$home][] = $sku;
                }
            }
            foreach (DemoCatalogue::PACKS[$sku] ?? [] as [$packName, $packUnit, $factor, $retail, $wholesalePack]) {
                $pack = $this->api->post("admin/products/{$product['id']}/units", [
                    'name' => $packName, 'unit_id' => $units[$packUnit] ?? null, 'factor' => $factor,
                    'retail_price' => $retail, 'wholesale_price' => $wholesalePack, 'is_active' => true,
                ])['data'];
                $this->products[$sku]['packs'][] = ['id' => $pack['id'], 'factor' => (float) $factor];
            }
        }
        foreach (DemoCatalogue::SUPPLIED_BY_TRANSFER as $to => [$from]) {
            foreach ($this->transferSkus($to) as $sku) {
                $this->branchSkus[$to][] = $sku;
            }
        }

        foreach (DemoCatalogue::CUSTOMERS as $key => [$name, $phone, $type, $limit, $branch, $email, $address, $tin, $vrn]) {
            $this->api->branch($this->branches[$branch]);
            $this->customers[$key] = $this->api->post('admin/customers', [
                'name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address,
                'city' => $branch === 'TIMBER' ? 'Kibaha' : 'Dar es Salaam', 'tin' => $tin, 'vrn' => $vrn,
                'customer_type' => $type, 'credit_limit' => $limit, 'allow_credit' => $limit > 0, 'sms_opt_in' => true, 'is_active' => true,
            ])['data']['id'];
        }

        // Online-shop customers register themselves.
        foreach (DemoCatalogue::SHOP_CUSTOMERS as $i => [$name, $phone, $email, $address]) {
            $this->at($this->start->copy()->addDays(2 + $i * 6), 19, 15);
            $this->api->guest()->post('shop/register', [
                'name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address, 'city' => 'Dar es Salaam',
                'password' => DemoCatalogue::PASSWORD, 'password_confirmation' => DemoCatalogue::PASSWORD, 'accept_terms' => true,
            ]);
            $this->shopUsers[$i] = User::query()->where('type', User::TYPE_CUSTOMER)->latest('id')->firstOrFail();
        }

        $this->openingTransfers();
        $this->seedFaqs();
    }

    /** Questions and answers for the online shop (also used by its chat assistant). */
    private function seedFaqs(): void
    {
        $this->api->as($this->admin, $this->branches['MAIN']);
        foreach ([
            ['Mnaleta mzigo nyumbani? Ni bei gani?', 'Ndiyo. Tunaleta ndani ya Dar es Salaam: ada ni TZS 1,500 pamoja na TZS 500 kwa kila kilomita (chini kabisa TZS 2,000, hadi km 30). Ada kamili inaonekana unapochagua mahali pako wakati wa kulipia.', 'Usafirishaji'],
            ['Mnafungua saa ngapi?', 'Supermarket, nyama na vinywaji: kila siku saa 1:30 asubuhi hadi saa 3 usiku. Pharmacy: saa 1 asubuhi hadi saa 4 usiku. Hardware na mbao: Jumatatu hadi Jumamosi, saa 2 asubuhi hadi saa 12 jioni.', 'Matawi'],
            ['Ninaweza kulipa kwa M-Pesa?', 'Ndiyo. Unaweza kulipa kwa M-Pesa, Airtel Money, Mixx by Yas au HaloPesa wakati wa kuagiza, au ulipe unapochukua mzigo au unapoletewa.', 'Malipo'],
            ['Naweza kurudisha bidhaa?', 'Ndiyo, ndani ya siku 3 ukiwa na risiti, kama bidhaa haijatumika na kifungashio kiko salama. Nyama na dawa hazirudishwi kwa sababu za kiafya.', 'Kurudisha'],
            ['Do you sell wholesale?', 'Yes. Most products have a wholesale price that applies automatically from the wholesale quantity. Hotels, schools and builders can also open a credit account: call us.', 'Payment'],
            ['Mnauza dawa bila cheti cha daktari?', 'Dawa za kawaida (kama Paracetamol na ORS) ndiyo. Antibiotiki na dawa nyingine zinahitaji cheti cha daktari; mfamasia wetu atakusaidia.', 'Pharmacy'],
        ] as $i => [$question, $answer, $group]) {
            $this->api->post('admin/faqs', ['question' => $question, 'answer' => $answer, 'category' => $group, 'sort_order' => $i, 'is_active' => true]);
        }
    }

    /** @return array<int, string> skus a branch receives from its supplying branch */
    private function transferSkus(string $to): array
    {
        [$from, , , $skus] = DemoCatalogue::SUPPLIED_BY_TRANSFER[$to];

        return $skus ?? array_values(array_filter($this->branchSkus[$from] ?? [], fn ($sku) => $this->products[$sku]['home'] === $from));
    }

    /**
     * Opening day: Pharmacy Kariakoo stocks the new Mbezi pharmacy, and the drinks
     * depot sends the supermarket its first water and soda; both received that afternoon.
     */
    private function openingTransfers(): void
    {
        foreach (DemoCatalogue::SUPPLIED_BY_TRANSFER as $to => [$from, $type, $share]) {
            $this->at($this->start, 10, 30);
            $items = [];
            foreach ($this->transferSkus($to) as $sku) {
                $p = $this->products[$sku];
                $qty = str_contains($p['flags'], 'd') ? round($p['opening'] * $share, 1) : floor($p['opening'] * $share);
                if ($qty > 0) {
                    $items[] = ['product_id' => $p['id'], 'quantity' => $qty];
                }
            }
            $transfer = $this->as($this->manager($from), $from)->post('admin/transfers', array_filter([
                'to_branch_id' => $this->branches[$to], 'type' => $type, 'items' => $items,
                'due_date' => $type === 'credit' ? $this->start->copy()->addDays(14)->toDateString() : null,
                'notes' => 'Mzigo wa kwanza wa '.DemoCatalogue::BRANCHES[$to][0],
            ]))['data'];
            $this->at($this->start, 15, 0);
            $this->as($this->manager($to), $to)->post("admin/transfers/{$transfer['id']}/receive", ['notes' => 'Umepokelewa wote.']);
        }
    }

    private function onHand(string $sku, string $branch): float
    {
        return app(StockService::class)->onHand($this->products[$sku]['id'], $this->branches[$branch]);
    }

    private function manager(string $branch): string
    {
        return DemoCatalogue::BRANCH_PEOPLE[$branch][0];
    }
}
