<?php

namespace Tests\Feature;

use App\Models\Core\LoginLog;
use App\Models\Core\PersonalAccessToken;
use App\Services\Core\SettingsService;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_staff_can_log_in_with_username_and_receives_permissions(): void
    {
        $user = $this->staff('cashier');

        $response = $this->postJson('/api/v1/auth/login', ['login' => $user->username, 'password' => 'Password123']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user' => ['permissions', 'branches', 'session_timeout_minutes']]]);
        $this->assertContains('pos.access', $response->json('data.user.permissions'));
        $this->assertNotContains('users.view', $response->json('data.user.permissions'));
        $this->assertDatabaseHas('login_logs', ['user_id' => $user->id, 'status' => 'success']);
    }

    public function test_wrong_password_is_logged_and_account_locks_after_max_attempts(): void
    {
        $user = $this->staff('cashier');
        app(SettingsService::class)->update(['max_login_attempts' => 3]);

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/login', ['login' => $user->username, 'password' => 'wrong-pass1'])->assertStatus(422);
        }

        $this->assertNotNull($user->fresh()->locked_until);
        $this->postJson('/api/v1/auth/login', ['login' => $user->username, 'password' => 'Password123'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'LOGIN_FAILED');
        $this->assertSame(3, LoginLog::query()->where('user_id', $user->id)->where('status', 'failed')->count());
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = $this->staff('cashier', null, ['is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['login' => $user->username, 'password' => 'Password123'])->assertStatus(422);
    }

    public function test_session_expires_after_admin_configured_idle_minutes(): void
    {
        $user = $this->staff('cashier');
        app(SettingsService::class)->update(['session_timeout_minutes' => 15]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $user->username, 'password' => 'Password123'])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        PersonalAccessToken::query()->update(['last_activity_at' => now()->subMinutes(16)]);
        $this->app['auth']->forgetGuards(); // the next request must re-read the token

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_EXPIRED');
        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertDatabaseHas('login_logs', ['user_id' => $user->id, 'logout_reason' => 'expired']);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->staff('cashier');
        $token = $this->postJson('/api/v1/auth/login', ['login' => $user->username, 'password' => 'Password123'])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_change_password_requires_current_password_and_strong_new_password(): void
    {
        $user = $this->staff('cashier');
        $token = $this->postJson('/api/v1/auth/login', ['login' => $user->username, 'password' => 'Password123'])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Password123', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Password123', 'password' => 'NewPassword456', 'password_confirmation' => 'NewPassword456',
        ])->assertOk();
    }
}
