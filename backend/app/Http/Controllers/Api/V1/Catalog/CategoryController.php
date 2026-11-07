<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Concerns\SimpleCrud;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    use SimpleCrud;

    protected string $model = Category::class;

    protected string $label = 'Category';

    protected array $searchable = ['name'];

    protected array $with = ['parent:id,name'];

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('categories', 'name')->ignore($record?->id)->whereNull('deleted_at')],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at'), Rule::notIn([$record?->id])],
            'description' => ['nullable', 'string', 'max:500'],
            'show_online' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepare(array $data, ?Model $record): array
    {
        if (! $record || $record->name !== $data['name']) {
            $base = Str::slug($data['name']) ?: 'category';
            $slug = $base;
            $i = 2;
            while (Category::withTrashed()->where('slug', $slug)->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                $slug = $base.'-'.$i++;
            }
            $data['slug'] = $slug;
        }

        return $data;
    }
}
