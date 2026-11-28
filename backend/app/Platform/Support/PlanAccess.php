<?php

namespace App\Platform\Support;

use App\Exceptions\BusinessException;
use App\Platform\Models\Plan;
use App\Support\Modules;

/**
 * What the current business's package allows: plan()->allows('voice'),
 * plan()->limit('users'), plan()->ensureWithin('users', $count).
 * In single mode it reads the licence in config/patopro.php (everything by default).
 */
final class PlanAccess
{
    /**
     * @param  array<int, string>  $features
     * @param  array<string, int|string|null>  $limits
     */
    public function __construct(
        public readonly string $name,
        private readonly array $features,
        private readonly array $limits,
        public readonly ?string $code = null,
    ) {}

    public static function fromPlan(?Plan $plan): self
    {
        if (! $plan) {
            return new self(__('No package'), [], array_fill_keys(array_keys(Features::LIMITS), 0));
        }

        return new self($plan->name, (array) $plan->features, (array) $plan->limits, $plan->code);
    }

    public static function single(): self
    {
        $c = config('patopro.single');

        return new self((string) $c['plan_name'], (array) ($c['features'] ?: ['*']), (array) $c['limits'], 'single');
    }

    public function everything(): bool
    {
        return in_array('*', $this->features, true);
    }

    public function allows(string $feature): bool
    {
        return $this->everything()
            || in_array($feature, $this->features, true)
            || in_array($feature, Modules::ALWAYS_ON, true);
    }

    /** null means no limit. */
    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null || $value === '' ? null : max(0, (int) $value);
    }

    /**
     * Stop adding one more when the package's limit is already reached.
     *
     * @param  int|\Closure(): int  $current  how many there are now (a closure is only called when there is a limit)
     */
    public function ensureWithin(string $key, int|\Closure $current): void
    {
        $limit = $this->limit($key);
        if ($limit === null) {
            return;
        }
        if ($current instanceof \Closure) {
            $current = $current();
        }
        if ($current >= $limit) {
            throw new BusinessException(
                __('Your package (:plan) allows :limit :what. Upgrade the package to add more.', [
                    'plan' => $this->name,
                    'limit' => $limit,
                    'what' => mb_strtolower(__(Features::LIMITS[$key] ?? $key)),
                ]),
                'PLAN_LIMIT',
                422,
            );
        }
    }

    /** Modules the package leaves out; Modules::disabled() switches them off. */
    public function disabledModules(): array
    {
        if ($this->everything()) {
            return [];
        }

        return array_values(array_diff(Features::moduleKeys(), $this->features));
    }

    public function toArray(): array
    {
        $features = $this->everything() ? array_keys(Features::all()) : array_values(array_filter($this->features, fn ($f) => $f !== '*'));

        return [
            'code' => $this->code,
            'name' => $this->name,
            'features' => $features,
            'limits' => collect(array_keys(Features::LIMITS))->mapWithKeys(fn ($k) => [$k => $this->limit($k)])->all(),
        ];
    }
}
