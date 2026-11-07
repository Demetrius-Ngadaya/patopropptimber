<?php

namespace App\Services\Site;

use App\Models\Core\User;
use App\Models\Site\SiteVisitor;
use App\Models\Site\SiteVisitorDay;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Counts website visitors. The browser sends a beacon on every page it opens
 * (a page view) and every minute while the page is open (still here). The
 * location comes from the hosting edge (Vercel or Cloudflare headers passed on
 * by the frontend), so no outside service is called.
 */
class VisitorTracker
{
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|preview|headless|lighthouse|pingdom|monitor|curl|wget|python|httpclient/i';

    public function record(Request $request, string $path, ?string $referrer, bool $pageView): ?SiteVisitor
    {
        $agent = (string) $request->userAgent();
        if ($agent === '' || preg_match(self::BOTS, $agent)) {
            return null;
        }
        $key = (string) $request->header('X-Visitor-Id');
        if (! preg_match('/^[A-Za-z0-9-]{16,64}$/', $key)) {
            $key = 'h-'.substr(sha1($request->ip().'|'.$agent), 0, 40);
        }
        $path = $this->cleanPath($path);
        $now = now();
        $user = Auth::guard('sanctum')->user();

        $visitor = SiteVisitor::query()->where('visitor_key', $key)->first();
        if (! $visitor) {
            try {
                $visitor = SiteVisitor::query()->create([
                    'visitor_key' => $key, 'first_seen_at' => $now, 'last_seen_at' => $now, 'visits' => 1, 'page_views' => 0,
                    'landing_path' => $path, 'current_path' => $path, 'referrer' => $this->cleanReferrer($referrer, $request),
                ] + $this->details($request, $agent));
            } catch (QueryException) {
                $visitor = SiteVisitor::query()->where('visitor_key', $key)->firstOrFail(); // the same browser, twice at once
            }
        } else {
            $newVisit = $visitor->last_seen_at->lt($now->copy()->subMinutes(SiteVisitor::SESSION_MINUTES));
            $visitor->fill(array_filter([
                'last_seen_at' => $now,
                'current_path' => $pageView ? $path : $visitor->current_path,
                'visits' => $newVisit && $pageView ? $visitor->visits + 1 : null,
                'landing_path' => $newVisit && $pageView ? $path : null,
                'referrer' => $newVisit && $pageView ? $this->cleanReferrer($referrer, $request) : null,
            ], fn ($v) => $v !== null) + $this->details($request, $agent));
        }
        if ($user instanceof User) {
            $visitor->user_id = $user->id;
            $visitor->kind = $user->type === User::TYPE_CUSTOMER ? 'customer' : 'staff';
        }
        if ($pageView) {
            $visitor->page_views++;
        }
        $visitor->save();

        $this->touchDay($visitor, $now, $pageView);
        if ($pageView && $path) {
            $this->countPage($now->toDateString(), $path);
        }

        return $visitor;
    }

    private function touchDay(SiteVisitor $visitor, $now, bool $pageView): void
    {
        $day = SiteVisitorDay::query()->firstOrNew(['site_visitor_id' => $visitor->id, 'day' => $now->toDateString()]);
        $day->first_at ??= $now;
        $day->last_at = $now;
        $day->page_views = (int) $day->page_views + ($pageView ? 1 : 0);
        try {
            $day->save();
        } catch (QueryException) {
            // Two beacons in the same instant: the other one created today's row.
        }
    }

    private function countPage(string $day, string $path): void
    {
        $updated = DB::table('site_page_stats')->where('day', $day)->where('path', $path)->increment('views');
        if (! $updated) {
            try {
                DB::table('site_page_stats')->insert(['day' => $day, 'path' => $path, 'views' => 1]);
            } catch (QueryException) {
                DB::table('site_page_stats')->where('day', $day)->where('path', $path)->increment('views');
            }
        }
    }

    /** Location (from the hosting edge), device, browser, system and language. Empty values are left out. */
    private function details(Request $request, string $agent): array
    {
        $header = fn (string $name) => ($v = trim(urldecode((string) $request->header($name)))) !== '' ? Str::limit($v, 80, '') : null;
        $country = strtoupper((string) ($header('X-Vercel-IP-Country') ?? $header('CF-IPCountry') ?? ''));
        $lat = $header('X-Vercel-IP-Latitude');
        $lng = $header('X-Vercel-IP-Longitude');

        return array_filter([
            'ip' => $request->ip(),
            'country' => preg_match('/^[A-Z]{2}$/', $country) && $country !== 'XX' ? $country : null,
            'region' => $header('X-Vercel-IP-Country-Region'),
            'city' => $header('X-Vercel-IP-City'),
            'latitude' => is_numeric($lat) ? (float) $lat : null,
            'longitude' => is_numeric($lng) ? (float) $lng : null,
            'timezone' => $header('X-Vercel-IP-Timezone'),
            'device' => $this->device($agent),
            'browser' => $this->browser($agent),
            'os' => $this->os($agent),
            'language' => substr((string) $request->getPreferredLanguage(), 0, 10) ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function device(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/ipad|tablet|kindle|silk|(android(?!.*mobile))/i', $ua) => 'tablet',
            (bool) preg_match('/mobi|iphone|ipod|android|opera mini|iemobile/i', $ua) => 'mobile',
            default => 'desktop',
        };
    }

    private function browser(string $ua): string
    {
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'UCBrowser' => 'UC Browser', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'CriOS' => 'Chrome', 'Safari/' => 'Safari'] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }

        return 'Other';
    }

    private function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/android/i', $ua) => 'Android',
            (bool) preg_match('/iphone|ipad|ipod/i', $ua) => 'iOS',
            (bool) preg_match('/windows/i', $ua) => 'Windows',
            (bool) preg_match('/mac os x|macintosh/i', $ua) => 'macOS',
            (bool) preg_match('/cros/i', $ua) => 'ChromeOS',
            (bool) preg_match('/linux/i', $ua) => 'Linux',
            default => 'Other',
        };
    }

    /** "/products/sukari?x=1#a" → "/products/sukari"; private link tokens are shortened. */
    private function cleanPath(string $path): string
    {
        $path = '/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        $path = preg_replace('#^/(d|q|t|receipt)/[^/]+#', '/$1/…', $path) ?? $path;

        return Str::limit($path, 180, '');
    }

    /** Only other websites count as referrers (not our own pages). */
    private function cleanReferrer(?string $referrer, Request $request): ?string
    {
        $host = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;
        if (! $host) {
            return null;
        }
        $own = array_filter([parse_url((string) config('mauzo.frontend_url'), PHP_URL_HOST), $request->getHost()]);

        return in_array($host, $own, true) ? null : Str::limit($host, 120, '');
    }
}
