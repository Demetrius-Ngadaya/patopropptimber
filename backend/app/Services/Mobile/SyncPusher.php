<?php

namespace App\Services\Mobile;

use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Mobile\MobileDevice;
use App\Models\Mobile\SyncMutation;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phone → server half of the two-way sync.
 *
 * The app records every change it makes (a sale, a new customer, an order
 * status, ...) in an outbox, each with its own UUID, and sends them in order
 * when it is online. Each change is replayed through the very same API
 * endpoint the web app uses, so permissions, validation, stock, accounting,
 * receipts and notifications behave exactly as for a sale made online.
 *
 * Exactly once: the UUID is stored with the result in the same database
 * transaction as the change itself. A change sent again (lost response, app
 * killed mid-sync) gets the first result back instead of a second sale.
 *
 * A change may point at a record that another offline change created, with
 * "@ref:<uuid>" (e.g. a sale for a customer registered offline); it is
 * replaced by the id that change produced.
 */
class SyncPusher
{
    /** Marks replayed requests (see the "api" rate limiter). */
    public const REPLAY_ATTRIBUTE = 'mobile_sync_replay';

    /** When an offline sale happened on the phone (read by the POS checkout). */
    public const OFFLINE_SOLD_AT = 'mobile_offline_sold_at';

    /** type => [HTTP method, path under /api/v1/admin with {id}] */
    public const TYPES = [
        'sale.create' => ['POST', 'pos/checkout'],
        'customer.create' => ['POST', 'customers'],
        'customer.update' => ['PUT', 'customers/{id}'],
        'customer.payment' => ['POST', 'customers/{id}/payments'],
        'order.status' => ['POST', 'orders/{id}/status'],
        'order.complete' => ['POST', 'orders/{id}/complete'],
        'notification.read' => ['POST', 'notifications/{id}/read'],
        'notification.read_all' => ['POST', 'notifications/read-all'],
    ];

    /**
     * @param  array<int, array<string, mixed>>  $mutations
     * @return array<int, array<string, mixed>>
     */
    public function push(Request $request, User $user, ?MobileDevice $device, array $mutations): array
    {
        $results = [];
        foreach ($mutations as $mutation) {
            $results[] = $this->apply($request, $user, $device, $mutation);
        }

        return $results;
    }

