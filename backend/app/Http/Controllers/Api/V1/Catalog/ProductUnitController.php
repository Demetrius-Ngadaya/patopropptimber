<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Packs / alternative units of a product, e.g. "Carton (24)" or "Dozen (12)".
 * Wholesalers sell by the pack, retailers by the piece: stock stays in base units.
 */
class ProductUnitController extends Controller
{
    public function index(int $productId): JsonResponse
    {
        $product = Product::withTrashed()->findOrFail($productId);

        return $this->ok($product->units()->withTrashed()->with('unit:id,name,short_name')->orderBy('sort_order')->orderBy('factor')->get());
    }

    public function store(Request $request, int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $unit = $product->units()->create($this->validated($request, $product));

        return $this->created($unit->load('unit:id,name,short_name'), __('Pack ":name" added successfully.', ['name' => $unit->name]));
    }

    public function update(Request $request, int $productId, int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $unit = $product->units()->findOrFail($id);
        $unit->update($this->validated($request, $product, $unit));

        return $this->ok($unit->fresh('unit:id,name,short_name'), __('Pack ":name" updated successfully.', ['name' => $unit->name]));
    }

    public function destroy(int $productId, int $id): JsonResponse
    {
        $unit = ProductUnit::query()->where('product_id', $productId)->findOrFail($id);
        $unit->delete();

        return $this->ok(null, __('Pack deleted. It can be restored.'));
    }

    public function restore(int $productId, int $id): JsonResponse
    {
        $unit = ProductUnit::onlyTrashed()->where('product_id', $productId)->findOrFail($id);
        if ($unit->barcode && ProductUnit::query()->where('barcode', $unit->barcode)->exists()) {
            throw new BusinessException(__('Another pack already uses barcode :code.', ['code' => $unit->barcode]), 'DUPLICATE', 422);
        }
        $unit->restore();

        return $this->ok($unit, __('Pack restored successfully.'));
    }

    private function validated(Request $request, Product $product, ?ProductUnit $unit = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('product_units', 'name')->where('product_id', $product->id)->whereNull('deleted_at')->ignore($unit?->id)],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'factor' => ['required', 'numeric', 'gt:1', 'max:1000000'],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-\.]+$/',
                Rule::unique('product_units', 'barcode')->ignore($unit?->id),
                Rule::unique('products', 'barcode')],
            'retail_price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'lte:retail_price'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ], [
            'factor.gt' => __('A pack must contain more than one base unit.'),
            'wholesale_price.lte' => __('The wholesale price cannot be higher than the retail price.'),
        ]);

        // A pack priced below its cost would sell at a loss without anyone noticing.
        $minimum = (float) $product->min_selling_price * (float) $data['factor'];
        if ($minimum > 0 && (float) $data['retail_price'] < $minimum) {
            throw new BusinessException(__('The pack price is below the minimum selling price (:min).', ['min' => number_format($minimum, 2)]), 'BELOW_MINIMUM', 422);
        }

        return $data;
    }
}
