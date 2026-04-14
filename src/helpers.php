<?php

use Blax\Addresses\Services\AddressService;

if (! function_exists('address')) {
    /**
     * Get the AddressService singleton.
     *
     * @return AddressService
     */
    function address(): AddressService
    {
        return app(AddressService::class);
    }
}
