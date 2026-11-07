<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Transfer;
use App\Services\Inventory\TransferService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transfers) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->visible(Transfer::query(), $request->query('direction', 'all'))
            ->with(['fromBranch:id,name', 'toBranch:id,name', 'sender', 'receiver'])
            ->withCount('items')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where('number', 'like', "%{$t}%"))
            ->latest('sent_at');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function show(int $id): JsonResponse
    {
        $transfer = $this->visible(Transfer::query(), 'all')
            ->with(['items.product:id,name,sku,unit_id', 'items.product.unit:id,short_name', 'fromBranch', 'toBranch', 'sender', 'receiver'])
            ->findOrFail($id);
        $transfer->items->each->makeHidden('batches');

        return $this->ok($transfer);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to_branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::in(Transfer::TYPES)],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $transfer = $this->transfers->send($data, $request->user(), $this->branch()->requireId());

        return $this->created($transfer, __('Transfer :transfer sent to :to_branch. It must be received there.', ['transfer' => $transfer->number, 'to_branch' => $transfer->toBranch->name]));
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $transfer = Transfer::query()->findOrFail($id);
        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.transfer_item_id' => ['required', 'integer'],
            'items.*.received_quantity' => ['required', 'numeric', 'min:0'],
            'receiving_costs' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $transfer = $this->transfers->receive($transfer, $data['items'] ?? [], (float) ($data['receiving_costs'] ?? 0), $data['notes'] ?? null, $request->user(), $this->branch()->requireId());

        return $this->ok($transfer, __('Transfer :transfer received. Stock has been added.', ['transfer' => $transfer->number]));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $transfer = Transfer::query()->findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $transfer = $this->transfers->cancel($transfer, $data['reason'], $request->user(), $this->branch()->requireId());

        return $this->ok($transfer, __('Transfer :transfer cancelled. Stock returned to the sending branch.', ['transfer' => $transfer->number]));
    }

    public function markPaid(int $id): JsonResponse
    {
        $transfer = $this->visible(Transfer::query(), 'all')->findOrFail($id);

        return $this->ok($this->transfers->markPaid($transfer), __('Transfer marked as paid.'));
    }

    private function visible(Builder $query, string $direction): Builder
    {
        if ($this->branch()->viewingAll()) {
            return $query;
        }
        $ids = $this->branch()->id() ? [$this->branch()->id()] : $this->branch()->accessibleIds();

        return match ($direction) {
            'in' => $query->whereIn('to_branch_id', $ids),
            'out' => $query->whereIn('from_branch_id', $ids),
            default => $query->where(fn ($w) => $w->whereIn('from_branch_id', $ids)->orWhereIn('to_branch_id', $ids)),
        };
    }
}
