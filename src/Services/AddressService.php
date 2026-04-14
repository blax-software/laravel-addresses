<?php

namespace Blax\Addresses\Services;

use Blax\Addresses\Enums\AddressLinkType;
use Blax\Addresses\Models\Address;
use Blax\Addresses\Models\AddressLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Central service for address operations that go beyond simple CRUD.
 *
 * Provides distance calculations (Haversine), proximity queries, duplicate
 * detection, geocoordinate helpers and bulk operations.
 *
 * Retrieve via DI or the `address()` helper:
 *
 *     app(AddressService::class)->distanceBetween($a, $b);
 *     address()->nearby($lat, $lng, 5);
 */
class AddressService
{
    /**
     * Mean radius of the Earth in kilometres (WGS-84 volumetric mean).
     */
    public const EARTH_RADIUS_KM = 6371.0;

    /**
     * Mean radius of the Earth in miles.
     */
    public const EARTH_RADIUS_MI = 3958.8;

    /*
    |--------------------------------------------------------------------------
    | Distance Calculation
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate the great-circle distance between two addresses using the
     * Haversine formula.
     *
     * Both addresses must have latitude and longitude set; returns null if
     * either is missing coordinates.
     *
     * @param  Address  $from
     * @param  Address  $to
     * @param  string   $unit  'km' (default) or 'mi'
     * @return float|null  Distance in the requested unit, or null if coordinates are missing
     */
    public function distanceBetween(Address $from, Address $to, string $unit = 'km'): ?float
    {
        if (! $from->hasCoordinates() || ! $to->hasCoordinates()) {
            return null;
        }

        return $this->haversine(
            $from->latitude,
            $from->longitude,
            $to->latitude,
            $to->longitude,
            $unit
        );
    }

    /**
     * Calculate the great-circle distance between two coordinate pairs.
     *
     * @param  float   $lat1   Latitude of point A (decimal degrees)
     * @param  float   $lng1   Longitude of point A (decimal degrees)
     * @param  float   $lat2   Latitude of point B (decimal degrees)
     * @param  float   $lng2   Longitude of point B (decimal degrees)
     * @param  string  $unit   'km' (default) or 'mi'
     * @return float   Distance in the requested unit
     */
    public function haversine(float $lat1, float $lng1, float $lat2, float $lng2, string $unit = 'km'): float
    {
        $radius = $unit === 'mi' ? self::EARTH_RADIUS_MI : self::EARTH_RADIUS_KM;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $radius * $c;
    }

    /**
     * Calculate the altitude difference between two addresses in metres.
     *
     * Returns null if either address is missing altitude data.
     *
     * @param  Address  $from
     * @param  Address  $to
     * @return float|null  Signed difference (to − from) in metres
     */
    public function altitudeDifference(Address $from, Address $to): ?float
    {
        if (! $from->hasAltitude() || ! $to->hasAltitude()) {
            return null;
        }

        return $to->altitude - $from->altitude;
    }

