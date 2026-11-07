<?php

namespace Tests\Feature;

use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Sales\Order;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopTest extends TestCase
{
    public function test_customer_registers_places_order_and_staff_completes_it(): void
    {
        $product = $this->productWithStock(10, ['selling_price' => 2000, 'show_online' => true]);
        $branch = $this->mainBranch();

        $token = $this->postJson('/api/v1/shop/register', [
            'name' => 'Asha Mohamed',
            'phone' => '0712 345 678',
            'email' => 'asha@example.com',
            'password' => 'Secret12345',
            'password_confirmation' => 'Secret12345',
            'accept_terms' => true,
        ])->assertCreated()->json('data.token');

        $this->assertDatabaseHas('customers', ['name' => 'Asha Mohamed', 'phone' => '255712345678']);

        $order = $this->withToken($token)->postJson('/api/v1/shop/account/orders', [
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'delivery_method' => 'pickup',
        ])->assertCreated()->json('data');

        $this->assertSame('pending', $order['status']);
        $this->assertSame(10.0, $this->onHand($product)); // stock moves only when completed

        // A customer cannot use staff endpoints.
        $this->withToken($token)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->staff('cashier'));
        $this->postJson("/api/v1/admin/orders/{$order['id']}/status", ['status' => 'confirmed'])->assertOk();
        $this->postJson("/api/v1/admin/orders/{$order['id']}/complete", [
            'payments' => [['method' => 'cash', 'amount' => 4000]],
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(8.0, $this->onHand($product));
        $this->assertNotNull(Order::find($order['id'])->sale_id);
    }

    public function test_storefront_hides_cost_prices_and_offline_products(): void
    {
        $online = $this->productWithStock(5, ['show_online' => true, 'cost_price' => 777]);
        $offline = $this->productWithStock(5, ['show_online' => false]);

        $response = $this->getJson('/api/v1/shop/products')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($online->id));
        $this->assertFalse($ids->contains($offline->id));
        $this->assertStringNotContainsString('777', $response->getContent());
        $this->assertTrue($response->json('data.0.in_stock'));
    }

    public function test_customer_can_update_own_profile(): void
    {
        $user = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $user->id, 'name' => $user->name]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/shop/account', ['name' => 'New Name', 'phone' => '0755000111', 'city' => 'Arusha'])
            ->assertOk();

        $this->assertDatabaseHas('customers', ['user_id' => $user->id, 'name' => 'New Name', 'city' => 'Arusha', 'phone' => '255755000111']);
    }

    public function test_registration_validation_messages_are_clear(): void
    {
        $this->postJson('/api/v1/shop/register', ['name' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['phone', 'password', 'accept_terms']);
    }
}
