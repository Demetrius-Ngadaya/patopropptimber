<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Models\Ai\AiConversation;
use App\Models\Ai\CustomerCreditScore;
use App\Models\Catalog\Product;
use App\Models\Crm\Customer;
use App\Services\Ai\AiClient;
use App\Services\Ai\AnomalyDetector;
use App\Services\Ai\AssistantService;
use App\Services\Ai\CreditScorer;
use App\Services\Ai\DemandForecaster;
use App\Services\Ai\InsightService;
use App\Services\Ai\ProductContentService;
use App\Services\Core\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiController extends Controller
{
    /** Which AI features are available, for the frontend to show or hide. */
    public function status(AiClient $ai, SettingsService $settings): JsonResponse
    {
        $budget = $settings->int('ai_monthly_token_budget');

        return $this->ok([
            'enabled' => $settings->bool('ai_enabled'),
            'assistant_available' => $ai->configured(),
            'provider' => $ai->provider(),
            'model' => $ai->configured() ? $ai->label() : null,
            'in_house_features' => ['forecast', 'reorder', 'anomalies', 'credit_score', 'insights'],
            'budget' => ['monthly_tokens' => $budget, 'used' => $ai->monthUsage()],
        ]);
    }

    // ---------------------------------------------------------- assistant

    public function conversations(Request $request): JsonResponse
    {
        return $this->paginated(AiConversation::query()->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')->paginate($this->perPage($request, 30)));
    }

    public function conversation(Request $request, int $id): JsonResponse
    {
        $conversation = AiConversation::query()->where('user_id', $request->user()->id)->with('messages')->findOrFail($id);

        return $this->ok($conversation);
    }

    public function ask(Request $request, AssistantService $assistant): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);
        $conversation = ! empty($data['conversation_id'])
            ? AiConversation::query()->where('user_id', $request->user()->id)->findOrFail($data['conversation_id'])
            : null;

        $result = $assistant->ask($request->user(), $data['message'], $conversation);

        return $this->ok([
            'conversation_id' => $result['conversation']->id,
            'title' => $result['conversation']->title,
            'message' => $result['message'],
        ]);
    }

    public function deleteConversation(Request $request, int $id): JsonResponse
    {
        AiConversation::query()->where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return $this->ok(null, __('Conversation deleted.'));
    }

    // ------------------------------------------------------- in-house AI

    public function insights(Request $request, InsightService $insights): JsonResponse
    {
        return $this->ok($insights->generate($this->branch()->id(), ! $request->boolean('no_narrative')));
    }

    public function reorder(Request $request, DemandForecaster $forecaster): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['out_of_stock', 'reorder_now', 'reorder_soon', 'dead_stock', 'overstock', 'ok'])]]);
        $rows = collect($forecaster->reorderPlan($this->branch()->id(), $request->query('search'), 2000))
            ->when($request->query('status'), fn ($c, $s) => $c->where('status', $s))->values();

        return $this->ok([
            'rows' => $rows,
            'summary' => [
                'out_of_stock' => $rows->where('status', 'out_of_stock')->count(),
                'reorder_now' => $rows->where('status', 'reorder_now')->count(),
                'reorder_soon' => $rows->where('status', 'reorder_soon')->count(),
                'dead_stock' => $rows->where('status', 'dead_stock')->count(),
                'suggested_order_cost' => round((float) $rows->sum('suggested_order_cost'), 2),
            ],
        ]);
    }

    public function productForecast(Request $request, int $productId, DemandForecaster $forecaster): JsonResponse
    {
        $request->validate(['horizon' => ['nullable', 'integer', 'min:7', 'max:90']]);

        return $this->ok($forecaster->productForecast(Product::query()->findOrFail($productId), $this->branch()->id(), $request->integer('horizon') ?: 30));
    }

    public function anomalies(Request $request, AnomalyDetector $detector): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:7', 'max:180']]);

        return $this->ok($detector->scan($this->branch()->id(), $request->integer('days') ?: 30));
    }

    public function creditScore(int $customerId, CreditScorer $scorer): JsonResponse
    {
        $customer = Customer::query()->findOrFail($customerId);
        $history = CustomerCreditScore::query()->where('customer_id', $customer->id)->latest('computed_at')->limit(12)->get(['score', 'band', 'computed_at']);

        return $this->ok(['customer' => $customer->only(['id', 'name', 'phone', 'credit_limit', 'balance'])] + $scorer->score($customer) + ['history' => $history]);
    }

    public function creditScores(Request $request, CreditScorer $scorer): JsonResponse
    {
        $customers = Customer::query()->where(fn ($q) => $q->where('allow_credit', true)->orWhere('balance', '>', 0))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")))
            ->orderByDesc('balance')->limit(200)->get();

        $rows = $customers->map(function (Customer $c) use ($scorer) {
            $r = $scorer->score($c);

            return ['customer_id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'balance' => (float) $c->balance, 'credit_limit' => (float) $c->credit_limit]
                + array_intersect_key($r, array_flip(['score', 'band', 'suggested_limit'])) + ['overdue' => $r['summary']['overdue']];
        })->sortBy('score')->values();

        return $this->ok($rows);
    }

    public function describeProduct(Request $request, int $productId, ProductContentService $content): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500'], 'save' => ['nullable', 'boolean']]);
        $product = Product::query()->findOrFail($productId);
        $result = $content->describe($product, $data['notes'] ?? null);

        if (! empty($data['save'])) {
            $product->update($result);
        }

        return $this->ok($result, ! empty($data['save']) ? __('Descriptions saved to the product.') : __('Descriptions written. Review them before saving.'));
    }

    public function usage(): JsonResponse
    {
        return $this->ok(AssistantService::usageSummary());
    }
}
