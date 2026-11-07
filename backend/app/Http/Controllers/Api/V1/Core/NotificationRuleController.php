<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\NotificationRule;
use App\Rules\EmailList;
use App\Services\Notifications\NotificationRuleRunner;
use App\Services\Notifications\NotificationSources;
use App\Support\DeliveryReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings → Automatic notifications: rules the admin creates. */
class NotificationRuleController extends Controller
{
    public function __construct(private readonly NotificationRuleRunner $runner) {}

    public function index(): JsonResponse
    {
        return $this->ok(NotificationRule::query()->orderBy('name')->get());
    }

    /** What can be reported, its placeholders and the default wording. */
    public function sources(): JsonResponse
    {
        $common = ['business' => __('Business name'), 'name' => __('This notification\'s name'), 'period' => __('The period covered'), 'date' => __('Today\'s date'), 'currency' => __('Currency')];

        // In the reader's language; the stored wording stays whatever the admin types.
        return $this->ok(collect(NotificationSources::catalogue())->map(fn ($s, $key) => [
            'key' => $key,
            'label' => __($s['label']),
            'variables' => array_map(fn ($v) => __($v), $s['variables']) + $common,
            'defaults' => array_map(fn ($v) => __($v), NotificationSources::defaults($key)),
        ])->values());
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok(NotificationRule::findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $rule = new NotificationRule($this->validated($request));
        $rule->next_run_at = $rule->is_active ? $rule->nextRun() : null;
        $rule->save();

        return $this->created($rule, __('Notification :name created. Next: :next.', ['name' => $rule->name, 'next' => $rule->next_run_at?->format('d/m/Y H:i') ?? '-']));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = NotificationRule::findOrFail($id);
        $rule->fill($this->validated($request));
        $rule->next_run_at = $rule->is_active ? $rule->nextRun() : null;
        $rule->save();

        return $this->ok($rule, __('Notification saved. Next: :next.', ['next' => $rule->next_run_at?->format('d/m/Y H:i') ?? '-']));
    }

    public function destroy(int $id): JsonResponse
    {
        NotificationRule::findOrFail($id)->delete();

        return $this->ok(null, __('Notification deleted.'));
    }

    /** Render with today's figures. Works for unsaved rules too (send the form). */
    public function preview(Request $request, ?int $id = null): JsonResponse
    {
        $rule = $id ? NotificationRule::findOrFail($id) : new NotificationRule;
        if ($request->has('source')) {
            $rule->fill($this->validated($request));
        }

        return $this->ok($this->runner->preview($rule));
    }

    public function run(int $id): JsonResponse
    {
        $outcome = $this->runner->run(NotificationRule::findOrFail($id), force: true);

        return $this->ok($outcome, DeliveryReport::message($outcome['result']).' '.__('It is also in the bell.'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'source' => ['required', Rule::in(array_keys(NotificationSources::catalogue()))],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'channels' => ['present', 'array'],
            'channels.*' => ['string', 'in:sms,whatsapp,email'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('is_developer', 0)->whereNull('deleted_at')],
            'send_to_role_users' => ['boolean'],
            'include_company_contacts' => ['boolean'],
            'extra_phones' => ['nullable', 'string', 'max:2000', 'regex:/^[0-9,;\s+]*$/'],
            'extra_whatsapp' => ['nullable', 'string', 'max:2000', 'regex:/^[0-9,;\s+]*$/'],
            'extra_emails' => ['nullable', 'string', 'max:2000', new EmailList],
            'frequency' => ['required', Rule::in(NotificationRule::FREQUENCIES)],
            'time' => ['required', 'date_format:H:i'],
            'weekday' => ['nullable', 'required_if:frequency,weekly', 'integer', 'between:0,6'],
            'month_day' => ['nullable', 'required_if:frequency,monthly', 'integer', 'between:0,28'],
            'run_at' => ['nullable', 'required_if:frequency,once', 'date', 'after:now'],
            'period' => ['required', Rule::in(NotificationRule::PERIODS)],
            'skip_when_empty' => ['boolean'],
            'sms_template' => ['nullable', 'string', 'max:640'],
            'whatsapp_template' => ['nullable', 'string', 'max:4000'],
            'email_subject' => ['nullable', 'string', 'max:200'],
            'email_template' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['boolean'],
        ]);

        return $data;
    }
}
