<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * List / show / create / update / soft delete / restore for small lookup tables.
 *
 * The using controller defines:
 *  - protected string $model        Eloquent class
 *  - protected string $label        e.g. "Category"
 *  - protected array $searchable     columns for ?search=
 *  - protected array $withCount      (optional) relation counts for the list
 *  - protected function rules(Request $request, ?Model $record): array
 */
trait SimpleCrud
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->applyTrashed(($this->model)::query(), $request)
            ->with($this->with ?? [])
            ->withCount($this->withCount ?? [])
            ->when($request->query('search'), function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    foreach ($this->searchable as $column) {
                        $w->orWhere($column, 'like', "%{$term}%");
                    }
                });
            })
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')));

        $this->sortable($query, $request, array_merge(['id', 'created_at'], $this->searchable), $this->defaultSort ?? 'id', $this->defaultDirection ?? 'desc');

        if ($request->boolean('all')) {
            return $this->ok($query->limit(1000)->get());
        }

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok(($this->model)::withTrashed()->with($this->with ?? [])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request, null));
        $record = ($this->model)::create($this->prepare($data, null));

        return $this->created($record->fresh($this->with ?? []), __(':label created successfully.', ['label' => __($this->label)]));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $record = ($this->model)::findOrFail($id);
        $data = $request->validate($this->rules($request, $record));
        $record->fill($this->prepare($data, $record))->save();

        return $this->ok($record->fresh($this->with ?? []), __(':label updated successfully.', ['label' => __($this->label)]));
    }

    public function destroy(int $id): JsonResponse
    {
        $record = ($this->model)::findOrFail($id);
        $this->beforeDelete($record);
        $record->delete();

        return $this->ok(null, __(':label deleted. It can be restored from the deleted items list.', ['label' => __($this->label)]));
    }

    public function restore(int $id): JsonResponse
    {
        $record = ($this->model)::onlyTrashed()->findOrFail($id);
        $record->restore();

        return $this->ok($record->fresh($this->with ?? []), __(':label restored successfully.', ['label' => __($this->label)]));
    }

    /** Hook to transform validated data (slugs etc.). */
    protected function prepare(array $data, ?Model $record): array
    {
        return $data;
    }

    /** Hook to block deletes (throw a BusinessException). */
    protected function beforeDelete(Model $record): void {}
}
