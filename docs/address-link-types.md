# AddressLinkType Enum

`Blax\Addresses\Enums\AddressLinkType` is a PHP 8.1 backed string enum with 17 cases. It describes the purpose of an address link — **why** a particular address is attached to a model.

## Usage

```php
use Blax\Addresses\Enums\AddressLinkType;

// When adding an address
$user->addAddress(['city' => 'Vienna'], AddressLinkType::Office);

// As a string value
$user->addAddress(['city' => 'Vienna'], 'office');

// Get the human-readable label
AddressLinkType::Office->label(); // "Office"

// Access the backing value
AddressLinkType::Office->value; // "office"

// Create from a string
$type = AddressLinkType::from('office');       // AddressLinkType::Office
$type = AddressLinkType::tryFrom('unknown');   // null

// List all cases
AddressLinkType::cases(); // array of all 17 cases
```

## All Types

### Residential

| Case                 | Value                 | Label               | Description                    |
|----------------------|-----------------------|---------------------|--------------------------------|
| `Home`               | `home`                | Home                | Primary living / home address  |
| `SecondaryResidence` | `secondary_residence` | Secondary Residence | Holiday home, second apartment |

### Business / Work

| Case           | Value          | Label        | Description                   |
|----------------|----------------|--------------|-------------------------------|
| `Office`       | `office`       | Office       | General office address        |
| `Headquarters` | `headquarters` | Headquarters | Company headquarters          |
| `Branch`       | `branch`       | Branch       | Branch or satellite office    |
| `Factory`      | `factory`      | Factory      | Factory or production site    |
| `Warehouse`    | `warehouse`    | Warehouse    | Warehouse or storage facility |

### Logistics & Shipping

| Case       | Value      | Label    | Description                         |
|------------|------------|----------|-------------------------------------|
| `Shipping` | `shipping` | Shipping | Shipping / delivery address         |
| `Billing`  | `billing`  | Billing  | Billing / invoicing address         |
| `Return`   | `return`   | Return   | Return / reverse-logistics address  |
| `Pickup`   | `pickup`   | Pick-up  | Pick-up point (parcel locker, shop) |

### Special Purpose

| Case              | Value               | Label             | Description                          |
|-------------------|---------------------|-------------------|--------------------------------------|
| `PointOfInterest` | `point_of_interest` | Point of Interest | Landmark, monument, notable location |
| `Site`            | `site`              | Site              | Construction or project site         |
| `Temporary`       | `temporary`         | Temporary         | Temporary / event-based address      |
| `Contact`         | `contact`           | Contact           | Correspondence address               |
| `Legal`           | `legal`             | Legal             | Registered / legal address           |

### Catch-All

| Case    | Value   | Label | Description                   |
|---------|---------|-------|-------------------------------|
| `Other` | `other` | Other | Any purpose not covered above |

## Using `Other` with labels

When none of the 17 types fit, use `Other` and set a `label` on the address link for detail:

```php
$user->addAddress([
    'city' => 'Munich',
], AddressLinkType::Other, [
    'label' => 'Emergency Shelter',
]);
```

## Multiple addresses of the same type

A model can have multiple addresses of the same type — for example, two offices:

```php
$link1 = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Office, [
    'label' => 'Vienna Office',
]);
$link2 = $user->addAddress(['city' => 'Berlin'], AddressLinkType::Office, [
    'label' => 'Berlin Office',
]);

// Set one as primary
$user->setPrimaryAddressLink($link1->id);

// Query
$user->addressesOfType(AddressLinkType::Office); // both
$user->primaryAddress(AddressLinkType::Office);   // Vienna
```

## Filtering with query scopes

`AddressLink` provides scopes for filtering by type:

```php
use Blax\Addresses\Models\AddressLink;

// All office links across all models
AddressLink::ofType(AddressLinkType::Office)->get();

// Combined with other scopes
AddressLink::ofType(AddressLinkType::Office)->active()->primary()->get();
```

## Default type

When no type is specified, the config default is used:

```php
// config/addresses.php
'default_link_type' => AddressLinkType::Other,

// These are equivalent:
$user->addAddress(['city' => 'Vienna']);
$user->addAddress(['city' => 'Vienna'], AddressLinkType::Other);
```
