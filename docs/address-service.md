# AddressService

The `AddressService` is a singleton service providing distance calculations, proximity queries, duplicate detection, formatting and coordinate conversion.

## Accessing the Service

```php
// Via the global helper
address()->distanceBetween($a, $b);

// Via dependency injection
use Blax\Addresses\Services\AddressService;

public function __construct(private AddressService $addressService) {}

// Via the container
app(AddressService::class)->nearby($lat, $lng, 10);
```

---

## Distance Calculation

### `distanceBetween(Address $from, Address $to, string $unit = 'km'): ?float`

Calculate the great-circle distance between two addresses using the Haversine formula.

```php
$vienna = Address::create(['latitude' => 48.2082, 'longitude' => 16.3738]);
$berlin = Address::create(['latitude' => 52.5200, 'longitude' => 13.4050]);

$km = address()->distanceBetween($vienna, $berlin);         // ~524.2 km
$mi = address()->distanceBetween($vienna, $berlin, 'mi');    // ~325.8 mi
```

Returns `null` if either address is missing coordinates.

### `haversine(float $lat1, float $lng1, float $lat2, float $lng2, string $unit = 'km'): float`

Calculate distance directly from coordinate pairs — no Address models needed.

```php
$distance = address()->haversine(48.2082, 16.3738, 52.5200, 13.4050); // ~524.2 km
```

### `altitudeDifference(Address $from, Address $to): ?float`

Calculate the altitude difference in metres (signed: `to − from`).

```php
$valley = Address::create(['altitude' => 200.0, 'latitude' => 0, 'longitude' => 0]);
$peak   = Address::create(['altitude' => 1800.0, 'latitude' => 0, 'longitude' => 0]);

address()->altitudeDifference($valley, $peak); // 1600.0
address()->altitudeDifference($peak, $valley); // -1600.0
```

Returns `null` if either address is missing altitude data.

### Constants

| Constant                          | Value  | Description                     |
|-----------------------------------|--------|---------------------------------|
| `AddressService::EARTH_RADIUS_KM` | 6371.0 | Mean Earth radius in kilometres |
| `AddressService::EARTH_RADIUS_MI` | 3958.8 | Mean Earth radius in miles      |

---

## Proximity Queries

### `nearby(float $latitude, float $longitude, float $radius, string $unit = 'km'): Collection`

Find all addresses within a given radius of a coordinate point. Uses a bounding-box pre-filter for performance, then refines with Haversine. Results are ordered by distance (nearest first).

```php
// All addresses within 10 km of St. Stephen's Cathedral
$nearby = address()->nearby(48.2082, 16.3738, 10);

foreach ($nearby as $address) {
    echo $address->city;      // "Vienna"
    echo $address->distance;  // 2.34 (km from centre)
}
```

Each returned `Address` has a `->distance` attribute appended with the calculated distance.

### `nearbyAddress(Address $address, float $radius, string $unit = 'km', bool $excludeSelf = true): Collection`

Convenience wrapper — find addresses near a given address.

```php
$office = Address::create([
    'street' => 'Stephansplatz 1',
    'city' => 'Vienna',
    'latitude' => 48.2082,
    'longitude' => 16.3738,
]);

// Find other addresses within 5 km
$neighbours = address()->nearbyAddress($office, 5);

// Include the reference address itself
$all = address()->nearbyAddress($office, 5, 'km', false);
```

Returns an empty collection if the address has no coordinates.

### `closest(float $latitude, float $longitude): ?Address`

Get the single closest address to a coordinate point.

```php
$nearest = address()->closest(48.2082, 16.3738);

echo $nearest->formatted;   // "Stephansplatz 1, 1010, Vienna, AT"
echo $nearest->distance;    // 0.12 (km)
```

Returns `null` if no addresses with coordinates exist.

---

## Bounding Box

### `boundingBox(float $latitude, float $longitude, float $radius, string $unit = 'km'): array`

Calculate a latitude/longitude bounding box around a centre point. Useful as a fast pre-filter before computing Haversine distances.

```php
$box = address()->boundingBox(48.2082, 16.3738, 10); // 10 km radius

// Returns:
// [
//     'minLat' => 48.1183...,
//     'maxLat' => 48.2981...,
//     'minLng' => 16.2395...,
//     'maxLng' => 16.5081...,
// ]

// Use in a query
Address::whereBetween('latitude', [$box['minLat'], $box['maxLat']])
    ->whereBetween('longitude', [$box['minLng'], $box['maxLng']])
    ->get();
```

---

## Duplicate Detection & Merging

### `findDuplicates(Address $address): Collection`

Find addresses that look like potential duplicates. Matches on `street`, `postal_code`, `city` and `country_code`.

