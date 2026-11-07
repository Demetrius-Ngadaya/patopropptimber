<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\Unit;
use App\Services\Core\AuditLogger;
use App\Services\Core\SettingsService;
use App\Services\Finance\PostingService;
use App\Services\Inventory\StockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class ProductService
{
    public const IMPORT_COLUMNS = [
        'name', 'sku', 'barcode', 'category', 'unit', 'brand', 'cost_price', 'selling_price',
        'min_selling_price', 'reorder_level', 'track_expiry', 'opening_quantity', 'expiry_date', 'batch_no', 'show_online',
    ];

    public function __construct(
        private readonly StockService $stock,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly PostingService $posting,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated product fields + optional opening_* fields
     */
    public function create(array $data, ?int $branchId, ?UploadedFile $image = null): Product
    {
        return DB::transaction(function () use ($data, $branchId, $image) {
            $data['slug'] = $this->uniqueSlug($data['name']);
            if (! empty($data['unit_id']) && ! array_key_exists('allow_decimal', $data)) {
                $data['allow_decimal'] = (bool) Unit::query()->whereKey($data['unit_id'])->value('allow_decimal');
            }
            if ($image) {
                $data['image_path'] = $image->store('products', 'public');
            }

            $product = Product::create(collect($data)->only((new Product)->getFillable())->all());

            $fill = [];
            if (empty($product->barcode) && $this->settings->bool('auto_generate_barcode')) {
                $fill['barcode'] = $this->generateBarcode($product->id);
            }
            if (empty($product->sku)) {
                $fill['sku'] = 'SKU'.str_pad((string) $product->id, 6, '0', STR_PAD_LEFT);
            }
            if ($fill) {
                $product->forceFill($fill)->saveQuietly();
            }

            $openingQty = (float) ($data['opening_quantity'] ?? 0);
            if ($openingQty > 0) {
                if (! $branchId) {
                    throw new BusinessException(__('Select a branch to record opening stock.'));
                }
                $this->stock->assertQuantity($product, $openingQty);
                $this->posting->openingStock($product, $branchId, round($openingQty * (float) $product->cost_price, 2));
                $this->stock->increase($product, $branchId, $openingQty, (float) $product->cost_price, 'opening', $product, [
                    'expiry_date' => $data['opening_expiry_date'] ?? null,
                    'batch_no' => $data['opening_batch_no'] ?? null,
                    'note' => 'Opening stock',
                ]);
            }

            return $product->fresh(['category', 'unit']);
        });
    }

    public function update(Product $product, array $data, string $reason, ?UploadedFile $image = null, bool $removeImage = false): Product
    {
        if (isset($data['name']) && $data['name'] !== $product->name) {
            $data['slug'] = $this->uniqueSlug($data['name'], $product->id);
        }
        if ($image) {
            $old = $product->image_path;
            $data['image_path'] = $image->store('products', 'public');
            if ($old) {
                Storage::disk('public')->delete($old);
            }
        } elseif ($removeImage && $product->image_path) {
            Storage::disk('public')->delete($product->image_path);
            $data['image_path'] = null;
        }

        $product->fill(collect($data)->only($product->getFillable())->all());
        $product->auditReason = $reason;
        $product->save();

        return $product->fresh(['category', 'unit']);
    }

    /** EAN-13 starting with "20" (in-store range, never clashes with manufacturer codes). */
    public function generateBarcode(int $seed): string
    {
        $attempt = 0;
        do {
            $body = '20'.str_pad((string) ($seed + $attempt * 7919), 10, '0', STR_PAD_LEFT);
            $body = substr($body, 0, 12);
            $code = $body.$this->ean13CheckDigit($body);
            $attempt++;
        } while (Product::withTrashed()->where('barcode', $code)->exists() && $attempt < 50);

        return $code;
    }

    public function ean13CheckDigit(string $twelveDigits): int
    {
        $sum = 0;
        foreach (str_split($twelveDigits) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $i = 2;
        while (Product::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * Import products from an Excel / CSV file. Each row is validated on its own;
     * valid rows are saved, invalid rows are reported back with the reason.
     *
     * @return array{created: int, updated: int, failed: array<int, array{row: int, errors: array<string>}>}
     */
    public function import(UploadedFile $file, ?int $branchId): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (Throwable $e) {
            throw new BusinessException(__('The file could not be read. Use the template (.xlsx or .csv).'));
        }

        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        if (count($rows) < 2) {
            throw new BusinessException(__('The file has no product rows.'));
        }

        $header = array_map(fn ($h) => Str::snake(trim((string) $h)), array_shift($rows));
        if (! in_array('name', $header, true) || ! in_array('selling_price', $header, true)) {
            throw new BusinessException(__('The first row must contain the column titles from the template (name, selling_price ...).'));
        }
        if (count($rows) > 5000) {
            throw new BusinessException(__('Import at most 5,000 products at a time.'));
        }

        $result = ['created' => 0, 'updated' => 0, 'failed' => []];
        $categories = Category::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id])->all();
        $units = Unit::query()->get(['id', 'name', 'short_name']);

        foreach ($rows as $index => $values) {
            $rowNumber = $index + 2;
            if (count(array_filter($values, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $row = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), null));
            $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);

            if (! empty($row['expiry_date']) && is_numeric($row['expiry_date'])) {
                $row['expiry_date'] = ExcelDate::excelToDateTimeObject((float) $row['expiry_date'])->format('Y-m-d');
            }
            foreach (['track_expiry', 'show_online'] as $flag) {
                $row[$flag] = in_array(strtolower((string) ($row[$flag] ?? '')), ['1', 'yes', 'ndiyo', 'true', 'y'], true);
            }

            $validator = Validator::make($row, [
                'name' => ['required', 'string', 'max:255'],
                'sku' => ['nullable', 'string', 'max:60'],
                'barcode' => ['nullable', 'string', 'max:64'],
                'cost_price' => ['nullable', 'numeric', 'min:0'],
                'selling_price' => ['required', 'numeric', 'min:0'],
                'min_selling_price' => ['nullable', 'numeric', 'min:0'],
                'reorder_level' => ['nullable', 'numeric', 'min:0'],
                'opening_quantity' => ['nullable', 'numeric', 'min:0'],
                'expiry_date' => ['nullable', 'date'],
            ]);
            if ($validator->fails()) {
                $result['failed'][] = ['row' => $rowNumber, 'errors' => $validator->errors()->all()];

                continue;
            }

            try {
                // Categories are created outside the row transaction so a failed row never leaves a dangling id.
                $categoryId = null;
                if (! empty($row['category'])) {
                    $key = mb_strtolower($row['category']);
                    $categoryId = $categories[$key] ??= Category::create([
                        'name' => $row['category'],
                        'slug' => $this->uniqueCategorySlug($row['category']),
                    ])->id;
                }

                DB::transaction(function () use ($row, $categoryId, $units, $branchId, &$result) {
                    $unit = ! empty($row['unit'])
                        ? $units->first(fn ($u) => strcasecmp($u->short_name, $row['unit']) === 0 || strcasecmp($u->name, $row['unit']) === 0)
                        : null;

                    $fields = array_filter([
                        'name' => $row['name'],
                        'sku' => $row['sku'] ?: null,
                        'barcode' => $row['barcode'] ? (string) $row['barcode'] : null,
                        'category_id' => $categoryId,
                        'unit_id' => $unit?->id,
                        'allow_decimal' => $unit?->allow_decimal ?? false,
                        'brand' => $row['brand'] ?? null,
                        'cost_price' => (float) ($row['cost_price'] ?? 0),
                        'selling_price' => (float) $row['selling_price'],
                        'min_selling_price' => isset($row['min_selling_price']) && $row['min_selling_price'] !== '' ? (float) $row['min_selling_price'] : null,
                        'reorder_level' => (float) ($row['reorder_level'] ?? 0),
                        'track_expiry' => $row['track_expiry'],
                        'show_online' => $row['show_online'],
                    ], fn ($v) => $v !== null);

                    $existing = null;
                    if (! empty($fields['sku'])) {
                        $existing = Product::query()->where('sku', $fields['sku'])->first();
                    }
                    if (! $existing && ! empty($fields['barcode'])) {
                        $existing = Product::query()->where('barcode', $fields['barcode'])->first();
                    }

                    if ($existing) {
                        $this->update($existing, $fields, 'Excel import');
                        $result['updated']++;
                    } else {
                        $this->create($fields + [
                            'opening_quantity' => $row['opening_quantity'] ?? 0,
                            'opening_expiry_date' => $row['expiry_date'] ?? null,
                            'opening_batch_no' => $row['batch_no'] ?? null,
                        ], $branchId);
                        $result['created']++;
                    }
                });
            } catch (BusinessException $e) {
                $result['failed'][] = ['row' => $rowNumber, 'errors' => [$e->getMessage()]];
            } catch (Throwable $e) {
                $result['failed'][] = ['row' => $rowNumber, 'errors' => ['Could not save this row (duplicate SKU or barcode?).']];
            }
        }

        $this->audit->event('products.imported', "Imported products: {$result['created']} created, {$result['updated']} updated, ".count($result['failed']).' failed', null, [
            'file' => $file->getClientOriginalName(),
        ]);

        return $result;
    }

    private function uniqueCategorySlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 2;
        while (Category::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
