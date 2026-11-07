<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\FiscalYear;
use App\Services\Finance\FiscalYearService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalYearController extends Controller
{
    public function __construct(private readonly FiscalYearService $years) {}

    public function index(): JsonResponse
    {
        return $this->ok(FiscalYear::query()->orderByDesc('start_date')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);
        $year = $this->years->create($data);

        return $this->created($year, __('Financial year :year created.', ['year' => $year->name]));
    }

    public function close(int $id): JsonResponse
    {
        $year = $this->years->close(FiscalYear::query()->findOrFail($id));

        return $this->ok($year, __(':year closed. Net result :amount moved to retained earnings.', ['year' => $year->name, 'amount' => number_format((float) $year->net_profit, 2)]));
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $year = $this->years->reopen(FiscalYear::query()->findOrFail($id), $data['reason']);

        return $this->ok($year, __(':year reopened.', ['year' => $year->name]));
    }
}
