<?php

namespace Blax\Addresses\Traits;

use Blax\Addresses\Models\Address;
use Blax\Addresses\Models\AddressAssignment;
use Blax\Addresses\Models\AddressLink;
use Illuminate\Support\Collection;

/**
 * Adds address-assignment capabilities to any Eloquent model.
 *
 * Use this trait on models that *consume* addresses owned by other models.
 * For example a `Job` or `Order` that needs to reference a `User`'s office
 * address without owning the address itself.
 *
 * Usage:
 *   class Job extends Model {
 *       use \Blax\Addresses\Traits\HasAddressAssignments;
 *   }
 *
 *   // User owns the address link
 *   $link = $user->addAddress([…], AddressLinkType::Office);
 *
 *   // Job references it
 *   $job->assignAddressLink($link, 'pickup');
 */
trait HasAddressAssignments
{
    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * All address assignments for this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function addressAssignments()
    {
        return $this->morphMany(
            config('addresses.models.address_assignment', AddressAssignment::class),
            'assignable'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Assigning / unassigning
    |--------------------------------------------------------------------------
    */

    /**
     * Assign an existing address link to this model.
     *
     * @param  AddressLink|string  $addressLink  AddressLink model or ID
     * @param  string|null      $role         Context-specific role ("pickup", "delivery", …)
     * @param  array            $extra        Additional attributes (label, meta)
     * @return AddressAssignment
     */
    public function assignAddressLink(AddressLink|string $addressLink, ?string $role = null, array $extra = []): AddressAssignment
    {
        $linkId = $addressLink instanceof AddressLink ? $addressLink->id : $addressLink;

        $assignmentModel = config('addresses.models.address_assignment', AddressAssignment::class);

        /** @var AddressAssignment $assignment */
        $assignment = $assignmentModel::create(array_merge([
            'address_link_id' => $linkId,
            'assignable_type' => $this->getMorphClass(),
            'assignable_id'   => $this->getKey(),
            'role'            => $role,
        ], $extra));

        $assignment->load('addressLink.address');

        return $assignment;
    }

    /**
     * Remove a specific address assignment by its ID.
     *
     * @param  int|string  $assignmentId
     * @return bool
     */
    public function removeAddressAssignment(int|string $assignmentId): bool
    {
        return (bool) $this->addressAssignments()->where('id', $assignmentId)->delete();
    }

    /**
     * Remove all assignments for a specific role.
     *
     * @param  string  $role
     * @return int  Number of assignments removed
     */
    public function removeAssignmentsForRole(string $role): int
    {
        return $this->addressAssignments()->where('role', $role)->delete();
    }

    /**
     * Remove all address assignments from this model.
     *
     * @return int  Number of assignments removed
     */
    public function removeAllAddressAssignments(): int
    {
        return $this->addressAssignments()->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Querying
    |--------------------------------------------------------------------------
    */

    /**
     * Get the assignment for a specific role.
     *
     * @param  string  $role
     * @return AddressAssignment|null
     */
    public function addressAssignmentForRole(string $role): ?AddressAssignment
    {
        return $this->addressAssignments()
            ->where('role', $role)
            ->with('addressLink.address')
            ->first();
    }

    /**
     * Get all assignments for a specific role.
     *
     * @param  string  $role
     * @return Collection<AddressAssignment>
     */
    public function addressAssignmentsForRole(string $role): Collection
    {
        return $this->addressAssignments()
            ->where('role', $role)
            ->with('addressLink.address')
            ->get();
    }

    /**
     * Get the address for a specific assignment role (convenience shortcut).
     *
     * @param  string  $role
     * @return Address|null
     */
    public function assignedAddressForRole(string $role): ?Address
    {
        $assignment = $this->addressAssignmentForRole($role);

        return $assignment?->addressLink?->address;
    }

    /**
     * Get all addresses assigned to this model (through their links).
     *
     * @return Collection<Address>
     */
    public function assignedAddresses(): Collection
    {
        return $this->addressAssignments()
            ->with('addressLink.address')
            ->get()
            ->map(fn(AddressAssignment $a) => $a->addressLink?->address)
            ->filter()
            ->values();
    }

    /**
     * Check whether this model has any address assignments.
     */
    public function hasAddressAssignments(): bool
    {
        return $this->addressAssignments()->exists();
    }

    /**
     * Check whether this model has an assignment for the given role.
     *
     * @param  string  $role
     */
    public function hasAssignmentForRole(string $role): bool
    {
        return $this->addressAssignments()->where('role', $role)->exists();
    }
}
