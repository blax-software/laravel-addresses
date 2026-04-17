<?php

namespace Blax\Addresses\Models;

use Blax\Workkit\Traits\HasMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Assigns an existing AddressLink to another model / context.
 *
 * While an AddressLink connects an Address to its *owner* (e.g. a User's
 * "Office" address), an AddressAssignment *references* that link from a
 * completely different model (e.g. a Job, Order or Event).
 *
 * Example flow:
 *   Address ("350 Fifth Avenue, New York")
 *     └── AddressLink  (User #7 — type: office)
 *           └── AddressAssignment  (Job #42 — role: "pickup")
 *
 * This lets you say: "Job #42 picks up from User #7's office address."
 *
 * @property int                        $id
 * @property int                        $address_link_id  – FK → address_links
 * @property string                     $assignable_type  – Morph type of the consuming model
 * @property int                        $assignable_id    – Morph ID of the consuming model
 * @property string|null                $role             – Context-specific purpose ("pickup", "delivery", …)
 * @property string|null                $label            – Free-text label
 * @property string|null                $name_on_door     – Name on door / bell / intercom
 * @property string|null                $email            – Contact email for this assignment
 * @property string|null                $phone            – Contact phone for this assignment
 * @property string|null                $floor            – Floor override for this assignment
 * @property string|null                $door             – Door / apartment / unit override
 * @property object|null                $meta             – Arbitrary JSON data
 * @property \Carbon\Carbon             $created_at
 * @property \Carbon\Carbon             $updated_at
 */
class AddressAssignment extends Model
{
    use HasMeta, HasUuids;

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'address_link_id',
        'assignable_type',
        'assignable_id',
        'role',
        'label',
        'name_on_door',
        'email',
        'phone',
        'floor',
        'door',
        'meta',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'meta' => 'object',
    ];

    /*
    |--------------------------------------------------------------------------
    | Constructor — configurable table name
    |--------------------------------------------------------------------------
    */

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('addresses.table_names.address_assignments') ?: parent::getTable();
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The address link this assignment references.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function addressLink()
    {
        return $this->belongsTo(
            config('addresses.models.address_link', AddressLink::class),
            'address_link_id'
        );
    }

    /**
     * The address (shortcut through the link).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOneThrough
     */
    public function address()
    {
        $addressModel = config('addresses.models.address', Address::class);
        $linkModel = config('addresses.models.address_link', AddressLink::class);

        return $this->hasOneThrough(
            $addressModel,
            $linkModel,
            'id',           // FK on address_links (intermediate)
            'id',           // FK on addresses (final)
            'address_link_id', // local key on address_assignments
            'address_id'    // FK on address_links → addresses
        );
    }

    /**
     * The model this address link is assigned to (Job, Order, Event …).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function assignable()
    {
        return $this->morphTo();
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors — effective values (assignment overrides base address)
    |--------------------------------------------------------------------------
    */

    /**
     * The effective floor: assignment's floor if set, otherwise the base address's floor.
     */
    public function getEffectiveFloorAttribute(): ?string
    {
        return $this->floor ?? $this->addressLink?->address?->floor;
    }

    /**
     * The effective door/room: assignment's door if set, otherwise the base address's room.
     */
    public function getEffectiveDoorAttribute(): ?string
    {
        return $this->door ?? $this->addressLink?->address?->room;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Only assignments with a specific role.
     */
    public function scopeForRole($query, string $role)
    {
        return $query->where('role', $role);
    }
}
