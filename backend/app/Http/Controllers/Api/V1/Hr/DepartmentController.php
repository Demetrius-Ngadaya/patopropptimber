<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\SimpleCrud;
use App\Http\Controllers\Controller;
use App\Models\Hr\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    use SimpleCrud;

    protected string $model = Department::class;

    protected string $label = 'Department';

    protected array $searchable = ['name', 'code'];

    protected array $with = ['branch:id,name', 'manager:id,first_name,last_name'];

    protected array $withCount = ['employees'];

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('departments', 'name')->ignore($record?->id)->whereNull('deleted_at')],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('departments', 'code')->ignore($record?->id)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    protected function beforeDelete(Model $record): void
    {
        if ($record->employees()->employed()->exists()) {
            throw new BusinessException(__('Move the employees of this department first.'));
        }
    }
}
