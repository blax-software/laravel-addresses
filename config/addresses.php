<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model Classes
    |--------------------------------------------------------------------------
    |
    | Override these with your own model classes if you need to extend or
    | customise the package models. Your custom models should extend the
    | corresponding package model so that migrations and relationships
    | continue to work out of the box.
    |
    */
    'models' => [
        'address'            => \Blax\Addresses\Models\Address::class,
        'address_link'       => \Blax\Addresses\Models\AddressLink::class,
        'address_assignment' => \Blax\Addresses\Models\AddressAssignment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | The database table names used by the package. Change these if they
    | collide with existing tables in your application.
    |
    */
    'table_names' => [
        'addresses'            => 'addresses',
        'address_links'        => 'address_links',
        'address_assignments'  => 'address_assignments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Address Link Type
    |--------------------------------------------------------------------------
    |
    | The default AddressLinkType applied when attaching an address to a model
    | without specifying a type explicitly.
    |
    */
    'default_link_type' => \Blax\Addresses\Enums\AddressLinkType::Other,

];
