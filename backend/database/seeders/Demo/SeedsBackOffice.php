<?php

namespace Database\Seeders\Demo;

use App\Models\Finance\Account;
use App\Models\Finance\FiscalYear;
use Illuminate\Support\Carbon;

/**
 * The back office set up on the first day: HR records, owner's capital,
 * fixed assets, budgets, uwakala lines, shift types, promotions and
 * automatic notifications.
 */
trait SeedsBackOffice
{
    /** @var array<string, int> username => employee id */
    private array $employees = [];

    /** @var array<int, array{id: int, provider: string, float: float}> */
    private array $agencyLines = [];

    /** @var array<string, int> shift type name => id */
    private array $shiftTypes = [];

    private function account(string $code): int
    {
        return (int) Account::query()->where('code', $code)->value('id');
    }

    private function seedBackOfficeSetup(): void
    {
        $day = $this->start;

        // Owner's capital and a bank loan, so the bank can pay suppliers and salaries.
        $this->at($day, 8, 15);
        $this->as('mhasibu', 'MAIN');
        $this->api->post('admin/accounting/journals/quick', ['type' => 'capital', 'amount' => 160000000, 'account_id' => $this->account('1200'), 'entry_date' => $day->toDateString(), 'description' => "Owner's capital into NMB account", 'reference' => 'DEP-0001']);
        $this->api->post('admin/accounting/journals/quick', ['type' => 'capital', 'amount' => 7000000, 'account_id' => $this->account('1100'), 'entry_date' => $day->toDateString(), 'description' => 'Cash float for the tills']);
        $this->api->post('admin/accounting/journals/quick', ['type' => 'loan_received', 'amount' => 40000000, 'account_id' => $this->account('1200'), 'entry_date' => $day->toDateString(), 'description' => 'NMB business loan (36 months)', 'reference' => 'NMB-LN-2207']);
        $this->api->post('admin/accounting/accounts', ['code' => '1210', 'name' => 'CRDB current account', 'type' => 'asset', 'sub_type' => 'bank', 'parent_id' => $this->account('1000'), 'bank_name' => 'CRDB Bank', 'account_number' => '0150-3344-2211', 'is_active' => true]);
        $this->api->post('admin/accounting/journals/quick', ['type' => 'transfer', 'amount' => 15000000, 'from_account_id' => $this->account('1200'), 'to_account_id' => $this->account('1210'), 'entry_date' => $day->toDateString(), 'description' => 'Opening CRDB account']);

        foreach ([
            ['Delivery motorbike (Boxer 150)', 'Vehicles', 'MC 123 ABC', '1720', 3200000, 300000, 48, 'MAIN'],
            ['Drinks delivery truck (Canter 3t)', 'Vehicles', 'T 456 DEF', '1720', 38000000, 6000000, 60, 'DRINKS'],
            ['Cold room and meat freezers', 'Equipment', 'CR-2207', '1710', 9500000, 800000, 60, 'BUTCHER'],
            ['Bandsaw and planing machine', 'Equipment', 'BS-8812', '1710', 14000000, 1500000, 72, 'TIMBER'],
            ['Pharmacy medicine fridge', 'Equipment', 'PF-0045', '1710', 2800000, 200000, 60, 'PHARMA1'],
            ['Shop shelves and display fridges', 'Furniture', null, '1710', 6500000, 500000, 60, 'MAIN'],
            ['POS computers and receipt printers (7 branches)', 'Equipment', 'SN-POS-0091', '1710', 8400000, 0, 36, 'MAIN'],
        ] as [$name, $category, $serial, $code, $cost, $salvage, $months, $branch]) {
            $this->api->post('admin/accounting/fixed-assets', [
                'name' => $name, 'category' => $category, 'serial_number' => $serial, 'location' => DemoCatalogue::BRANCHES[$branch][0],
                'asset_account_id' => $this->account($code), 'acquired_on' => $day->toDateString(),
                'cost' => $cost, 'salvage_value' => $salvage, 'useful_life_months' => $months, 'funding' => 'bank',
            ]);
        }

        $year = FiscalYear::query()->where('start_date', '<=', $day->toDateString())->where('end_date', '>=', $day->toDateString())->first();
        if ($year) {
            $monthly = ['6200' => 6200000, '6210' => 1900000, '6220' => 260000, '6300' => 450000, '6310' => 300000, '6100' => 19500000, '6700' => 600000, '4100' => 120000000];
            $this->api->put("admin/accounting/fiscal-years/{$year->id}/budgets", ['rows' => collect($monthly)->map(fn ($amount, $code) => [
                'account_id' => $this->account((string) $code),
                'months' => array_map(fn ($m) => round($amount * (1 + (($m % 3) - 1) * 0.05)), range(1, 12)),
            ])->values()->all()]);
        }

        $this->seedPeople($day);

        // Uwakala lines at the supermarket and its two shifts.
        $this->at($day, 9, 30);
        $this->as('meneja', 'MAIN');
        foreach (DemoCatalogue::AGENCY_LINES as $i => [$name, $provider, $number, $float]) {
            $id = $this->api->post('admin/agency/accounts', ['name' => $name, 'provider' => $provider, 'agent_number' => $number, 'branch_id' => $this->branches['MAIN'], 'sort_order' => $i, 'is_active' => true])['data']['id'];
            $this->agencyLines[] = ['id' => $id, 'provider' => $provider, 'float' => (float) $float];
        }
        $this->as('hrmanager', 'MAIN');
        foreach ([['Asubuhi', '07:00', '15:00', 'green'], ['Jioni', '15:00', '22:00', 'purple']] as [$name, $from, $to, $color]) {
            $this->shiftTypes[$name] = $this->api->post('admin/shifts/types', ['name' => $name, 'start_time' => $from, 'end_time' => $to, 'color' => $color, 'branch_id' => $this->branches['MAIN'], 'is_active' => true])['data']['id'];
        }

        $this->seedPromotionsAndRules();
    }

