<?php

namespace App\Platform\Models;

use App\Platform\Support\PlatformSms;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business being registered online, until its phone is confirmed.
 *
 * @property array{business_name: string, owner_name: string, email: ?string, password: string, plan_id: int, billing_cycle: string} $data
 */
class Signup extends Model
{
    use HasUuids;

    protected $table = 'platform_signups';

    protected $guarded = [];

    protected $hidden = ['data', 'code_hash'];

    protected $casts = [
        'data' => 'encrypted:array',
        'code_sent_at' => 'datetime',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
        'codes_sent' => 'integer',
        'attempts' => 'integer',
    ];

    public function getConnectionName(): ?string
    {
        return config('patopro.platform.connection');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    public function codeMatches(string $code): bool
    {
        return hash_equals($this->code_hash, self::hashCode(trim($code)));
    }

    /** The owner's account details for the new business's database. */
    public function owner(): array
    {
        return [
            'name' => $this->data['owner_name'] ?? null,
            'phone' => $this->phone,
            'email' => $this->data['email'] ?? null,
            'password' => $this->data['password'], // already hashed
            'must_change_password' => false,
        ];
    }

    /** "+255 7•• ••• 678" for showing where the code went. */
    public function maskedPhone(): string
    {
        $p = $this->phone;

        return '+'.substr($p, 0, 3).' '.substr($p, 3, 1).'•• ••• '.substr($p, -3);
    }

    public function sendWelcome(Business $business): void
    {
        if ($this->data['welcomed'] ?? false) {
            return;
        }
        app(PlatformSms::class)->send($this->phone, __('Welcome to PatoPro! :name is ready: :url Sign in with your phone number and password. Free trial until :date.', [
            'name' => $business->name,
            'url' => $business->url(),
            'date' => $business->trial_ends_at?->format('d/m/Y'),
        ]));
        $this->update(['data' => array_merge($this->data, ['welcomed' => true])]);
    }
}
