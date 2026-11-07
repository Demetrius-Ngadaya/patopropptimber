<?php

namespace Tests\Feature;

use App\Models\Shop\Faq;
use App\Services\Core\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_faqs_are_managed_by_staff_and_published_to_the_shop(): void
    {
        $this->actingAsStaff('admin');
        $id = $this->postJson('/api/v1/admin/faqs', ['question' => 'Do you deliver on Sundays?', 'answer' => 'Yes, from 9:00 to 14:00.', 'is_active' => true])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/faqs', ['question' => 'Hidden draft', 'answer' => 'Not yet.', 'is_active' => false])->assertCreated();

        $this->getJson('/api/v1/shop/faqs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/shop/faqs?search=sunday')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAsStaff('cashier');
        $this->getJson('/api/v1/admin/faqs')->assertForbidden();
    }

    public function test_the_assistant_looks_up_products_before_answering(): void
    {
        $this->configureAi();
        $this->productWithStock(12, ['name' => 'Sukari ya Kilombero 1kg', 'selling_price' => 3200, 'show_online' => true]);
        $calls = 0;
        Http::fake(['api.groq.test/*' => function (Request $request) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                // The model asks for the product search tool first.
                $this->assertSame('openai/gpt-oss-20b', $request['model']);
                $this->assertContains('search_products', array_column(array_column($request['tools'], 'function'), 'name'));

                return Http::response(['choices' => [['message' => ['content' => '', 'tool_calls' => [[
                    'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search_products', 'arguments' => '{"query":"sukari"}'],
                ]]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]);
            }
            // The tool result reaches the model: the real price and stock from the database.
            $tool = collect($request['messages'])->firstWhere('role', 'tool');
            $this->assertStringContainsString('Sukari ya Kilombero 1kg', $tool['content']);
            $this->assertStringContainsString('3,200.00', $tool['content']);
            $this->assertStringNotContainsString('cost', strtolower($tool['content']));

            return Http::response(['choices' => [['message' => ['content' => 'Ndiyo, tuna Sukari ya Kilombero 1kg kwa TZS 3,200.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]);
        }]);

        $this->postJson('/api/v1/shop/assistant', ['message' => 'Mna sukari? Bei gani?'])
            ->assertOk()->assertJsonPath('data.source', 'ai')->assertJsonPath('data.answer', 'Ndiyo, tuna Sukari ya Kilombero 1kg kwa TZS 3,200.');
        $this->assertSame(2, $calls);
    }

    public function test_without_ai_the_assistant_answers_from_the_faq_and_has_a_daily_limit(): void
    {
        app(SettingsService::class)->update(['ai_enabled' => false, 'shop_assistant_daily_limit' => 2]);
        Faq::query()->create(['question' => 'What are your opening hours?', 'answer' => 'Every day 7:30 to 21:00.', 'is_active' => true]);

        $this->postJson('/api/v1/shop/assistant', ['message' => 'opening hours?'])->assertOk()
            ->assertJsonPath('data.source', 'faq')->assertJsonPath('data.answer', 'Every day 7:30 to 21:00.');
        $this->postJson('/api/v1/shop/assistant', ['message' => 'hours'])->assertOk();
        $this->postJson('/api/v1/shop/assistant', ['message' => 'hours again'])->assertStatus(429);
    }

    private function configureAi(): void
    {
        config(['ai.provider' => 'groq', 'ai.providers.groq.key' => 'gsk_test', 'ai.providers.groq.base_url' => 'https://api.groq.test/openai/v1', 'ai.model' => 'openai/gpt-oss-20b']);
        app(SettingsService::class)->update(['ai_enabled' => true]);
    }
}
