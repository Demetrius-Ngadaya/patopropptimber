<?php

namespace Tests\Feature;

use App\Models\Ai\AiUsage;
use App\Models\Crm\Customer;
use App\Models\Sales\Sale;
use App\Services\Core\SettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiTest extends TestCase
{
    private function sellDaily($product, int $days, float $qty): void
    {
        for ($d = $days; $d >= 1; $d--) {
            $this->postJson('/api/v1/admin/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => $qty]],
                'payments' => [['method' => 'cash', 'amount' => 1000 * $qty]],
                'sold_at' => now()->subDays($d)->setTime(12, 0)->toDateTimeString(),
            ])->assertCreated();
        }
    }

    private function claudeReply(array $content, string $stop = 'end_turn'): array
    {
        return ['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'content' => $content, 'stop_reason' => $stop, 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]];
    }

    public function test_reorder_plan_forecasts_demand_and_flags_dead_stock(): void
    {
        $this->actingAsStaff();
        $sugar = $this->productWithStock(100, ['name' => 'Sugar 1kg', 'selling_price' => 1000, 'min_selling_price' => 0]);
        $dusty = $this->productWithStock(30, ['name' => 'Old lamp']);
        $this->sellDaily($sugar, 42, 2);

        $res = $this->getJson('/api/v1/admin/ai/reorder')->assertOk();
        $rows = collect($res->json('data.rows'));
        $s = $rows->firstWhere('product_id', $sugar->id);
        $this->assertEqualsWithDelta(2, $s['forecast_daily'], 0.25);
        $this->assertContains($s['status'], ['reorder_soon', 'reorder_now']);
        $this->assertGreaterThan(0, $s['suggested_order']);
        $this->assertSame(16.0, (float) $s['on_hand']);
        $this->assertSame('dead_stock', $rows->firstWhere('product_id', $dusty->id)['status']);

        $f = $this->getJson("/api/v1/admin/ai/forecast/{$sugar->id}")->assertOk();
        $this->assertCount(30, $f->json('data.forecast'));
        $this->assertCount(84, $f->json('data.history'));
    }

    public function test_anomaly_scan_flags_a_cashier_who_cancels_many_sales(): void
    {
        $product = $this->productWithStock(500, ['selling_price' => 1000, 'min_selling_price' => 0]);
        $honest = $this->staff('cashier');
        $suspect = $this->staff('cashier', null, ['name' => 'Suspect Cashier']);
        $ids = [];
        foreach ([$honest, $suspect] as $cashier) {
            Sanctum::actingAs($cashier);
            for ($i = 0; $i < 12; $i++) {
                $ids[$cashier->id][] = $this->postJson('/api/v1/admin/pos/checkout', [
                    'items' => [['product_id' => $product->id, 'quantity' => 1]],
                    'payments' => [['method' => 'cash', 'amount' => 1000]],
                ])->assertCreated()->json('data.id');
            }
        }
        $this->actingAsStaff();
        foreach (array_slice($ids[$suspect->id], 0, 5) as $id) {
            $this->postJson("/api/v1/admin/sales/{$id}/cancel", ['reason' => 'Customer changed mind'])->assertOk();
        }

        $scan = $this->getJson('/api/v1/admin/ai/anomalies?days=7')->assertOk();
        $flag = collect($scan->json('data.anomalies'))->firstWhere('type', 'cancellations');
        $this->assertNotNull($flag);
        $this->assertSame($suspect->id, $flag['subject']['id']);
        $this->assertSame('high', $flag['severity']);
    }

    public function test_credit_score_rewards_good_payers_and_punishes_overdue_debt(): void
    {
        $this->actingAsStaff();
        $product = $this->productWithStock(100, ['selling_price' => 10000]);
        $good = Customer::factory()->create(['allow_credit' => true, 'credit_limit' => 100000]);
        $bad = Customer::factory()->create(['allow_credit' => true, 'credit_limit' => 100000]);

        foreach ([$good, $bad] as $customer) {
            $this->postJson('/api/v1/admin/pos/checkout', [
                'customer_id' => $customer->id, 'due_date' => now()->addWeek()->toDateString(),
                'items' => [['product_id' => $product->id, 'quantity' => 5]], 'payments' => [],
            ])->assertCreated();
        }
        $goodSale = Sale::where('customer_id', $good->id)->firstOrFail();
        $this->postJson("/api/v1/admin/credit-sales/{$goodSale->id}/payments", ['mode' => 'full', 'method' => 'cash'])->assertCreated();
        Sale::where('customer_id', $bad->id)->update(['due_date' => now()->subDays(45)->toDateString()]);

        $g = $this->getJson("/api/v1/admin/ai/credit-scores/{$good->id}")->assertOk()->json('data');
        $b = $this->getJson("/api/v1/admin/ai/credit-scores/{$bad->id}")->assertOk()->json('data');
        $this->assertGreaterThan($b['score'], $g['score']);
        $this->assertContains($b['band'], ['C', 'D', 'E']);
        $this->assertCount(6, $b['factors']);
        $this->assertSame(50000.0, (float) $b['summary']['overdue']);

        $list = $this->getJson('/api/v1/admin/ai/credit-scores')->assertOk()->json('data');
        $this->assertSame($bad->id, $list[0]['customer_id']); // weakest first
    }

    public function test_insights_work_without_an_ai_key(): void
    {
        config(['ai.provider' => 'groq', 'ai.providers.groq.key' => null]);
        $this->actingAsStaff();
        $product = $this->productWithStock(0, ['name' => 'Rice']);
        Http::fake();

        $res = $this->getJson('/api/v1/admin/ai/insights')->assertOk();
        $this->assertNull($res->json('data.narrative'));
        $this->assertFalse($res->json('data.ai_available'));
        $this->assertIsArray($res->json('data.insights'));
        Http::assertNothingSent();

        $this->postJson('/api/v1/admin/ai/ask', ['message' => 'How are sales?'])->assertStatus(503)->assertJsonPath('code', 'AI_NOT_CONFIGURED');
        unset($product);
    }

    public function test_assistant_uses_permitted_tools_and_stores_the_conversation(): void
    {
        config(['ai.provider' => 'anthropic', 'ai.providers.anthropic.key' => 'test-key']);
        $cashier = $this->actingAsStaff('cashier');
        $this->productWithStock(12, ['name' => 'Coca Cola 500ml', 'selling_price' => 1000]);

        Http::fakeSequence('api.anthropic.com/*')
            ->push($this->claudeReply([
                ['type' => 'text', 'text' => 'Let me check.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'find_product', 'input' => ['query' => 'Coca']],
            ], 'tool_use'))
            ->push($this->claudeReply([['type' => 'text', 'text' => 'There are 12 Coca Cola 500ml in stock at 1,000 each.']]));

        $res = $this->postJson('/api/v1/admin/ai/ask', ['message' => 'Tuna soda ngapi?'])->assertOk();
        $this->assertSame('There are 12 Coca Cola 500ml in stock at 1,000 each.', $res->json('data.message.content'));
        $this->assertSame('find_product', $res->json('data.message.tool_calls.0.tool'));

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertCount(2, $sent);
        /** @var HttpRequest $first */
        $first = $sent->first();
        $this->assertSame('test-key', $first->header('x-api-key')[0]);
        $offered = collect($first['tools'])->pluck('name');
        $this->assertContains('find_product', $offered);
        $this->assertNotContains('financial_statement', $offered); // cashier has no accounting rights
        $this->assertNotContains('anomaly_scan', $offered);

        // The tool result reached the model as data.
        $toolResult = collect($sent->last()['messages'])->last()['content'][0];
        $this->assertSame('tool_result', $toolResult['type']);
        $this->assertStringContainsString('Coca Cola 500ml', $toolResult['content']);

        $conversationId = $res->json('data.conversation_id');
        $this->getJson("/api/v1/admin/ai/conversations/{$conversationId}")->assertOk()->assertJsonCount(2, 'data.messages');
        $this->assertSame(240, (int) AiUsage::sum(DB::raw('input_tokens + output_tokens')));

        // Another user cannot read it.
        $this->actingAsStaff('cashier');
        $this->getJson("/api/v1/admin/ai/conversations/{$conversationId}")->assertNotFound();
        unset($cashier);
    }

    public function test_monthly_budget_stops_ai_calls(): void
    {
        config(['ai.provider' => 'anthropic', 'ai.providers.anthropic.key' => 'test-key']);
        app(SettingsService::class)->update(['ai_monthly_token_budget' => 100]);
        AiUsage::create(['feature' => 'assistant', 'model' => 'x', 'input_tokens' => 90, 'output_tokens' => 20]);
        Http::fake();
        $this->actingAsStaff();

        $this->postJson('/api/v1/admin/ai/ask', ['message' => 'Hello there'])->assertStatus(429)->assertJsonPath('code', 'AI_BUDGET_EXCEEDED');
        Http::assertNothingSent();
    }

    public function test_product_descriptions_in_english_and_swahili(): void
    {
        config(['ai.provider' => 'anthropic', 'ai.providers.anthropic.key' => 'test-key']);
        $this->actingAsStaff();
        $product = $this->productWithStock(1, ['name' => 'Unga wa Sembe 2kg']);
        Http::fake(['api.anthropic.com/*' => Http::response($this->claudeReply([['type' => 'text', 'text' => '{"en": "Fine maize flour for ugali.", "sw": "Unga safi wa sembe kwa ugali."}']]))]);

        $this->postJson("/api/v1/admin/ai/products/{$product->id}/describe", ['save' => true])->assertOk()
            ->assertJsonPath('data.description_sw', 'Unga safi wa sembe kwa ugali.');
        $this->assertSame('Fine maize flour for ugali.', $product->fresh()->description);
    }

    public function test_free_openai_compatible_provider_runs_the_tool_loop(): void
    {
        config(['ai.provider' => 'groq', 'ai.providers.groq.key' => 'gsk_test']);
        $this->actingAsStaff('cashier');
        $this->productWithStock(12, ['name' => 'Coca Cola 500ml', 'selling_price' => 1000]);

        Http::fakeSequence('api.groq.com/*')
            ->push(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'find_product', 'arguments' => '{"query":"Coca"}']],
            ]]]], 'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 20]])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '<think>count stock</think>Kuna Coca Cola 500ml 12 stoo.']]], 'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 15]]);

        $res = $this->postJson('/api/v1/admin/ai/ask', ['message' => 'Tuna soda ngapi?'])->assertOk();
        $this->assertSame('Kuna Coca Cola 500ml 12 stoo.', $res->json('data.message.content')); // reasoning removed

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $first = $sent->first();
        $this->assertSame('https://api.groq.com/openai/v1/chat/completions', $first->url());
        $this->assertSame('Bearer gsk_test', $first->header('Authorization')[0]);
        $this->assertSame('openai/gpt-oss-120b', $first['model']);
        $this->assertSame('system', $first['messages'][0]['role']);
        $this->assertContains('find_product', collect($first['tools'])->pluck('function.name'));

        // Second call carries the assistant's tool call and the tool's answer in OpenAI format.
        $messages = collect($sent->last()['messages']);
        $this->assertSame('call_1', $messages->firstWhere('role', 'assistant')['tool_calls'][0]['id']);
        $tool = $messages->firstWhere('role', 'tool');
        $this->assertSame('call_1', $tool['tool_call_id']);
        $this->assertStringContainsString('Coca Cola 500ml', $tool['content']);

        $this->assertSame(385, (int) AiUsage::sum(DB::raw('input_tokens + output_tokens')));
        $this->assertSame('groq:openai/gpt-oss-120b', AiUsage::query()->value('model'));
        $this->getJson('/api/v1/admin/ai/status')->assertOk()->assertJsonPath('data.provider', 'groq')->assertJsonPath('data.assistant_available', true);
    }

    public function test_local_ollama_needs_no_key(): void
    {
        config(['ai.provider' => 'ollama', 'ai.model' => 'llama3.2']);
        $this->actingAsStaff();
        Http::fake(['127.0.0.1:11434/*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"en": "Maize flour.", "sw": "Unga wa mahindi."}']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10]])]);
        $product = $this->productWithStock(1, ['name' => 'Unga 2kg']);

        $this->postJson("/api/v1/admin/ai/products/{$product->id}/describe")->assertOk()->assertJsonPath('data.description_sw', 'Unga wa mahindi.');
        Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization') && $r['model'] === 'llama3.2');
    }
}
