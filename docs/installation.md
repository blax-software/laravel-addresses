# Installation & Configuration

## Requirements

- PHP 8.1 or higher
- Laravel 9.x, 10.x, 11.x or 12.x
- `blax-software/laravel-workkit` (pulled in automatically as a dependency)

## Install via Composer

```bash
composer require blax-software/laravel-addresses
```

The service provider is registered automatically via Laravel's package auto-discovery.

## Publish Migrations

```bash
php artisan vendor:publish --tag="addresses-migrations"
php artisan migrate
```

This creates three tables:

| Table                 | Purpose                                                |
|-----------------------|--------------------------------------------------------|
| `addresses`           | Physical address records (street, city, coordinates …) |
| `address_links`       | Polymorphic pivot connecting addresses to models       |
| `address_assignments` | References an address link from another model context  |

## Publish Configuration (optional)

```bash
php artisan vendor:publish --tag="addresses-config"
```

This copies `config/addresses.php` into your application's `config/` directory.

## Configuration Reference

```php
// config/addresses.php

return [
    /*
    |--------------------------------------------------------------------------
    | Model Classes
    |--------------------------------------------------------------------------
    |
    | Override with your own model classes if you need to extend the package
    | models. Your custom models should extend the corresponding package model.
    |
    */
    'models' => [
        'address'            => \Blax\Addresses\Models\Address::class,
        'address_link'       => \Blax\Addresses\Models\AddressLink::class,
        'address_assignment' => \Blax\Addresses\Models\AddressAssignment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Change these if the default names collide with existing tables.
    |
    */
    'table_names' => [
        'addresses'           => 'addresses',
        'address_links'       => 'address_links',
        'address_assignments' => 'address_assignments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Address Link Type
    |--------------------------------------------------------------------------
    |
    | Applied when attaching an address without specifying a type.
    |
    */
    'default_link_type' => \Blax\Addresses\Enums\AddressLinkType::Other,
];
```

### Models

Each model key maps to a fully-qualified class name. To customise behaviour, create your own model that extends the package model and update the config:

```php
'models' => [
    'address' => \App\Models\CustomAddress::class,
    // ...
],
```

See [Customization](customization.md) for details.

### Table Names

All three table names are configurable. If you change them, make sure to update the published migration before running it.

### Default Link Type

When you call `$model->addAddress([...])` without a second argument, this type is used. Defaults to `AddressLinkType::Other`.
