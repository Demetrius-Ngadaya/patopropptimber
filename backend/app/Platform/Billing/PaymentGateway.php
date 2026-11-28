<?php

namespace App\Platform\Billing;

use App\Platform\Models\Payment;

/** A way to pay for a package: a USSD push to the payer's phone, confirmed later. */
interface PaymentGateway
{
    public function key(): string;

    public function label(): string;

    /** Its keys are in .env. */
    public function configured(): bool;

    /** @return array<string, string> method code => label (mobile networks) */
    public function methods(): array;

    /** Send the push to the phone; fills in gateway_reference. */
    public function start(Payment $payment, array $payer): void;

    /** Ask the gateway how the payment stands: pending | completed | failed. */
    public function check(Payment $payment): string;
}
