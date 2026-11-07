<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\LoginSlide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Pictures that slide beside the login form. */
class LoginSlideController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $slides = $this->applyTrashed(LoginSlide::query(), $request)
            ->with('creator')
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return $this->ok($slides);
    }

    /** Public list for the login page (only active pictures, no internal fields). */
    public function public(): JsonResponse
    {
        return $this->ok(LoginSlide::query()->visible()->get(['id', 'title', 'caption', 'image_path'])
            ->map(fn (LoginSlide $s) => $s->only(['id', 'title', 'caption', 'image_url'])));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $data['image_path'] = $request->file('image')->store('login-slides', 'public');
        $data['sort_order'] ??= (int) LoginSlide::query()->max('sort_order') + 1;

        $slide = LoginSlide::create($data);

        return $this->created($slide, __('Picture added to the login page.'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $slide = LoginSlide::query()->findOrFail($id);
        $data = $this->validated($request, false);
        if ($request->hasFile('image')) {
            // The old file is kept: the change can still be traced in the audit log.
            $data['image_path'] = $request->file('image')->store('login-slides', 'public');
        }
        $slide->fill($data)->save();

        return $this->ok($slide->fresh(), __('Picture updated.'));
    }

    public function destroy(int $id): JsonResponse
    {
        LoginSlide::query()->findOrFail($id)->delete();

        return $this->ok(null, __('Picture removed from the login page. You can restore it later.'));
    }

    public function restore(int $id): JsonResponse
    {
        $slide = LoginSlide::onlyTrashed()->findOrFail($id);
        $slide->restore();

        return $this->ok($slide, __('Picture restored.'));
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', Rule::exists('login_slides', 'id')],
        ])['ids'];

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $position => $id) {
                LoginSlide::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return $this->ok(null, __('Order saved.'));
    }

    private function validated(Request $request, bool $creating): array
    {
        foreach (['is_active'] as $flag) {
            if ($request->has($flag)) {
                $request->merge([$flag => filter_var($request->input($flag), FILTER_VALIDATE_BOOLEAN)]);
            }
        }

        return collect($request->validate([
            'image' => [$creating ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=600,min_height=400,max_width=6000,max_height=6000'],
            'title' => ['nullable', 'string', 'max:120'],
            'caption' => ['nullable', 'string', 'max:300'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['boolean'],
        ], [
            'image.dimensions' => __('Use a picture at least 600 × 400 pixels (landscape works best).'),
        ]))->except('image')->all();
    }
}
