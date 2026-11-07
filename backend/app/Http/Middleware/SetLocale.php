<?php

namespace App\Http\Middleware;

use App\Services\Core\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Messages are returned in English or Swahili. Order of preference:
 * X-Locale header (the app's language switch) -> the user's saved language
 * -> Accept-Language -> the business default.
 */
class SetLocale
{
    public const SUPPORTED = ['en', 'sw'];

    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->pick($request->header('X-Locale'))
            ?? $this->pick($request->user()?->locale)
            ?? $this->pick(substr((string) $request->getPreferredLanguage(self::SUPPORTED), 0, 2))
            ?? $this->pick($this->settings->get('default_locale'))
            ?? 'en';

        app()->setLocale($locale);
        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function pick(?string $value): ?string
    {
        $value = strtolower(substr((string) $value, 0, 2));

        return in_array($value, self::SUPPORTED, true) ? $value : null;
    }
}
