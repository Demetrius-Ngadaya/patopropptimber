<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\VisitorTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The visitor beacon: a page was opened (page_view) or is still open (heartbeat). */
class VisitController extends Controller
{
    public function store(Request $request, VisitorTracker $tracker): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:500'],
            'referrer' => ['nullable', 'string', 'max:500'],
            'type' => ['nullable', 'in:page_view,heartbeat'],
        ]);
        $tracker->record($request, $data['path'], $data['referrer'] ?? null, ($data['type'] ?? 'page_view') === 'page_view');

        return response()->json(['success' => true], 202);
    }
}
