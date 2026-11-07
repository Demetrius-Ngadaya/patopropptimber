<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Concerns\SimpleCrud;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    use SimpleCrud;

    protected string $model = Unit::class;

    protected string $label = 'Unit';

    protected array $searchable = ['name', 'short_name'];

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'short_name' => ['required', 'string', 'max:20', Rule::unique('units', 'short_name')->ignore($record?->id)],
            'allow_decimal' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
