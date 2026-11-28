<?php

namespace App\Platform\Support;

use App\Platform\Models\Business;
use App\Services\Core\SettingsService;
use Illuminate\Support\Carbon;

/**
 * The business this request (or command) works for, in either mode.
 * Code everywhere else asks this instead of checking the mode.
 */
final class CurrentBusiness
{
    private ?PlanAccess $plan = null;

    public function __construct(private readonly ?Business $record) {}

    public function onPlatform(): bool
    {
        return $this->record !== null;
    }

    public function record(): ?Business
    {
        return $this->record;
    }

    public function id(): ?int
    {
        return $this->record?->id;
    }

    /** A short key for caches and rate limits that must not mix businesses. */
    public function key(): string
    {
        return $this->record ? 'b'.$this->record->id : 'single';
    }

    public function slug(): ?string
    {
        return $this->record?->slug;
    }

    public function name(): string
    {
        return $this->record?->name ?? (string) app(SettingsService::class)->get('business_name');
    }

    public function state(): SubscriptionState
    {
        return $this->record ? $this->record->state() : SubscriptionState::Active;
    }

    public function canWrite(): bool
    {
        return $this->state()->canWrite();
    }

    public function servedUntil(): ?Carbon
    {
        return $this->record?->servedUntil();
    }

    /** Whole days left in the paid or trial period; null in single mode. */
    public function daysLeft(): ?int
    {
        $end = $this->servedUntil();

        return $end ? (int) floor(now()->diffInDays($end, false)) : null;
    }

    public function plan(): PlanAccess
    {
        return $this->plan ??= $this->record ? PlanAccess::fromPlan($this->record->plan) : PlanAccess::single();
    }

    public function toArray(): array
    {
        return [
            'mode' => $this->onPlatform() ? 'platform' : 'single',
            'slug' => $this->slug(),
            'name' => $this->name(),
            'state' => $this->state()->value,
            'state_label' => $this->state()->label(),
            'can_write' => $this->canWrite(),
            'served_until' => $this->servedUntil()?->toIso8601String(),
            'days_left' => $this->daysLeft(),
            'plan' => $this->plan()->toArray(),
        ];
    }
}
