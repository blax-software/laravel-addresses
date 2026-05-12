<?php

namespace Blax\Addresses\Services\Geocoding;

use Blax\Addresses\Models\Address;
use Blax\Addresses\Services\Geocoding\Contracts\Geocoder;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;

/**
 * Geocoder backed by Nominatim (OpenStreetMap).
 *
 * Concurrency / rate limit
 * ────────────────────────
 * Nominatim's usage policy caps the public server at **one request per
 * second**, globally. To enforce that even across multiple workers we:
 *
 *   1. Acquire a `Cache::lock` so only one process ever talks upstream
 *      at any moment — this serializes the calls cluster-wide as long
 *      as everyone shares a cache store that supports locking (redis,
 *      memcached, database, file, …).
 *
 *   2. Inside the lock, compare `microtime(true)` against a "last call
 *      finished at" timestamp stored in the same cache store, sleeping
 *      the difference if the gap to the previous call is shorter than
 *      the configured minimum.
 *
 *   3. Record the new "last call finished at" right after the response
 *      comes back, before releasing the lock — so the next caller pays
 *      the rate-limit cost based on when we actually stopped talking
 *      upstream, not when the lock was first taken.
 *
 * The lock has a TTL so a hard crash (kill -9, OOM) can't pin it open
 * forever; default 15 s comfortably exceeds the 8 s HTTP timeout + 1 s
 * floor.
 *
 * @see https://operations.osmfoundation.org/policies/nominatim/
 */
