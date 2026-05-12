<?php

use Blax\Addresses\Enums\AddressLinkType;
use Blax\Addresses\Models\Address;
use Blax\Addresses\Models\AddressAssignment;
use Blax\Addresses\Models\AddressLink;

return [

    /*
    |--------------------------------------------------------------------------
    | Model Classes
    |--------------------------------------------------------------------------
    |
    | Override these with your own model classes if you need to extend or
    | customise the package models. Your custom models should extend the
    | corresponding package model so that migrations and relationships
    | continue to work out of the box.
    |
    */
    'models' => [
        'address' => Address::class,
        'address_link' => AddressLink::class,
        'address_assignment' => AddressAssignment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | The database table names used by the package. Change these if they
    | collide with existing tables in your application.
    |
    */
    'table_names' => [
        'addresses' => 'addresses',
        'address_links' => 'address_links',
        'address_assignments' => 'address_assignments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Address Link Type
    |--------------------------------------------------------------------------
    |
    | The default AddressLinkType applied when attaching an address to a model
    | without specifying a type explicitly.
    |
    */
    'default_link_type' => AddressLinkType::Other,

    /*
    |--------------------------------------------------------------------------
    | Geocoding
    |--------------------------------------------------------------------------
    |
    | After an Address is saved, the package can resolve its `latitude` and
    | `longitude` from the postal fields using a public geocoder. By default
    | this uses Nominatim (OpenStreetMap) — free, no API key, requires a
    | descriptive User-Agent and a hard limit of one request per second
    | (operations.osmfoundation.org/policies/nominatim/).
    |
    | The observer holds a Laravel `Cache::lock` while it makes the call, so
    | even when the same code is running in multiple workers only ONE call
    | hits the upstream at a time, and the 1-req/sec floor is enforced
    | globally across the cluster via a shared cache timestamp.
    |
    | Set `enabled => false` to turn the observer off (e.g. in CI). Set
    | `update_only_when_missing => true` to keep manually-entered coordinates
    | and only geocode rows whose coordinates are still null.
    |
    */
    'geocoding' => [

        // Master switch. Opt-in by default — turning it on starts firing
        // outbound HTTP calls on every Address save, which apps updating
        // from a previous version don't want as a surprise. Flip it via
        // ADDRESSES_GEOCODING_ENABLED=true once you've reviewed the
        // Nominatim usage policy and set a proper User-Agent below.
        'enabled' => env('ADDRESSES_GEOCODING_ENABLED', false),

        // Driver — currently only `nominatim` is shipped. The Geocoder
        // contract is in `src/Services/Geocoding/Contracts/Geocoder.php`
        // so apps can bind their own implementation if they need Google
        // Maps / Mapbox / etc.
        'driver' => env('ADDRESSES_GEOCODING_DRIVER', 'nominatim'),

        // True → only geocode when latitude/longitude are still NULL.
        //   • Manually-entered coordinates win, the observer leaves them alone.
        // False → re-geocode every time a postal field actually changes.
        //   • Coordinates always track the textual address.
        'update_only_when_missing' => env('ADDRESSES_GEOCODING_ONLY_WHEN_MISSING', false),

        // Cache store used for the lock + the global "last call at" stamp.
        // null = the app's default cache store. Pick something shared
        // (redis / memcached / database) if you run multiple workers,
        // otherwise the 1-req/sec ceiling is only per-process.
        'cache_store' => env('ADDRESSES_GEOCODING_CACHE_STORE'),

        // Cache key prefix — gives operators a single root to flush.
        'cache_prefix' => 'addresses:geocoding',

        // How long to wait (seconds) for the global geocoding lock before
        // giving up. Bursts of saves queue up against this; pick a value
        // that's roughly `expected_burst_size * min_interval`.
        'lock_wait_seconds' => env('ADDRESSES_GEOCODING_LOCK_WAIT', 10),

        // Lock TTL — protects against a hard crash leaving the lock held.
        // Should comfortably exceed `timeout_seconds + min_interval_seconds`.
        'lock_ttl_seconds' => env('ADDRESSES_GEOCODING_LOCK_TTL', 15),

        // Minimum interval (seconds, float-friendly) between two consecutive
        // upstream calls. Nominatim's published policy is "no more than 1
        // per second". Don't go below 1.0 on the public server.
        'min_interval_seconds' => env('ADDRESSES_GEOCODING_MIN_INTERVAL', 1.0),

        // HTTP read+connect timeout for a single upstream call (seconds).
        'timeout_seconds' => env('ADDRESSES_GEOCODING_TIMEOUT', 8),

        // Languages preference (Accept-Language). Nominatim uses this to
        // pick localized `display_name` strings.
        'accept_language' => env('ADDRESSES_GEOCODING_LANG', 'en'),

        // Driver-specific settings.
        'drivers' => [
            'nominatim' => [
                // Base endpoint. Use a self-hosted instance here to lift
                // the 1-req/sec restriction; see
                // https://github.com/mediagis/nominatim-docker.
                'endpoint' => env('ADDRESSES_GEOCODING_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search'),

                // Nominatim's usage policy requires a descriptive User-Agent
                // that identifies your application. The default uses the
                // package name; SET YOUR OWN APP NAME + CONTACT in
                // production so the OSMF can reach you if you're causing
                // load problems instead of blocking your IP cold.
                'user_agent' => env(
                    'ADDRESSES_GEOCODING_USER_AGENT',
                    'blax-software/laravel-addresses (https://github.com/blax-software/laravel-addresses)',
                ),

                // Optional contact email — included as the `email` query
                // parameter as suggested by the Nominatim docs.
                'email' => env('ADDRESSES_GEOCODING_EMAIL'),
            ],
        ],
    ],

];
