<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Concerns\SimpleCrud;
use App\Http\Controllers\Controller;
use App\Models\Hr\SalaryComponent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Allowances and deductions (housing, transport, union dues, loans...). */
class SalaryComponentController extends Controller
{
    use SimpleCrud;

    protected string $model = SalaryComponent::class;

    protected string $label = 'Salary component';

    protected array $searchable = ['name', 'code'];

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('salary_components', 'code')->ignore($record?->id)],
            'type' => ['required', Rule::in(['earning', 'deduction'])],
            'calculation' => ['required', Rule::in(['fixed', 'percent'])],
            'amount' => ['required', 'numeric', 'min:0', $request->input('calculation') === 'percent' ? 'max:100' : 'max:999999999'],
            'is_taxable' => ['boolean'],
            'is_pensionable' => ['boolean'],
            'applies_to_all' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