    /** Departments, positions, allowances and an employee record for every staff member. */
    private function seedPeople(Carbon $day): void
    {
        $this->at($day, 9, 0);
        $this->as('hrmanager', 'MAIN');
        $departments = [];
        foreach (['Management' => 'MGT', 'Sales' => 'SLS', 'Pharmacy' => 'PHA', 'Stores' => 'STR', 'Finance' => 'FIN', 'Logistics' => 'LOG'] as $name => $code) {
            $departments[$name] = $this->api->post('admin/hr/departments', ['name' => $name, 'code' => $code, 'is_active' => true])['data']['id'];
        }
        $positions = [];
        foreach (DemoCatalogue::PAY as [$salary, $title, $department]) {
            $positions[$title] ??= $this->api->post('admin/hr/positions', [
                'title' => $title, 'department_id' => $departments[$department], 'min_salary' => round($salary * 0.8, -4), 'max_salary' => round($salary * 1.5, -4), 'is_active' => true,
            ])['data']['id'];
        }
        $transport = $this->api->post('admin/hr/salary-components', ['name' => 'Transport allowance', 'code' => 'TRANSPORT', 'type' => 'earning', 'calculation' => 'fixed', 'amount' => 60000, 'is_taxable' => true, 'is_pensionable' => false, 'applies_to_all' => true, 'is_active' => true])['data']['id'];
        $this->api->post('admin/hr/salary-components', ['name' => 'Responsibility allowance', 'code' => 'RESP', 'type' => 'earning', 'calculation' => 'percent', 'amount' => 10, 'is_taxable' => true, 'is_pensionable' => true, 'applies_to_all' => false, 'is_active' => true]);
        $this->api->post('admin/hr/salary-components', ['name' => 'SACCOS contribution', 'code' => 'SACCOS', 'type' => 'deduction', 'calculation' => 'fixed', 'amount' => 20000, 'is_taxable' => false, 'is_pensionable' => false, 'applies_to_all' => false, 'is_active' => true]);

        $banks = ['NMB Bank', 'CRDB Bank', 'NBC Bank'];
        foreach (DemoCatalogue::PAY as $username => [$salary, $title, $department]) {
            [$name, , $branch, $phone] = DemoCatalogue::STAFF[$username];
            $parts = explode(' ', $name);
            $byBank = $salary >= 500000;
            $this->employees[$username] = $this->api->post('admin/hr/employees', [
                'first_name' => $parts[0], 'last_name' => end($parts),
                'gender' => in_array($parts[0], DemoCatalogue::WOMEN, true) ? 'female' : 'male',
                'date_of_birth' => $day->copy()->subYears(mt_rand(23, 48))->subDays(mt_rand(0, 300))->toDateString(),
                'marital_status' => $this->pick(['single', 'married']),
                'phone' => $phone, 'address' => $branch === 'TIMBER' ? 'Kibaha' : 'Dar es Salaam',
                'emergency_contact_name' => 'Ndugu wa '.$parts[0], 'emergency_contact_phone' => '2557'.mt_rand(10000000, 99999999), 'emergency_contact_relation' => 'Ndugu',
                'branch_id' => $branch ? $this->branches[$branch] : $this->branches['MAIN'],
                'department_id' => $departments[$department], 'position_id' => $positions[$title],
                'user_id' => $this->user($username)->id,
                'employment_type' => in_array($username, ['muuzaji_saidi', 'mlinzi'], true) ? 'contract' : 'permanent',
                'contract_end_date' => in_array($username, ['muuzaji_saidi', 'mlinzi'], true) ? $day->copy()->addMonths(8)->toDateString() : null,
                'hire_date' => $day->copy()->subMonths(mt_rand(4, 60))->toDateString(),
                'basic_salary' => $salary,
                'payment_method' => $byBank ? 'bank' : 'mobile_money',
                'bank_name' => $byBank ? $this->pick($banks) : null,
                'bank_account_number' => $byBank ? (string) mt_rand(1000000000, 9999999999) : null,
                'mobile_money_number' => $byBank ? null : $phone,
                'tin' => $byBank ? mt_rand(100, 999).'-'.mt_rand(100, 999).'-'.mt_rand(100, 999) : null,
                'nssf_number' => (string) mt_rand(10000000, 99999999),
                'components' => array_values(array_filter([
                    ['salary_component_id' => $transport],
                ])),
            ])['data']['id'];
        }
    }

