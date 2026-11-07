<?php

namespace App\Services\Marketing;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Crm\Customer;
use App\Models\Marketing\Promotion;
use App\Models\Marketing\PromotionRedemption;
use App\Models\Sales\Sale;
use App\Services\Core\NumberGenerator;
use App\Services\Core\SettingsService;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\OutgoingMessage;
use App\Support\Channels;
use App\Support\Recipients;
use Illuminate\Support\Collection;

/**
 * Who a promotion is for, how much it takes off a sale, and telling the
 * customers about it.
 */
class PromotionService
{
    public function __construct(
        private readonly CustomerInsights $insights,
        private readonly SettingsService $settings,
        private readonly NumberGenerator $numbers,
        private readonly Messenger $messenger,
    ) {}

    public function create(array $data): Promotion
    {
        $promotion = new Promotion($data);
        $promotion->code = $this->numbers->next('promotion');
        $promotion->save();

        return $promotion;
    }

    /** Running promotions this customer may use now (audience and use limit checked). */
    public function eligibleFor(?Customer $customer): Collection
    {
        $segments = null;

        return Promotion::query()->running()->orderBy('ends_on')->get()->filter(function (Promotion $p) use ($customer, &$segments) {
            if ($p->audience !== 'all') {
                if (! $customer) {
                    return false;
                }
                if ($p->audience === 'customers' && ! in_array($customer->id, array_map('intval', (array) $p->customer_ids), true)) {
                    return false;
                }
                if ($p->audience === 'segments') {
                    $segments ??= $this->insights->segmentsFor($customer);
                    if (array_intersect((array) $p->segments, $segments) === []) {
                        return false;
                    }
                }
            }

            return ! ($customer && $p->uses_per_customer && $p->redemptions()->where('customer_id', $customer->id)->count() >= $p->uses_per_customer);
        })->values();
    }

    /**
     * The discount a promotion gives on these lines.
     *
     * @return array{0: Promotion, 1: float}
     */
    public function discountFor(int $promotionId, ?Customer $customer, iterable $lines): array
    {
        return [$promotion = $this->findEligible($promotionId, $customer), $this->discount($promotion, $lines)];
    }

    /** The promotion, if this customer may use it now. Check before the sale is written (a sale changes the customer's group). */
    public function findEligible(int $promotionId, ?Customer $customer): Promotion
    {
        $promotion = $this->eligibleFor($customer)->firstWhere('id', $promotionId);
        if (! $promotion) {
            throw new BusinessException(__('This promotion is not available for this customer.'), 'PROMOTION_NOT_AVAILABLE');
        }

        return $promotion;
    }

    /** @param iterable<array{product_id: int, line_total: float}> $lines */
    public function discount(Promotion $promotion, iterable $lines): float
    {
        $lines = collect($lines);
        $categories = Product::query()->whereIn('id', $lines->pluck('product_id'))->pluck('category_id', 'id');
        $base = round((float) $lines->filter(fn ($l) => match ($promotion->applies_to) {
            'products' => in_array((int) $l['product_id'], array_map('intval', (array) $promotion->product_ids), true),
            'categories' => in_array((int) ($categories[$l['product_id']] ?? 0), array_map('intval', (array) $promotion->category_ids), true),
            default => true,
        })->sum(fn ($l) => (float) $l['line_total']), 2);

        if ($base <= 0) {
            throw new BusinessException(__('None of the items in this sale are part of the promotion.'), 'PROMOTION_NOT_APPLICABLE');
        }
        if ($base < (float) $promotion->min_purchase) {
            throw new BusinessException(__('This promotion needs a purchase of at least :amount.', ['amount' => number_format((float) $promotion->min_purchase)]), 'PROMOTION_MIN_PURCHASE');
        }

        $discount = $promotion->type === 'percent' ? $base * (float) $promotion->value / 100 : (float) $promotion->value;
        if ($promotion->max_discount !== null && (float) $promotion->max_discount > 0) {
            $discount = min($discount, (float) $promotion->max_discount);
        }

        return round(min($discount, $base), 2);
    }

