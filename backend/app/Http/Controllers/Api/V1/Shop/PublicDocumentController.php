<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesDocument;
use App\Services\Documents\SalesDocumentPresenter;
use App\Services\Documents\SalesDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The customer's copy of a document, opened from the link in their SMS,
 * WhatsApp or email. The 40-character token is the only key; drafts are
 * never shown.
 */
class PublicDocumentController extends Controller
{
    public function __construct(
        private readonly SalesDocumentService $documents,
        private readonly SalesDocumentPresenter $presenter,
    ) {}

    public function show(string $token): JsonResponse
    {
        $document = $this->find($token);
        $this->documents->markViewed($document);

        $data = $this->presenter->data($document);
        unset($data['brand']['logo_path']); // a file path on the server, only the PDF renderer needs it

        return $this->ok($data + [
            'can_respond' => $document->type === SalesDocument::QUOTATION && $document->stage === 'sent',
            'accepted_at' => $document->accepted_at?->toIso8601String(),
        ]);
    }

    public function pdf(string $token): Response
    {
        $document = $this->find($token);

        return response($this->presenter->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->presenter->fileName($document).'"',
        ]);
    }

    public function respond(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['accept' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);
        $document = $this->documents->respond($this->find($token), $data['accept'], $data['reason'] ?? null);

        return $this->ok(['stage' => $document->stage], $data['accept'] ? __('Thank you. We have received your acceptance.') : __('Thank you for letting us know.'));
    }

    private function find(string $token): SalesDocument
    {
        return SalesDocument::query()->where('public_token', $token)->where('status', '!=', 'draft')->firstOrFail();
    }
}
