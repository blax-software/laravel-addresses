[![Blax Software OSS](https://raw.githubusercontent.com/blax-software/laravel-workkit/master/art/oss-initiative-banner.svg)](https://github.com/blax-software)

# Laravel Addresses

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-9.x--12.x-orange)](https://laravel.com)

Universal Laravel address management — from rural GPS coordinates to specific rooms inside skyscrapers, worldwide.

## Overview

This package provides a complete address management system for Laravel applications built on a **three-layer architecture**:

```
Address            →  The physical place (street, city, coordinates …)
  └── AddressLink  →  Connects an address to a model with a purpose (User's "Office")
        └── AddressAssignment  →  References a link from another context (Job's "pickup")
```

**Example:** A user has an office address. A job references that office as its pickup location — without duplicating the address data.

## Features

- **15 address fields** — street-level to room-level precision, with GPS coordinates (WGS-84) and altitude
- **Polymorphic links** — attach addresses to any Eloquent model
- **17 built-in link types** — Home, Office, Shipping, Billing, Warehouse and more
- **Address assignments** — reference someone else's address in another context
- **Temporal validity** — `active_from` / `active_until` on every link
- **AddressService** — distance calculations (Haversine), proximity queries, duplicate detection, coordinate conversion
- **Fully configurable** — custom model classes, table names, default link type
- **Soft deletes** on addresses, cascade deletes on links and assignments

## Requirements

- PHP 8.1+
- Laravel 9, 10, 11 or 12
- `blax-software/laravel-workkit` (installed automatically)

## Installation

```bash
composer require blax-software/laravel-addresses
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="addresses-migrations"
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag="addresses-config"
```

## Quick Start

### 1. Add the trait to your model

```php
use Blax\Addresses\Traits\HasAddresses;

class User extends Model
{
    use HasAddresses;
}
```

### 2. Create and attach an address

```php
use Blax\Addresses\Enums\AddressLinkType;

$link = $user->addAddress([
    'street'       => '350 Fifth Avenue',
    'city'         => 'New York',
    'state'        => 'NY',
    'postal_code'  => '10118',
    'country_code' => 'US',
    'latitude'     => 40.748817,
    'longitude'    => -73.985428,
], AddressLinkType::Office);
```

### 3. Query addresses

```php
$user->addresses;                              // all addresses
$user->addressesOfType(AddressLinkType::Office); // only offices
$user->primaryAddress();                        // primary across all types
$user->activeAddressLinks();                    // only currently active links
```

### 4. Assign an address to another model

```php
use Blax\Addresses\Traits\HasAddressAssignments;

class Job extends Model
{
    use HasAddressAssignments;
}

$job->assignAddressLink($link, 'pickup');
$job->assignedAddressForRole('pickup'); // → the Address model
```

### 5. Use the AddressService

```php
// Via helper
$distance = address()->distanceBetween($addressA, $addressB); // km

// Nearby addresses within 10 km
$nearby = address()->nearby(48.2082, 16.3738, 10);

// Format for display
echo address()->formatMultiline($address);
```

## Documentation

| Guide                                                          | Description                                          |
|----------------------------------------------------------------|------------------------------------------------------|
| [Installation & Configuration](docs/installation.md)           | Setup, publishing, config options                    |
| [Core Concepts](docs/core-concepts.md)                         | The three-layer architecture explained               |
| [HasAddresses Trait](docs/has-addresses.md)                    | Full API for address-owning models                   |
| [HasAddressAssignments Trait](docs/has-address-assignments.md) | Full API for address-consuming models                |
| [AddressService](docs/address-service.md)                      | Distance, proximity, formatting, conversion          |
| [AddressLinkType Enum](docs/address-link-types.md)             | All 17 built-in types with descriptions              |
| [Customization](docs/customization.md)                         | Extending models, custom tables, overriding defaults |

## Testing

```bash
composer test
```

## License

MIT
