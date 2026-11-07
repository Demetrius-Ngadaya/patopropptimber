<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Delivery\Delivery;
use App\Models\Sales\Sale;
use App\Services\Messaging\CustomerMessages;
use App\Services\Sales\SaleService;
use App\Support\Channels;
use App\Support\DeliveryReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $this->branch()->scope(Sale::query())
            ->with(['customer:id,name,phone', 'cashier', 'branch:id,name'])
            ->when(! $user->hasPermission('sales.view_all_users'), fn ($q) => $q->where('cashier_id', $user->id))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('customer_name', 'like', "%{$t}%")->orWhere('customer_phone', 'like', "%{$t}%")))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('payment_status'), fn ($q, $v) => $q->where('payment_status', $v))
            ->when($request->query('channel'), fn ($q, $v) => $q->where('channel', $v))
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->query('cashier_id'), fn ($q, $v) => $q->where('cashier_id', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->where('sold_at', '>=', $v.' 00:00:00'))
            ->when($request->query('date_to'), fn ($q, $v) => $q->where('sold_at', '<=', $v.' 23:59:59'));

        $this->sortable($query, $request, ['sold_at', 'total', 'number', 'balance'], 'sold_at');
        $page = $query->paginate($this->perPage($request));
        if (! $user->hasPermission('sales.view_profit')) {
            $page->getCollection()->each->makeHidden('cost_total');
        }

        return $this->paginated($page);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $sale = $this->findAccessible($request, $id)
            ->load(['items.product:id,name,sku,barcode,unit_id', 'items.product.unit:id,short_name', 'payments.receiver', 'customer', 'cashier', 'seller', 'branch', 'returns.items.product:id,name', 'returns.creator']);

        if (! $request->user()->hasPermission('sales.view_profit')) {
            $sale->makeHidden('cost_total');
            $sale->items->each->makeHidden('unit_cost');
        }

        return $this->ok($sale->toArray() + [
            'delivery' => Delivery::query()->where('sale_id', $sale->id)->with('courier:id,name,phone')->latest('id')->first(),
        ]);
    }

    public function cancel(Request $request, int $id, SaleService $sales): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $sale = $sales->cancel($this->findAccessible($request, $id), $request->user(), $data['reason']);

        return $this->ok($sale, __('Sale :sale cancelled. Stock has been returned.', ['sale' => $sale->number]));
    }

    public function returnItems(Request $request, int $id, SaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.condition' => ['required', Rule::in(['restock', 'damaged'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'refund_method' => ['nullable', Rule::in(['cash', 'mobile_money', 'bank', 'credit_note'])],
        ]);

        $return = $sales->processReturn($this->findAccessible($request, $id), $data['items'], $data['reason'], $data['refund_method'] ?? 'cash', $request->user());

        $msg = "Return {$return->number} recorded.";
        if ((float) $return->refund_amount > 0) {
            $msg .= ' Refund to customer: '.number_format((float) $return->refund_amount, 2).'.';
        }
        if ((float) $return->credit_amount > 0) {
            $msg .= ' Debt reduced by '.number_format((float) $return->credit_amount, 2).'.';
        }

        return $this->created($return, $msg);
    }

    /** Send (or re-send) the receipt to the customer on the chosen channels. */
    public function sendReceipt(Request $request, int $id, CustomerMessages $messages): JsonResponse
    {
        $sale = $this->findAccessible($request, $id);
        $data = $request->validate([
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'in:sms,whatsapp,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);
        $channels = Channels::filter($data['channels'] ?? [Channels::SMS]);
        if ($channels === []) {
            throw new BusinessException(__('Choose at least one way of sending.'));
        }
        $result = $messages->receipt($sale, $channels, $data['phone'] ?? null, $data['email'] ?? null);

        return $this->ok(['result' => $result, 'sent' => DeliveryReport::sentAny($result)], DeliveryReport::message($result));
    }

    private function findAccessible(Request $request, int $id): Sale
    {
        $user = $request->user();

        return $this->branch()->scope(Sale::query())
            ->when(! $user->hasPermission('sales.view_all_users'), fn ($q) => $q->where('cashier_id', $user->id))
            ->findOrFail($id);
    }
}
