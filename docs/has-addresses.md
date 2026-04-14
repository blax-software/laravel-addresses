# HasAddresses Trait

Add the `HasAddresses` trait to any Eloquent model that **owns** addresses.

```php
use Blax\Addresses\Traits\HasAddresses;

class User extends Model
{
    use HasAddresses;
}

class Company extends Model
{
    use HasAddresses;
}
```

## Relationships

### `addressLinks()`

Returns all `AddressLink` pivot rows for this model.

```php
$links = $user->addressLinks;

foreach ($links as $link) {
    echo $link->type->label();  // "Office"
    echo $link->label;          // "Main Office"
    echo $link->address->city;  // "Vienna"
}
```

**Return:** `MorphMany` of `AddressLink`

### `addresses()`

Returns all `Address` models linked to this model (many-to-many through `address_links`). Pivot columns are included automatically.

```php
$addresses = $user->addresses;

foreach ($addresses as $address) {
    echo $address->city;
    echo $address->pivot->type;       // "office"
    echo $address->pivot->is_primary; // true/false
}
```

**Return:** `MorphToMany` of `Address` (with pivot: `id`, `type`, `label`, `is_primary`, `active_from`, `active_until`, `meta`)

---

## Adding Addresses

### `addAddress(array $attributes, AddressLinkType|string|null $type = null, array $pivot = []): AddressLink`

Creates a new `Address` record and links it to this model in one step.

```php
use Blax\Addresses\Enums\AddressLinkType;

// Minimal
$link = $user->addAddress([
    'city'         => 'Vienna',
    'country_code' => 'AT',
]);

// With type
$link = $user->addAddress([
    'street'       => '350 Fifth Avenue',
    'city'         => 'New York',
    'postal_code'  => '10118',
    'country_code' => 'US',
], AddressLinkType::Office);

// With type and pivot data
$link = $user->addAddress([
    'street'       => 'Baker Street 221B',
    'city'         => 'London',
    'country_code' => 'GB',
], AddressLinkType::Home, [
    'label'      => 'Primary Residence',
    'is_primary' => true,
    'meta'       => ['floor' => 'ground'],
]);
```

**Parameters:**
- `$attributes` — Address fields (street, city, latitude, etc.)
- `$type` — `AddressLinkType` enum, string value, or `null` (uses config default)
- `$pivot` — Extra link data: `label`, `is_primary`, `active_from`, `active_until`, `meta`

**Returns:** The created `AddressLink` with the `address` relation loaded.

### `linkAddress(Address|int $address, AddressLinkType|string|null $type = null, array $pivot = []): AddressLink`

Links an **existing** address to this model. Useful when the same address should be shared by multiple models.

```php
use Blax\Addresses\Models\Address;

$address = Address::create([
    'street'       => 'Shared Office Road 1',
    'city'         => 'Berlin',
    'country_code' => 'DE',
]);

// Link to multiple models
$user->linkAddress($address, AddressLinkType::Office);
$company->linkAddress($address, AddressLinkType::Headquarters);

// Also accepts an address ID
$user->linkAddress($address->id, AddressLinkType::Home);
```

**Returns:** The created `AddressLink` with the `address` relation loaded.

---

## Removing Addresses

### `removeAddressLink(int $linkId): bool`

Removes a specific address link by its pivot ID. The address record is preserved.

```php
$link = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Office);

$user->removeAddressLink($link->id); // true
```

### `detachAddress(Address|int $address): int`

Removes **all** links between this model and a specific address.

```php
// If the user has multiple links to the same address (e.g. Office + Billing)
$removed = $user->detachAddress($address); // 2
```

**Returns:** Number of links removed.

### `detachAllAddresses(): int`

Removes all address links from this model.

```php
$removed = $user->detachAllAddresses(); // 5
```

**Returns:** Number of links removed.

> **Note:** These methods only remove the `AddressLink` pivot rows. The `Address` records themselves are never deleted by these operations.

---

## Querying

### `addressesOfType(AddressLinkType|string $type): Collection`

Get all addresses linked with a specific type.

```php
$offices = $user->addressesOfType(AddressLinkType::Office);
$homes   = $user->addressesOfType('home'); // string value also works
```

