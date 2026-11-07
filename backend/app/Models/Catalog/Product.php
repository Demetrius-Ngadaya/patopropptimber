<?php

namespace App\Models\Catalog;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Inventory\ProductStock;
use App\Models\Inventory\StockBatch;
use App\Models\Sales\SaleItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    use Auditable, HasFactory, HasUserstamps, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'sku', 'barcode', 'category_id', 'unit_id', 'brand', 'description', 'description_sw', 'image_path',
        'cost_price', 'selling_price', 'wholesale_price', 'wholesale_min_qty', 'min_selling_price', 'tax_rate', 'reorder_level',
        'track_expiry', 'expiry_alert_days', 'allow_decimal', 'is_service', 'show_online', 'is_active',
    ];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'wholesale_min_qty' => 'decimal:3',
            'min_selling_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'reorder_level' => 'decimal:3',
            'track_expiry' => 'boolean',
            'allow_decimal' => 'boolean',
            'is_service' => 'boolean',
            'show_online' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Every product gets an unguessable token for its QR code link.
        static::creating(function (Product $product) {
            $product->qr_token ??= static::newQrToken();
        });
    }

    public static function newQrToken(): string
    {
        do {
            $token = Str::lower(Str::random(16));
        } while (static::withTrashed()->where('qr_token', $token)->exists());

        return $token;
    }

    /** The address a QR code opens: the product page on the website. */
    public function qrUrl(): string
    {
        return rtrim((string) config('mauzo.frontend_url'), '/').'/q/'.$this->qr_token;
    }

    /** Extract a QR token from a scanned value (full link or bare token). */
    public static function tokenFromScan(string $code): ?string
    {
        if (preg_match('~/q/([a-z0-9]{8,24})(?:[/?#]|$)~i', $code, $m)) {
            return strtolower($m[1]);
        }

        return preg_match('/^[a-z0-9]{16}$/', $code) ? $code : null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /** Packs / alternative units (carton, dozen ...). */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('sort_order')->orderBy('factor');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    /** Stock row for one branch; use with ->where('branch_id', ..) or the scope below. */
    public function branchStock(): HasOne
    {
        return $this->hasOne(ProductStock::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', $term)
                ->orWhere('brand', 'like', "%{$term}%");
        });
    }

    /** Adds `stock_quantity` for the given branch (or all branches when null). */
    public function scopeWithStock(Builder $query, ?int $branchId, ?array $branchIds = null): Builder
    {
        return $query->withSum(['stocks as stock_quantity' => function (Builder $q) use ($branchId, $branchIds) {
            if ($branchId) {
                $q->where('branch_id', $branchId);
            } elseif ($branchIds !== null) {
                $q->whereIn('branch_id', $branchIds ?: [0]);
            }
        }], 'quantity');
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
