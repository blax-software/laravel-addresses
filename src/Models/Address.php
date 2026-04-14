<?php

namespace Blax\Addresses\Models;

use Blax\Workkit\Traits\HasMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a physical address — from a rural GPS coordinate to a specific
 * room inside a high-rise building.
 *
 * Coordinate system:
 *   latitude / longitude → WGS-84 decimal degrees
 *   altitude             → metres Above Mean Sea Level (AMSL)
 *
 * @property int         $id
 * @property string|null $street       – Primary street line (name + number)
 * @property string|null $street_extra – Secondary line (c/o, suite, P.O. box …)
 * @property string|null $building     – Building / complex name
 * @property string|null $floor        – Floor / level (string: "GF", "B2", "Mezzanine")
 * @property string|null $room         – Room, suite or unit identifier
 * @property string|null $postal_code  – Postal / ZIP code
 * @property string|null $city         – City, town, village or locality
 * @property string|null $state        – State, province, canton …
 * @property string|null $county       – County, district …
 * @property string|null $country_code – ISO 3166-1 alpha-2 ("AT", "US", "JP")
 * @property float|null  $latitude     – Decimal degrees (−90 … +90)
 * @property float|null  $longitude    – Decimal degrees (−180 … +180)
 * @property float|null  $altitude     – Metres AMSL (positive = above, negative = below)
 * @property string|null $notes        – Free-form notes / delivery instructions
 * @property object|null $meta         – Arbitrary JSON data
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
class Address extends Model
{
    use HasMeta;
    use SoftDeletes;

    /**
     * Mass-assignable attributes.
     *
     * Every column is intentionally nullable so that an address record can be
     * as sparse as a single coordinate pair or as detailed as a full postal
     * address with indoor precision.
     */
    protected $fillable = [
        'street',
        'street_extra',
        'building',
        'floor',
        'room',
        'postal_code',
        'city',
        'state',
        'county',
        'country_code',
        'latitude',
        'longitude',
        'altitude',
        'notes',
        'meta',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'latitude'  => 'float',
        'longitude' => 'float',
        'altitude'  => 'float',
        'meta'      => 'object',
    ];

    /*
    |--------------------------------------------------------------------------
    | Constructor — configurable table name
    |--------------------------------------------------------------------------
    */

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('addresses.table_names.addresses') ?: parent::getTable();
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * All links that reference this address (polymorphic pivot rows).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function links()
    {
        return $this->hasMany(
            config('addresses.models.address_link', AddressLink::class),
            'address_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the address has geographic coordinates set.
     */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Whether the address has altitude (AMSL) set.
     */
    public function hasAltitude(): bool
    {
        return $this->altitude !== null;
    }

    /**
     * Build a single-line formatted representation of the address.
     *
     * Useful for display purposes — joins non-empty components with ", ".
     */
    public function getFormattedAttribute(): string
    {
        return collect([
            $this->street,
            $this->street_extra,
            $this->building ? "({$this->building})" : null,
            $this->floor ? "Floor {$this->floor}" : null,
            $this->room ? "Room {$this->room}" : null,
            $this->postal_code,
            $this->city,
            $this->state,
            $this->county,
            $this->country_code,
        ])->filter()->implode(', ');
    }

    /**
     * Return coordinates as an associative array.
     *
     * @return array{latitude: float|null, longitude: float|null, altitude: float|null}
     */
    public function toCoordinates(): array
    {
        return [
            'latitude'  => $this->latitude,
            'longitude' => $this->longitude,
            'altitude'  => $this->altitude,
        ];
    }
}
