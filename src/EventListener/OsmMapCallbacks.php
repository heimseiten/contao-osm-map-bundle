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

namespace Heimseiten\ContaoOsmMapBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Heimseiten\ContaoOsmMapBundle\Service\MapBuilder;
use Heimseiten\ContaoOsmMapBundle\Service\OsmMapException;

/**
 * Erzeugt das Kartenbild, sobald ein Karten-Element gespeichert wird.
 *
 * Bewusst beim Speichern und nicht beim Seitenaufruf: So merkt der Redakteur sofort, ob
 * die Anschrift gefunden wurde, das Bild liegt danach im Dateimanager, und für Besucher
 * entsteht nie eine Verbindung zu einem fremden Server.
 */
class OsmMapCallbacks
{
    public function __construct(
        private readonly MapBuilder $mapBuilder,
        private readonly Connection $connection,
    ) {
    }

    #[AsCallback(table: 'tl_content', target: 'config.onsubmit')]
    public function karteErzeugen(DataContainer $dc): void
    {
        if (!$dc->id || !$dc->activeRecord || 'osm_map' !== $dc->activeRecord->type) {
            return;
        }

        $datensatz = $dc->activeRecord;

        try {
            $ergebnis = $this->mapBuilder->bauen(
                [
                    'punkte' => $datensatz->osmPoints,
                    'zoom' => (string) $datensatz->osmZoom,
                    'breite' => (int) $datensatz->osmWidth,
                    'hoehe' => (int) $datensatz->osmHeight,
                    'markerFarbe' => (string) $datensatz->osmMarkerColor,
                    'markerForm' => (string) $datensatz->osmMarkerForm,
                    'kartenstil' => (string) $datensatz->osmKartenstil,
                    'positionen' => (string) $datensatz->osmPositionen,
                    'gespeicherterZoom' => (int) $datensatz->osmZoomEffektiv,
                    // Ohne den Haken bleiben beide Maße 0 — dann entsteht keine zweite Fassung.
                    'breiteMobil' => $datensatz->osmMobil ? (int) $datensatz->osmWidthMobil : 0,
                    'hoeheMobil' => $datensatz->osmMobil ? (int) $datensatz->osmHeightMobil : 0,
                ],
                (string) $datensatz->osmHash ?: null,
                $datensatz->osmImage ?: null,
                $datensatz->osmImageMobil ?: null,
            );
        } catch (OsmMapException $e) {
            Message::addError($e->getMessage());

            return;
        }

        $this->connection->update('tl_content', [
            'osmImage' => $ergebnis['uuid'],
            'osmImageMobil' => $ergebnis['uuidMobil'],
            'osmPositionen' => json_encode($ergebnis['positionen'], JSON_UNESCAPED_UNICODE),
            'osmHash' => $ergebnis['fingerabdruck'],
            'osmZoomEffektiv' => $ergebnis['zoom'],
        ], ['id' => $dc->id]);

        // Bewegliche Karte: Kartendaten für das Gebiet um die Markierungen
        if ($datensatz->osmInteraktiv) {
            try {
                $daten = $this->mapBuilder->kartendatenBauen(
                    $ergebnis['positionen'],
                    (string) $datensatz->osmKartendatenHash ?: null,
                    $datensatz->osmKartendaten ?: null,
                );

                $this->connection->update('tl_content', [
                    'osmKartendaten' => $daten['uuid'],
                    'osmKartendatenHash' => $daten['fingerabdruck'],
                ], ['id' => $dc->id]);

                if ($daten['neu']) {
                    Message::addConfirmation(\sprintf(
                        'Die Kartendaten für die bewegliche Karte wurden geholt (%d Kacheln, %s MB).',
                        $daten['kacheln'],
                        number_format($daten['bytes'] / 1_000_000, 1, ',', ''),
                    ));
                }
            } catch (OsmMapException $e) {
                Message::addError('Bewegliche Karte: '.$e->getMessage());
            }
        }

        if ($ergebnis['neu']) {
            Message::addConfirmation(\sprintf(
                'Die Karte wurde erzeugt (Maßstab %d). Gefundene %s: %s',
                $ergebnis['zoom'],
                1 === \count($ergebnis['positionen']) ? 'Position' : 'Positionen',
                implode(' · ', array_map(
                    static fn (array $p) => $p['anzeigename'],
                    $ergebnis['positionen'],
                )),
            ));
        }
    }
}
