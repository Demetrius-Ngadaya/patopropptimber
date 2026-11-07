<?php

namespace App\Support;

use App\Exceptions\BusinessException;

/**
 * The branch the current request works in. Resolved once per request by the
 * ResolveBranch middleware from the user's assignment and the X-Branch-Id header.
 */
class BranchContext
{
    private ?int $branchId = null;

    private bool $allBranches = false;

    /** @var array<int> */
    private array $accessibleIds = [];

    public function set(?int $branchId, bool $allBranches, array $accessibleIds): void
    {
        $this->branchId = $branchId;
        $this->allBranches = $allBranches;
        $this->accessibleIds = array_map('intval', $accessibleIds);
    }

    /** Selected branch, or null when an all-branch user is viewing every branch. */
    public function id(): ?int
    {
        return $this->branchId;
    }

    /** Branch id for write operations (sales, purchases ...). A branch must be selected. */
    public function requireId(): int
    {
        if ($this->branchId === null) {
            throw new BusinessException(__('Please select a branch before performing this action.'), 'BRANCH_REQUIRED');
        }

        return $this->branchId;
    }

    public function viewingAll(): bool
    {
        return $this->branchId === null && $this->allBranches;
    }

    public function canAccessAll(): bool
    {
        return $this->allBranches;
    }

    /** @return array<int> */
    public function accessibleIds(): array
    {
        return $this->accessibleIds;
    }

    public function canAccess(?int $branchId): bool
    {
        return $branchId !== null && ($this->allBranches || in_array($branchId, $this->accessibleIds, true));
    }

    /**
     * Restrict a query to the current branch (or nothing extra when viewing all).
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    public function scope($query, string $column = 'branch_id')
    {
        if ($this->branchId !== null) {
            $query->where($column, $this->branchId);
        } elseif (! $this->allBranches) {
            $query->whereIn($column, $this->accessibleIds ?: [0]);
        }

        return $query;
    }
}
