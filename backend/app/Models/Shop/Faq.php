<?php

namespace App\Models\Shop;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A question and answer for the online shop (FAQ page and chat assistant). */
class Faq extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $fillable = ['question', 'answer', 'category', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'views' => 'integer'];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Published questions matching any word of the search (for the assistant). */
    public function scopeMatching(Builder $query, string $text): Builder
    {
        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [])
            ->filter(fn ($w) => mb_strlen($w) >= 3)->unique()->take(8);

        return $query->where(function (Builder $q) use ($words, $text) {
            $q->where('question', 'like', '%'.$text.'%');
            foreach ($words as $word) {
                $q->orWhere('question', 'like', "%{$word}%")->orWhere('answer', 'like', "%{$word}%");
            }
        });
    }
}
