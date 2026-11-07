<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Core\Role;
use App\Models\Core\User;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeDocument;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class EmployeeService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  employee fields, plus optional components and login
     */
    public function create(array $data, ?UploadedFile $photo = null): Employee
    {
        return DB::transaction(function () use ($data, $photo) {
            $employee = new Employee(collect($data)->only((new Employee)->getFillable())->all());
            $employee->employee_no = $this->numbers->next('employee');
            if ($photo) {
                $employee->photo_path = $photo->store('employees', 'public');
            }
            if (! empty($data['login'])) {
                $employee->user_id = $this->createLogin($employee, $data['login'])->id;
            }
            $this->assertLinkable($employee->user_id);
            $employee->save();

            if (isset($data['components'])) {
                $this->syncComponents($employee, $data['components']);
            }

            return $employee;
        });
    }

    public function update(Employee $employee, array $data, ?UploadedFile $photo = null, bool $removePhoto = false): Employee
    {
        return DB::transaction(function () use ($employee, $data, $photo, $removePhoto) {
            if (array_key_exists('user_id', $data) && $data['user_id'] != $employee->user_id) {
                $this->assertLinkable($data['user_id'], $employee->id);
            }
            $employee->fill(collect($data)->only($employee->getFillable())->all());
            if ($photo || $removePhoto) {
                if ($employee->photo_path) {
                    Storage::disk('public')->delete($employee->photo_path);
                }
                $employee->photo_path = $photo?->store('employees', 'public');
            }
            $employee->save();

            if (isset($data['components'])) {
                $this->syncComponents($employee, $data['components']);
            }

            return $employee;
        });
    }

    /** End employment. The employee's login is disabled and signed out everywhere. */
    public function terminate(Employee $employee, string $status, string $date, string $reason): Employee
    {
        if (! in_array($status, ['terminated', 'resigned'], true)) {
            throw new BusinessException(__('Choose terminated or resigned.'));
        }
        if (! $employee->isEmployed()) {
            throw new BusinessException(__(':name is no longer employed.', ['name' => $employee->full_name]));
        }

        return DB::transaction(function () use ($employee, $status, $date, $reason) {
            $employee->forceFill(['status' => $status, 'exit_date' => $date, 'exit_reason' => $reason])->save();
            if ($employee->user) {
                $employee->user->forceFill(['is_active' => false])->save();
                $employee->user->tokens()->delete();
            }
            $this->audit->event('employee.exit', __(':name :status on :date', ['name' => $employee->full_name, 'status' => __($status), 'date' => $date]), $employee, ['reason' => $reason]);

            return $employee;
        });
    }

    public function changeStatus(Employee $employee, string $status, ?string $note): Employee
    {
        if (! in_array($status, ['active', 'suspended'], true) || ! $employee->isEmployed()) {
            throw new BusinessException(__('This status change is not allowed.'));
        }
        $from = $employee->status;
        $employee->forceFill(['status' => $status])->save();
        $this->audit->event('employee.status', __(':name: :from → :to', ['name' => $employee->full_name, 'from' => __($from), 'to' => __($status)]), $employee, ['note' => $note]);

        return $employee;
    }

    public function addDocument(Employee $employee, UploadedFile $file, array $data): EmployeeDocument
    {
        // Private disk: never directly reachable from the web.
        $path = $file->store("hr/documents/{$employee->id}", 'local');

        return $employee->documents()->create([
            'title' => $data['title'],
            'type' => $data['type'] ?? 'other',
            'file_path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'expires_on' => $data['expires_on'] ?? null,
        ]);
    }

    /** @param array<int, array{salary_component_id: int, amount?: ?float, starts_on?: ?string, ends_on?: ?string}> $components */
    public function syncComponents(Employee $employee, array $components): void
    {
        $employee->components()->sync(collect($components)->mapWithKeys(fn ($c) => [
            $c['salary_component_id'] => [
                'amount' => $c['amount'] ?? null,
                'starts_on' => $c['starts_on'] ?? null,
                'ends_on' => $c['ends_on'] ?? null,
            ],
        ])->all());
    }

    /**
     * Create the employee's system login, or change the existing one (username,
     * new password, enabled). Lets HR add a login that was forgotten at hiring.
     *
     * @param  array{username: string, password?: ?string, is_active?: bool}  $data
     */
    public function saveLogin(Employee $employee, array $data): User
    {
        return DB::transaction(function () use ($employee, $data) {
            $user = $employee->user_id ? User::query()->lockForUpdate()->find($employee->user_id) : null;

            if (! $user) {
                if (empty($data['password'])) {
                    throw new BusinessException(__('Enter a password for the new login.'));
                }
                $user = $this->createLogin($employee, ['username' => $data['username'], 'password' => $data['password']]);
                $employee->forceFill(['user_id' => $user->id])->save();
                $this->audit->event('employee.login_created', __('Login :username created for :name', ['username' => $user->username, 'name' => $employee->full_name]), $employee, [], 'security');

                return $user;
            }

            $user->username = $data['username'];
            if (array_key_exists('is_active', $data)) {
                $user->is_active = (bool) $data['is_active'];
            }
            $passwordChanged = ! empty($data['password']);
            if ($passwordChanged) {
                $user->password = Hash::make($data['password']);
                $user->must_change_password = true;
            }
            $user->save();
            if ($passwordChanged || ! $user->is_active) {
                $user->tokens()->delete(); // signed out everywhere
            }
            $this->audit->event('employee.login_updated', __('Login of :name updated', ['name' => $employee->full_name]), $employee, ['password_reset' => $passwordChanged, 'active' => $user->is_active], 'security');

            return $user;
        });
    }

    /** @param array{username: string, password: string} $login */
    private function createLogin(Employee $employee, array $login): User
    {
        $role = Role::query()->where('slug', 'employee')->first()
            ?? throw new BusinessException(__('The Employee role is missing. Run the seeders.'));

        return User::create([
            'type' => User::TYPE_STAFF,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'username' => $login['username'],
            'email' => $employee->email,
            'phone' => $employee->phone,
            'password' => Hash::make($login['password']),
            'role_id' => $role->id,
            'branch_id' => $employee->branch_id,
            'is_active' => true,
            'must_change_password' => true,
        ]);
    }

    private function assertLinkable(?int $userId, ?int $ignoreEmployeeId = null): void
    {
        if (! $userId) {
            return;
        }
        $user = User::query()->find($userId);
        if (! $user || $user->type !== User::TYPE_STAFF) {
            throw new BusinessException(__('Only staff user accounts can be linked to an employee.'));
        }
        $taken = Employee::withTrashed()->where('user_id', $userId)->when($ignoreEmployeeId, fn ($q) => $q->whereKeyNot($ignoreEmployeeId))->exists();
        if ($taken) {
            throw new BusinessException(__('That user account is already linked to another employee.'));
        }
    }
}
