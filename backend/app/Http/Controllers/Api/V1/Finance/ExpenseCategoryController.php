<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Concerns\SimpleCrud;
use App\Http\Controllers\Controller;
use App\Models\Finance\ExpenseCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    use SimpleCrud;

    protected string $model = ExpenseCategory::class;

    protected string $label = 'Expense category';

    protected array $searchable = ['name'];

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('expense_categories', 'name')->ignore($record?->id)->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }
}
