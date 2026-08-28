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
                    'positionen' => (string) $datensatz->osmPositionen,
                    'gespeicherterZoom' => (int) $datensatz->osmZoomEffektiv,
                ],
                (string) $datensatz->osmHash ?: null,
                $datensatz->osmImage ?: null,
            );
        } catch (OsmMapException $e) {
            Message::addError($e->getMessage());

            return;
        }

        $this->connection->update('tl_content', [
            'osmImage' => $ergebnis['uuid'],
            'osmPositionen' => json_encode($ergebnis['positionen'], JSON_UNESCAPED_UNICODE),
            'osmHash' => $ergebnis['fingerabdruck'],
            'osmZoomEffektiv' => $ergebnis['zoom'],
        ], ['id' => $dc->id]);

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
