<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Concerns\SimpleCrud;
use App\Http\Controllers\Controller;
use App\Models\Hr\Holiday;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    use SimpleCrud;

    protected string $model = Holiday::class;

    protected string $label = 'Holiday';

    protected array $searchable = ['name'];

    protected string $defaultSort = 'date';

    protected string $defaultDirection = 'asc';

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'date' => ['required', 'date'],
            'recurring' => ['boolean'],
        ];
    }
}
