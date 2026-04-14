# Core Concepts

## The Three-Layer Architecture

Laravel Addresses uses three models that work together:

```
Address
  └── AddressLink
        └── AddressAssignment
```

Each layer has a distinct responsibility:

### Layer 1: Address

The **physical place**. An `Address` is a standalone record containing street data, postal information, and optional GPS coordinates. It knows nothing about who uses it or why.

```php
Address::create([
    'street'       => '350 Fifth Avenue',
    'building'     => 'Empire State Building',
    'floor'        => '32',
    'room'         => '3201',
    'postal_code'  => '10118',
    'city'         => 'New York',
    'state'        => 'NY',
    'country_code' => 'US',
    'latitude'     => 40.748817,
    'longitude'    => -73.985428,
    'altitude'     => 443.0,
]);
```

All fields are nullable — an address can be as minimal as a GPS coordinate pair or as detailed as a full postal address with indoor precision.

**Available fields:**

| Field          | Type   | Description                                    |
|----------------|--------|------------------------------------------------|
| `street`       | string | Street name + house number                     |
| `street_extra` | string | c/o, suite, P.O. box                           |
| `building`     | string | Building or complex name                       |
| `floor`        | string | Floor/level (supports "GF", "B2", "Mezzanine") |
| `room`         | string | Room, suite or unit identifier                 |
| `postal_code`  | string | Postal / ZIP code                              |
| `city`         | string | City, town, village                            |
| `state`        | string | State, province, canton                        |
| `county`       | string | County, district                               |
| `country_code` | string | ISO 3166-1 alpha-2 ("US", "AT", "JP")          |
| `latitude`     | float  | WGS-84 decimal degrees (−90 … +90)             |
| `longitude`    | float  | WGS-84 decimal degrees (−180 … +180)           |
| `altitude`     | float  | Metres above mean sea level (AMSL)             |
| `notes`        | text   | Free-form notes, delivery instructions         |
| `meta`         | JSON   | Arbitrary extra data                           |

Addresses use **soft deletes** — calling `$address->delete()` sets `deleted_at` instead of removing the row.

### Layer 2: AddressLink

The **ownership pivot**. An `AddressLink` connects an `Address` to an Eloquent model (User, Company, Order …) and describes the **purpose** of that connection.

```php
$link = $user->addAddress([
    'street'       => 'Stephansplatz 1',
    'city'         => 'Vienna',
    'country_code' => 'AT',
], AddressLinkType::Office, [
    'label'      => 'Main Office',
    'is_primary' => true,
]);

// $link->type  → AddressLinkType::Office
// $link->label → "Main Office"
// $link->address → the Address model
```

**Key properties:**

| Property       | Type            | Description                                   |
|----------------|-----------------|-----------------------------------------------|
| `type`         | AddressLinkType | The purpose (Home, Office, Shipping …)        |
| `label`        | string\|null    | Free-text label to refine the type            |
| `is_primary`   | bool            | Whether this is the primary link for its type |
| `active_from`  | datetime\|null  | When the link becomes effective               |
| `active_until` | datetime\|null  | When the link expires                         |
| `meta`         | object\|null    | Arbitrary JSON data                           |

**Important:** The same address can be linked to multiple models, and each model can have multiple addresses. A user can have a Home address, an Office address, and a Billing address — each as a separate `AddressLink`.

**Polymorphic:** Uses `addressable_type` / `addressable_id` morphs, so any Eloquent model can own addresses.

### Layer 3: AddressAssignment

The **contextual reference**. An `AddressAssignment` lets one model reference another model's address link without owning the address.

**The problem it solves:** A transport job needs a pickup and delivery address. Those addresses belong to the customer, not the job. Instead of duplicating address data, the job *assigns* the customer's existing address links.

```php
// User owns the address
$link = $user->addAddress([
    'street' => 'Kärntner Straße 21',
    'city'   => 'Vienna',
], AddressLinkType::Office);

// Job references it as "pickup"
$job->assignAddressLink($link, 'pickup');

// Later, retrieve it
$pickupAddress = $job->assignedAddressForRole('pickup');
// → Address { street: "Kärntner Straße 21", city: "Vienna" }
```

**Key properties:**

| Property          | Type         | Description                                       |
|-------------------|--------------|---------------------------------------------------|
| `address_link_id` | int          | FK to the address link being referenced           |
| `role`            | string\|null | Context-specific purpose ("pickup", "delivery" …) |
| `label`           | string\|null | Free-text label                                   |
| `meta`            | object\|null | Arbitrary JSON data                               |

## Cascade Behaviour

- **Deleting an Address** → all its `AddressLink` rows are cascade-deleted
- **Deleting an AddressLink** → all its `AddressAssignment` rows are cascade-deleted
- Addresses use **soft deletes**; links and assignments use **hard deletes**

## Traits

The package provides two traits to add to your Eloquent models:

| Trait                   | Purpose                           | Use on                                              |
|-------------------------|-----------------------------------|-----------------------------------------------------|
| `HasAddresses`          | Own and manage addresses          | Models that **have** addresses (User, Company …)    |
| `HasAddressAssignments` | Reference other models' addresses | Models that **use** addresses (Job, Order, Event …) |

A model can use both traits if it both owns and references addresses.

## The AddressService

A singleton service for operations that go beyond CRUD:

```php
// Distance between two addresses (Haversine)
address()->distanceBetween($a, $b);

// Find nearby addresses
address()->nearby($lat, $lng, $radiusKm);

// Detect duplicates
address()->findDuplicates($address);

// Format for display
address()->formatMultiline($address);
```

Access via the `address()` helper or dependency injection:

```php
use Blax\Addresses\Services\AddressService;

public function __construct(private AddressService $addressService) {}
```

See the individual documentation pages for complete API references:

- [HasAddresses Trait](has-addresses.md)
- [HasAddressAssignments Trait](has-address-assignments.md)
- [AddressService](address-service.md)
- [AddressLinkType Enum](address-link-types.md)
- [Customization](customization.md)
