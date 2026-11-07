<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function test_business_and_validation_messages_follow_the_requested_language(): void
    {
        $this->actingAsStaff('cashier');
        $product = $this->productWithStock(1);

        $this->withHeaders(['X-Locale' => 'sw'])->postJson('/api/v1/admin/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 99999]],
        ])->assertStatus(422)
            ->assertHeader('Content-Language', 'sw')
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_starts_with($m, 'Stoku ya '))->etc());

        $this->withHeaders(['X-Locale' => 'sw'])->postJson('/api/v1/admin/pos/checkout', ['payments' => []])
            ->assertStatus(422)->assertJsonPath('message', 'Kikapu kiko tupu.');

        $this->withHeaders(['X-Locale' => 'en'])->postJson('/api/v1/admin/pos/checkout', ['payments' => []])
            ->assertStatus(422)->assertJsonPath('message', 'The cart is empty.');
    }

    public function test_user_language_preference_is_remembered(): void
    {
        $this->actingAsStaff();
        $this->putJson('/api/v1/auth/preferences', ['locale' => 'sw', 'theme' => 'dark'])->assertOk();

        $this->getJson('/api/v1/admin/hr/employees/999999')->assertNotFound()
            ->assertJsonPath('message', 'Taarifa zilizoombwa hazijapatikana.');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.theme', 'dark')->assertJsonPath('data.locale', 'sw');
    }
}