    private function seedPromotionsAndRules(): void
    {
        $this->at($this->start, 11, 0);
        $this->as('meneja', 'MAIN');
        $category = fn (string $name) => \App\Models\Catalog\Category::query()->where('name', $name)->value('id');
        $promos = [
            ['Ofa ya Vinywaji 10%', 'Punguzo la 10% kwa maji, soda na juisi.', 'percent', 10, 'categories', [$category('Water & Soft Drinks')], 0, 20, 45],
            ['Wiki ya Usafi: punguzo 2,000', 'Nunua bidhaa za usafi za 20,000 au zaidi upate punguzo la 2,000.', 'amount', 2000, 'categories', [$category('Household')], 20000, self::DAYS - 9, self::DAYS + 5],
            ['Msimu wa Ujenzi 5%', 'Punguzo la 5% kwa vifaa vya ujenzi na mbao, ununuzi wa 500,000 au zaidi.', 'percent', 5, 'categories', [$category('Building Materials'), $category('Timber & Boards')], 500000, 40, self::DAYS + 20],
            ['Nyama ya Sikukuu 5%', 'Punguzo la 5% kwa nyama na kuku msimu wa sikukuu.', 'percent', 5, 'categories', [$category('Meat & Poultry')], 30000, self::DAYS + 25, self::DAYS + 60],
        ];
        foreach ($promos as [$name, $description, $type, $value, $appliesTo, $categories, $min, $from, $to]) {
            $this->api->post('admin/promotions', [
                'name' => $name, 'description' => $description, 'type' => $type, 'value' => $value,
                'applies_to' => $appliesTo, 'category_ids' => $categories ?: null, 'min_purchase' => $min ?: null,
                'max_discount' => $type === 'percent' ? 50000 : null, 'audience' => 'all',
                'starts_on' => $this->start->copy()->addDays($from)->toDateString(), 'ends_on' => $this->start->copy()->addDays($to)->toDateString(),
                'uses_per_customer' => null, 'is_active' => true,
            ]);
        }
        $this->api->post('admin/promotions', [
            'name' => 'Wateja wetu wakubwa (VIP) 7%', 'description' => 'Asante kwa kuwa nasi: 7% kwa wateja wa VIP.', 'type' => 'percent', 'value' => 7,
            'applies_to' => 'all', 'audience' => 'customers', 'customer_ids' => [$this->customers['hotel.sea'], $this->customers['fundi.jumanne'], $this->customers['samani.bora']],
            'max_discount' => 100000, 'starts_on' => $this->start->copy()->addDays(30)->toDateString(), 'ends_on' => $this->start->copy()->addDays(self::DAYS + 30)->toDateString(),
            'uses_per_customer' => 5, 'is_active' => true,
        ]);

        $this->as('admin', 'MAIN');
        $adminRole = \App\Models\Core\Role::query()->whereIn('slug', ['admin', 'accountant'])->pluck('id')->all();
        foreach ([
            ['Muhtasari wa mauzo kila siku', 'sales_summary', 'daily', '20:30', null, null, 'today', ['sms', 'email']],
            ['Bidhaa zinazoisha (kila Jumatatu)', 'stock_alerts', 'weekly', '07:30', 1, null, 'today', ['email']],
            ['Madeni yaliyochelewa', 'overdue_debts', 'weekly', '09:00', 5, null, 'today', ['sms']],
            ['Matumizi ya mwezi', 'expenses', 'monthly', '08:00', null, 1, 'last_month', ['email']],
        ] as [$name, $source, $frequency, $time, $weekday, $monthDay, $period, $channels]) {
            $this->api->post('admin/notification-rules', [
                'name' => $name, 'source' => $source, 'channels' => $channels, 'role_ids' => $adminRole, 'send_to_role_users' => true,
                'include_company_contacts' => false, 'frequency' => $frequency, 'time' => $time, 'weekday' => $weekday, 'month_day' => $monthDay,
                'period' => $period, 'skip_when_empty' => true, 'is_active' => true,
            ]);
        }
    }
}
