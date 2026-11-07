<?php

namespace App\Services\Mobile;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductUnit;
use App\Models\Core\AppNotification;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Inventory\ProductStock;
use App\Models\Sales\Order;
use App\Models\Sales\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Server → phone half of the two-way sync.
 *
 * Each kind of record has its own cursor (last updated_at + id seen). A pull
 * returns at most one page per kind, oldest change first; the app keeps
 * pulling while any kind says has_more. Deleted records come back as
 * tombstones ({id, deleted: true}) so the phone removes them too.
 *
 * Timestamps have one-second precision and a long transaction can commit a
 * row with an older updated_at after we read past it, so the cursor saved at
 * the end of a round steps back OVERLAP_SECONDS: those rows are sent again,
 * which is harmless because the app upserts by id.
 */
class SyncPuller
{
    public const ENTITIES = ['categories', 'products', 'units', 'stock', 'customers', 'orders', 'sales', 'notifications'];

    private const OVERLAP_SECONDS = 60;

    /**
     * @param  array<string, ?string>  $cursors
     * @return array{server_time: string, entities: array<string, array{rows: array<int, mixed>, cursor: ?string, has_more: bool}>}
     */
    public function pull(User $user, int $branchId, array $cursors, ?array $only = null): array
    {
        $limit = max(50, (int) config('mauzo.mobile.pull_page_size', 500));
        $out = [];
        foreach (self::ENTITIES as $entity) {
            if (($only && ! in_array($entity, $only, true)) || ! $this->allowed($user, $entity)) {
                continue;
            }
            $cursor = $cursors[$entity] ?? null;
            $out[$entity] = $entity === 'notifications'
                ? $this->notifications($user, $cursor, $limit)
                : $this->page($entity, $this->query($entity, $user, $branchId, $cursor === null), $cursor, $limit, fn ($row) => $this->present($entity, $row));
        }

        return ['server_time' => now()->toIso8601String(), 'entities' => $out];
    }

    public function allowed(User $user, string $entity): bool
    {
        return match ($entity) {
            'categories', 'products', 'units', 'stock' => $user->hasPermission('products.view') || $user->hasPermission('pos.access'),
            'customers' => $user->hasPermission('customers.view') || $user->hasPermission('pos.access'),
            'orders' => $user->hasPermission('orders.view'),
            'sales' => $user->hasPermission('sales.view') || $user->hasPermission('pos.access'),
            'notifications' => true,
            default => false,
        };
    }

    private function query(string $entity, User $user, int $branchId, bool $initial): Builder
    {
        return match ($entity) {
            'categories' => Category::query()->withTrashed(),
            'products' => Product::query()->withTrashed()->with('unit:id,short_name'),
            'units' => ProductUnit::query()->withTrashed(),
            'stock' => ProductStock::query()->where('branch_id', $branchId),
            'customers' => Customer::query()->withTrashed(),
            'orders' => Order::query()->where('branch_id', $branchId)
                ->with(['customer:id,name,phone', 'items:id,order_id,product_id,product_name,quantity,unit_price,line_total'])
                // First download: the last 60 days; afterwards every change.
                ->when($initial, fn ($q) => $q->where('created_at', '>=', now()->subDays(60))),
            'sales' => Sale::query()->where('branch_id', $branchId)
                ->withCount('items')
                ->with('payments:id,sale_id,method,amount')
                ->when(! $user->hasPermission('sales.view_all_users') || ! $user->hasPermission('sales.view'), fn ($q) => $q->where('cashier_id', $user->id))
                ->when($initial, fn ($q) => $q->where('sold_at', '>=', now()->subDays(14))),
        };
    }

    /** @return array{rows: array<int, mixed>, cursor: ?string, has_more: bool} */
    private function page(string $entity, Builder $query, ?string $cursor, int $limit, callable $present): array
    {
        $table = $query->getModel()->getTable();
        [$ts, $id] = $this->decode($cursor);
        if ($ts) {
            $query->where(fn ($q) => $q->where("{$table}.updated_at", '>', $ts)
                ->orWhere(fn ($w) => $w->where("{$table}.updated_at", $ts)->where("{$table}.id", '>', $id)));
        }
        $rows = $query->orderBy("{$table}.updated_at")->orderBy("{$table}.id")->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $last = $rows->last();

        if (! $last) {
            return ['rows' => [], 'cursor' => $cursor, 'has_more' => false];
        }
        $lastTs = $last->updated_at instanceof Carbon ? $last->updated_at : Carbon::parse((string) $last->updated_at);
        $next = $hasMore
            ? $this->encode($lastTs, (int) $last->id)
            // End of this round: step back so rows committed late are not missed.
            : $this->encode(max($lastTs->copy()->subSeconds(self::OVERLAP_SECONDS), $ts ?? $lastTs->copy()->subSeconds(self::OVERLAP_SECONDS)), 0);

        return ['rows' => $rows->map($present)->values()->all(), 'cursor' => $next, 'has_more' => $hasMore];
    }

