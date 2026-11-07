<?php

namespace App\Services\Messaging;

use App\Mail\NotificationMail;
use App\Services\Core\SettingsService;
use App\Services\Core\SmsService;
use App\Support\Channels;
use App\Support\Recipients;
use App\Support\SettingDefinitions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Delivers one message on the chosen channels:
 *   SMS       → the short summary
 *   WhatsApp  → the fuller text
 *   Email     → the detailed document as HTML, plus the same document as a PDF attachment
 *
 * Never throws; every attempt lands in the message log.
 */
class Messenger
{
    public function __construct(
        private readonly SmsService $sms,
        private readonly WhatsAppService $whatsapp,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<int, string>  $channels  already filtered with Channels::filter()
     * @return array<string, array{recipients: int, sent: bool}>
     */
    public function send(OutgoingMessage $message, array $channels, Recipients $to): array
    {
        $result = [];
        foreach (Channels::filter($channels) as $channel) {
            $recipients = $to->for($channel);
            if ($recipients === []) {
                $result[$channel] = ['recipients' => 0, 'sent' => false];

                continue;
            }
            $sent = match ($channel) {
                Channels::SMS => $this->sms->sendText($recipients, $message->sms, $message->purpose, $message->relatedType, $message->relatedId),
                Channels::WHATSAPP => $this->whatsapp->send($recipients, $message->text, $message->purpose, $message->relatedType, $message->relatedId),
                Channels::EMAIL => $this->email($message, $recipients),
            };
            $result[$channel] = ['recipients' => count($recipients), 'sent' => $sent];
        }

        return $result;
    }

    /** Run $callback in the language chosen for outgoing messages. */
    public function inMessageLocale(callable $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale((string) ($this->settings->get('message_locale') ?: 'sw'));
        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Business details for the header of an email, PDF or printed document.
     * Only the details the admin chose for that document are filled in
     * (Settings → Invoices & documents → Company details on each document).
     */
    public function brand(string $document = 'email'): array
    {
        $s = $this->settings;
        $chosen = (array) (((array) $s->get('document_company_fields', []))[$document] ?? SettingDefinitions::COMPANY_FIELDS);
        $on = fn (string $field) => in_array($field, $chosen, true);
        $logo = $on('logo') ? $s->get('business_logo') : null;

        return [
            'name' => (string) $s->get('business_name'),
            'phone' => $on('phone') ? (string) $s->get('business_phone') : '',
            'whatsapp' => $on('whatsapp') ? (string) $s->get('business_whatsapp') : '',
            'email' => $on('email') ? (string) $s->get('business_email') : '',
            'website' => $on('website') ? (string) $s->get('business_website') : '',
            'address' => $on('address') ? trim((string) $s->get('business_address').(filled($s->get('business_po_box')) ? ', P.O. Box '.$s->get('business_po_box') : ''), ', ') : '',
            'tin' => $on('tin') ? (string) $s->get('business_tin') : '',
            'vrn' => $on('vrn') ? (string) $s->get('business_vrn') : '',
            'bank' => $on('bank') ? (string) $s->get('business_bank_details') : '',
            'color' => (string) ($s->get('theme_primary') ?: '#157a55'),
            'logo_url' => $logo ? Storage::disk('public')->url($logo) : null,
            'logo_path' => $logo && Storage::disk('public')->exists($logo) ? Storage::disk('public')->path($logo) : null,
            'currency' => (string) $s->get('currency'),
        ];
    }

    /** One line of contact details, e.g. for a footer: "Dar es Salaam · 0712… · TIN 123". */
    public static function contactLine(array $brand): string
    {
        return collect([
            $brand['address'] ?? null,
            $brand['phone'] ?? null,
            ! empty($brand['whatsapp']) ? 'WhatsApp '.$brand['whatsapp'] : null,
            $brand['email'] ?? null,
            $brand['website'] ?? null,
            ! empty($brand['tin']) ? 'TIN '.$brand['tin'] : null,
            ! empty($brand['vrn']) ? 'VRN '.$brand['vrn'] : null,
        ])->filter()->implode(' · ');
    }

    /** The PDF bytes of a message (null if it could not be rendered). */
    public function pdf(OutgoingMessage $message): ?string
    {
        try {
            return $this->inMessageLocale(fn () => Pdf::loadView(
                $message->pdfView ?? 'pdf.document',
                $message->pdfView ? $message->pdfData + ['brand' => $this->brand('report')] : ['doc' => $message->document, 'brand' => $this->brand('report')],
            )->setPaper('a4')->output());
        } catch (Throwable $e) {
            Log::warning('Message PDF failed: '.$e->getMessage());

            return null;
        }
    }

    /** @param array<int, string> $recipients */
    private function email(OutgoingMessage $message, array $recipients): bool
    {
        $configured = Channels::configured()[Channels::EMAIL];
        $pdf = $configured ? $this->pdf($message) : null;
        $brand = $this->brand();
        $any = false;

        foreach ($recipients as $address) {
            if (! $configured) {
                MessageLogger::log(Channels::EMAIL, $address, $message->text, $message->purpose, 'disabled', 'Email is not set up on the server (MAIL_MAILER, MAIL_HOST, MAIL_FROM_ADDRESS).', $message->relatedType, $message->relatedId, $message->subject);

                continue;
            }
            try {
                $this->inMessageLocale(fn () => Mail::to($address)->send(new NotificationMail($message->subject, $message->document, $brand, $pdf, $message->attachmentName)));
                $status = 'sent';
                $response = null;
            } catch (Throwable $e) {
                $status = 'failed';
                $response = $e->getMessage();
                Log::warning('Email send failed: '.$e->getMessage());
            }
            MessageLogger::log(Channels::EMAIL, $address, $message->text, $message->purpose, $status, $response, $message->relatedType, $message->relatedId, $message->subject, $pdf !== null);
            $any = $any || $status === 'sent';
        }

        return $any;
    }
}
