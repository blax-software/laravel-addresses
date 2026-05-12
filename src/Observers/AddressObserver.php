<?php

namespace Blax\Addresses\Observers;

use Blax\Addresses\Models\Address;
use Blax\Addresses\Services\Geocoding\Contracts\Geocoder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Synchronous model observer that auto-geocodes addresses on save.
 *
 * Flow on every `saved` event:
 *   1. Bail out fast if geocoding is disabled in config or if the
 *      address has no postal-y fields to look up.
 *   2. Decide whether we need to geocode at all:
 *        • update_only_when_missing = true  → only when lat or lon is null.
 *        • update_only_when_missing = false → whenever a postal field
 *          actually changed (latitude/longitude themselves don't count
 *          — those are our own writes coming back through).
 *   3. Ask the bound Geocoder for coordinates. The geocoder handles
 *      the cache lock + rate-limit; the observer just routes the
 *      result back onto the model.
 *   4. Persist with `saveQuietly` so we don't trigger another `saved`
 *      event and re-enter ourselves.
 *
 * The observer is registered via the service provider as a model
 * observer — `Model::observe()` is called once at `boot()` time.
 */
class AddressObserver
{
    /**
     * The postal fields whose change should trigger a re-geocode (when
     * `update_only_when_missing` is off). Latitude / longitude are NOT
     * in this list — they're the OUTPUT and watching them would loop.
     */
    protected const POSTAL_FIELDS = [
        'street',
        'street_extra',
        'building',
        'postal_code',
        'city',
        'state',
        'county',
        'country_code',
    ];

    public function __construct(
        protected Container $container,
        protected Config $config,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Fired right after an INSERT lands in the database. For a brand-new
     * address we always geocode (subject to the `enabled` master switch
     * and the `update_only_when_missing` policy), because there's no
     * "previous state" to compare against — the row didn't exist a
     * moment ago.
     */
    public function created(Address $address): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (! $this->hasAnyPostalField($address)) {
            return;
        }
        if ($this->skipForMissingPolicy($address)) {
            return;
        }
        $this->geocodeAndApply($address);
    }

    /**
     * Fired right after an UPDATE lands. We split this from `created`
     * deliberately:
     *   • `wasRecentlyCreated` stays `true` on the same instance even
     *     across subsequent saves, so we can't use it to distinguish.
     *   • `wasChanged()` is only populated by `performUpdate` — on a
     *     raw INSERT it'd return false for every field.
     * The two events together give us a clean view of what just happened.
     */
    public function updated(Address $address): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (! $this->hasAnyPostalField($address)) {
            return;
        }
        if ($this->skipForMissingPolicy($address)) {
            return;
        }
        if (! $this->postalFieldChanged($address)) {
            // Only lat/lon (or unrelated columns) moved — most likely
            // our own write-back from the geocoder coming through.
            return;
        }
        $this->geocodeAndApply($address);
    }

    /*
    |--------------------------------------------------------------------------
    | Shared pipeline
    |--------------------------------------------------------------------------
    */

    /**
     * Common path: ask the bound Geocoder, then route the result onto
     * the model with a `saveQuietly` so we don't trigger ourselves.
     *
     * Errors are swallowed (logged) — the model's own save has already
     * committed, and a transient upstream failure shouldn't blow up
     * the caller's `save()` / `create()` call.
     */
    protected function geocodeAndApply(Address $address): void
    {
        try {
            $geocoder = $this->container->make(Geocoder::class);
            $result = $geocoder->geocode($address);
        } catch (LockTimeoutException $e) {
            // A burst of saves exceeded the lock_wait window. Log and
            // move on — the user can re-save (or run a backfill) to retry.
            $this->logger->info('Address geocoding skipped: lock wait timed out.', [
                'address_id' => $address->getKey(),
            ]);

            return;
        } catch (Throwable $e) {
            // Network blip, upstream 5xx, etc. The address itself
            // saved fine; we just don't have coordinates for it yet.
            $this->logger->warning('Address geocoding failed.', [
                'address_id' => $address->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if ($result === null) {
            // Upstream had no match.
            return;
        }

        $address->latitude = $result->latitude;
        $address->longitude = $result->longitude;
        // saveQuietly bypasses observers so we don't re-enter `updated`.
        $address->saveQuietly();
    }

    /*
    |--------------------------------------------------------------------------
    | Predicates
    |--------------------------------------------------------------------------
    */

    /** Master switch. Disabled in CI, bulk import, etc. */
    protected function isEnabled(): bool
    {
        return (bool) $this->config->get('addresses.geocoding.enabled', true);
    }

    /**
     * When `update_only_when_missing` is on, the observer leaves
     * manually-entered coordinates alone — only fills in the blanks.
     * Returns true when we should bail out for this row under that
     * policy (= both coords already set).
     */
    protected function skipForMissingPolicy(Address $address): bool
    {
        if (! $this->config->get('addresses.geocoding.update_only_when_missing', false)) {
            return false;
        }

        return $address->latitude !== null && $address->longitude !== null;
    }

    /**
     * True if any of the fields we'd actually feed into the geocoder
     * was just modified. Latitude/longitude themselves are excluded —
     * those are the geocoder's outputs, watching them would loop.
     */
    protected function postalFieldChanged(Address $address): bool
    {
        foreach (self::POSTAL_FIELDS as $field) {
            if ($address->wasChanged($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if the address has at least one of the fields we'd send
     * upstream. Mirrors the early-exit inside NominatimGeocoder so we
     * don't bother acquiring the lock for a guaranteed no-op.
     */
    protected function hasAnyPostalField(Address $address): bool
    {
        return ! empty($address->street)
            || ! empty($address->postal_code)
            || ! empty($address->city);
    }
}
