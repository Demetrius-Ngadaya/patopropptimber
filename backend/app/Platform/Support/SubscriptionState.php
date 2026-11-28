<?php

namespace App\Platform\Support;

use App\Platform\Models\Business;
use Illuminate\Support\Carbon;

/**
 * Where a business stands with its subscription, worked out from its dates:
 *
 *   Trial / Active ──end──► Grace (full use, warning) ──► ReadOnly (view, export, pay)
 *        ──► after keep_days: still ReadOnly, and due to be archived ──► Archived
 *
 * Suspended is set by hand by the Software Engineer. Paying at any stage
 * moves the business back to Active.
 */
enum SubscriptionState: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Grace = 'grace';
    case ReadOnly = 'read_only';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public static function for(Business $business, Carbon $now): self
    {
        if ($business->archived_at) {
            return self::Archived;
        }
        if ($business->suspended_at) {
            return self::Suspended;
        }

        $paid = $business->paid_until;
        if ($paid && $now->lte($paid)) {
            return self::Active;
        }
        $trial = $business->trial_ends_at;
        if ($trial && $now->lte($trial)) {
            return self::Trial;
        }

        $end = $business->servedUntil();
        if (! $end) {
            return self::ReadOnly; // never had a period: nothing to sell on
        }

        return $now->lte($end->copy()->addDays((int) config('patopro.platform.subscription.grace_days')))
            ? self::Grace
            : self::ReadOnly;
    }

    /** Selling, buying and editing are allowed. */
    public function canWrite(): bool
    {
        return in_array($this, [self::Trial, self::Active, self::Grace], true);
    }

    /** People can sign in and look (read-only still lets them view, export and pay). */
    public function canOpen(): bool
    {
        return ! in_array($this, [self::Suspended, self::Archived], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Trial => __('Trial'),
            self::Active => __('Active'),
            self::Grace => __('Payment overdue'),
            self::ReadOnly => __('Read only'),
            self::Suspended => __('Suspended'),
            self::Archived => __('Archived'),
        };
    }
}
