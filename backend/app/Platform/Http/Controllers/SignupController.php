<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Models\Signup;
use App\Platform\Support\SignupService;
use App\Platform\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Online registration of a new business on patopro.shezoni.com. */
class SignupController extends Controller
{
    public function __construct(private readonly SignupService $signups) {}

    /** POST platform/signup/check-address {slug} — is patopro.shezoni.com/<slug> free? */
    public function checkAddress(Request $request): JsonResponse
    {
        $slug = Slug::normalise((string) $request->input('slug', ''));
        $problem = Slug::problem($slug);

        return response()->json(['success' => true, 'data' => [
            'slug' => $slug,
            'available' => $problem === null,
            'message' => $problem,
            'url' => config('patopro.platform.url').'/'.$slug,
            'suggestions' => $problem ? $this->suggestions($slug) : [],
        ]]);
    }

    /** POST platform/signup — details in, SMS code out. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'max:40'],
            'owner_name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:160'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
            'plan' => ['required', 'string', 'max:40'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly'],
            'accept_terms' => ['accepted'],
        ]);

        ['signup' => $signup, 'debug_code' => $code] = $this->signups->start($data, $request->ip());

        return response()->json(['success' => true, 'message' => __('We sent a code by SMS to :phone.', ['phone' => $signup->maskedPhone()]), 'data' => $this->signups->status($signup) + array_filter(['debug_code' => $code])], 201);
    }

    /** POST platform/signup/{signup}/resend */
    public function resend(Signup $signup): JsonResponse
    {
        ['debug_code' => $code] = $this->signups->resend($signup);

        return response()->json(['success' => true, 'message' => __('A new code is on its way.'), 'data' => $this->signups->status($signup->refresh()) + array_filter(['debug_code' => $code])]);
    }

    /** POST platform/signup/{signup}/verify {code} */
    public function verify(Request $request, Signup $signup): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:10']]);
        $business = $this->signups->verify($signup, (string) $request->input('code'));

        return response()->json([
            'success' => true,
            'message' => $business->status === 'ready' ? __('Your business is ready.') : __('Phone confirmed. We are preparing your business; this takes a moment.'),
            'data' => $this->signups->status($signup->refresh()),
        ]);
    }

    /** GET platform/signup/{signup} — the page checks this while the business is being prepared. */
    public function show(Signup $signup): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->signups->status($signup)]);
    }

    /** @return array<int, string> */
    private function suggestions(string $slug): array
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '', '-') ?: 'duka';
        $base = substr($base, 0, 24);
        $candidates = [$base, "$base-tz", "$base-shop", "$base-".date('y'), "duka-$base", $base.'-'.random_int(10, 99)];

        return collect($candidates)->unique()->filter(fn ($s) => Slug::problem($s) === null)->take(3)->values()->all();
    }
}
