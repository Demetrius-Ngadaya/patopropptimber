<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Services\Ai\ShopAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The online shop's chat assistant (no login needed; a signed-in customer can ask about their orders). */
class ShopAssistantController extends Controller
{
    public function ask(Request $request, ShopAssistantService $assistant): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:3000'],
        ]);

        $user = Auth::guard('sanctum')->user();
        $customer = $user instanceof User && $user->type === User::TYPE_CUSTOMER ? $user : null;
        $visitor = $customer ? 'user:'.$customer->id : 'ip:'.$request->ip().'|'.substr((string) $request->header('X-Visitor-Id'), 0, 64);

        return $this->ok($assistant->ask($data['message'], $data['history'] ?? [], $customer, $visitor));
    }
}