### `activeAddressLinks(): Collection`

Get all address links that are currently active (respecting `active_from` / `active_until`).

```php
$activeLinks = $user->activeAddressLinks();

foreach ($activeLinks as $link) {
    echo $link->address->formatted;
}
```

A link is active when:
- `active_from` is `null` OR in the past/present **AND**
- `active_until` is `null` OR in the future

### `primaryAddress(AddressLinkType|string|null $type = null): ?Address`

Get the primary address, optionally filtered by type.

```php
// Primary address across all types
$primary = $user->primaryAddress();

// Primary office address specifically
$primaryOffice = $user->primaryAddress(AddressLinkType::Office);
```

**Returns:** `Address` or `null`.

### `hasAddresses(): bool`

Check whether this model has any address linked.

```php
if ($user->hasAddresses()) {
    // ...
}
```

### `hasAddressOfType(AddressLinkType|string $type): bool`

Check whether this model has an address of a specific type.

```php
if (! $user->hasAddressOfType(AddressLinkType::Billing)) {
    // prompt user to add a billing address
}
```

---

## Updating

### `setPrimaryAddressLink(int $linkId): bool`

Set an address link as the primary for its type. Automatically unsets any previous primary of the same type on this model.

```php
$officeA = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Office);
$officeB = $user->addAddress(['city' => 'Berlin'], AddressLinkType::Office);

$user->setPrimaryAddressLink($officeA->id); // Vienna is primary
$user->setPrimaryAddressLink($officeB->id); // Berlin is now primary, Vienna is unset
```

**Returns:** `true` on success, `false` if the link ID was not found.

---

## Temporal Validity

Address links support time-based activation via `active_from` and `active_until`:

```php
$link = $user->addAddress([
    'street' => 'Summer Cottage Lane 3',
    'city'   => 'Hallstatt',
], AddressLinkType::SecondaryResidence, [
    'active_from'  => '2025-06-01',
    'active_until' => '2025-09-01',
]);

// Check if a specific link is active
$link->isActive(); // depends on current date

// Get only active links
$user->activeAddressLinks();

// Query scopes on AddressLink
AddressLink::active()->get();
AddressLink::expired()->get();
```

---

## Working with the pivot

When accessing addresses through the `addresses()` relationship, all pivot columns are available:

```php
foreach ($user->addresses as $address) {
    $address->pivot->type;         // "office"
    $address->pivot->label;        // "Main Office"
    $address->pivot->is_primary;   // true
    $address->pivot->active_from;  // "2025-01-01 00:00:00"
    $address->pivot->active_until; // null
    $address->pivot->meta;         // "{...}"
}
```

---

## Full Example

```php
use App\Models\User;
use Blax\Addresses\Enums\AddressLinkType;

$user = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

// Add a home address (primary)
$home = $user->addAddress([
    'street'       => 'Musterstraße 42',
    'postal_code'  => '1010',
    'city'         => 'Vienna',
    'country_code' => 'AT',
    'latitude'     => 48.2082,
    'longitude'    => 16.3738,
], AddressLinkType::Home, [
    'is_primary' => true,
]);

// Add an office address
$office = $user->addAddress([
    'street'       => 'Kärntner Straße 21',
    'postal_code'  => '1010',
    'city'         => 'Vienna',
    'country_code' => 'AT',
], AddressLinkType::Office, [
    'label' => 'Downtown Office',
]);

// Query
$user->hasAddresses();                             // true
$user->hasAddressOfType(AddressLinkType::Shipping); // false
$user->primaryAddress(AddressLinkType::Home);       // → Address { Musterstraße 42 }
$user->addressesOfType(AddressLinkType::Office);    // → Collection with 1 Address

// Switch primary
$newHome = $user->addAddress(['city' => 'Graz'], AddressLinkType::Home);
$user->setPrimaryAddressLink($newHome->id);
$user->primaryAddress(AddressLinkType::Home); // → Address { city: Graz }

// Clean up
$user->removeAddressLink($office->id); // remove office link
$user->detachAllAddresses();           // remove everything
```
