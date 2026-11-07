<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Account;
use App\Services\Finance\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Chart of accounts. */
class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date'], 'branch_id' => ['nullable', 'integer']]);

        $tree = $this->accounts->tree($request->query('as_of'), $this->reportBranch($request), $request->boolean('with_inactive'));
        if ($request->boolean('postable')) {
            $tree = $tree->where('is_header', false)->where('is_active', true)->values();
        }
        if ($request->boolean('money')) {
            $tree = $tree->whereIn('sub_type', Account::MONEY_SUB_TYPES)->where('is_header', false)->values();
        }

        return $this->ok($tree->values());
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->accounts->create($this->validated($request));

        return $this->created($account, __('Account :account created successfully.', ['account' => $account->auditLabel()]));
    }

    public function show(int $id): JsonResponse
    {
        $account = Account::withTrashed()->with(['parent:id,code,name', 'children:id,parent_id,code,name'])->findOrFail($id);

        return $this->ok($account->setAttribute('balance', $this->accounts->balance($account)));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = Account::query()->findOrFail($id);
        $account = $this->accounts->update($account, $this->validated($request, $account));

        return $this->ok($account, __('Account :account updated successfully.', ['account' => $account->auditLabel()]));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->accounts->delete(Account::query()->findOrFail($id));

        return $this->ok(null, __('Account deleted. It can be restored.'));
    }

    public function restore(int $id): JsonResponse
    {
        $account = Account::onlyTrashed()->findOrFail($id);
        $account->restore();

        return $this->ok($account, __('Account restored successfully.'));
    }

    private function validated(Request $request, ?Account $account = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z\-\.]+$/', Rule::unique('accounts', 'code')->ignore($account?->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'sub_type' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'is_header' => ['sometimes', 'boolean'],
            'allow_manual' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:60'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
        ]);
    }

    private function reportBranch(Request $request): ?int
    {
        $id = $request->integer('branch_id') ?: null;

        return $id && $this->branch()->canAccess($id) ? $id : ($this->branch()->canAccessAll() ? null : $this->branch()->id());
    }
}
