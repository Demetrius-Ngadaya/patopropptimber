<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Crm\Customer;
use App\Rules\EmailList;
use App\Services\Core\SettingsService;
use App\Services\Messaging\CustomerMessages;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\OutgoingMessage;
use App\Support\Channels;
use App\Support\DeliveryReport;
use App\Support\Recipients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Messages typed by staff: to phone numbers / emails, to chosen customers, or to all debtors. */
class SmsController extends Controller
{
    public function send(Request $request, Messenger $messenger, CustomerMessages $customerMessages, SettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'in:numbers,customers,debtors'],
            'channels' => ['nullable', 'array', 'min:1'],
            'channels.*' => ['string', 'in:sms,whatsapp,email'],
            'numbers' => ['nullable', 'string', 'max:2000'],
            'emails' => ['nullable', 'string', 'max:2000', new EmailList],
            'customer_ids' => ['required_if:audience,customers', 'nullable', 'array', 'max:500'],
            'customer_ids.*' => ['integer', 'exists:customers,id'],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:2', 'max:2000'],
        ]);
        $channels = Channels::filter($data['channels'] ?? [Channels::SMS]);
        if ($channels === []) {
            throw new BusinessException(__('Choose at least one way of sending.'));
        }
        if (in_array(Channels::SMS, $channels, true) && mb_strlen($data['message']) > 640) {
            throw new BusinessException(__('An SMS can be at most 640 characters. Shorten the message or send it by WhatsApp or email only.'));
        }
        $subject = ($data['subject'] ?? null) ?: __('Message from :business', ['business' => $settings->get('business_name')]);
        $message = new OutgoingMessage('manual', $data['message'], $data['message'], $subject, [
            'title' => $subject,
            'intro' => preg_split('/\n{2,}/', trim($data['message'])),
        ], 'message.pdf');

        if ($data['audience'] === 'numbers') {
            $to = new Recipients(Channels::phones($data['numbers'] ?? ''), Channels::phones($data['numbers'] ?? ''), Channels::emails($data['emails'] ?? ''));
            if ($to->isEmpty()) {
                throw new BusinessException(__('No valid phone numbers or email addresses to send to.'));
            }
            $result = $messenger->send($message, $channels, $to);

            return $this->ok(['result' => $result], DeliveryReport::message($result));
        }

        $customers = Customer::query()->where('is_active', true)->where('sms_opt_in', true)
            ->when($data['audience'] === 'customers', fn ($q) => $q->whereIn('id', $data['customer_ids']))
            ->when($data['audience'] === 'debtors', fn ($q) => $q->where('balance', '>', 0))
            ->limit(5000)->get();
        if ($customers->isEmpty()) {
            throw new BusinessException(__('None of these customers has agreed to receive messages.'));
        }

        // SMS and WhatsApp: one bulk send. Email: one personal email each, with their unpaid invoices.
        $phones = Channels::phones($customers->pluck('phone')->filter()->all());
        $result = $messenger->send($message, array_values(array_diff($channels, [Channels::EMAIL])), new Recipients($phones, $phones));
        if (in_array(Channels::EMAIL, $channels, true)) {
            $withEmail = $customers->filter(fn ($c) => filter_var($c->email, FILTER_VALIDATE_EMAIL))->take(500);
            $sent = 0;
            foreach ($withEmail as $customer) {
                $sent += ($customerMessages->custom($customer, $subject, $data['message'], [Channels::EMAIL])[Channels::EMAIL]['sent'] ?? false) ? 1 : 0;
            }
            $result[Channels::EMAIL] = ['recipients' => $withEmail->count(), 'sent' => $sent > 0];
        }

        return $this->ok(['result' => $result], DeliveryReport::message($result));
    }
}
