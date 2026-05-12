<?php

namespace Blax\Addresses\Services\Geocoding;

/**
 * Result of a geocoding lookup.
 *
 * Drivers return one of these on success and `null` when the address
 * couldn't be resolved. Keeping the structure small on purpose — anything
 * driver-specific lives in `$raw` for the caller to inspect when needed
 * (e.g. confidence scores, OSM type, bounding box, …).
 */
final class GeocodingResult
{
    /**
     * @param  float  $latitude  Decimal degrees, WGS-84 (−90 … +90).
     * @param  float  $longitude  Decimal degrees, WGS-84 (−180 … +180).
     * @param  string|null  $displayName  Human-readable canonical address from the provider.
     * @param  array<string, mixed>  $raw  Driver-specific payload (forensic / debugging).
     */
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly ?string $displayName = null,
        public readonly array $raw = [],
    ) {}
}
