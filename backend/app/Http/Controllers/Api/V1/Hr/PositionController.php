<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\SimpleCrud;
use App\Http\Controllers\Controller;
use App\Models\Hr\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    use SimpleCrud;

    protected string $model = Position::class;

    protected string $label = 'Position';

    protected array $searchable = ['title', 'grade'];

    protected array $with = ['department:id,name'];

    protected string $defaultSort = 'title';

    protected string $defaultDirection = 'asc';

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'grade' => ['nullable', 'string', 'max:20'],
            'min_salary' => ['nullable', 'numeric', 'min:0'],
            'max_salary' => ['nullable', 'numeric', 'min:0', 'gte:min_salary'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function beforeDelete(Model $record): void
    {
        if ($record->employees()->employed()->exists()) {
            throw new BusinessException(__('Employees still hold this position.'));
        }
    }
}
