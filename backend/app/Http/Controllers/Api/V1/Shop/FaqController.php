<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Concerns\SimpleCrud;
use App\Http\Controllers\Controller;
use App\Models\Shop\Faq;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Shop FAQs: managed in the back office, shown in the online shop and used by its assistant. */
class FaqController extends Controller
{
    use SimpleCrud;

    protected string $model = Faq::class;

    protected string $label = 'Question';

    protected array $searchable = ['question', 'answer', 'category'];

    protected string $defaultSort = 'sort_order';

    protected string $defaultDirection = 'asc';

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepare(array $data, ?Model $record): array
    {
        $data['sort_order'] ??= 0;

        return $data;
    }

    /** Published FAQs for the online shop. */
    public function published(Request $request): JsonResponse
    {
        $rows = Faq::query()->published()
            ->when($request->query('search'), fn ($q, $t) => $q->matching((string) $t))
            ->limit(200)->get(['id', 'question', 'answer', 'category']);

        return $this->ok($rows);
    }
}
