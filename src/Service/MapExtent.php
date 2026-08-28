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
 * Bestimmt Mittelpunkt und Maßstab, wenn mehrere Orte auf eine Karte sollen.
 *
 * Bei einem einzigen Ort ist das trivial. Bei mehreren muss der Ausschnitt so gewählt
 * werden, dass alle hineinpassen — sonst zeigt die Karte nur einen Teil der Standorte.
 */
class MapExtent
{
    private const KACHEL = 256;

    /** Rand, der um die äußersten Punkte frei bleibt, damit die Schilder Platz haben. */
    private const RAND = 0.22;

    /**
     * Mittelpunkt aller Punkte.
     *
     * @param list<array{lat: float, lon: float}> $punkte
     *
     * @return array{0: float, 1: float}
     */
    public function mittelpunkt(array $punkte): array
    {
        $latMin = $latMax = $punkte[0]['lat'];
        $lonMin = $lonMax = $punkte[0]['lon'];

        foreach ($punkte as $punkt) {
            $latMin = min($latMin, $punkt['lat']);
            $latMax = max($latMax, $punkt['lat']);
            $lonMin = min($lonMin, $punkt['lon']);
            $lonMax = max($lonMax, $punkt['lon']);
        }

        return [($latMin + $latMax) / 2, ($lonMin + $lonMax) / 2];
    }

    /**
     * Größte Zoomstufe, bei der noch alle Punkte ins Bild passen.
     *
     * Je höher die Stufe, desto näher die Ansicht — gesucht ist also die näheste Ansicht,
     * die alle Orte zeigt.
     *
     * @param list<array{lat: float, lon: float}> $punkte
     */
    public function passenderZoom(array $punkte, int $breite, int $hoehe, int $min = 3, int $max = 19): int
    {
        if (\count($punkte) < 2) {
            return 16; // ein einzelner Ort: Straßenebene
        }

        $latMin = $latMax = $punkte[0]['lat'];
        $lonMin = $lonMax = $punkte[0]['lon'];

        foreach ($punkte as $punkt) {
            $latMin = min($latMin, $punkt['lat']);
            $latMax = max($latMax, $punkt['lat']);
            $lonMin = min($lonMin, $punkt['lon']);
            $lonMax = max($lonMax, $punkt['lon']);
        }

        // Nutzbare Fläche abzüglich des Randes
        $nutzBreite = $breite * (1 - self::RAND);
        $nutzHoehe = $hoehe * (1 - self::RAND);

        for ($zoom = $max; $zoom >= $min; --$zoom) {
            $spanneX = abs($this->pixelX($lonMax, $zoom) - $this->pixelX($lonMin, $zoom));
            $spanneY = abs($this->pixelY($latMin, $zoom) - $this->pixelY($latMax, $zoom));

            if ($spanneX <= $nutzBreite && $spanneY <= $nutzHoehe) {
                return $zoom;
            }
        }

        return $min;
    }

    private function pixelX(float $lon, int $zoom): float
    {
        return ($lon + 180) / 360 * (2 ** $zoom * self::KACHEL);
    }

    private function pixelY(float $lat, int $zoom): float
    {
        return (1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * (2 ** $zoom * self::KACHEL);
    }
}
