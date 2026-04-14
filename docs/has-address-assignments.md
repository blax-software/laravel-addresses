# HasAddressAssignments Trait

Add the `HasAddressAssignments` trait to any Eloquent model that **references** addresses owned by other models.

```php
use Blax\Addresses\Traits\HasAddressAssignments;

class Job extends Model
{
    use HasAddressAssignments;
}

class Order extends Model
{
    use HasAddressAssignments;
}
```

## When to use this

Use `HasAddressAssignments` when a model needs to reference an address that it does **not** own. Instead of duplicating address data, the model creates an assignment that points to an existing `AddressLink`.

**Example:** A transport job needs a pickup address and a delivery address. Those addresses belong to customers. The job *assigns* the customers' address links to itself with context-specific roles.

```php
// Customer owns the address
$pickupLink   = $customer->addAddress(['city' => 'Vienna'], AddressLinkType::Office);
$deliveryLink = $recipient->addAddress(['city' => 'Berlin'], AddressLinkType::Home);

// Job references them
$job->assignAddressLink($pickupLink, 'pickup');
$job->assignAddressLink($deliveryLink, 'delivery');
```

> A model can use **both** `HasAddresses` and `HasAddressAssignments` if it both owns and references addresses.

---

## Relationship

### `addressAssignments()`

Returns all `AddressAssignment` records for this model.

```php
$assignments = $job->addressAssignments;

foreach ($assignments as $assignment) {
    echo $assignment->role;                     // "pickup"
    echo $assignment->addressLink->type->label(); // "Office"
    echo $assignment->addressLink->address->city; // "Vienna"
}
```

**Return:** `MorphMany` of `AddressAssignment`

---

## Assigning

### `assignAddressLink(AddressLink|int $addressLink, ?string $role = null, array $extra = []): AddressAssignment`

Assign an existing address link to this model.

```php
use Blax\Addresses\Enums\AddressLinkType;

$link = $user->addAddress([
    'street'       => 'Kärntner Straße 21',
    'city'         => 'Vienna',
    'country_code' => 'AT',
], AddressLinkType::Office);

// Assign with a role
$assignment = $job->assignAddressLink($link, 'pickup');

// Assign with extra attributes
$assignment = $job->assignAddressLink($link, 'delivery', [
    'label' => 'Customer Office Delivery',
    'meta'  => ['priority' => 'express', 'time_window' => '09:00-12:00'],
]);

// Assign by link ID
$assignment = $job->assignAddressLink($link->id, 'origin');
```

**Parameters:**
- `$addressLink` — `AddressLink` model or its ID
- `$role` — Context-specific purpose string (e.g. "pickup", "delivery", "origin", "billing")
- `$extra` — Additional attributes: `label`, `meta`

**Returns:** The created `AddressAssignment` with `addressLink.address` loaded.

---

## Removing

### `removeAddressAssignment(int $assignmentId): bool`

Remove a specific assignment by its ID.

```php
$assignment = $job->assignAddressLink($link, 'pickup');
$job->removeAddressAssignment($assignment->id); // true
```

### `removeAssignmentsForRole(string $role): int`

Remove all assignments for a specific role.

```php
// Remove all "pickup" assignments
$removed = $job->removeAssignmentsForRole('pickup'); // 1
```

**Returns:** Number of assignments removed.

### `removeAllAddressAssignments(): int`

Remove all address assignments from this model.

```php
$removed = $job->removeAllAddressAssignments(); // 3
```

**Returns:** Number of assignments removed.

---

## Querying

### `addressAssignmentForRole(string $role): ?AddressAssignment`

Get the first assignment for a specific role (eager-loads the address link and address).

```php
$assignment = $job->addressAssignmentForRole('pickup');

if ($assignment) {
    echo $assignment->role;                       // "pickup"
    echo $assignment->addressLink->address->city; // "Vienna"
}
```

### `addressAssignmentsForRole(string $role): Collection`

Get **all** assignments for a specific role. Useful when a model has multiple addresses for the same role.

```php
// A job with multiple stops
$job->assignAddressLink($linkA, 'stop');
$job->assignAddressLink($linkB, 'stop');
$job->assignAddressLink($linkC, 'stop');

$stops = $job->addressAssignmentsForRole('stop'); // Collection of 3
```

### `assignedAddressForRole(string $role): ?Address`

Convenience shortcut — returns the `Address` model directly for a role.

```php
$pickupAddress = $job->assignedAddressForRole('pickup');
echo $pickupAddress->formatted; // "Kärntner Straße 21, 1010, Vienna, AT"
```

**Returns:** `Address` or `null`.

### `assignedAddresses(): Collection`

Get all addresses assigned to this model (through their links).

```php
$addresses = $job->assignedAddresses();

foreach ($addresses as $address) {
    echo $address->city;
}
```

**Returns:** `Collection` of `Address` models.

### `hasAddressAssignments(): bool`

Check whether this model has any address assignments.

```php
if ($job->hasAddressAssignments()) {
    // ...
}
```

### `hasAssignmentForRole(string $role): bool`

Check whether this model has an assignment for a specific role.

```php
if (! $job->hasAssignmentForRole('delivery')) {
    // prompt to assign a delivery address
}
```

---

## Cascade Behaviour

When an `AddressLink` is deleted (e.g. because the user removes their office address), all `AddressAssignment` rows referencing that link are **cascade-deleted** at the database level.

```php
// User removes their office address link
$user->removeAddressLink($officeLink->id);

// All jobs that referenced this link automatically lose their assignment
$job->hasAssignmentForRole('pickup'); // false
```

---

## Full Example

```php
use App\Models\User;
use App\Models\Job;
use Blax\Addresses\Enums\AddressLinkType;

// Setup: users own addresses
$sender = User::create(['name' => 'Alice']);
$receiver = User::create(['name' => 'Bob']);

$senderOffice = $sender->addAddress([
    'street'       => 'Stephansplatz 1',
    'city'         => 'Vienna',
    'country_code' => 'AT',
], AddressLinkType::Office);

$receiverHome = $receiver->addAddress([
    'street'       => 'Unter den Linden 77',
    'city'         => 'Berlin',
    'country_code' => 'DE',
], AddressLinkType::Home);

// Job references both addresses
$job = Job::create(['title' => 'Piano Transport #42']);

$job->assignAddressLink($senderOffice, 'pickup', [
    'label' => "Alice's Office",
]);

$job->assignAddressLink($receiverHome, 'delivery', [
    'label' => "Bob's Home",
    'meta'  => ['floor' => 3, 'elevator' => false],
]);

// Query
$job->assignedAddressForRole('pickup')->city;   // "Vienna"
$job->assignedAddressForRole('delivery')->city;  // "Berlin"
$job->assignedAddresses()->count();              // 2
$job->hasAssignmentForRole('pickup');             // true
$job->hasAssignmentForRole('billing');            // false

// Clean up a single assignment
$job->removeAssignmentsForRole('delivery');
$job->assignedAddresses()->count(); // 1

// Clean up everything
$job->removeAllAddressAssignments();
```
