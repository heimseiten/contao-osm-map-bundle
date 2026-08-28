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

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Schlägt zu einer Anschrift die Koordinaten nach (Nominatim, der Suchdienst von
 * OpenStreetMap).
 *
 * Der Dienst wird ausschließlich im Backend angefragt — beim Speichern eines
 * Karten-Elements. Für Besucher der Website entsteht dadurch keine Verbindung zu einem
 * fremden Server.
 *
 * Die Nutzungsbedingungen von Nominatim verlangen einen aussagekräftigen User-Agent und
 * höchstens eine Anfrage pro Sekunde. Beides hält diese Klasse ein.
 *
 * @see https://operations.osmfoundation.org/policies/nominatim/
 */
class Geocoder
{
    private const ENDPUNKT = 'https://nominatim.openstreetmap.org/search';

    /**
     * Nominatim erlaubt eine Anfrage je Sekunde. Der Zeitpunkt der letzten Anfrage steht in
     * einer Datei, damit die Sperre auch über mehrere Seitenaufrufe hinweg greift (jeder
     * Backend-Aufruf ist ein eigener PHP-Prozess und wüsste sonst nichts voneinander).
     */
    private const MINDESTABSTAND = 1.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $projectDir,
        private readonly string $userAgent,
    ) {
    }

    /**
     * @return array{lat: float, lon: float, anzeigename: string}
     *
     * @throws OsmMapException wenn die Anschrift nicht gefunden wird oder der Dienst nicht antwortet
     */
    public function suche(string $anschrift): array
    {
        $anschrift = trim($anschrift);

        if ('' === $anschrift) {
            throw new OsmMapException('Es ist keine Anschrift eingetragen.');
        }

        // Koordinaten statt Anschrift? Dann ist nichts nachzuschlagen.
        if (null !== $direkt = $this->alsKoordinaten($anschrift)) {
            return $direkt;
        }

        $this->warteAufFreigabe();

        try {
            $antwort = $this->httpClient->request('GET', self::ENDPUNKT, [
                'query' => [
                    'q' => $anschrift,
                    'format' => 'jsonv2',
                    'limit' => 1,
                    'addressdetails' => 0,
                ],
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept-Language' => 'de',
                ],
                'timeout' => 20,
            ]);

            $treffer = $antwort->toArray();
        } catch (\Throwable $e) {
            throw new OsmMapException(
                \sprintf('Die Adresssuche von OpenStreetMap ist nicht erreichbar (%s).', $e->getMessage()),
                previous: $e,
            );
        }

        if (!$treffer) {
            throw new OsmMapException(\sprintf(
                'Zu „%s" wurde keine Position gefunden. Bitte die Schreibweise prüfen — hilfreich ist die Form „Straße Hausnummer, PLZ Ort".',
                $anschrift,
            ));
        }

        $erster = $treffer[0];

        return [
            'lat' => (float) $erster['lat'],
            'lon' => (float) $erster['lon'],
            'anzeigename' => (string) ($erster['display_name'] ?? $anschrift),
        ];
    }

    /**
     * Erkennt ein direkt eingetragenes Koordinatenpaar wie „50.9413035, 6.9581380".
     *
     * Nicht jede Lage hat eine Anschrift, die sich nachschlagen lässt — Neubaugebiete
     * tragen oft noch keine Hausnummern. Dann trägt der Redakteur die Koordinaten ein,
     * und es wird gar nicht erst gesucht.
     *
     * @return array{lat: float, lon: float, anzeigename: string}|null
     */
    private function alsKoordinaten(string $eingabe): array|null
    {
        if (!preg_match('/^\s*(-?\d{1,2}(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:[.,]\d+)?)\s*$/', $eingabe, $treffer)) {
            return null;
        }

        $lat = (float) str_replace(',', '.', $treffer[1]);
        $lon = (float) str_replace(',', '.', $treffer[2]);

        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return null;
        }

        return [
            'lat' => $lat,
            'lon' => $lon,
            'anzeigename' => \sprintf('Koordinaten %s, %s', $lat, $lon),
        ];
    }

    /**
     * Hält den Mindestabstand zwischen zwei Anfragen ein.
     */
    private function warteAufFreigabe(): void
    {
        $merker = $this->projectDir.'/var/cache/osm-nominatim-letzte-anfrage';

        @mkdir(\dirname($merker), 0777, true);

        $letzte = is_file($merker) ? (float) file_get_contents($merker) : 0.0;
        $wartezeit = self::MINDESTABSTAND - (microtime(true) - $letzte);

        if ($wartezeit > 0) {
            usleep((int) ($wartezeit * 1_000_000));
        }

        @file_put_contents($merker, (string) microtime(true));
    }
}
