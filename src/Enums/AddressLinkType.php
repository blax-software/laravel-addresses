<?php

namespace Blax\Addresses\Enums;

/**
 * Describes the purpose or role an address fulfils when linked to a model.
 *
 * This enum gives consuming applications a sensible pre-selection while still
 * being flexible: the `Other` case combined with the `label` column on the
 * pivot allows developers to store any custom designation.
 */
enum AddressLinkType: string
{
    /*
    |--------------------------------------------------------------------------
    | Residential
    |--------------------------------------------------------------------------
    */

    /** Primary living / home address. */
    case Home = 'home';

    /** Secondary or holiday residence. */
    case SecondaryResidence = 'secondary_residence';

    /*
    |--------------------------------------------------------------------------
    | Business / Work
    |--------------------------------------------------------------------------
    */

    /** General office address. */
    case Office = 'office';

    /** Company headquarters. */
    case Headquarters = 'headquarters';

    /** Branch or satellite office. */
    case Branch = 'branch';

    /** Factory or production site. */
    case Factory = 'factory';

    /** Warehouse or storage facility. */
    case Warehouse = 'warehouse';

    /*
    |--------------------------------------------------------------------------
    | Logistics & Shipping
    |--------------------------------------------------------------------------
    */

    /** Address used for shipping / delivery. */
    case Shipping = 'shipping';

    /** Address used for billing / invoicing. */
    case Billing = 'billing';

    /** Return / reverse-logistics address. */
    case Return = 'return';

    /** Pick-up point (e.g. parcel locker, shop). */
    case Pickup = 'pickup';

    /*
    |--------------------------------------------------------------------------
    | Special Purpose
    |--------------------------------------------------------------------------
    */

    /** Point of interest or landmark (e.g. a rural stone, monument). */
    case PointOfInterest = 'point_of_interest';

    /** Construction or project site. */
    case Site = 'site';

    /** Temporary / event-based address. */
    case Temporary = 'temporary';

    /** Contact / correspondence address (may differ from legal). */
    case Contact = 'contact';

    /** Registered / legal address. */
    case Legal = 'legal';

    /*
    |--------------------------------------------------------------------------
    | Catch-All
    |--------------------------------------------------------------------------
    */

    /** Any purpose not covered above — use the `label` on the pivot for detail. */
    case Other = 'other';

    /**
     * Human-readable label for display in UIs.
     */
    public function label(): string
    {
        return match ($this) {
            self::Home               => 'Home',
            self::SecondaryResidence => 'Secondary Residence',
            self::Office             => 'Office',
            self::Headquarters       => 'Headquarters',
            self::Branch             => 'Branch',
            self::Factory            => 'Factory',
            self::Warehouse          => 'Warehouse',
            self::Shipping           => 'Shipping',
            self::Billing            => 'Billing',
            self::Return             => 'Return',
            self::Pickup             => 'Pick-up',
            self::PointOfInterest    => 'Point of Interest',
            self::Site               => 'Site',
            self::Temporary          => 'Temporary',
            self::Contact            => 'Contact',
            self::Legal              => 'Legal',
            self::Other              => 'Other',
        };
    }
}
