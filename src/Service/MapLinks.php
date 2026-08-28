<?php

declare(strict_types=1);

/*
 * This file is part of contao-osm-map-bundle.
 *
 * (c) heimseiten.de - Webdesign aus Köln 2026 <info@heimseiten.de>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/heimseiten/contao-osm-map-bundle
 */

namespace Heimseiten\ContaoOsmMapBundle\Service;

/**
 * Die möglichen Linkziele einer Karte — an einer Stelle.
 *
 * Sowohl der Klick auf das Bild als auch die Links in der Beschriftung greifen hierauf zu,
 * damit beide dieselben Ziele kennen und sich die Adressen nicht auseinanderentwickeln.
 */
class MapLinks
{
    public const KARTE_OSM = 'karte_osm';
    public const KARTE_GOOGLE = 'karte_google';
    public const ROUTE_OSM = 'route_osm';
    public const ROUTE_GOOGLE = 'route_google';
    public const KEINER = 'keiner';

    /** Reihenfolge im Auswahlfeld: erst die Karte, dann die Routenplanung, zuletzt „ohne". */
    public const AUSWAHL = [
        self::KARTE_OSM,
        self::KARTE_GOOGLE,
        self::ROUTE_OSM,
        self::ROUTE_GOOGLE,
        self::KEINER,
    ];

    /** Beschriftung, die ein Platzhalter ohne eigenen Text bekommt. */
    public const BESCHRIFTUNG = [
        self::KARTE_OSM => 'OpenStreetMap',
        self::KARTE_GOOGLE => 'Google Maps',
        self::ROUTE_OSM => 'Route planen',
        self::ROUTE_GOOGLE => 'Route planen mit Google Maps',
    ];

    /**
     * Kurzschreibweisen für die Platzhalter im Beschriftungstext.
     *
     * {osm} und {google} zeigen die Karte, {route} und {route-google} die Routenplanung.
     */
    public const PLATZHALTER = [
        'osm' => self::KARTE_OSM,
        'google' => self::KARTE_GOOGLE,
        'route' => self::ROUTE_OSM,
        'route-google' => self::ROUTE_GOOGLE,
        'route-osm' => self::ROUTE_OSM,
    ];

    /**
     * Die Adresse zu einem Ziel. Bei „keiner" (oder unbekanntem Ziel) eine leere Zeichenkette.
     */
    public function url(string $ziel, float $lat, float $lon, int $zoom = 15): string
    {
        $lat = $this->koordinate($lat);
        $lon = $this->koordinate($lon);
        $zoom = max(3, min(19, $zoom));

        return match ($ziel) {
            self::KARTE_OSM => \sprintf('https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=%3$d/%1$s/%2$s', $lat, $lon, $zoom),
            self::KARTE_GOOGLE => \sprintf('https://www.google.com/maps/search/?api=1&query=%s%%2C%s', $lat, $lon),
            self::ROUTE_OSM => \sprintf('https://www.openstreetmap.org/directions?to=%s%%2C%s', $lat, $lon),
            self::ROUTE_GOOGLE => \sprintf('https://www.google.com/maps/dir/?api=1&destination=%s%%2C%s', $lat, $lon),
            default => '',
        };
    }

    /**
     * Führt das Ziel zu Google? Wichtig für den Hinweis im Backend: Ein Link lädt zwar
     * nichts nach, der Betreiber sollte aber wissen, wohin er seine Besucher schickt.
     */
    public function fuehrtZuGoogle(string $ziel): bool
    {
        return \in_array($ziel, [self::KARTE_GOOGLE, self::ROUTE_GOOGLE], true);
    }

    private function koordinate(float $wert): string
    {
        return rtrim(rtrim(number_format($wert, 7, '.', ''), '0'), '.');
    }
}