    /** @return array{rows: array<int, mixed>, cursor: ?string, has_more: bool} */
    private function notifications(User $user, ?string $cursor, int $limit): array
    {
        $base = AppNotification::query()->visibleTo($user)
            ->withExists(['readers as is_read' => fn ($q) => $q->whereKey($user->id)]);
        if ($cursor === null) {
            // First download: only the latest 100, not two months of history.
            $rows = $base->latest('id')->limit(100)->get()->reverse()->values();
            $hasMore = false;
        } else {
            $rows = $base->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit + 1)->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit);
        }

        return [
            'rows' => $rows->map(fn (AppNotification $n) => $n->present((bool) $n->is_read))->values()->all(),
            'cursor' => (string) ($rows->max('id') ?? $cursor ?? 0),
            'has_more' => $hasMore,
        ];
    }

    private function present(string $entity, $m): array
    {
        if (method_exists($m, 'trashed') && $m->trashed()) {
            return ['id' => $m->id, 'deleted' => true];
        }

        return match ($entity) {
            'categories' => [
                'id' => $m->id, 'parent_id' => $m->parent_id, 'name' => $m->name, 'sort_order' => (int) $m->sort_order, 'is_active' => (bool) $m->is_active,
            ],
            'products' => [
                'id' => $m->id, 'name' => $m->name, 'sku' => $m->sku, 'barcode' => $m->barcode, 'category_id' => $m->category_id,
                'unit' => $m->unit?->short_name, 'selling_price' => (float) $m->selling_price,
                'wholesale_price' => $m->wholesale_price !== null ? (float) $m->wholesale_price : null,
                'wholesale_min_qty' => $m->wholesale_min_qty !== null ? (float) $m->wholesale_min_qty : null,
                'min_selling_price' => $m->min_selling_price !== null ? (float) $m->min_selling_price : null,
                'tax_rate' => (float) $m->tax_rate, 'allow_decimal' => (bool) $m->allow_decimal, 'is_service' => (bool) $m->is_service,
                'reorder_level' => $m->reorder_level !== null ? (float) $m->reorder_level : null,
                'is_active' => (bool) $m->is_active, 'image_url' => $m->image_url, 'updated_at' => $m->updated_at?->toIso8601String(),
            ],
            'units' => [
                'id' => $m->id, 'product_id' => $m->product_id, 'name' => $m->name, 'factor' => (float) $m->factor, 'barcode' => $m->barcode,
                'retail_price' => (float) $m->retail_price, 'wholesale_price' => $m->wholesale_price !== null ? (float) $m->wholesale_price : null,
                'is_active' => (bool) $m->is_active, 'sort_order' => (int) $m->sort_order,
            ],
            'stock' => ['id' => $m->id, 'product_id' => $m->product_id, 'quantity' => (float) $m->quantity],
            'customers' => [
                'id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'phone' => $m->phone, 'email' => $m->email, 'address' => $m->address,
                'city' => $m->city, 'customer_type' => $m->customer_type, 'allow_credit' => (bool) $m->allow_credit,
                'credit_limit' => (float) $m->credit_limit, 'balance' => (float) $m->balance, 'is_active' => (bool) $m->is_active,
                'updated_at' => $m->updated_at?->toIso8601String(),
            ],
            'orders' => [
                'id' => $m->id, 'number' => $m->number, 'status' => $m->status, 'source' => $m->source,
                'customer' => $m->customer?->only(['id', 'name', 'phone']), 'contact_phone' => $m->contact_phone,
                'delivery_method' => $m->delivery_method, 'delivery_address' => $m->delivery_address, 'payment_method' => $m->payment_method,
                'subtotal' => (float) $m->subtotal, 'delivery_fee' => (float) $m->delivery_fee, 'discount' => (float) $m->discount, 'total' => (float) $m->total,
                'paid_online' => (float) ($m->paid_online ?? 0), 'customer_notes' => $m->customer_notes, 'sale_id' => $m->sale_id,
                'items' => $m->items->map(fn ($i) => ['product_id' => $i->product_id, 'name' => $i->product_name, 'quantity' => (float) $i->quantity, 'unit_price' => (float) $i->unit_price, 'line_total' => (float) $i->line_total])->values()->all(),
                'created_at' => $m->created_at?->toIso8601String(), 'updated_at' => $m->updated_at?->toIso8601String(),
            ],
            'sales' => [
                'id' => $m->id, 'number' => $m->number, 'status' => $m->status, 'payment_status' => $m->payment_status,
                'customer_name' => $m->customer_name, 'customer_id' => $m->customer_id, 'cashier_id' => $m->cashier_id,
                'total' => (float) $m->total, 'amount_paid' => (float) $m->amount_paid, 'balance' => (float) $m->balance,
                'items_count' => (int) $m->items_count, 'sold_at' => $m->sold_at?->toIso8601String(),
                // How it was paid, so the phone's "today" card splits cash from the rest.
                'payments' => $m->payments->map(fn ($p) => ['method' => $p->method, 'amount' => (float) $p->amount])->values()->all(),
            ],
        };
    }

    /** @return array{0: ?Carbon, 1: int} */
    private function decode(?string $cursor): array
    {
        if (! $cursor || ! str_contains($cursor, '|')) {
            return [null, 0];
        }
        [$ts, $id] = explode('|', $cursor, 2);
        try {
            return [Carbon::createFromTimestamp((int) $ts, config('app.timezone')), (int) $id];
        } catch (\Throwable) {
            return [null, 0];
        }
    }

    private function encode(Carbon $ts, int $id): string
    {
        return $ts->getTimestamp().'|'.$id;
    }
}
