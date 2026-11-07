<?php

namespace Tests\Feature;

use App\Models\Core\Permission;
use App\Models\Core\Role;
use App\Models\Core\User;
use App\Models\Hr\Employee;
use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveType;
use App\Models\Hr\Payslip;
use App\Services\Hr\PayrollCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HrPayrollTest extends TestCase
{
    private function employee(array $attributes = []): Employee
    {
        $e = new Employee(array_merge([
            'first_name' => 'Neema', 'last_name' => 'Mushi', 'gender' => 'female', 'employment_type' => 'permanent',
            'hire_date' => now()->subYear()->startOfYear(), 'basic_salary' => 1000000, 'payment_method' => 'bank',
            'bank_name' => 'CRDB', 'bank_account_number' => '0150123456789', 'branch_id' => $this->mainBranch()->id,
        ], $attributes));
        $e->employee_no = 'EMP-'.uniqid();
        $e->save();

        return $e;
    }

    public function test_paye_nssf_and_employer_levies_follow_settings(): void
    {
        $calc = app(PayrollCalculator::class);

        $r = $calc->calculate(['basic' => 1000000, 'earnings' => [], 'deductions' => []]);
        $this->assertSame(100000.0, $r['nssf_employee']);
        $this->assertSame(900000.0, $r['taxable_income']);
        $this->assertSame(103000.0, $r['paye']);           // 68,000 + 25% of 140,000
        $this->assertSame(797000.0, $r['net']);
        $this->assertSame(35000.0, $r['sdl']);
        $this->assertSame(5000.0, $r['wcf']);
        $this->assertSame(100000.0, $r['nssf_employer']);

        $this->assertSame(14400.0, $calc->calculate(['basic' => 500000, 'earnings' => [], 'deductions' => []])['paye']);
        $this->assertSame(0.0, $calc->calculate(['basic' => 300000, 'earnings' => [], 'deductions' => []])['paye']);

        // Deductions never make the net negative.
        $r = $calc->calculate(['basic' => 100000, 'earnings' => [], 'deductions' => [['name' => 'Loan', 'amount' => 500000]], 'advance_due' => 50000]);
        $this->assertSame(0.0, $r['net']);
        $this->assertSame(0.0, $r['advance_recovery']);
    }

    public function test_employee_records_encrypt_ids_and_hide_salaries_without_permission(): void
    {
        $this->actingAsStaff('hr-manager');

        $id = $this->postJson('/api/v1/admin/hr/employees', [
            'first_name' => 'Juma', 'last_name' => 'Hassan', 'gender' => 'male', 'employment_type' => 'permanent',
            'hire_date' => now()->subMonths(6)->toDateString(), 'basic_salary' => 800000, 'payment_method' => 'mobile_money',
            'mobile_money_number' => '0712345678', 'national_id' => '19900101-12345-00001-11', 'tin' => '123-456-789',
            'login' => ['username' => 'juma_hassan', 'password' => 'Secret123!'],
        ])->assertCreated()->json('data.id');

        $raw = DB::table('employees')->where('id', $id)->first();
        $this->assertNotSame('19900101-12345-00001-11', $raw->national_id);
        $this->assertSame('19900101-12345-00001-11', Employee::find($id)->national_id);
        $this->assertSame('employee', User::find($raw->user_id)->role->slug);
        $this->assertTrue(DB::table('audit_logs')->where('auditable_type', 'employee')->exists(), 'Employee creation should be audited.');
        $this->assertFalse(DB::table('audit_logs')->where('new_values', 'like', '%19900101%')->orWhere('new_values', 'like', '%123-456-789%')->exists(), 'Decrypted IDs must never reach the audit log.');

        $this->getJson("/api/v1/admin/hr/employees/{$id}")->assertOk()
            ->assertJsonPath('data.employee.basic_salary', '800000.00')
            // Calendar dates travel as plain Y-m-d so no time zone can shift them by a day.
            ->assertJsonPath('data.employee.hire_date', now()->subMonths(6)->toDateString());

        $this->actingAsStaff('accountant'); // employees.view but not view_salary
        $res = $this->getJson("/api/v1/admin/hr/employees/{$id}")->assertOk();
        $this->assertArrayNotHasKey('basic_salary', $res->json('data.employee'));
        $this->assertArrayNotHasKey('national_id', $res->json('data.employee'));
        $this->postJson('/api/v1/admin/hr/employees', ['first_name' => 'X'])->assertForbidden();
    }

    public function test_editing_an_employee_without_salary_access_keeps_pay_details(): void
    {
        $employee = $this->employee(['basic_salary' => 1200000, 'bank_name' => 'NMB']);
        $role = Role::query()->create(['name' => 'HR clerk', 'slug' => 'hr-clerk']);
        $role->permissions()->sync(Permission::query()->whereIn('key', ['employees.view', 'employees.update'])->pluck('id'));
        Sanctum::actingAs($this->staff('hr-clerk'));

        $this->putJson("/api/v1/admin/hr/employees/{$employee->id}", [
            'first_name' => 'Renamed', 'last_name' => $employee->last_name, 'employment_type' => 'permanent',
            'hire_date' => $employee->hire_date->toDateString(),
            'basic_salary' => 1, 'bank_name' => 'Tampered', // ignored: this user cannot see pay
        ])->assertOk();

        $employee->refresh();
        $this->assertSame('Renamed', $employee->first_name);
        $this->assertSame('1200000.00', $employee->basic_salary);
        $this->assertSame('NMB', $employee->bank_name);
    }

    public function test_leave_request_approval_and_balance(): void
    {
        // Early in the year, so the 8-week request below never crosses into the next year.
        $this->travelTo(Carbon::create((int) now()->year, 3, 2, 9));
        Holiday::query()->forceDelete();
        $hr = $this->actingAsStaff('hr-manager');
        $employee = $this->employee();
        $annual = LeaveType::where('code', 'ANNUAL')->firstOrFail();
        $monday = Carbon::parse('next monday')->addWeek();

        $id = $this->postJson('/api/v1/admin/hr/leave', [
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id,
            'start_date' => $monday->toDateString(), 'end_date' => $monday->copy()->addDays(2)->toDateString(),
        ])->assertCreated()->assertJsonPath('data.days', '3.0')->json('data.id');

        // Overlapping request refused.
        $this->postJson('/api/v1/admin/hr/leave', [
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id,
            'start_date' => $monday->copy()->addDay()->toDateString(), 'end_date' => $monday->copy()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('code', 'OVERLAP');

        $this->postJson("/api/v1/admin/hr/leave/{$id}/approve")->assertOk();
        $balance = $this->getJson('/api/v1/admin/hr/leave/balances?employee_id='.$employee->id)->assertOk()->json('data.0.balances');
        $annualBalance = collect($balance)->firstWhere('leave_type_id', $annual->id);
        $this->assertEquals(3, $annualBalance['taken']);
        $this->assertEquals(25, $annualBalance['remaining']);
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'status' => 'on_leave']);

        // Too many days.
        $this->postJson('/api/v1/admin/hr/leave', [
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id,
            'start_date' => $monday->copy()->addWeeks(2)->toDateString(), 'end_date' => $monday->copy()->addWeeks(8)->toDateString(),
        ])->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_LEAVE');

        // Nobody approves their own leave.
        $own = $this->employee(['user_id' => $hr->id, 'first_name' => 'Hr']);
        $ownId = $this->postJson('/api/v1/admin/hr/leave', [
            'employee_id' => $own->id, 'leave_type_id' => $annual->id,
            'start_date' => $monday->toDateString(), 'end_date' => $monday->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/hr/leave/{$ownId}/approve")->assertForbidden();
    }

    public function test_payroll_prepare_approve_pay_posts_balanced_entries_and_recovers_advances(): void
    {
        $preparer = $this->actingAsStaff('hr-manager');
        $a = $this->employee(['basic_salary' => 1000000]);
        $this->employee(['first_name' => 'Ali', 'basic_salary' => 500000, 'gender' => 'male']);

        // Advance of 200,000 over 2 months for employee A.
        $advanceId = $this->postJson('/api/v1/admin/hr/advances', ['employee_id' => $a->id, 'amount' => 200000, 'installments' => 2])->assertCreated()->json('data.id');
        $this->actingAsStaff('accountant');
        $this->postJson("/api/v1/admin/hr/advances/{$advanceId}/decide", ['approve' => true])->assertOk();
        $this->postJson("/api/v1/admin/hr/advances/{$advanceId}/pay", ['method' => 'cash'])->assertOk();

        Sanctum::actingAs($preparer);
        $runId = $this->postJson('/api/v1/admin/hr/payroll', ['period' => now()->format('Y-m')])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/hr/payroll', ['period' => now()->format('Y-m')])->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_PAYROLL');

        $slip = Payslip::where('payroll_run_id', $runId)->where('employee_id', $a->id)->firstOrFail();
        $this->assertSame('100000.00', $slip->advance_recovery);
        $this->assertSame('697000.00', $slip->net); // 797,000 - 100,000 advance

        // Segregation of duties: the preparer cannot approve.
        $this->postJson("/api/v1/admin/hr/payroll/{$runId}/approve")->assertForbidden();

        $this->actingAsStaff('accountant');
        $this->postJson("/api/v1/admin/hr/payroll/{$runId}/approve")->assertOk();

        // A: net 697,000; B (500,000): NSSF 50,000, PAYE 14,400, net 435,600. Under 10 staff: no SDL.
        $this->assertSame(-1132600.0, $this->ledgerBalance('salaries_payable'));
        $this->assertSame(-117400.0, $this->ledgerBalance('paye_payable'));
        $this->assertSame(-300000.0, $this->ledgerBalance('nssf_payable'));
        $this->assertSame(0.0, $this->ledgerBalance('sdl_payable'));
        $this->assertSame(100000.0, $this->ledgerBalance('employee_advances'));
        $this->assertDatabaseHas('salary_advances', ['id' => $advanceId, 'recovered' => 100000, 'status' => 'paid']);

        $this->postJson("/api/v1/admin/hr/payroll/{$runId}/pay", ['method' => 'bank', 'reference' => 'BATCH-1'])->assertOk();
        $this->assertSame(0.0, $this->ledgerBalance('salaries_payable'));

        $this->postJson('/api/v1/admin/hr/payroll/remittances', ['type' => 'paye', 'amount' => 117400, 'method' => 'bank', 'date' => now()->toDateString()])->assertOk();
        $this->assertSame(0.0, $this->ledgerBalance('paye_payable'));

        $this->get("/api/v1/admin/hr/payroll/{$runId}/payslips/{$slip->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertLedgerBalanced();
    }

    public function test_cancelling_an_approved_payroll_reverses_postings_and_restores_advances(): void
    {
        $preparer = $this->actingAsStaff('hr-manager');
        $a = $this->employee();
        $advanceId = $this->postJson('/api/v1/admin/hr/advances', ['employee_id' => $a->id, 'amount' => 100000, 'installments' => 1])->json('data.id');
        $this->actingAsStaff('accountant');
        $this->postJson("/api/v1/admin/hr/advances/{$advanceId}/decide", ['approve' => true])->assertOk();
        $this->postJson("/api/v1/admin/hr/advances/{$advanceId}/pay", ['method' => 'cash'])->assertOk();
        Sanctum::actingAs($preparer);
        $runId = $this->postJson('/api/v1/admin/hr/payroll', ['period' => now()->format('Y-m')])->json('data.id');
        $this->actingAsStaff('accountant');
        $this->postJson("/api/v1/admin/hr/payroll/{$runId}/approve")->assertOk();
        $this->assertDatabaseHas('salary_advances', ['id' => $advanceId, 'status' => 'recovered']);

        $this->postJson("/api/v1/admin/hr/payroll/{$runId}/cancel", ['reason' => 'Wrong month'])->assertOk();
        $this->assertSame(0.0, $this->ledgerBalance('salaries_payable'));
        $this->assertSame(100000.0, $this->ledgerBalance('employee_advances'));
        $this->assertDatabaseHas('salary_advances', ['id' => $advanceId, 'status' => 'paid', 'recovered' => 0]);
        $this->assertLedgerBalanced();

        // A new payroll for the same month is allowed after cancelling.
        Sanctum::actingAs($preparer);
        $this->postJson('/api/v1/admin/hr/payroll', ['period' => now()->format('Y-m')])->assertCreated();
    }

    public function test_employee_self_service_sees_only_own_records(): void
    {
        Holiday::query()->forceDelete();
        $user = $this->staff('employee');
        $mine = $this->employee(['user_id' => $user->id]);
        $other = $this->employee(['first_name' => 'Other']);

        $hr = $this->actingAsStaff('hr-manager');
        $runId = $this->postJson('/api/v1/admin/hr/payroll', ['period' => now()->format('Y-m')])->json('data.id');
        $this->actingAsStaff('accountant');
        $this->postJson("/api/v1/admin/hr/payroll/{$runId}/approve")->assertOk();
        $otherSlip = Payslip::where('employee_id', $other->id)->firstOrFail();

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/hr/me')->assertOk()->assertJsonPath('data.id', $mine->id);
        $slips = $this->getJson('/api/v1/admin/hr/me/payslips')->assertOk()->json('data');
        $this->assertCount(1, $slips);
        $this->get('/api/v1/admin/hr/me/payslips/'.$slips[0]['id'].'/pdf')->assertOk();
        $this->get('/api/v1/admin/hr/me/payslips/'.$otherSlip->id.'/pdf')->assertNotFound();

        // HR pages are closed to a plain employee.
        $this->getJson('/api/v1/admin/hr/employees')->assertForbidden();
        $this->getJson('/api/v1/admin/hr/payroll')->assertForbidden();

        $this->postJson('/api/v1/admin/hr/me/attendance/clock-in')->assertCreated();
        $this->postJson('/api/v1/admin/hr/me/attendance/clock-in')->assertStatus(422)->assertJsonPath('code', 'ALREADY_CLOCKED_IN');
        $this->postJson('/api/v1/admin/hr/me/attendance/clock-out')->assertOk();

        $monday = Carbon::parse('next monday')->addWeek();
        $leaveId = $this->postJson('/api/v1/admin/hr/me/leave', [
            'leave_type_id' => LeaveType::where('code', 'ANNUAL')->value('id'),
            'start_date' => $monday->toDateString(), 'end_date' => $monday->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/hr/me/leave/{$leaveId}/cancel")->assertOk();

        $this->postJson('/api/v1/admin/hr/me/advances', ['amount' => 900000, 'installments' => 2, 'reason' => 'School fees'])
            ->assertStatus(422)->assertJsonPath('code', 'ADVANCE_LIMIT'); // max 50% of basic
        $this->postJson('/api/v1/admin/hr/me/advances', ['amount' => 300000, 'installments' => 2, 'reason' => 'School fees'])->assertCreated();
        unset($hr);
    }

    public function test_paye_bands_are_validated_and_used(): void
    {
        $this->actingAsStaff('super-admin');
        $this->putJson('/api/v1/admin/settings', ['hr_paye_bands' => [['from' => 0, 'to' => 100, 'rate' => 150, 'base' => 0]]])
            ->assertStatus(422)->assertJsonValidationErrors('hr_paye_bands.0.rate');

        $this->putJson('/api/v1/admin/settings', ['hr_paye_bands' => [
            ['from' => 0, 'to' => 500000, 'rate' => 0, 'base' => 0],
            ['from' => 500000, 'to' => null, 'rate' => 10, 'base' => 0],
        ]])->assertOk();
        // 1,000,000 gross - 100,000 NSSF = 900,000 taxable -> 10% of 400,000
        $this->getJson('/api/v1/admin/hr/payroll/preview?basic=1000000')->assertOk()->assertJsonPath('data.paye', 40000);
    }

    public function test_hr_can_add_or_change_an_employee_login_later(): void
    {
        $employee = $this->employee();
        $this->actingAsStaff('hr-manager');

        $this->putJson("/api/v1/admin/hr/employees/{$employee->id}/login", ['username' => 'neema'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson("/api/v1/admin/hr/employees/{$employee->id}/login", ['username' => 'neema', 'password' => 'Secret123!'])->assertOk();

        $user = User::where('username', 'neema')->firstOrFail();
        $this->assertSame($user->id, $employee->fresh()->user_id);
        $this->assertTrue($user->must_change_password);
        $this->assertSame('employee', $user->role->slug);

        $this->putJson("/api/v1/admin/hr/employees/{$employee->id}/login", ['username' => 'neema_m', 'is_active' => false])->assertOk();
        $user->refresh();
        $this->assertSame('neema_m', $user->username);
        $this->assertFalse($user->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.login_updated']);
    }

    public function test_branch_hr_sees_employees_added_without_a_branch(): void
    {
        $unassigned = $this->employee(['branch_id' => null]);
        $role = Role::where('slug', 'hr-manager')->first();
        $role->forceFill(['all_branches' => false])->save();
        Sanctum::actingAs($this->staff('hr-manager', null, ['all_branches' => false]));

        $ids = collect($this->getJson('/api/v1/admin/hr/employees?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($unassigned->id, $ids);
    }
}
