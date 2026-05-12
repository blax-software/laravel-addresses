<?php

namespace Blax\Addresses;

use Blax\Addresses\Models\Address;
use Blax\Addresses\Models\AddressAssignment;
use Blax\Addresses\Models\AddressLink;
use Blax\Addresses\Observers\AddressObserver;
use Blax\Addresses\Services\AddressService;
use Blax\Addresses\Services\Geocoding\Contracts\Geocoder;
use Blax\Addresses\Services\Geocoding\NominatimGeocoder;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;

class AddressesServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * Merges the package config so that it is available even when the
     * consuming application has not published it.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/addresses.php',
            'addresses'
        );

        // Register AddressService as a singleton.
        $this->app->singleton(AddressService::class);

        // Default Geocoder binding. Apps can rebind this contract to a
        // different driver (Mapbox, Google, …) in their own provider —
        // the AddressObserver only knows about the contract, not the
        // concrete implementation.
        $this->app->singleton(Geocoder::class, function ($app) {
            return new NominatimGeocoder(
                $app->make(HttpFactory::class),
                $app->make(CacheFactory::class),
                $app['config']->get('addresses.geocoding', []),
            );
        });
    }

    /**
     * Bootstrap the application events.
     *
     * Publishes config and migration stubs, registers model bindings so
     * that the container always resolves the (possibly overridden) model,
     * and auto-loads any *additive* migrations the package ships in
     * `database/migrations/` (plain .php files — the create-tables
     * baseline stays a `.stub` for vendor:publish only).
     */
    public function boot(): void
    {
        $this->offerPublishing();

        // Auto-load additive migrations (e.g. new columns / indexes) shipped
        // with the package as plain `.php` files. The original `create_…`
        // migration is a `.stub` and is therefore NOT picked up here —
        // consumers must still `vendor:publish` it once to set the baseline
        // (preserves backwards-compatibility with apps that already published
        // a customised version, like UUID PKs).
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerModelBindings();

        $this->registerModelObservers();
    }

    /*
    |--------------------------------------------------------------------------
    | Observers
    |--------------------------------------------------------------------------
    */

    /**
     * Attach the AddressObserver to the (possibly overridden) Address
     * model so saving an address triggers the geocoding pipeline.
     *
     * Resolves the concrete Address class through config so a consumer
     * extending the model still gets observed.
     */
    protected function registerModelObservers(): void
    {
        $addressModel = $this->app['config']->get('addresses.models.address', Address::class);

        $addressModel::observe(AddressObserver::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Publishing
    |--------------------------------------------------------------------------
    */

    /**
     * Set up publishing of config and migration files for `php artisan vendor:publish`.
     */
    protected function offerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Config
        $this->publishes([
            __DIR__.'/../config/addresses.php' => $this->app->configPath('addresses.php'),
        ], 'addresses-config');

        // Migrations
        $this->publishes([
            __DIR__.'/../database/migrations/create_blax_address_tables.php.stub' => $this->getMigrationFileName('create_blax_address_tables.php'),
        ], 'addresses-migrations');
    }

    /**
     * Returns an existing migration file if one is already published,
     * otherwise generates a timestamped path.
     */
    protected function getMigrationFileName(string $migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        $filesystem = $this->app->make(Filesystem::class);

        return Collection::make([
            $this->app->databasePath().DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR,
        ])
            ->flatMap(fn ($path) => $filesystem->glob($path.'*_'.$migrationFileName))
            ->push($this->app->databasePath()."/migrations/{$timestamp}_{$migrationFileName}")
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Model Bindings
    |--------------------------------------------------------------------------
    */

    /**
     * Bind the package model abstractions to the (potentially customised)
     * concrete classes from config.
     */
    protected function registerModelBindings(): void
    {
        $this->app->bind(
            Address::class,
            fn ($app) => $app->make($app->config['addresses.models.address'])
        );

        $this->app->bind(
            AddressLink::class,
            fn ($app) => $app->make($app->config['addresses.models.address_link'])
        );

        $this->app->bind(
            AddressAssignment::class,
            fn ($app) => $app->make($app->config['addresses.models.address_assignment'])
        );
    }
}