    public function recordRedemption(Promotion $promotion, Sale $sale, float $amount): void
    {
        PromotionRedemption::create(['promotion_id' => $promotion->id, 'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'amount' => $amount]);
    }

    /** Customers a promotion is meant for, who agreed to receive messages. */
    public function audience(Promotion $promotion): Collection
    {
        $query = Customer::query()->where('is_active', true)->where('sms_opt_in', true);
        $ids = match ($promotion->audience) {
            'customers' => array_map('intval', (array) $promotion->customer_ids),
            'segments' => $this->insights->customersIn((array) $promotion->segments),
            default => null,
        };

        return ($ids === null ? $query : $query->whereIn('id', $ids ?: [0]))->limit(5000)->get(['id', 'name', 'phone', 'email']);
    }

    /**
     * Tell the customers: a short SMS, a fuller WhatsApp message, and an email
     * with the full offer and a PDF flyer. Staff may write their own text.
     *
     * @return array<string, array{recipients: int, sent: bool}>
     */
    public function notify(Promotion $promotion, array $channels, ?string $smsText = null, ?string $message = null): array
    {
        $customers = $this->audience($promotion);
        if ($customers->isEmpty()) {
            throw new BusinessException(__('None of the customers in this promotion has agreed to receive messages.'));
        }
        $channels = Channels::filter($channels);
        $built = $this->messenger->inMessageLocale(fn () => $this->message($promotion, $smsText, $message));

        // SMS and WhatsApp in bulk; email one by one (each person gets their own copy).
        $phones = Channels::phones($customers->pluck('phone')->filter()->all());
        $result = $this->messenger->send($built, array_values(array_diff($channels, [Channels::EMAIL])), new Recipients($phones, $phones));
        if (in_array(Channels::EMAIL, $channels, true)) {
            $emails = Channels::emails($customers->pluck('email')->filter()->all());
            $result += $this->messenger->send($built, [Channels::EMAIL], new Recipients(emails: $emails));
        }
        $promotion->forceFill(['notified_at' => now()])->save();

        return $result;
    }

    public function message(Promotion $promotion, ?string $smsText = null, ?string $text = null): OutgoingMessage
    {
        $b = (string) $this->settings->get('business_name');
        $currency = (string) $this->settings->get('currency');
        $phone = (string) $this->settings->get('business_phone');
        $offer = $promotion->headline($currency);
        $until = $promotion->ends_on?->format('d/m/Y');
        $scope = $this->scopeText($promotion);
        $min = (float) $promotion->min_purchase > 0 ? __('when you buy :currency :amount or more', ['currency' => $currency, 'amount' => number_format((float) $promotion->min_purchase)]) : null;

        $sms = $smsText ?: __(':business: :name - :offer :scope until :date. Karibu!', ['business' => $b, 'name' => $promotion->name, 'offer' => $offer, 'scope' => $scope, 'date' => $until]);
        $lines = array_values(array_filter([
            "*{$b}*",
            "*{$promotion->name}*",
            $text ?: $promotion->description,
            '',
            '🎁 '.$offer.' '.$scope.($min ? ', '.$min : ''),
            '📅 '.__('From :from to :to', ['from' => $promotion->starts_on?->format('d/m/Y'), 'to' => $until]),
            $phone ? '📞 '.$phone : null,
        ], fn ($l) => $l !== null));

        $doc = [
            'title' => $promotion->name,
            'subtitle' => $offer.' '.$scope,
            'intro' => array_values(array_filter([$text ?: $promotion->description])),
            'meta' => array_filter([
                __('Offer') => $offer,
                __('On') => $scope,
                __('Minimum purchase') => $min ? $currency.' '.number_format((float) $promotion->min_purchase) : null,
                __('Valid') => __('From :from to :to', ['from' => $promotion->starts_on?->format('d/m/Y'), 'to' => $until]),
                __('Promotion code') => $promotion->code,
            ]),
            'sections' => $promotion->applies_to === 'products' ? [[
                'heading' => __('Products on offer'),
                'columns' => [__('Product'), __('Usual price')],
                'numeric' => [1],
                'rows' => Product::query()->whereIn('id', (array) $promotion->product_ids)->orderBy('name')->get(['name', 'selling_price'])
                    ->map(fn ($p) => [$p->name, number_format((float) $p->selling_price)])->all(),
            ]] : [],
            'closing' => [__('Show this message at the till or mention the promotion code. We look forward to seeing you.')],
        ];

        return new OutgoingMessage('promotion', $sms, implode("\n", $lines), $promotion->name.' · '.$b, $doc, 'offer-'.$promotion->code.'.pdf', $promotion->getMorphClass(), $promotion->id);
    }

    private function scopeText(Promotion $promotion): string
    {
        return match ($promotion->applies_to) {
            'products' => __('on :items', ['items' => Product::query()->whereIn('id', (array) $promotion->product_ids)->limit(3)->pluck('name')->implode(', ')]),
            'categories' => __('on :items', ['items' => Category::query()->whereIn('id', (array) $promotion->category_ids)->limit(3)->pluck('name')->implode(', ')]),
            default => __('on everything'),
        };
    }
}