class NominatimGeocoder implements Geocoder
{
    /**
     * @param  HttpFactory  $http  Laravel HTTP client — fakeable via Http::fake().
     * @param  CacheFactory  $cache  Cache factory — drives the lock + "last call" stamp.
     * @param  array  $config  Resolved `addresses.geocoding` config block.
     */
    public function __construct(
        protected HttpFactory $http,
        protected CacheFactory $cache,
        protected array $config,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function geocode(Address $address): ?GeocodingResult
    {
        $params = $this->buildQueryParams($address);
        if ($params === null) {
            // Nothing meaningful to ask about — skip the network round trip.
            return null;
        }

        $store = $this->cacheStore();
        $lockKey = $this->config['cache_prefix'].':lock';
        $lockTtl = (int) ($this->config['lock_ttl_seconds'] ?? 15);
        $lockWait = (int) ($this->config['lock_wait_seconds'] ?? 10);

        $lock = $store->lock($lockKey, $lockTtl);

        try {
            // block() returns true on success, throws LockTimeoutException
            // when the wait runs out. We propagate that so the caller can
            // decide whether to retry / queue / silently swallow.
            $lock->block($lockWait);
        } catch (LockTimeoutException $e) {
            throw $e;
        }

        try {
            $this->respectMinInterval();
            $result = $this->callUpstream($params);
            // Stamp the moment the upstream call finished — pacing the
            // *gap to the next call* by when we actually stopped talking.
            $store->put(
                $this->config['cache_prefix'].':last_call_at',
                microtime(true),
                300,
            );

            return $result;
        } finally {
            $lock->release();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Rate-limit primitive
    |--------------------------------------------------------------------------
    */

    /**
     * Sleep until the configured minimum interval has elapsed since the
     * previously recorded upstream call. Reads the stamp from the shared
     * cache so the throttle is cluster-wide, not per-process.
     */
    protected function respectMinInterval(): void
    {
        $min = (float) ($this->config['min_interval_seconds'] ?? 1.0);
        if ($min <= 0) {
            return;
        }

        $lastCallAt = (float) $this->cacheStore()->get(
            $this->config['cache_prefix'].':last_call_at',
            0,
        );
        if ($lastCallAt <= 0) {
            return;
        }

        $waitFor = ($lastCallAt + $min) - microtime(true);
        if ($waitFor > 0) {
            // usleep takes integer microseconds — `ceil` so we never
            // under-sleep into a rate-limit violation.
            usleep((int) ceil($waitFor * 1_000_000));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Upstream call
    |--------------------------------------------------------------------------
    */

    /**
     * Issue the actual HTTP request and translate the response into a
     * GeocodingResult. Returns null when the provider returned no match.
     *
     * @throws RequestException when the upstream returns an HTTP error.
     */
    protected function callUpstream(array $params): ?GeocodingResult
    {
        $driver = $this->config['drivers']['nominatim'] ?? [];
        $endpoint = $driver['endpoint'] ?? 'https://nominatim.openstreetmap.org/search';
        $userAgent = $driver['user_agent']
            ?? 'blax-software/laravel-addresses (https://github.com/blax-software/laravel-addresses)';
        $timeout = (int) ($this->config['timeout_seconds'] ?? 8);
        $language = $this->config['accept_language'] ?? 'en';

        // Optional contact email — Nominatim recommends including one so
        // they can reach out about traffic problems instead of just
        // blocking the IP.
        if (! empty($driver['email'])) {
            $params['email'] = $driver['email'];
        }

        $response = $this->http
            ->withHeaders([
                'User-Agent' => $userAgent,
                'Accept-Language' => $language,
            ])
            ->timeout($timeout)
            ->acceptJson()
            ->get($endpoint, $params);

        // Re-throw on any 4xx/5xx — callers decide what to do.
        $response->throw();

        $payload = $response->json();
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        $first = $payload[0] ?? null;
        if (! is_array($first)) {
            return null;
        }

        // Nominatim returns lat/lon as JSON strings — cast carefully.
        // If either is missing or unparseable we treat it as "no match"
        // rather than poisoning the model with NaNs.
        $lat = $this->coerceFloat($first['lat'] ?? null);
        $lon = $this->coerceFloat($first['lon'] ?? null);
        if ($lat === null || $lon === null) {
            return null;
        }

        return new GeocodingResult(
            latitude: $lat,
            longitude: $lon,
            displayName: isset($first['display_name']) ? (string) $first['display_name'] : null,
            raw: $first,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query construction
    |--------------------------------------------------------------------------
    */

    /**
     * Build the structured Nominatim query from the address's postal
     * fields. Returns null when the address has too little signal to
     * even bother asking (no street, no postal code, no city).
     *
     * @return array<string, string>|null
     */
    protected function buildQueryParams(Address $address): ?array
    {
        $street = $this->stringOrNull($address->street);
        $postalCode = $this->stringOrNull($address->postal_code);
        $city = $this->stringOrNull($address->city);
        $state = $this->stringOrNull($address->state);
        $county = $this->stringOrNull($address->county);
        $country = $this->stringOrNull($address->country_code);

        if ($street === null && $postalCode === null && $city === null) {
            // Nothing actionable to look up.
            return null;
        }

        $params = [
            'format' => 'jsonv2',
            'limit' => '1',
            'addressdetails' => '0',
        ];

        if ($street !== null) {
            $params['street'] = $street;
        }
        if ($postalCode !== null) {
            $params['postalcode'] = $postalCode;
        }
        if ($city !== null) {
            $params['city'] = $city;
        }
        if ($state !== null) {
            $params['state'] = $state;
        }
        if ($county !== null) {
            $params['county'] = $county;
        }
        if ($country !== null) {
            // Nominatim accepts the ISO 3166-1 alpha-2 code via `country`
            // or `countrycodes`. The latter is the documented filter and
            // returns more reliable matches.
            $params['countrycodes'] = strtolower($country);
        }

        return $params;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the cache repository to use for the lock + stamp. Falls
     * back to the application default when no explicit store is set.
     */
    protected function cacheStore()
    {
        $store = $this->config['cache_store'] ?? null;

        return $this->cache->store($store);
    }

    /**
     * Normalise a postal-field value to either a trimmed non-empty
     * string or null. Saves the rest of the code from having to handle
     * empty strings, all-whitespace strings, and nulls separately.
     */
    protected function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Tolerant numeric coercion — accepts native floats / ints AND
     * Nominatim's stringly-typed lat/lon values, refuses NaN / Inf so
     * the caller never sees garbage.
     */
    protected function coerceFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;
        if (is_nan($float) || is_infinite($float)) {
            return null;
        }

        return $float;
    }
}
