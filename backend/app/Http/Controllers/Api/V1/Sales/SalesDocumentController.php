<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Sales\Payment;
use App\Models\Sales\SalesDocument;
use App\Services\Documents\SalesDocumentPresenter;
use App\Services\Documents\SalesDocumentSender;
use App\Services\Documents\SalesDocumentService;
use App\Support\Channels;
use App\Support\DeliveryReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Quotations, proforma invoices and invoices (staff side). */
class SalesDocumentController extends Controller
{
    public function __construct(
        private readonly SalesDocumentService $documents,
        private readonly SalesDocumentPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(SalesDocument::TYPES)]])['type'];
        $base = fn () => $this->branch()->scope(SalesDocument::query()->where('type', $type))
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('issue_date', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('issue_date', '<=', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")
                ->orWhere('customer_name', 'like', "%{$t}%")->orWhere('customer_phone', 'like', "%{$t}%")));

        $query = $base()->with(['sale:id,number,status,total,amount_paid,balance', 'branch:id,name'])
            ->when($request->query('stage'), fn ($q, $s) => $q->stage($s));
        $this->sortable($query, $request, ['issue_date', 'due_date', 'total', 'number', 'created_at'], 'id', 'desc');

        // Counts for the stage tabs.
        $counts = collect(SalesDocument::STAGES[$type])->mapWithKeys(fn ($s) => [$s => $base()->stage($s)->count()]);

        return $this->paginated($query->paginate($this->perPage($request)), ['counts' => $counts, 'stages' => SalesDocument::STAGES[$type]]);
    }

    public function show(int $id): JsonResponse
    {
        $document = $this->find($id)->load(['items.product:id,name,sku,unit_id', 'customer', 'branch:id,name', 'sale', 'creator:id,name', 'events.user:id,name', 'convertedFrom:id,type,number,status', 'convertedTo:id,type,number,status']);
        // Linked documents are only shown as links: skip their worked-out stage and balance.
        $document->convertedFrom?->setAppends([]);
        $document->convertedTo?->setAppends([]);

        return $this->ok($document->toArray() + [
            'title' => $document->title(),
            'editable' => $this->documents->editable($document),
            'payments' => $this->documents->payments($document),
            'public_url' => app(SalesDocumentSender::class)->link($document),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        return $this->ok($this->documents->preview($this->validated($request, partial: true)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $document = $this->documents->save($data['type'], $data, $request->user(), $this->branch()->requireId());

        return $this->created($document, __(':title :number saved as a draft.', ['title' => $document->title(), 'number' => $document->number]));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $document = $this->find($id);
        $data = $this->validated($request);
        $document = $this->documents->save($document->type, $data, $request->user(), $document->branch_id, $document);

        return $this->ok($document, __(':number saved.', ['number' => $document->number]));
    }

    public function destroy(int $id): JsonResponse
    {
        $document = $this->find($id);
        if ($document->status !== 'draft') {
            throw new BusinessException(__('Only drafts can be deleted. Cancel it instead.'));
        }
        $document->delete();

        return $this->ok(null, __('Draft deleted.'));
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        $document = $this->documents->issue($this->find($id), $request->user());

        return $this->ok($document, __('Invoice :number issued. The amount is now owed by :customer.', ['number' => $document->number, 'customer' => $document->customer_name]));
    }

    public function send(Request $request, int $id, SalesDocumentSender $sender): JsonResponse
    {
        $document = $this->find($id);
        $data = $request->validate([
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:sms,whatsapp,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);
        $channels = Channels::filter($data['channels']);
        if ($channels === []) {
            throw new BusinessException(__('Choose at least one way of sending.'));
        }
        if ($document->type === SalesDocument::INVOICE && $document->status === 'draft' && ! $request->user()->hasPermission('documents.issue')) {
            throw new BusinessException(__('Sending a draft invoice issues it, and you are not allowed to issue invoices.'), 'FORBIDDEN', 403);
        }
        if (in_array($document->stage, ['cancelled', 'converted', 'closed'], true)) {
            throw new BusinessException(__('This :type is :stage and cannot be sent.', ['type' => __($document->type), 'stage' => __($document->stage)]));
        }

        ['document' => $document, 'result' => $result] = $sender->send($document, $channels, $request->user(), $data['phone'] ?? null, $data['email'] ?? null);

        return $this->ok(['document' => $document, 'result' => $result], DeliveryReport::message($result));
    }

    public function payment(Request $request, int $id): JsonResponse
    {
        $document = $this->find($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(Payment::TILL_METHODS)],
            'provider' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $document = $this->documents->receivePayment($document, $data, $request->user(), $this->branch()->requireId());

        return $this->ok($document, $document->stage === 'paid'
            ? __(':number is fully paid.', ['number' => $document->number])
            : __('Payment recorded. Balance: :balance.', ['balance' => number_format($document->balance, 2)]));
    }

    public function convert(Request $request, int $id): JsonResponse
    {
        $to = $request->validate(['to' => ['required', Rule::in([SalesDocument::PROFORMA, SalesDocument::INVOICE])]])['to'];
        $new = $this->documents->convert($this->find($id), $to, $request->user());

        return $this->created($new, __(':number created as a draft.', ['number' => $new->number]));
    }

    /** Staff record the customer's answer (e.g. given by phone). */
    public function respond(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['accept' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);
        $document = $this->documents->respond($this->find($id), $data['accept'], $data['reason'] ?? null);

        return $this->ok($document, $data['accept'] ? __('Quotation accepted.') : __('Quotation declined.'));
    }

    public function close(int $id): JsonResponse
    {
        return $this->ok($this->documents->close($this->find($id)), __('Closed.'));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];
        $document = $this->documents->cancel($this->find($id), $reason, $request->user());

        return $this->ok($document, __(':number cancelled.', ['number' => $document->number]));
    }

    public function pdf(int $id): Response
    {
        $document = $this->find($id);

        return response($this->presenter->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->presenter->fileName($document).'"',
        ]);
    }

    private function find(int $id): SalesDocument
    {
        return $this->branch()->scope(SalesDocument::query())->findOrFail($id);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'type' => [$partial ? 'nullable' : 'required', Rule::in(SalesDocument::TYPES)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'customer' => ['nullable', 'array'],
            'customer.name' => [$partial ? 'nullable' : 'required_without:customer_id', 'nullable', 'string', 'max:150'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:120'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'customer.tin' => ['nullable', 'string', 'max:40'],
            'customer.vrn' => ['nullable', 'string', 'max:40'],
            'price_level' => ['nullable', 'in:retail,wholesale'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
