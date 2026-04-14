# Customization

## Custom Model Classes

You can extend any of the three package models with your own. This is useful for adding custom methods, relationships, accessors or validation logic.

### 1. Create your custom model

```php
// app/Models/CustomAddress.php

namespace App\Models;

use Blax\Addresses\Models\Address as BaseAddress;

class CustomAddress extends BaseAddress
{
    public function getFullAddressAttribute(): string
    {
        return "{$this->street}, {$this->postal_code} {$this->city}, {$this->country_code}";
    }

    public function geocode(): self
    {
        // your geocoding logic
        return $this;
    }
}
```

### 2. Update the config

```php
// config/addresses.php

'models' => [
    'address'            => \App\Models\CustomAddress::class,
    'address_link'       => \Blax\Addresses\Models\AddressLink::class,
    'address_assignment' => \Blax\Addresses\Models\AddressAssignment::class,
],
```

The package resolves all model classes through `config('addresses.models.…')`, so your custom class will be used everywhere — in relationships, service methods, and traits.

### Example: Custom AddressLink

```php
namespace App\Models;

use Blax\Addresses\Models\AddressLink as BaseAddressLink;

class CustomAddressLink extends BaseAddressLink
{
    protected static function booted()
    {
        static::creating(function (self $link) {
            // Auto-set label from type if not provided
            if (! $link->label) {
                $link->label = $link->type->label();
            }
        });
    }
}
```

```php
'models' => [
    'address_link' => \App\Models\CustomAddressLink::class,
],
```

---

## Custom Table Names

Change the table names if they collide with existing tables in your application:

```php
// config/addresses.php

'table_names' => [
    'addresses'           => 'company_addresses',
    'address_links'       => 'company_address_links',
    'address_assignments' => 'company_address_assignments',
],
```

**Important:** Update the published migration to match these names before running `php artisan migrate`. The migration stub reads from the config, so if you publish the config first and then the migration, the table names will be picked up automatically.

---

## Custom Default Link Type

Change the default `AddressLinkType` applied when adding an address without specifying a type:

```php
// config/addresses.php

'default_link_type' => \Blax\Addresses\Enums\AddressLinkType::Home,
```

Now `$user->addAddress(['city' => 'Vienna'])` will use `Home` instead of `Other`.

---

## Using the Meta Column

All three models (`Address`, `AddressLink`, `AddressAssignment`) include a `meta` JSON column for storing arbitrary data. The column is cast to `object`.

```php
// On addresses — store extra data
$link = $user->addAddress([
    'street' => 'Main Street 1',
    'city'   => 'Vienna',
    'meta'   => [
        'plus_code'  => '8FWR4HCJ+XX',
        'what3words' => 'index.home.raft',
        'timezone'   => 'Europe/Vienna',
    ],
]);

// On address links — store context about the relationship
$link = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Office, [
    'meta' => [
        'department' => 'Engineering',
        'access_code' => '4521',
    ],
]);

// On address assignments — store context about the assignment
$job->assignAddressLink($link, 'delivery', [
    'meta' => [
        'time_window' => '09:00-12:00',
        'priority'    => 'express',
    ],
]);

// Reading meta
$address->meta->plus_code;       // "8FWR4HCJ+XX"
$link->meta->department;         // "Engineering"
$assignment->meta->time_window;  // "09:00-12:00"
```

---

## Model Bindings

The service provider registers model bindings so that resolving a package model through the container returns the configured (possibly customised) class:

```php
// Always resolves to your custom class if configured
$address = app(\Blax\Addresses\Models\Address::class);
```

This means type-hinting the base class in dependency injection will automatically resolve to your custom model.

---

## Extending the AddressService

The `AddressService` is registered as a singleton. To add custom methods, extend it and rebind:

```php
namespace App\Services;

use Blax\Addresses\Services\AddressService;
use Blax\Addresses\Models\Address;

class CustomAddressService extends AddressService
{
    public function geocode(Address $address): Address
    {
        // your geocoding implementation
        return $address;
    }
}
```

In a service provider:

```php
$this->app->singleton(
    \Blax\Addresses\Services\AddressService::class,
    \App\Services\CustomAddressService::class
);
```

The `address()` helper and all DI injection will now resolve your custom service.