    /** @param  array<string, mixed>  $m */
    private function apply(Request $request, User $user, ?MobileDevice $device, array $m): array
    {
        $clientId = (string) $m['id'];

        $done = SyncMutation::query()->where('client_id', $clientId)->first();
        if ($done) {
            if ($done->user_id !== $user->id) {
                return $this->result($clientId, 'rejected', message: __('This change belongs to another user.'), httpStatus: 403);
            }

            return $this->result($clientId, 'duplicate', $done->result_id, $done->result);
        }

        try {
            $payload = $this->resolveRefs((array) ($m['payload'] ?? []), $user);
            $target = isset($m['target_id']) ? $this->resolveRefs(['t' => $m['target_id']], $user)['t'] : null;
        } catch (UnresolvedReference $e) {
            return $this->result($clientId, 'rejected', message: __('This change depends on another change that was not saved: fix that one first.'), code: 'DEPENDENCY', httpStatus: 409);
        }

        [$method, $path] = self::TYPES[$m['type']];
        if (str_contains($path, '{id}')) {
            if (! is_numeric($target)) {
                return $this->result($clientId, 'rejected', message: __('The record this change is for is missing.'), httpStatus: 422);
            }
            $path = str_replace('{id}', (string) (int) $target, $path);
        }

        // Someone changed the customer on another device after this phone last saw it.
        if ($m['type'] === 'customer.update' && ! empty($m['base_updated_at'])) {
            $current = Customer::query()->find((int) $target);
            if ($current && $current->updated_at && $current->updated_at->gt(Carbon::parse((string) $m['base_updated_at'])->addSecond())) {
                return $this->result($clientId, 'conflict', $current->id, null,
                    __(':name was changed by someone else while you were offline. Their version was kept: check it and edit again if needed.', ['name' => $current->name]),
                    'CONFLICT', 409);
            }
        }

        $attributes = [];
        if ($m['type'] === 'sale.create' && ! empty($m['created_at'])) {
            // When it really happened on the phone, not when it reached the server.
            $attributes[self::OFFLINE_SOLD_AT] = Carbon::parse((string) $m['created_at'])->setTimezone(config('app.timezone'))->min(now())->toDateTimeString();
            unset($payload['sold_at']);
        }

        DB::beginTransaction();
        try {
            $record = SyncMutation::query()->create([
                'client_id' => $clientId,
                'user_id' => $user->id,
                'device_id' => $device?->id,
                'type' => $m['type'],
                'client_created_at' => isset($m['created_at']) ? Carbon::parse((string) $m['created_at']) : null,
            ]);
        } catch (QueryException) {
            DB::rollBack();
            // The same change is being saved by a parallel sync right now.
            $done = SyncMutation::query()->where('client_id', $clientId)->first();

            return $this->result($clientId, $done ? 'duplicate' : 'failed', $done?->result_id, $done?->result, $done ? null : __('Try again.'), httpStatus: $done ? 200 : 503);
        }

        try {
            $response = $this->replay($request, $method, $path, $payload, (int) ($m['branch_id'] ?? 0) ?: null, $attributes);
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);

            return $this->result($clientId, 'failed', message: __('The server could not save this change. It will be tried again.'), httpStatus: 500);
        }

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true) ?: [];

        if ($status >= 200 && $status < 300) {
            $data = $body['data'] ?? null;
            $resultId = is_array($data) && isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : (is_numeric($target) ? (int) $target : null);
            $summary = $this->summary($m['type'], is_array($data) ? $data : []);
            $record->forceFill(['result_id' => $resultId, 'result' => $summary + ['message' => $body['message'] ?? null]])->save();
            DB::commit();

            return $this->result($clientId, 'applied', $resultId, $summary, $body['message'] ?? null, httpStatus: $status);
        }

        DB::rollBack();

        return $this->result(
            $clientId,
            $status >= 500 || $status === 429 ? 'failed' : 'rejected',
            message: $body['message'] ?? __('Not saved.'),
            code: $body['code'] ?? null,
            httpStatus: $status,
            errors: isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : null,
        );
    }

    /** The same endpoint the web app calls, inside this request. */
    private function replay(Request $original, string $method, string $path, array $payload, ?int $branchId, array $attributes = []): Response
    {
        $sub = Request::create('/api/v1/admin/'.$path, $method, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $original->ip(),
            'HTTP_USER_AGENT' => (string) $original->userAgent(),
        ], json_encode($payload, JSON_THROW_ON_ERROR));
        foreach (['Authorization', 'Accept-Language'] as $header) {
            if ($original->headers->has($header)) {
                $sub->headers->set($header, (string) $original->headers->get($header));
            }
        }
        $branch = $branchId ?: $original->headers->get('X-Branch-Id');
        if ($branch) {
            $sub->headers->set('X-Branch-Id', (string) $branch);
        }
        $sub->attributes->set(self::REPLAY_ATTRIBUTE, true);
        foreach ($attributes as $key => $value) {
            $sub->attributes->set($key, $value);
        }
        $sub->setUserResolver($original->getUserResolver());

        $app = app();
        try {
            return $app->make(Kernel::class)->handle($sub);
        } finally {
            // Put the sync request back as "the" request.
            $app->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }
    }

    /**
     * Replace "@ref:<uuid>" with the id that earlier change produced.
     *
     * @throws UnresolvedReference
     */
    private function resolveRefs(array $data, User $user): array
    {
        array_walk_recursive($data, function (&$value) use ($user) {
            if (is_string($value) && str_starts_with($value, '@ref:')) {
                $id = SyncMutation::query()->where('client_id', substr($value, 5))->where('user_id', $user->id)->value('result_id');
                if (! $id) {
                    throw new UnresolvedReference($value);
                }
                $value = (int) $id;
            }
        });

        return $data;
    }

    /** What the app needs back to update its own copy. */
    private function summary(string $type, array $data): array
    {
        return match ($type) {
            'sale.create' => Arr::only($data, ['id', 'number', 'total', 'amount_paid', 'balance', 'change_given', 'payment_status', 'status', 'sold_at']),
            'customer.create', 'customer.update' => Arr::only($data, ['id', 'code', 'name', 'phone', 'balance', 'updated_at']),
            'customer.payment' => Arr::only((array) ($data['customer'] ?? []), ['id', 'balance', 'updated_at']),
            'order.status', 'order.complete' => Arr::only($data, ['id', 'number', 'status', 'sale_id', 'updated_at']),
            default => [],
        };
    }

    private function result(string $id, string $status, ?int $resultId = null, ?array $data = null, ?string $message = null, ?string $code = null, int $httpStatus = 200, ?array $errors = null): array
    {
        return array_filter([
            'id' => $id,
            'status' => $status,
            'result_id' => $resultId,
            'data' => $data,
            'message' => $message,
            'code' => $code,
            'http_status' => $httpStatus,
            'errors' => $errors,
        ], fn ($v) => $v !== null);
    }
}