```php
$address = Address::create([
    'street'       => 'Baker Street 221B',
    'postal_code'  => 'NW1 6XE',
    'city'         => 'London',
    'country_code' => 'GB',
]);

$duplicates = address()->findDuplicates($address);

foreach ($duplicates as $dup) {
    echo "Possible duplicate: #{$dup->id} — {$dup->formatted}";
}
```

### `merge(Address $target, Address $duplicate): int`

Merge a duplicate address into a target. All `AddressLink` rows pointing to the duplicate are reassigned to the target, and the duplicate is soft-deleted.

```php
$target    = Address::find(1); // the one to keep
$duplicate = Address::find(2); // the one to merge away

$reassigned = address()->merge($target, $duplicate);
echo "Reassigned {$reassigned} links";

$duplicate->trashed(); // true
```

---

## Query Builders

These methods return Eloquent `Builder` instances for further chaining.

### `inCountry(string $countryCode): Builder`

```php
$austrianAddresses = address()->inCountry('AT')->get();
$austrianCount     = address()->inCountry('AT')->count();
```

### `inCity(string $city, ?string $countryCode = null): Builder`

```php
$viennaAddresses = address()->inCity('Vienna')->get();

// Disambiguate: Vienna, Austria vs Vienna, Virginia
$at = address()->inCity('Vienna', 'AT')->get();
```

### `inPostalCode(string $postalCode, ?string $countryCode = null): Builder`

```php
$addresses = address()->inPostalCode('1010')->get();
$addresses = address()->inPostalCode('1010', 'AT')->get();
```

### `withCoordinates(): Builder`

Get all addresses that have latitude and longitude set.

```php
$geoAddresses = address()->withCoordinates()->get();
$count        = address()->withCoordinates()->count();
```

---

## Formatting

### `format(Address $address, string $separator = ', '): string`

Build a single-line formatted string from an address.

```php
$address = Address::create([
    'street'       => '350 Fifth Avenue',
    'building'     => 'Empire State Building',
    'floor'        => '32',
    'postal_code'  => '10118',
    'city'         => 'New York',
    'state'        => 'NY',
    'country_code' => 'US',
]);

echo address()->format($address);
// "350 Fifth Avenue, (Empire State Building), Floor 32, 10118, New York, NY, US"

echo address()->format($address, ' | ');
// "350 Fifth Avenue | (Empire State Building) | Floor 32 | 10118 | New York | NY | US"
```

> **Tip:** The `Address` model also has a `$address->formatted` accessor that produces the same single-line output with `", "` separator.

### `formatMultiline(Address $address): string`

Build a multi-line, postal-style formatted string.

```php
echo address()->formatMultiline($address);
// 350 Fifth Avenue
// Empire State Building, Floor 32
// 10118 New York, NY
// US
```

Line structure:
1. Street + street_extra
2. Building, floor, room
3. Postal code + city, state
4. County (if set)
5. Country code

### `formatCoordinates(Address $address): ?string`

Format coordinates as a human-readable string.

```php
$address = Address::create([
    'latitude'  => 48.2082,
    'longitude' => 16.3738,
    'altitude'  => 171.0,
]);

echo address()->formatCoordinates($address);
// "48.2082000°N, 16.3738000°E (alt: 171.00m AMSL)"
```

Returns `null` if no coordinates are set.

---

## Coordinate Conversion

### `dmsToDecimal(int $degrees, int $minutes, float $seconds, string $direction): float`

Convert degrees/minutes/seconds (DMS) to decimal degrees.

```php
// 48° 12' 29.52" N
$lat = address()->dmsToDecimal(48, 12, 29.52, 'N'); // 48.2082

// 16° 22' 25.68" E
$lng = address()->dmsToDecimal(16, 22, 25.68, 'E'); // 16.3738

// Southern / Western hemispheres yield negative values
$lat = address()->dmsToDecimal(33, 51, 54.0, 'S'); // -33.865
```

### `decimalToDms(float $decimal, string $axis = 'lat'): array`

Convert decimal degrees to DMS.

```php
$dms = address()->decimalToDms(48.2082, 'lat');
// [
//     'degrees'   => 48,
//     'minutes'   => 12,
//     'seconds'   => 29.52,
//     'direction' => 'N',
// ]

$dms = address()->decimalToDms(-73.9854, 'lng');
// [
//     'degrees'   => 73,
//     'minutes'   => 59,
//     'seconds'   => 7.44,
//     'direction' => 'W',
// ]
```

The `$axis` parameter determines the direction letter:
- `'lat'` → N (positive) / S (negative)
- `'lng'` → E (positive) / W (negative)
