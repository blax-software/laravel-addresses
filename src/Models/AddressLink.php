<?php

namespace Blax\Addresses\Models;

use Blax\Addresses\Enums\AddressLinkType;
use Blax\Workkit\Traits\HasMeta;
use Illuminate\Database\Eloquent\Model;

/**
 * Polymorphic pivot that links an Address to any Eloquent model.
 *
 * A single address can serve multiple purposes for the same or different
 * models (e.g. both "Office" and "Billing" for a company), each tracked
 * as a separate AddressLink row.
 *
 * @property int                        $id
 * @property int                        $address_id     – FK → addresses
 * @property string                     $addressable_type – Morph type of the owning model
 * @property int                        $addressable_id   – Morph ID of the owning model
 * @property string                     $type             – AddressLinkType enum value
 * @property string|null                $label            – Free-text label (refines or overrides type)
 * @property bool                       $is_primary       – Whether this is the primary link for its type
 * @property \Carbon\Carbon|null        $active_from      – When the link becomes effective
 * @property \Carbon\Carbon|null        $active_until     – When the link expires
 * @property object|null                $meta             – Arbitrary JSON data for consuming apps
 * @property \Carbon\Carbon             $created_at
 * @property \Carbon\Carbon             $updated_at
 */
class AddressLink extends Model
{
    use HasMeta;

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'address_id',
        'addressable_type',
        'addressable_id',
        'type',
        'label',
        'is_primary',
        'active_from',
        'active_until',
        'meta',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'type'         => AddressLinkType::class,
        'is_primary'   => 'boolean',
        'active_from'  => 'datetime',
        'active_until' => 'datetime',
        'meta'         => 'object',
    ];

    /*
    |--------------------------------------------------------------------------
    | Constructor — configurable table name
    |--------------------------------------------------------------------------
    */

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('addresses.table_names.address_links') ?: parent::getTable();
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The address record this link points to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function address()
    {
        return $this->belongsTo(
            config('addresses.models.address', Address::class),
            'address_id'
        );
    }

    /**
     * The owning model (User, Company, Order …).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function addressable()
    {
        return $this->morphTo();
    }

    /**
     * All assignments that reference this link from other models.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function assignments()
    {
        return $this->hasMany(
            config('addresses.models.address_assignment', AddressAssignment::class),
            'address_link_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Only links that are currently active (respects active_from / active_until).
     *
     * A link is active when:
     *  - active_from  is null OR in the past/present, AND
     *  - active_until is null OR in the future.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('active_from')
                ->orWhere('active_from', '<=', now());
        })->where(function ($q) {
            $q->whereNull('active_until')
                ->orWhere('active_until', '>', now());
        });
    }

    /**
     * Only expired links (active_until is in the past).
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('active_until')
            ->where('active_until', '<=', now());
    }

    /**
     * Only links of a specific type.
     */
    public function scopeOfType($query, AddressLinkType|string $type)
    {
        $value = $type instanceof AddressLinkType ? $type->value : $type;

        return $query->where('type', $value);
    }

    /**
     * Only primary links.
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this link is currently active.
     */
    public function isActive(): bool
    {
        $fromOk  = $this->active_from === null || $this->active_from->isPast() || $this->active_from->isToday();
        $untilOk = $this->active_until === null || $this->active_until->isFuture();

        return $fromOk && $untilOk;
    }
}
