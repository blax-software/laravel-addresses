# Auto-Geocoding

After every `Address` save, an observer asks a geocoding service for
`latitude` / `longitude` based on the postal fields, then writes the
result back onto the row.

The default driver is **Nominatim (OpenStreetMap)** — free, no API key
required, but [policy-capped](https://operations.osmfoundation.org/policies/nominatim/)
at **1 request per second** on the public instance. The package
enforces that cap with a Laravel `Cache::lock`, so even multiple PHP-FPM
workers / queue runners sharing the same address book never overshoot.

> **Opt-in.** The observer is wired up but the master switch defaults to
> `false`, so upgrading the package doesn't change behaviour for an
> existing app. Set `ADDRESSES_GEOCODING_ENABLED=true` (and a
> descriptive `ADDRESSES_GEOCODING_USER_AGENT`) to turn it on.

## Lifecycle

```
Address::create([...])
        │
        ├─► INSERT row
        │
        ├─► AddressObserver::created
        │       │
        │       ├─► Cache::lock acquired (cluster-wide)
        │       ├─► sleep until min_interval since last call
        │       ├─► HTTP GET nominatim/search?…
        │       ├─► stamp "last call at" → cache
        │       └─► lock released
        │
        ├─► $address->saveQuietly()  (lat / lon → DB, no recursion)
        │
        └─► return $address
```

Updates work the same way, but the observer only fires the geocoder
when a **postal** field actually changed (street, postal_code, city,
state, county, country_code, building, street_extra). Plain
latitude/longitude edits — including the observer's own write-back —
don't loop, and unrelated columns (notes, meta, has_elevator, …) are
ignored.

## Configuration

```php
// config/addresses.php
'geocoding' => [
    'enabled'                  => true,            // master switch
    'driver'                   => 'nominatim',     // only one shipped
    'update_only_when_missing' => false,           // keep manual coords
    'cache_store'              => null,            // default cache store
    'cache_prefix'             => 'addresses:geocoding',
    'lock_wait_seconds'        => 10,              // queue depth budget
    'lock_ttl_seconds'         => 15,              // crash-recovery TTL
    'min_interval_seconds'     => 1.0,             // OSMF policy floor
    'timeout_seconds'          => 8,
    'accept_language'          => 'en',
    'drivers' => [
        'nominatim' => [
            'endpoint'   => 'https://nominatim.openstreetmap.org/search',
            'user_agent' => 'your-app-name (you@example.com)',
            'email'      => 'you@example.com',
        ],
    ],
],
```

Every key reads from a matching `env('ADDRESSES_GEOCODING_*')` so you
can override per-environment without publishing the config.

### Notable env vars

| Variable                                     | Default                 | Use case                                           |
|----------------------------------------------|-------------------------|----------------------------------------------------|
| `ADDRESSES_GEOCODING_ENABLED`                | `true`                  | Turn the whole observer off (CI, bulk imports)     |
| `ADDRESSES_GEOCODING_ONLY_WHEN_MISSING`      | `false`                 | Preserve manually-entered coordinates              |
| `ADDRESSES_GEOCODING_MIN_INTERVAL`           | `1.0`                   | Lower than 1 only if you self-host Nominatim       |
| `ADDRESSES_GEOCODING_USER_AGENT`             | package default         | **Set in production** — OSMF blocks generic UAs    |
| `ADDRESSES_GEOCODING_EMAIL`                  | `null`                  | Contact email for OSMF (recommended)               |
| `ADDRESSES_GEOCODING_NOMINATIM_URL`          | OSM public endpoint     | Point at a self-hosted Nominatim instance          |
| `ADDRESSES_GEOCODING_CACHE_STORE`            | app default             | Share the lock across processes (redis / memcache) |

## Plug in a different provider

The observer talks to the `Geocoder` contract, not to any concrete
driver. Bind your own implementation in a service provider:

```php
use Blax\Addresses\Services\Geocoding\Contracts\Geocoder;
use App\Services\MyMapboxGeocoder;

public function register(): void
{
    $this->app->singleton(Geocoder::class, MyMapboxGeocoder::class);
}
```

The contract is:

```php
interface Geocoder
{
    public function geocode(Address $address): ?GeocodingResult;
}
```

`GeocodingResult` is a small DTO with `latitude`, `longitude`,
`displayName`, and the raw provider payload.

Your driver is responsible for its own concurrency / rate-limit policy
— Google Maps and Mapbox have quota systems server-side, so a custom
driver usually doesn't need a `Cache::lock` at all.

## Failure modes

The observer is forgiving by design — geocoding is best-effort:

| Failure                       | Behaviour                                                                                          |
|-------------------------------|----------------------------------------------------------------------------------------------------|
| `Cache::lock` wait times out  | Logged (`info`), the address keeps its current coords. Re-save (or backfill) to retry.             |
| Network / 5xx from upstream   | Logged (`warning`), the address keeps its current coords.                                          |
| Upstream returns no match     | Silent — no logs, no DB writes.                                                                    |
| Upstream returns garbage      | Silent — invalid lat/lon (`NaN`, missing, non-numeric) are treated as "no match".                  |

In every case the user's `save()` / `create()` call returns
successfully — geocoding never blows up the consuming code path.

## Testing

Drop `Http::fake()` into your test setup; the geocoder uses Laravel's
HTTP client so it picks up the fakes automatically. To turn the
observer off entirely:

```php
protected function defineEnvironment($app): void
{
    $app['config']->set('addresses.geocoding.enabled', false);
}
```

See `tests/Unit/GeocodingTest.php` for full examples including the
rate-limit timing assertion.
