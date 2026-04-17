<?php

namespace Blax\Addresses\Traits;

use Blax\Addresses\Enums\AddressLinkType;
use Blax\Addresses\Models\Address;
use Blax\Addresses\Models\AddressLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Adds address management capabilities to any Eloquent model.
 *
 * Usage:
 *   class Customer extends Model {
 *       use \Blax\Addresses\Traits\HasAddresses;
 *   }
 *
 *   $customer->addAddress([…], AddressLinkType::Office);
 *   $customer->addresses;                 // all linked addresses
 *   $customer->addressesOfType('billing'); // filtered by type
 */
trait HasAddresses
{
    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * All address links for this model (polymorphic one-to-many through pivot).
     *
     * Access the actual Address via `$link->address`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function addressLinks()
    {
        return $this->morphMany(
            config('addresses.models.address_link', AddressLink::class),
            'addressable'
        );
    }

    /**
     * All addresses attached to this model (many-to-many through address_links).
     *
     * Pivot columns (type, label, is_primary, active_from, active_until, meta)
     * are included automatically.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function addresses()
    {
        $linkTable = config('addresses.table_names.address_links', 'address_links');

        return $this->morphToMany(
            config('addresses.models.address', Address::class),
            'addressable',
            $linkTable,
        )->withPivot('id', 'type', 'label', 'is_primary', 'active_from', 'active_until', 'meta')
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | Adding / attaching
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new address and link it to this model in one step.
     *
     * @param  array                       $attributes  Address attributes (street, city, …)
     * @param  AddressLinkType|string|null  $type        Purpose of the link (default from config)
     * @param  array                       $pivot       Extra pivot data (label, is_primary, active_from, active_until, meta)
     * @return AddressLink                              The created link (with address relation loaded)
     */
    public function addAddress(array $attributes, AddressLinkType|string|null $type = null, array $pivot = []): AddressLink
    {
        $addressModel = config('addresses.models.address', Address::class);

        /** @var Address $address */
        $address = $addressModel::create($attributes);

        return $this->linkAddress($address, $type, $pivot);
    }

    /**
     * Link an existing address to this model.
     *
     * @param  Address|string              $address  Address model or ID
     * @param  AddressLinkType|string|null $type     Purpose of the link
     * @param  array                      $pivot    Extra pivot data
     * @return AddressLink                          The created link
     */
    public function linkAddress(Address|string $address, AddressLinkType|string|null $type = null, array $pivot = []): AddressLink
    {
        $addressId = $address instanceof Address ? $address->id : $address;

        $type = $type instanceof AddressLinkType
            ? $type->value
            : ($type ?? config('addresses.default_link_type', AddressLinkType::Other)->value);

        $linkModel = config('addresses.models.address_link', AddressLink::class);

        /** @var AddressLink $link */
        $link = $linkModel::create(array_merge([
            'address_id'       => $addressId,
            'addressable_type' => $this->getMorphClass(),
            'addressable_id'   => $this->getKey(),
            'type'             => $type,
        ], $pivot));

        $link->load('address');

        return $link;
    }

    /*
    |--------------------------------------------------------------------------
    | Removing / detaching
    |--------------------------------------------------------------------------
    */

    /**
     * Remove a specific address link by its pivot ID.
     *
     * This only removes the link — the address itself is preserved.
     *
     * @param  int|string  $linkId  The AddressLink ID
     * @return bool
     */
    public function removeAddressLink(int|string $linkId): bool
    {
        return (bool) $this->addressLinks()->where('id', $linkId)->delete();
    }

    /**
     * Detach an address completely from this model (removes all links to it).
     *
     * The address record itself is preserved.
     *
     * @param  Address|string  $address  Address model or ID
     * @return int  Number of links removed
     */
    public function detachAddress(Address|string $address): int
    {
        $addressId = $address instanceof Address ? $address->id : $address;

        return $this->addressLinks()->where('address_id', $addressId)->delete();
    }

    /**
     * Remove all address links from this model.
     *
     * Address records remain untouched.
     *
     * @return int  Number of links removed
     */
    public function detachAllAddresses(): int
    {
        return $this->addressLinks()->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Querying
    |--------------------------------------------------------------------------
    */

    /**
     * Get all addresses of a specific link type.
     *
     * @param  AddressLinkType|string  $type
     * @return Collection<Address>
     */
    public function addressesOfType(AddressLinkType|string $type): Collection
    {
        $value = $type instanceof AddressLinkType ? $type->value : $type;

        return $this->addresses()
            ->wherePivot('type', $value)
            ->get();
    }

    /**
     * Get all currently active address links.
     *
     * @return Collection<AddressLink>
     */
    public function activeAddressLinks(): Collection
    {
        return $this->addressLinks()
            ->where(function ($q) {
                $q->whereNull('active_from')
                    ->orWhere('active_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('active_until')
                    ->orWhere('active_until', '>', now());
            })
            ->with('address')
            ->get();
    }

    /**
     * Get the primary address for a given type (or across all types if null).
     *
     * @param  AddressLinkType|string|null  $type
     * @return Address|null
     */
    public function primaryAddress(AddressLinkType|string|null $type = null): ?Address
    {
        $query = $this->addressLinks()
            ->where('is_primary', true);

        if ($type !== null) {
            $value = $type instanceof AddressLinkType ? $type->value : $type;
            $query->where('type', $value);
        }

        /** @var AddressLink|null $link */
        $link = $query->with('address')->first();

        return $link?->address;
    }

    /**
     * Check whether this model has any address linked.
     */
    public function hasAddresses(): bool
    {
        return $this->addressLinks()->exists();
    }

    /**
     * Check whether this model has an address of the given type.
     *
     * @param  AddressLinkType|string  $type
     */
    public function hasAddressOfType(AddressLinkType|string $type): bool
    {
        $value = $type instanceof AddressLinkType ? $type->value : $type;

        return $this->addressLinks()->where('type', $value)->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Updating
    |--------------------------------------------------------------------------
    */

    /**
     * Set an address link as the primary for its type, unsetting any previous primary.
     *
     * @param  int|string  $linkId  The AddressLink ID to promote
     * @return bool
     */
    public function setPrimaryAddressLink(int|string $linkId): bool
    {
        $linkModel = config('addresses.models.address_link', AddressLink::class);

        /** @var AddressLink|null $link */
        $link = $this->addressLinks()->where('id', $linkId)->first();

        if (! $link) {
            return false;
        }

        // Unset any existing primary for the same type on this model.
        $this->addressLinks()
            ->where('type', $link->type instanceof AddressLinkType ? $link->type->value : $link->type)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);

        $link->update(['is_primary' => true]);

        return true;
    }
}
