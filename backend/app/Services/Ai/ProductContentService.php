<?php

namespace App\Services\Ai;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;

/** Writes online-shop product descriptions in English and Swahili. */
class ProductContentService
{
    public function __construct(private readonly AiClient $ai) {}

    /** @return array{description: string, description_sw: string} */
    public function describe(Product $product, ?string $notes = null): array
    {
        $product->loadMissing(['category:id,name', 'unit:id,name']);
        $facts = array_filter([
            'name' => $product->name,
            'brand' => $product->brand,
            'category' => $product->category?->name,
            'sold_per' => $product->unit?->name,
            'existing_description' => $product->description,
            'staff_notes' => $notes,
        ]);

        $raw = $this->ai->text(
            'product_description',
            'You write short, honest product descriptions for an online shop in Tanzania. '.
            'Use only the facts given; do not invent sizes, ingredients, health claims or prices. '.
            'Two to three sentences each, friendly and clear. '.
            'Return JSON only, exactly: {"en": "...", "sw": "..."} where "sw" is natural Kiswahili (not a word-for-word translation).',
            json_encode($facts, JSON_UNESCAPED_UNICODE),
            fast: true,
            maxTokens: 500,
        );

        $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $raw)), true);
        if (! is_array($json) || empty($json['en']) || empty($json['sw'])) {
            throw new BusinessException(__('The AI reply could not be used. Please try again.'), 'AI_BAD_OUTPUT', 502);
        }

        return [
            'description' => mb_substr(strip_tags((string) $json['en']), 0, 2000),
            'description_sw' => mb_substr(strip_tags((string) $json['sw']), 0, 2000),
        ];
    }
}
