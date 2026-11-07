<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Concerns\SimpleCrud;
use App\Http\Controllers\Controller;
use App\Models\Hr\LeaveType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveTypeController extends Controller
{
    use SimpleCrud;

    protected string $model = LeaveType::class;

    protected string $label = 'Leave type';

    protected array $searchable = ['name', 'code'];

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('leave_types', 'code')->ignore($record?->id)],
            'days_per_year' => ['required', 'numeric', 'min:0', 'max:366'],
            'is_paid' => ['boolean'],
            'max_carry_forward' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'requires_attachment' => ['boolean'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'is_active' => ['boolean'],
        ];
    }
}
