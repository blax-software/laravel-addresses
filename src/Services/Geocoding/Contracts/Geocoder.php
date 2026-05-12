<?php

namespace Blax\Addresses\Services\Geocoding\Contracts;

use Blax\Addresses\Models\Address;
use Blax\Addresses\Services\Geocoding\GeocodingResult;

/**
 * A geocoder turns a postal-shaped Address into coordinates.
 *
 * Implementations are responsible for their own concurrency control and
 * rate limiting — the observer just calls `geocode()` and trusts the
 * driver to behave nicely upstream. NominatimGeocoder serializes with a
 * Cache::lock and enforces the 1-req/sec OSMF policy; a hypothetical
 * GoogleMapsGeocoder might do nothing at all because Google has its own
 * quota system.
 *
 * Bind your own implementation by binding this contract in a service
 * provider:
 *
 *     $this->app->bind(
 *         \Blax\Addresses\Services\Geocoding\Contracts\Geocoder::class,
 *         \App\Services\MyMapboxGeocoder::class,
 *     );
 */
interface Geocoder
{
    /**
     * Resolve coordinates for the given address.
     *
     * Returns null when the upstream provider couldn't match the address
     * (or when the address doesn't carry enough postal fields to attempt
     * a lookup). Implementations should not throw on a "no match"
     * outcome — that's the caller's normal codepath, not an error.
     *
     * Implementations MUST throw on network / transport problems so the
     * caller can decide whether to retry or queue.
     */
    public function geocode(Address $address): ?GeocodingResult;
}
