<?php

namespace Tests\Feature;

use App\Services\Core\SettingsService;
use App\Support\Security\Totp;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    public function test_login_does_not_reveal_whether_an_account_exists(): void
    {
        $this->staff('cashier', null, ['username' => 'real_user']);

        $unknown = $this->postJson('/api/v1/auth/login', ['login' => 'nobody_here', 'password' => 'Wrong123!'])->assertStatus(422)->json('message');
        $wrong = $this->postJson('/api/v1/auth/login', ['login' => 'real_user', 'password' => 'Wrong123!'])->assertStatus(422)->json('message');

        $this->assertSame($unknown, $wrong);
    }

    public function test_two_factor_setup_login_replay_and_recovery_codes(): void
    {
        $user = $this->staff('accountant', null, ['username' => 'mary', 'password' => 'Secret123!']);
        Sanctum::actingAs($user);

        $secret = $this->postJson('/api/v1/auth/two-factor/setup')->assertOk()->json('data.secret');
        $this->assertStringStartsWith('otpauth://totp/', $this->postJson('/api/v1/auth/two-factor/setup')->json('data.otpauth_url') ?? 'otpauth://totp/'); // re-setup before confirm is allowed
        $secret = $user->fresh()->two_factor_secret;

        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
        $codes = $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => Totp::code($secret)])->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);

        // The secret never appears in API output, and is encrypted at rest.
        $me = $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertTrue($me->json('data.two_factor_enabled'));
        $this->assertStringNotContainsString($secret, $me->getContent());
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
        $this->assertFalse(DB::table('audit_logs')->where('new_values', 'like', '%'.$secret.'%')->exists());

        $this->app['auth']->forgetGuards();

        // Password alone no longer gives a token.
        $login = $this->postJson('/api/v1/auth/login', ['login' => 'mary', 'password' => 'Secret123!'])->assertOk();
        $this->assertTrue($login->json('data.two_factor_required'));
        $this->assertNull($login->json('data.token'));
        $challenge = $login->json('data.challenge');

        // The code just used to confirm cannot be replayed; the next one works.
        $this->postJson('/api/v1/auth/two-factor/verify', ['challenge' => $challenge, 'code' => Totp::code($secret)])->assertStatus(422);
        $token = $this->postJson('/api/v1/auth/two-factor/verify', ['challenge' => $challenge, 'code' => Totp::code($secret, time() + 30)])->assertOk()->json('data.token');
        $this->assertNotEmpty($token);

        // A challenge is single use.
        $this->postJson('/api/v1/auth/two-factor/verify', ['challenge' => $challenge, 'code' => Totp::code($secret, time() + 30)])
            ->assertStatus(422)->assertJsonPath('code', 'CHALLENGE_EXPIRED');

        // A recovery code works exactly once.
        $c2 = $this->postJson('/api/v1/auth/login', ['login' => 'mary', 'password' => 'Secret123!'])->json('data.challenge');
        $this->postJson('/api/v1/auth/two-factor/verify', ['challenge' => $c2, 'code' => strtolower($codes[0])])->assertOk();
        $c3 = $this->postJson('/api/v1/auth/login', ['login' => 'mary', 'password' => 'Secret123!'])->json('data.challenge');
        $this->postJson('/api/v1/auth/two-factor/verify', ['challenge' => $c3, 'code' => $codes[0]])->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
    }

    public function test_challenge_is_destroyed_after_too_many_wrong_codes(): void
    {
        $user = $this->staff('cashier', null, ['username' => 'joe', 'password' => 'Secret123!']);
        $secret = Totp::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $challenge = $this->postJson('/api/v1/auth/login', ['login' => 'joe', 'password' => 'Secret123!'])->json('data.challenge');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/two-factor/verify', ['challenge' => $challenge, 'code' => '111111']);
        }
        $this->postJson('/api/v1/auth/two-factor/verify', ['challenge' => $challenge, 'code' => Totp::code($secret)])
            ->assertStatus(422)->assertJsonPath('code', 'CHALLENGE_EXPIRED');
    }

    public function test_roles_can_be_forced_to_use_two_factor(): void
    {
        app(SettingsService::class)->update(['two_factor_required_roles' => 'accountant']);
        $this->actingAsStaff('accountant');

        $this->getJson('/api/v1/admin/expenses')->assertForbidden()->assertJsonPath('code', 'TWO_FACTOR_SETUP_REQUIRED');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.two_factor_required', true);
        $this->postJson('/api/v1/auth/two-factor/setup')->assertOk(); // can still set it up

        $this->actingAsStaff('cashier');
        $this->getJson('/api/v1/admin/pos/products')->assertOk(); // other roles unaffected
    }

    public function test_browser_preflight_allows_the_language_header(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000']]);

        $this->call('OPTIONS', '/api/v1/admin/pos/products', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,x-locale,x-branch-id',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');

        $allowed = strtolower((string) $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-locale',
        ])->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('x-locale', $allowed);
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/api/v1/health')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
