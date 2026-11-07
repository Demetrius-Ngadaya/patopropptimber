<?php

namespace App\Services\Core;

use App\Models\Core\Setting;
use App\Support\SettingDefinitions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Typed, cached access to admin-configurable settings.
 */
class SettingsService
{
    private const CACHE_KEY = 'mauzo.settings';

    private ?array $loaded = null;

    public function get(string $key, mixed $fallback = null): mixed
    {
        $all = $this->all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return $fallback ?? (SettingDefinitions::get($key)['default'] ?? null);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** All settings merged over their defaults. */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $defaults = array_map(fn ($d) => $d['default'], SettingDefinitions::all());

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, function () {
                if (! Schema::hasTable('settings')) {
                    return [];
                }

                return Setting::query()->pluck('value', 'key')
                    ->map(fn ($v) => json_decode((string) $v, true))
                    ->all();
            });
        } catch (Throwable) {
            $stored = [];
        }

        $merged = array_merge($defaults, array_intersect_key($stored, $defaults));

        foreach ($merged as $key => $value) {
            $merged[$key] = $this->cast($key, $value);
        }

        return $this->loaded = $merged;
    }

    /** Settings that are safe to show without logging in. */
    public function public(): array
    {
        $public = array_filter(SettingDefinitions::all(), fn ($d) => $d['public'] ?? false);

        return array_intersect_key($this->all(), $public);
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): void
    {
        foreach ($values as $key => $value) {
            $definition = SettingDefinitions::get($key);
            if (! $definition) {
                continue;
            }

            $setting = Setting::firstOrNew(['key' => $key]);
            $setting->group = $definition['group'];
            $setting->is_public = $definition['public'] ?? false;
            $setting->value = json_encode($this->cast($key, $value));
            $setting->updated_by = Auth::id();
            $setting->save();
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }

    private function cast(string $key, mixed $value): mixed
    {
        return match (SettingDefinitions::get($key)['type'] ?? 'string') {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => is_string($value) ? (json_decode($value, true) ?? []) : (array) $value,
            default => $value === null ? null : (string) $value,
        };
    }
}
