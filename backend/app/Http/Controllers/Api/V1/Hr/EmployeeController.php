<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeDocument;
use App\Models\Hr\LeaveType;
use App\Services\Core\AuditLogger;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\LeaveService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly LeaveService $leave,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->applyTrashed($this->scoped(), $request)
            ->with(['department:id,name', 'position:id,title', 'branch:id,name'])
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('first_name', 'like', "%{$t}%")
                ->orWhere('last_name', 'like', "%{$t}%")->orWhere('employee_no', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")))
            ->when($request->query('status'), fn ($q, $v) => $v === 'employed' ? $q->employed() : $q->where('status', $v))
            ->when($request->query('department_id'), fn ($q, $v) => $q->where('department_id', $v))
            ->when($request->query('employment_type'), fn ($q, $v) => $q->where('employment_type', $v));
        $this->sortable($query, $request, ['first_name', 'employee_no', 'hire_date', 'basic_salary'], 'first_name', 'asc');

        $page = $query->paginate($this->perPage($request));
        if (! $request->user()->hasPermission('employees.view_salary')) {
            $page->getCollection()->each->makeHidden(Employee::SALARY_FIELDS);
        }

        return $this->paginated($page);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $this->scoped(Employee::withTrashed())
            ->with(['department:id,name', 'position:id,title', 'branch:id,name', 'manager:id,first_name,last_name', 'user:id,username,is_active', 'components', 'documents'])
            ->findOrFail($id);

        $year = (int) now()->year;
        $balances = LeaveType::query()->where('is_active', true)->get()
            ->filter(fn ($t) => ! $t->gender || ! $employee->gender || $t->gender === $employee->gender)
            ->map(fn ($t) => $this->leave->balance($employee, $t, $year)->load('leaveType:id,name,code,is_paid'))->values();

        if (! $request->user()->hasPermission('employees.view_salary')) {
            $employee->makeHidden(Employee::SALARY_FIELDS)->setRelation('components', collect());
        }

        return $this->ok(['employee' => $employee, 'leave_balances' => $balances]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        // No branch chosen: the branch being worked in (none = company-wide when viewing all branches).
        $data['branch_id'] ??= $this->branch()->id();
        $this->assertBranchAllowed($data['branch_id'] ?? null);
        $employee = $this->employees->create($data, $request->file('photo'));

        return $this->created($employee->load(['department:id,name', 'position:id,title']), __('Employee :name added with number :no.', ['name' => $employee->full_name, 'no' => $employee->employee_no]));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $employee = $this->scoped()->findOrFail($id);
        if (! $request->user()->hasPermission('employees.view_salary')) {
            // Pay details are hidden from this user: validate against what is stored.
            $request->merge(['basic_salary' => $employee->basic_salary, 'payment_method' => $employee->payment_method, 'bank_name' => $employee->bank_name,
                'bank_account_number' => $employee->bank_account_number, 'mobile_money_number' => $employee->mobile_money_number]);
        }
        $data = $this->validated($request, $employee);
        if (! $request->user()->hasPermission('employees.view_salary')) {
            // Without salary rights the pay fields cannot be changed either.
            $data = array_diff_key($data, array_flip([...Employee::SALARY_FIELDS, 'payment_method', 'components']));
        }
        $employee = $this->employees->update($employee, $data, $request->file('photo'), $request->boolean('remove_photo'));

        return $this->ok($employee->fresh(['department:id,name', 'position:id,title']), __('Employee :name updated successfully.', ['name' => $employee->full_name]));
    }

    public function destroy(int $id): JsonResponse
    {
        $employee = $this->scoped()->findOrFail($id);
        $employee->delete();

        return $this->ok(null, __('Employee deleted. It can be restored.'));
    }

    public function restore(int $id): JsonResponse
    {
        $employee = $this->scoped(Employee::onlyTrashed())->findOrFail($id);
        $employee->restore();

        return $this->ok($employee, __('Employee restored successfully.'));
    }

    public function terminate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['terminated', 'resigned'])],
            'exit_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $employee = $this->employees->terminate($this->scoped()->findOrFail($id), $data['status'], $data['exit_date'], $data['reason']);

        return $this->ok($employee, __('Employment of :name ended.', ['name' => $employee->full_name]));
    }

    /** Create or change the employee's system login (e.g. one forgotten when the employee was added). */
    public function login(Request $request, int $id): JsonResponse
    {
        $employee = $this->scoped()->findOrFail($id);
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($employee->user_id)],
            'password' => [$employee->user_id ? 'nullable' : 'required', 'string', Password::defaults()],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $created = ! $employee->user_id;
        $user = $this->employees->saveLogin($employee, $data);

        return $this->ok(['user' => $user->only(['id', 'username', 'is_active'])], $created
            ? __('Login :username created. The employee must change the password at first login.', ['username' => $user->username])
            : __('Login updated.'));
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])], 'note' => ['nullable', 'string', 'max:500']]);
        $employee = $this->employees->changeStatus($this->scoped()->findOrFail($id), $data['status'], $data['note'] ?? null);

        return $this->ok($employee, __('Employee status updated.'));
    }

    // -------------------------------------------------------------- documents

    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(EmployeeDocument::TYPES)],
            'expires_on' => ['nullable', 'date'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
        ]);
        $document = $this->employees->addDocument($this->scoped()->findOrFail($id), $request->file('file'), $data);

        return $this->created($document, __('Document uploaded successfully.'));
    }

    public function downloadDocument(int $id, int $documentId): StreamedResponse
    {
        $employee = $this->scoped()->findOrFail($id);
        $document = $employee->documents()->findOrFail($documentId);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);
        app(AuditLogger::class)->event('employee.document_downloaded', __('Document ":title" of :name downloaded', ['title' => $document->title, 'name' => $employee->full_name]), $employee, ['document_id' => $document->id], 'security');

        return Storage::disk('local')->download($document->file_path, $document->original_name, [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function deleteDocument(int $id, int $documentId): JsonResponse
    {
        $employee = $this->scoped()->findOrFail($id);
        $employee->documents()->findOrFail($documentId)->delete();

        return $this->ok(null, __('Document deleted.'));
    }

    // ---------------------------------------------------------------- helpers

    private function assertBranchAllowed(?int $branchId): void
    {
        if ($branchId && ! $this->branch()->canAccessAll() && ! in_array($branchId, $this->branch()->accessibleIds(), true)) {
            throw new BusinessException(__('You can only add employees to your own branch.'), 'BRANCH_NOT_ALLOWED', 403);
        }
    }

    private function scoped(?Builder $query = null): Builder
    {
        $query ??= Employee::query();

        // Employees without a branch belong to the whole company, so every HR user sees them.
        return $this->branch()->canAccessAll() ? $query : $query->where(fn ($q) => $q->whereIn('branch_id', $this->branch()->accessibleIds())->orWhereNull('branch_id'));
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        $phone = ['nullable', 'string', 'regex:/^\+?[0-9 ]{9,15}$/'];

        return $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'middle_name' => ['nullable', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:-15 years'],
            'marital_status' => ['nullable', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'national_id' => ['nullable', 'string', 'max:40'],
            'phone' => $phone,
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => $phone,
            'emergency_contact_relation' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')->whereNull('deleted_at')],
            'manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at'), $employee ? Rule::notIn([$employee->id]) : 'nullable'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'employment_type' => ['required', Rule::in(Employee::TYPES)],
            'hire_date' => ['required', 'date'],
            'probation_end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'contract_end_date' => ['nullable', 'date', 'after:hire_date', Rule::requiredIf(fn () => $request->input('employment_type') === 'contract')],
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'payment_method' => ['required', Rule::in(['bank', 'mobile_money', 'cash'])],
            'bank_name' => ['nullable', 'required_if:payment_method,bank', 'string', 'max:100'],
            'bank_account_number' => ['nullable', 'required_if:payment_method,bank', 'string', 'max:40'],
            'mobile_money_number' => ['nullable', 'required_if:payment_method,mobile_money', 'string', 'regex:/^\+?[0-9 ]{9,15}$/'],
            'tin' => ['nullable', 'string', 'max:30'],
            'nssf_number' => ['nullable', 'string', 'max:40'],
            'nhif_number' => ['nullable', 'string', 'max:40'],
            'paye_exempt' => ['boolean'],
            'nssf_exempt' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:3072'],
            'components' => ['sometimes', 'array', 'max:30'],
            'components.*.salary_component_id' => ['required', 'integer', 'distinct', Rule::exists('salary_components', 'id')->whereNull('deleted_at')],
            'components.*.amount' => ['nullable', 'numeric', 'min:0'],
            'components.*.starts_on' => ['nullable', 'date'],
            'components.*.ends_on' => ['nullable', 'date'],
            'login' => [$employee ? 'prohibited' : 'nullable', 'array'],
            'login.username' => ['required_with:login', 'string', 'min:3', 'max:60', 'alpha_dash', Rule::unique('users', 'username')],
            'login.password' => ['required_with:login', Password::defaults()],
        ]);
    }
}
