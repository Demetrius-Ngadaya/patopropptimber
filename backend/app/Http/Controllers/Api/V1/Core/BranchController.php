<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\SimpleCrud;
use App\Http\Controllers\Controller;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Inventory\ProductStock;
use App\Models\Inventory\Transfer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    use SimpleCrud;

    protected string $model = Branch::class;

    protected string $label = 'Branch';

    protected array $searchable = ['name', 'code', 'location'];

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('branches', 'code')->ignore($record?->id)],
            'location' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'is_main' => ['boolean'],
            'is_active' => ['boolean'],
            'accepts_online_orders' => ['boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    protected function prepare(array $data, ?Model $record): array
    {
        $data['code'] = strtoupper($data['code']);
        if (! empty($data['is_main'])) {
            Branch::query()->where('is_main', true)->when($record, fn ($q) => $q->whereKeyNot($record->id))->update(['is_main' => false]);
        }

        return $data;
    }

    protected function beforeDelete(Model $record): void
    {
        if ($record->is_main) {
            throw new BusinessException(__('The main branch cannot be deleted. Make another branch the main branch first.'));
        }
        if (User::query()->where('branch_id', $record->id)->where('is_active', true)->exists()) {
            throw new BusinessException(__('Move or deactivate the users of this branch before deleting it.'));
        }
        if (ProductStock::query()->where('branch_id', $record->id)->where('quantity', '>', 0)->exists()) {
            throw new BusinessException(__('This branch still has stock. Transfer it to another branch first.'));
        }
        if (Transfer::query()->where('status', 'in_transit')->where(fn ($q) => $q->where('from_branch_id', $record->id)->orWhere('to_branch_id', $record->id))->exists()) {
            throw new BusinessException(__('This branch has transfers in transit.'));
        }
    }
}