    /*
    |--------------------------------------------------------------------------
    | Proximity Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Find all addresses within a given radius of a coordinate point.
     *
     * Uses a bounding-box pre-filter for performance, then refines with
     * Haversine. Results are ordered by distance (nearest first).
     *
     * @param  float   $latitude   Centre latitude (decimal degrees)
     * @param  float   $longitude  Centre longitude (decimal degrees)
     * @param  float   $radius     Search radius
     * @param  string  $unit       'km' (default) or 'mi'
     * @return Collection<int, Address>  Each model has a `->distance` attribute appended
     */
    public function nearby(float $latitude, float $longitude, float $radius, string $unit = 'km'): Collection
    {
        $earthRadius = $unit === 'mi' ? self::EARTH_RADIUS_MI : self::EARTH_RADIUS_KM;

        // Bounding box pre-filter (rough but fast — avoids full-table Haversine)
        $boundingBox = $this->boundingBox($latitude, $longitude, $radius, $unit);

        $addressModel = config('addresses.models.address', Address::class);

        return $addressModel::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$boundingBox['minLat'], $boundingBox['maxLat']])
            ->whereBetween('longitude', [$boundingBox['minLng'], $boundingBox['maxLng']])
            ->get()
            ->map(function (Address $address) use ($latitude, $longitude, $unit) {
                $address->distance = $this->haversine(
                    $latitude,
                    $longitude,
                    $address->latitude,
                    $address->longitude,
                    $unit
                );

                return $address;
            })
            ->filter(fn(Address $address) => $address->distance <= $radius)
            ->sortBy('distance')
            ->values();
    }

    /**
     * Find addresses near a given address (convenience wrapper around nearby()).
     *
     * @param  Address  $address        The reference address (must have coordinates)
     * @param  float    $radius         Search radius
     * @param  string   $unit           'km' or 'mi'
     * @param  bool     $excludeSelf    Whether to exclude the reference address from results
     * @return Collection<int, Address>
     */
    public function nearbyAddress(Address $address, float $radius, string $unit = 'km', bool $excludeSelf = true): Collection
    {
        if (! $address->hasCoordinates()) {
            return collect();
        }

        $results = $this->nearby($address->latitude, $address->longitude, $radius, $unit);

        if ($excludeSelf) {
            $results = $results->reject(fn(Address $a) => $a->id === $address->id)->values();
        }

        return $results;
    }

    /**
     * Get the closest address to a coordinate point.
     *
     * @param  float  $latitude
     * @param  float  $longitude
     * @return Address|null  The nearest address, with `->distance` appended (km)
     */
    public function closest(float $latitude, float $longitude): ?Address
    {
        $addressModel = config('addresses.models.address', Address::class);

        $addresses = $addressModel::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        if ($addresses->isEmpty()) {
            return null;
        }

        return $addresses
            ->map(function (Address $address) use ($latitude, $longitude) {
                $address->distance = $this->haversine(
                    $latitude,
                    $longitude,
                    $address->latitude,
                    $address->longitude,
                );

                return $address;
            })
            ->sortBy('distance')
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Bounding Box
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate a latitude/longitude bounding box around a centre point.
     *
     * Useful as a fast pre-filter before applying the more expensive
     * Haversine calculation.
     *
     * @param  float   $latitude
     * @param  float   $longitude
     * @param  float   $radius
     * @param  string  $unit  'km' or 'mi'
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public function boundingBox(float $latitude, float $longitude, float $radius, string $unit = 'km'): array
    {
        $earthRadius = $unit === 'mi' ? self::EARTH_RADIUS_MI : self::EARTH_RADIUS_KM;

        // Angular radius in degrees
        $angularRadius = rad2deg($radius / $earthRadius);

        // Latitude bounds are straightforward
        $minLat = $latitude - $angularRadius;
        $maxLat = $latitude + $angularRadius;

        // Longitude bounds must account for meridian convergence
        $lngDelta = rad2deg(asin(sin(deg2rad($angularRadius)) / cos(deg2rad($latitude))));
        $minLng = $longitude - $lngDelta;
        $maxLng = $longitude + $lngDelta;

        return [
            'minLat' => max($minLat, -90),
            'maxLat' => min($maxLat, 90),
            'minLng' => max($minLng, -180),
            'maxLng' => min($maxLng, 180),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate / Match Detection
    |--------------------------------------------------------------------------
    */

    /**
     * Find addresses that look like potential duplicates of the given address.
     *
     * Matches on the combination of street, postal_code, city and country_code.
     * Coordinates are NOT considered (two records for the same street may have
     * slightly different GPS positions).
     *
     * @param  Address  $address
     * @return Collection<int, Address>  Potential duplicates (excluding the address itself)
     */
    public function findDuplicates(Address $address): Collection
    {
        $addressModel = config('addresses.models.address', Address::class);

        return $addressModel::query()
            ->where('id', '!=', $address->id)
            ->when($address->street, fn(Builder $q) => $q->where('street', $address->street))
            ->when($address->postal_code, fn(Builder $q) => $q->where('postal_code', $address->postal_code))
            ->when($address->city, fn(Builder $q) => $q->where('city', $address->city))
            ->when($address->country_code, fn(Builder $q) => $q->where('country_code', $address->country_code))
            ->get();
    }

    /**
     * Merge a duplicate address into a target address.
     *
     * All address_links currently pointing to `$duplicate` are re-pointed to
     * `$target`. The duplicate address is then soft-deleted.
     *
     * @param  Address  $target     The address to keep
     * @param  Address  $duplicate  The address to merge away
     * @return int  Number of links reassigned
     */
    public function merge(Address $target, Address $duplicate): int
    {
        $linkModel = config('addresses.models.address_link', AddressLink::class);

        $affected = $linkModel::where('address_id', $duplicate->id)
            ->update(['address_id' => $target->id]);

        $duplicate->delete();

        return $affected;
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes / Builders
    |--------------------------------------------------------------------------
    */

    /**
     * Get a query builder for addresses filtered by country code.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 code
     * @return Builder
     */
    public function inCountry(string $countryCode): Builder
    {
        $addressModel = config('addresses.models.address', Address::class);

        return $addressModel::where('country_code', strtoupper($countryCode));
    }

    /**
     * Get a query builder for addresses in a specific city.
     *
     * @param  string       $city
     * @param  string|null  $countryCode  Optional ISO alpha-2 filter
     * @return Builder
     */
    public function inCity(string $city, ?string $countryCode = null): Builder
    {
        $addressModel = config('addresses.models.address', Address::class);

        $query = $addressModel::where('city', $city);

        if ($countryCode) {
            $query->where('country_code', strtoupper($countryCode));
        }

        return $query;
    }

    /**
     * Get a query builder for addresses matching a postal code.
     *
     * @param  string       $postalCode
     * @param  string|null  $countryCode  Optional ISO alpha-2 filter
     * @return Builder
     */
    public function inPostalCode(string $postalCode, ?string $countryCode = null): Builder
    {
        $addressModel = config('addresses.models.address', Address::class);

        $query = $addressModel::where('postal_code', $postalCode);

        if ($countryCode) {
            $query->where('country_code', strtoupper($countryCode));
        }

        return $query;
    }

    /**
     * Get all addresses that have coordinates set.
     *
     * @return Builder
     */
    public function withCoordinates(): Builder
    {
        $addressModel = config('addresses.models.address', Address::class);

        return $addressModel::whereNotNull('latitude')
            ->whereNotNull('longitude');
    }

    /*
    |--------------------------------------------------------------------------
    | Formatting
    |--------------------------------------------------------------------------
    */

    /**
     * Build a single-line formatted string from an address.
     *
     * Joins non-empty components with the given separator.
     *
     * @param  Address  $address
     * @param  string   $separator  Glue between parts (default: ", ")
     * @return string
     */
    public function format(Address $address, string $separator = ', '): string
    {
        return collect([
            $address->street,
            $address->street_extra,
            $address->building ? "({$address->building})" : null,
            $address->floor ? "Floor {$address->floor}" : null,
            $address->room ? "Room {$address->room}" : null,
            $address->postal_code,
            $address->city,
            $address->state,
            $address->county,
            $address->country_code,
        ])->filter()->implode($separator);
    }

    /**
     * Build a multi-line formatted string from an address.
     *
     * Produces a postal-style block, e.g.:
     *
     *   350 Fifth Avenue, Suite 3200
     *   Empire State Building, Floor 32, Room 3201
     *   10118 New York, NY
     *   US
     *
     * @param  Address  $address
     * @return string
     */
    public function formatMultiline(Address $address): string
    {
        $lines = [];

        // Line 1: street
        $street = collect([$address->street, $address->street_extra])->filter()->implode(', ');
        if ($street) {
            $lines[] = $street;
        }

        // Line 2: building / indoor
        $indoor = collect([
            $address->building,
            $address->floor ? "Floor {$address->floor}" : null,
            $address->room ? "Room {$address->room}" : null,
        ])->filter()->implode(', ');
        if ($indoor) {
            $lines[] = $indoor;
        }

        // Line 3: postal code + city + state
        $cityLine = collect([
            collect([$address->postal_code, $address->city])->filter()->implode(' '),
            $address->state,
        ])->filter()->implode(', ');
        if ($cityLine) {
            $lines[] = $cityLine;
        }

        // Line 4: county (if present)
        if ($address->county) {
            $lines[] = $address->county;
        }

        // Line 5: country
        if ($address->country_code) {
            $lines[] = $address->country_code;
        }

        return implode("\n", $lines);
    }

    /**
     * Format coordinates as a human-readable string.
     *
     * Example: "48.2082000°N, 16.3738000°E (alt: 171.00m AMSL)"
     *
     * @param  Address  $address
     * @return string|null  null if no coordinates are set
     */
    public function formatCoordinates(Address $address): ?string
    {
        if (! $address->hasCoordinates()) {
            return null;
        }

        $lat = number_format(abs($address->latitude), 7);
        $latDir = $address->latitude >= 0 ? 'N' : 'S';

        $lng = number_format(abs($address->longitude), 7);
        $lngDir = $address->longitude >= 0 ? 'E' : 'W';

        $result = "{$lat}°{$latDir}, {$lng}°{$lngDir}";

        if ($address->hasAltitude()) {
            $alt = number_format($address->altitude, 2);
            $result .= " (alt: {$alt}m AMSL)";
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Coordinate Conversion
    |--------------------------------------------------------------------------
    */

    /**
     * Convert degrees, minutes, seconds (DMS) to decimal degrees.
     *
     * @param  int    $degrees
     * @param  int    $minutes
     * @param  float  $seconds
     * @param  string $direction  'N', 'S', 'E' or 'W'
     * @return float  Decimal degrees (negative for S/W)
     */
    public function dmsToDecimal(int $degrees, int $minutes, float $seconds, string $direction): float
    {
        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        if (in_array(strtoupper($direction), ['S', 'W'])) {
            $decimal *= -1;
        }

        return $decimal;
    }

    /**
     * Convert decimal degrees to degrees, minutes, seconds (DMS).
     *
     * @param  float   $decimal    Decimal degrees
     * @param  string  $axis      'lat' or 'lng' — determines the direction letter
     * @return array{degrees: int, minutes: int, seconds: float, direction: string}
     */
    public function decimalToDms(float $decimal, string $axis = 'lat'): array
    {
        $direction = $axis === 'lat'
            ? ($decimal >= 0 ? 'N' : 'S')
            : ($decimal >= 0 ? 'E' : 'W');

        $decimal = abs($decimal);
        $degrees = (int) floor($decimal);
        $minutesDecimal = ($decimal - $degrees) * 60;
        $minutes = (int) floor($minutesDecimal);
        $seconds = round(($minutesDecimal - $minutes) * 60, 4);

        return [
            'degrees'   => $degrees,
            'minutes'   => $minutes,
            'seconds'   => $seconds,
            'direction' => $direction,
        ];
    }
}
