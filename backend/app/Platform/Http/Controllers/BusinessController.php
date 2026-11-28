<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BusinessController extends Controller
{
    /**
     * GET /api/v1/business — who this business is and what its package allows,
     * for the website and the app (banners, hidden menu items, limits).
     */
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => business()->toArray()]);
    }
}
