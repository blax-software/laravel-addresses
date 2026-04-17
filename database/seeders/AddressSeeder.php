<?php

namespace Blax\Addresses\Seeders;

use Blax\Addresses\Models\Address;
use Illuminate\Database\Seeder;

/**
 * Seeds realistic addresses for development.
 *
 * Usage:
 *   (new AddressSeeder)->run();                          // 10 Austrian addresses
 *   (new AddressSeeder)->run(country: 'DE', count: 20); // 20 German addresses
 *   (new AddressSeeder)->run(country: 'CH', count: 5);  // 5 Swiss addresses
 */
class AddressSeeder extends Seeder
{
    /**
     * Realistic address pools per country.
     * Each entry: [street, postal_code, city, state, lat, lng, has_elevator]
     */
    protected static array $pools = [
        'AT' => [
            ['Mariahilfer Straße 45', '1060', 'Wien', 'Wien', 48.1952, 16.3430, true],
            ['Getreidegasse 9', '5020', 'Salzburg', 'Salzburg', 47.8001, 13.0438, false],
            ['Herrengasse 12', '8010', 'Graz', 'Steiermark', 47.0707, 15.4395, true],
            ['Landstraße 33', '4020', 'Linz', 'Oberösterreich', 48.3064, 14.2858, true],
            ['Maria-Theresien-Straße 18', '6020', 'Innsbruck', 'Tirol', 47.2620, 11.3960, false],
            ['Bahnhofstraße 7', '9020', 'Klagenfurt', 'Kärnten', 46.6247, 14.3050, true],
            ['Hauptplatz 1', '2700', 'Wiener Neustadt', 'Niederösterreich', 47.8126, 16.2449, false],
            ['Domplatz 5', '3100', 'St. Pölten', 'Niederösterreich', 48.2058, 15.6261, false],
            ['Rathausplatz 3', '6900', 'Bregenz', 'Vorarlberg', 47.5031, 9.7471, false],
            ['Wiener Straße 21', '7000', 'Eisenstadt', 'Burgenland', 47.8455, 16.5189, false],
            ['Wiedner Hauptstraße 73', '1040', 'Wien', 'Wien', 48.1897, 16.3644, true],
            ['Graben 21', '1010', 'Wien', 'Wien', 48.2082, 16.3694, true],
            ['Sporgasse 8', '8010', 'Graz', 'Steiermark', 47.0732, 15.4387, false],
            ['Rudolfskai 14', '5020', 'Salzburg', 'Salzburg', 47.7985, 13.0424, true],
            ['Bürgerstraße 20', '6020', 'Innsbruck', 'Tirol', 47.2668, 11.3902, false],
        ],
        'DE' => [
            ['Friedrichstraße 43', '10117', 'Berlin', 'Berlin', 52.5200, 13.3880, true],
            ['Maximilianstraße 15', '80539', 'München', 'Bayern', 48.1391, 11.5802, true],
            ['Königstraße 28', '70173', 'Stuttgart', 'Baden-Württemberg', 48.7764, 9.1775, true],
            ['Hohe Straße 52', '50667', 'Köln', 'Nordrhein-Westfalen', 50.9375, 6.9603, true],
            ['Jungfernstieg 7', '20354', 'Hamburg', 'Hamburg', 53.5511, 9.9937, true],
            ['Zeil 106', '60313', 'Frankfurt am Main', 'Hessen', 50.1136, 8.6797, true],
            ['Marienplatz 8', '01067', 'Dresden', 'Sachsen', 51.0504, 13.7373, false],
            ['Breite Straße 29', '18055', 'Rostock', 'Mecklenburg-Vorpommern', 54.0887, 12.1407, false],
            ['Kaiserstraße 14', '76131', 'Karlsruhe', 'Baden-Württemberg', 49.0069, 8.4037, true],
            ['Schlossstraße 11', '30159', 'Hannover', 'Niedersachsen', 52.3759, 9.7320, true],
            ['Bahnhofstraße 5', '90402', 'Nürnberg', 'Bayern', 49.4521, 11.0767, true],
            ['Am Markt 3', '28195', 'Bremen', 'Bremen', 53.0793, 8.8017, false],
            ['Schillerstraße 16', '04109', 'Leipzig', 'Sachsen', 51.3397, 12.3731, true],
            ['Poststraße 22', '40213', 'Düsseldorf', 'Nordrhein-Westfalen', 51.2277, 6.7735, true],
            ['Lange Straße 9', '44137', 'Dortmund', 'Nordrhein-Westfalen', 51.5136, 7.4653, true],
        ],
        'CH' => [
            ['Bahnhofstrasse 21', '8001', 'Zürich', 'Zürich', 47.3769, 8.5417, true],
            ['Marktgasse 46', '3011', 'Bern', 'Bern', 46.9480, 7.4474, false],
            ['Rue du Rhône 30', '1204', 'Genf', 'Genève', 46.2044, 6.1432, true],
            ['Freie Strasse 68', '4001', 'Basel', 'Basel-Stadt', 47.5596, 7.5886, true],
            ['Pilatusstrasse 15', '6003', 'Luzern', 'Luzern', 47.0502, 8.3093, false],
            ['Neumarkt 5', '8400', 'Winterthur', 'Zürich', 47.4984, 8.7235, false],
            ['Obere Bahnhofstrasse 32', '9500', 'Wil', 'St. Gallen', 47.4614, 9.0446, false],
            ['Avenue de la Gare 10', '1003', 'Lausanne', 'Vaud', 46.5197, 6.6323, true],
            ['Via Nassa 12', '6900', 'Lugano', 'Ticino', 46.0037, 8.9511, true],
            ['Kramgasse 49', '3011', 'Bern', 'Bern', 46.9479, 7.4519, false],
        ],
    ];

    /**
     * Seed addresses.
     *
     * @param  string  $country  ISO 3166-1 alpha-2 country code
     * @param  int     $count    Number of addresses to create
     * @return \Illuminate\Support\Collection<int, Address>
     */
    public function run(string $country = 'AT', int $count = 10): \Illuminate\Support\Collection
    {
        $pool = static::$pools[$country] ?? static::$pools['AT'];
        $addressModel = config('addresses.models.address', Address::class);

        $addresses = collect();

        for ($i = 0; $i < $count; $i++) {
            $entry = $pool[$i % count($pool)];

            $addresses->push($addressModel::create([
                'street'       => $entry[0],
                'postal_code'  => $entry[1],
                'city'         => $entry[2],
                'state'        => $entry[3],
                'country_code' => $country,
                'latitude'     => $entry[4],
                'longitude'    => $entry[5],
                'has_elevator' => $entry[6],
            ]));
        }

        return $addresses;
    }

    /**
     * Get the available country codes.
     *
     * @return array<string>
     */
    public static function availableCountries(): array
    {
        return array_keys(static::$pools);
    }

    /**
     * Register additional address pools at runtime.
     *
     * @param  string  $country  ISO 3166-1 alpha-2 country code
     * @param  array   $entries  Array of [street, postal_code, city, state, lat, lng, has_elevator]
     */
    public static function registerPool(string $country, array $entries): void
    {
        static::$pools[$country] = $entries;
    }
}
