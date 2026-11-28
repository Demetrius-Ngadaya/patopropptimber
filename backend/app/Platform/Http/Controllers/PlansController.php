<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Models\Plan;
use App\Platform\Support\Features;
use Illuminate\Http\JsonResponse;

class PlansController extends Controller
{
    /** GET /api/v1/platform/plans — the packages for the pricing and sign-up pages. */
    public function index(): JsonResponse
    {
        $plans = Plan::query()->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->orderBy('price_monthly')->get();

        return response()->json(['success' => true, 'data' => [
            'plans' => $plans->map->toPublicArray()->values(),
            'features' => collect(Features::all())->map(fn ($label) => __($label)),
            'limits' => collect(Features::LIMITS)->map(fn ($label) => __($label)),
            'trial_days' => (int) config('patopro.platform.subscription.trial_days'),
        ]]);
    }
}
