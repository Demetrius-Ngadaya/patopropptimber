<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Crm\Customer;
use App\Models\Marketing\Promotion;
use App\Services\Core\AuditLogger;
use App\Services\Core\SettingsService;
use App\Services\Marketing\CustomerInsights;
use App\Services\Marketing\PromotionService;
use App\Services\Messaging\Messenger;
use App\Support\Channels;
use App\Support\DeliveryReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    public function __construct(private readonly PromotionService $promotions, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->applyTrashed(Promotion::query(), $request)
            ->withCount('redemptions')->withSum('redemptions', 'amount')
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('code', 'like', "%{$t}%")))
            ->when($request->query('state') === 'running', fn ($q) => $q->running())
            ->when($request->query('state') === 'ended', fn ($q) => $q->whereDate('ends_on', '<', today()))
            ->when($request->query('state') === 'scheduled', fn ($q) => $q->whereDate('starts_on', '>', today()))
            ->latest('id');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function show(int $id): JsonResponse
    {
        $promotion = Promotion::withTrashed()->withCount('redemptions')->withSum('redemptions', 'amount')->findOrFail($id);

        return $this->ok($promotion->toArray() + [
            'audience_size' => $this->promotions->audience($promotion)->count(),
            'recent_redemptions' => $promotion->redemptions()->with(['sale:id,number,total,sold_at', 'customer:id,name'])->latest('id')->limit(20)->get(),
            'sales_value' => (float) DB::table('sales')->where('promotion_id', $promotion->id)->where('status', 'completed')->sum('total'),
            // Names for the chosen products and customers (the form shows them as chips).
            'products' => Product::query()->whereIn('id', (array) $promotion->product_ids)->get(['id', 'name', 'sku']),
            'customers' => Customer::query()->whereIn('id', (array) $promotion->customer_ids)->limit(500)->get(['id', 'name', 'phone']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $promotion = $this->promotions->create($this->validated($request));
        $this->audit->event('promotion.created', __('Promotion :name created', ['name' => $promotion->name]), $promotion);

        return $this->created($promotion, __('Promotion :name created.', ['name' => $promotion->name]));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        $promotion->fill($this->validated($request))->save();

        return $this->ok($promotion, __('Promotion saved.'));
    }

    public function destroy(int $id): JsonResponse
    {
        Promotion::findOrFail($id)->delete();

        return $this->ok(null, __('Promotion deleted.'));
    }

    /** Promotions the customer at the till can use. */
    public function eligible(Request $request): JsonResponse
    {
        $customerId = $request->validate(['customer_id' => ['nullable', 'integer']])['customer_id'] ?? null;
        $customer = $customerId ? Customer::query()->find($customerId) : null;
        $currency = (string) app(SettingsService::class)->get('currency');

        return $this->ok($this->promotions->eligibleFor($customer)->map(fn (Promotion $p) => $p->only(['id', 'code', 'name', 'type', 'value', 'applies_to', 'min_purchase', 'ends_on']) + ['headline' => $p->headline($currency)])->values());
    }

    public function notify(Request $request, int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        $data = $request->validate([
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:sms,whatsapp,email'],
            'sms_text' => ['nullable', 'string', 'max:320'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        if (Channels::filter($data['channels']) === []) {
            throw new BusinessException(__('Choose at least one way of sending.'));
        }
        $result = $this->promotions->notify($promotion, $data['channels'], $data['sms_text'] ?? null, $data['message'] ?? null);
        $this->audit->event('promotion.notified', __('Customers told about :name', ['name' => $promotion->name]), $promotion, ['result' => $result]);

        return $this->ok(['result' => $result], DeliveryReport::message($result));
    }

    /** What the messages will look like, before sending. */
    public function preview(Request $request, int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        $data = $request->validate(['sms_text' => ['nullable', 'string', 'max:320'], 'message' => ['nullable', 'string', 'max:2000']]);
        $message = app(Messenger::class)->inMessageLocale(fn () => $this->promotions->message($promotion, $data['sms_text'] ?? null, $data['message'] ?? null));

        return $this->ok(['sms' => $message->sms, 'whatsapp' => $message->text, 'email_subject' => $message->subject, 'audience' => $this->promotions->audience($promotion)->count()]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', 'in:percent,amount'],
            'value' => ['required', 'numeric', 'gt:0', $request->input('type') === 'percent' ? 'max:100' : 'max:100000000'],
            'applies_to' => ['required', 'in:all,categories,products'],
            'category_ids' => ['required_if:applies_to,categories', 'nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'product_ids' => ['required_if:applies_to,products', 'nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'audience' => ['required', 'in:all,segments,customers'],
            'segments' => ['required_if:audience,segments', 'nullable', 'array'],
            'segments.*' => [Rule::in(CustomerInsights::SEGMENTS)],
            'customer_ids' => ['required_if:audience,customers', 'nullable', 'array', 'max:5000'],
            'customer_ids.*' => ['integer', 'exists:customers,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'uses_per_customer' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
        $data['min_purchase'] ??= 0;

        return $data;
    }
}
