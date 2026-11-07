<?php

namespace App\Support;

/** Who a message goes to, per channel. */
final class Recipients
{
    /**
     * @param  array<int, string>  $sms  phone numbers for SMS
     * @param  array<int, string>  $whatsapp  phone numbers for WhatsApp
     * @param  array<int, string>  $emails  email addresses
     */
    public function __construct(
        public readonly array $sms = [],
        public readonly array $whatsapp = [],
        public readonly array $emails = [],
    ) {}

    /** One person: the same phone for SMS and WhatsApp. */
    public static function person(?string $phone, ?string $email): self
    {
        $phones = Channels::phones($phone ? [$phone] : []);

        return new self($phones, $phones, Channels::emails($email ? [$email] : []));
    }

    /** @return array<int, string> */
    public function for(string $channel): array
    {
        return match ($channel) {
            Channels::SMS => $this->sms,
            Channels::WHATSAPP => $this->whatsapp,
            Channels::EMAIL => $this->emails,
            default => [],
        };
    }

    public function isEmpty(): bool
    {
        return ! $this->sms && ! $this->whatsapp && ! $this->emails;
    }
}
