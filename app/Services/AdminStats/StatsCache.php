<?php

namespace App\Services\AdminStats;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Thin caching wrapper shared by the five admin stat services.
 *
 * Store selection is config-driven (cache.admin_stats_store): null falls back
 * to the app's default store — which is `array` under phpunit, so tests never
 * touch Redis — while production sets ADMIN_STATS_CACHE_STORE=redis-stats to
 * land on the LiveKit Redis server under its own DB index (REDIS_STATS_DB=2)
 * with the `admin_stats:` key prefix below on top of the app-wide prefix.
 *
 * TTL default 600s (within the spec's 5–15 min window); there is deliberately
 * no invalidation — stale-by-a-few-minutes is acceptable for admin dashboards.
 */
class StatsCache
{
    public function remember(string $stat, string $paramsKey, Closure $compute): mixed
    {
        $repo = Cache::store(config('cache.admin_stats_store'));
        $ttl = (int) config('cache.admin_stats_ttl', 600);

        return $repo->remember("admin_stats:{$stat}:{$paramsKey}", $ttl, $compute);
    }
}
