<?php

namespace App\Http\Middleware;

use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which branch a staff request acts on.
 *  - Users limited to one branch always work in that branch.
 *  - All-branch users pick a branch with the X-Branch-Id header, or "all" to view every branch.
 */
class ResolveBranch
{
    public function __construct(private readonly BranchContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isStaff()) {
            return $next($request);
        }

        $accessible = $user->accessibleBranchIds();
        $allBranches = $user->canAccessAllBranches();
        $header = $request->header('X-Branch-Id');

        if ($allBranches) {
            if ($header === null || $header === '' || $header === 'all') {
                $branchId = $header === 'all' ? null : ($user->branch_id ? (int) $user->branch_id : null);
            } elseif (ctype_digit((string) $header) && in_array((int) $header, $accessible, true)) {
                $branchId = (int) $header;
            } else {
                return response()->json(['success' => false, 'message' => __('The selected branch does not exist or is inactive.')], 422);
            }
        } else {
            if (! $user->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => __('Your account is not assigned to a branch. Contact the administrator.'),
                    'code' => 'NO_BRANCH',
                ], 403);
            }
            if ($header !== null && $header !== '' && $header !== 'all' && (int) $header !== (int) $user->branch_id) {
                return response()->json(['success' => false, 'message' => __('You can only work in your assigned branch.')], 403);
            }
            $branchId = (int) $user->branch_id;
        }

        $this->context->set($branchId, $allBranches, $accessible);

        return $next($request);
    }
}
