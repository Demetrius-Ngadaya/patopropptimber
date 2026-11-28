<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/** The platform's own settings, e.g. which payment gateways are offered (central database). */
class Setting extends Model
{
    protected $table = 'platform_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['value' => 'array'];

    public function getConnectionName(): ?string
    {
        return config('patopro.platform.connection');
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->find($key);

        return $row ? ($row->value['v'] ?? $default) : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
    }
}
