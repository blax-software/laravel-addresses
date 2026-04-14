<?php

namespace Blax\Addresses;

use Blax\Addresses\Services\AddressService;
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
            __DIR__ . '/../config/addresses.php',
            'addresses'
        );

        // Register AddressService as a singleton.
        $this->app->singleton(AddressService::class);
    }

    /**
     * Bootstrap the application events.
     *
     * Publishes config and migration stubs, and registers model bindings
     * so that the container always resolves the (possibly overridden) model.
     */
    public function boot(): void
    {
        $this->offerPublishing();

        $this->registerModelBindings();
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
            __DIR__ . '/../config/addresses.php' => $this->app->configPath('addresses.php'),
        ], 'addresses-config');

        // Migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/create_blax_address_tables.php.stub' => $this->getMigrationFileName('create_blax_address_tables.php'),
        ], 'addresses-migrations');
    }

    /**
     * Returns an existing migration file if one is already published,
     * otherwise generates a timestamped path.
     */
    protected function getMigrationFileName(string $migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        $filesystem = $this->app->make(\Illuminate\Filesystem\Filesystem::class);

        return \Illuminate\Support\Collection::make([
            $this->app->databasePath() . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR,
        ])
            ->flatMap(fn($path) => $filesystem->glob($path . '*_' . $migrationFileName))
            ->push($this->app->databasePath() . "/migrations/{$timestamp}_{$migrationFileName}")
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
            \Blax\Addresses\Models\Address::class,
            fn($app) => $app->make($app->config['addresses.models.address'])
        );

        $this->app->bind(
            \Blax\Addresses\Models\AddressLink::class,
            fn($app) => $app->make($app->config['addresses.models.address_link'])
        );

        $this->app->bind(
            \Blax\Addresses\Models\AddressAssignment::class,
            fn($app) => $app->make($app->config['addresses.models.address_assignment'])
        );
    }
}
