<?php

namespace Database\Seeders\Demo;

use App\Models\Core\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\TransientToken;
use RuntimeException;

/**
 * Sends requests to the system's own API from inside the seeder, as a chosen
 * user at a chosen branch and moment. The demo data therefore goes through the
 * same validation, permissions, stock, debt and accounting rules as the screens.
 */
final class DemoApi
{
    public int $calls = 0;

    /** @var array<string, array{count: int, last: string}> soft failures by endpoint */
    public array $skipped = [];

    private ?User $user = null;

    private ?int $branch = null;

    public function __construct(private readonly Application $app) {}

    public function as(User $user, ?int $branchId = null): self
    {
        $this->user = $user;
        $this->branch = $branchId ?? $user->branch_id;

        return $this;
    }

    /** Not logged in (a visitor of the online shop). */
    public function guest(): self
    {
        $this->user = null;
        $this->branch = null;

        return $this;
    }

    public function branch(?int $branchId): self
    {
        $this->branch = $branchId;

        return $this;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /** Move the clock: everything recorded from now on happens at this moment. */
    public function at(Carbon|string $moment): Carbon
    {
        $time = $moment instanceof Carbon ? $moment->copy() : Carbon::parse($moment);
        Carbon::setTestNow($time);

        return $time;
    }

    public function get(string $uri, array $query = []): array
    {
        return $this->send('GET', $uri, $query) ?? [];
    }

    public function post(string $uri, array $data = []): array
    {
        return $this->send('POST', $uri, $data) ?? [];
    }

    public function put(string $uri, array $data = []): array
    {
        return $this->send('PUT', $uri, $data) ?? [];
    }

    /** Like post(), but a business-rule refusal (e.g. not enough stock) is counted and skipped. */
    public function try(string $uri, array $data = [], string $method = 'POST'): ?array
    {
        return $this->send($method, $uri, $data, soft: true);
    }

    private function send(string $method, string $uri, array $data, bool $soft = false): ?array
    {
        $this->calls++;
        $auth = $this->app['auth'];
        $auth->forgetGuards();
        if ($this->user) {
            // Same as a logged-in session, without a stored token.
            $this->user->withAccessToken(new TransientToken);
            $auth->guard('sanctum')->setUser($this->user);
            $auth->shouldUse('sanctum');
        }

        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'REMOTE_ADDR' => '127.0.0.1'];
        if ($this->branch) {
            $server['HTTP_X_BRANCH_ID'] = (string) $this->branch;
        }
        $isGet = $method === 'GET';
        $request = Request::create('/api/v1/'.ltrim($uri, '/'), $method, $isGet ? $data : [], [], [], $server, $isGet ? null : json_encode($data));

        $kernel = $this->app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getContent(), true) ?? [];
        if ($status < 300) {
            return $json;
        }

        $why = (string) ($json['message'] ?? 'HTTP '.$status);
        if (! empty($json['errors'])) {
            $why .= ' '.json_encode($json['errors'], JSON_UNESCAPED_UNICODE);
        }
        if ($soft && in_array($status, [409, 422], true)) {
            $key = $method.' '.preg_replace('/\d+/', '{id}', $uri);
            $this->skipped[$key] = ['count' => ($this->skipped[$key]['count'] ?? 0) + 1, 'last' => mb_substr($why, 0, 200)];

            return null;
        }

        throw new RuntimeException("Demo data: {$method} {$uri} as ".($this->user?->username ?? 'guest')." failed ({$status}): {$why}");
    }
}
