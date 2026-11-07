<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Services\Core\AuditLogger;
use App\Services\Core\SettingsService;
use App\Support\Modules;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Software Engineer: choose which modules this installation shows. */
class SystemModuleController extends Controller
{
    public function __construct(private readonly SettingsService $settings, private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $off = Modules::disabled();
        $catalog = PermissionCatalog::modules();

        return $this->ok([
            'modules' => collect($catalog)->map(fn ($m, $key) => [
                'key' => $key,
                'label' => $m['label'],
                'always_on' => in_array($key, Modules::ALWAYS_ON, true),
                'optional' => in_array($key, Modules::OPTIONAL, true),
                'enabled' => ! in_array($key, $off, true),
                'permissions' => count($m['permissions']),
            ])->values(),
            'disabled' => $off,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'disabled' => ['present', 'array'],
            'disabled.*' => ['string', 'distinct', Rule::in(array_keys(Modules::toggleable()))],
        ]);
        $before = Modules::disabled();
        $optional = Modules::OPTIONAL;
        $this->settings->update([
            'disabled_modules' => array_values(array_diff($data['disabled'], $optional)),
            'optional_modules' => array_values(array_diff($optional, $data['disabled'])),
        ]);

        $this->audit->event('system.modules_changed', 'System modules changed', null, [
            'switched_off' => array_values(array_diff($data['disabled'], $before)),
            'switched_on' => array_values(array_diff($before, $data['disabled'])),
        ], 'security');

        return $this->ok(['disabled' => Modules::disabled()], __('System modules updated. Users see the change on their next page load.'));
    }
}
