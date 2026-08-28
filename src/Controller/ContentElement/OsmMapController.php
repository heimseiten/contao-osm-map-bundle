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

namespace Heimseiten\ContaoOsmMapBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Heimseiten\ContaoOsmMapBundle\Service\MapLinks;
use Heimseiten\ContaoOsmMapBundle\Service\OverlayTextParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zeigt das beim Speichern erzeugte Kartenbild an und verlinkt es.
 *
 * Beim Aufruf der Seite wird nichts nachgeladen — das Bild liegt auf dem eigenen Server.
 */
#[AsContentElement(category: 'texts')]
class OsmMapController extends AbstractContentElementController
{
    public function __construct(
        private readonly Studio $studio,
        private readonly MapLinks $links,
        private readonly OverlayTextParser $overlayTextParser,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        if (!$model->osmImage) {
            // Noch kein Bild: im Frontend nichts ausgeben, im Vorschaumodus einen Hinweis.
            $template->set('bild', null);
            $template->set('hinweis', 'Für dieses Karten-Element wurde noch kein Bild erzeugt. Bitte das Element im Backend einmal speichern.');

            return $template->getResponse();
        }

        $figure = $this->studio
            ->createFigureBuilder()
            ->fromUuid($model->osmImage)
            ->setSize($model->size)
            ->setOverwriteMetadata($model->getOverwriteMetadata())
            // Kein Link am Bild selbst: Die Verlinkung setzt dieses Element um das ganze
            // Bild. Ohne diese Zeile käme aus dem Feld „Bild-Link" (oder aus den
            // Datei-Angaben) ein zweiter Link — verschachtelte Links sind ungültiges HTML
            // und in Screenreadern nicht bedienbar.
            ->setLinkHref('')
            ->buildIfResourceExists()
        ;

        // Alle Markierungen mit ihren Koordinaten. Die erste ist die Hauptanschrift.
        $positionen = json_decode((string) $model->osmPositionen, true) ?: [];
        $haupt = $positionen[0] ?? ['lat' => 0.0, 'lon' => 0.0, 'anzeigename' => ''];

        // Für die Links zählt der tatsächlich verwendete Maßstab — bei „automatisch"
        // steht im Feld osmZoom nur das Wort, nicht die berechnete Stufe.
        $zoom = (int) ($model->osmZoomEffektiv ?: 15);

        $bildZiel = (string) ($model->osmLinkTyp ?: MapLinks::KARTE_OSM);
        $bildLink = $this->links->url($bildZiel, (float) $haupt['lat'], (float) $haupt['lon'], $zoom);

        $template->set('bild', $figure);
        $template->set('hinweis', null);
        $template->set('verlinken', '' !== $bildLink);
        $template->set('link', $bildLink);
        $template->set('link_titel', $this->linkTitel($model, $bildZiel));
        $template->set('anschrift', (string) ($haupt['anzeigename'] ?? ''));

        // Der Balken über dem unteren Bildrand: Text mit einzeln verlinkten Wörtern.
        $template->set('balken', $this->overlayTextParser->zerlegen(
            (string) $model->osmOverlayText,
            $positionen,
            $zoom,
        ));

        return $template->getResponse();
    }

    /**
     * Beschriftung des Bildlinks für Screenreader und als Tooltip. Ein eigener Text lässt
     * sich über die Bild-Metadaten (Feld „Titel") setzen; sonst greift diese Vorgabe.
     */
    private function linkTitel(ContentModel $model, string $ziel): string
    {
        if ($model->overwriteMeta && $model->imageTitle) {
            return (string) $model->imageTitle;
        }

        return match ($ziel) {
            MapLinks::KARTE_GOOGLE => 'Adresse auf der Karte von Google Maps ansehen',
            MapLinks::ROUTE_OSM => 'Route zu dieser Adresse planen (öffnet openstreetmap.org)',
            MapLinks::ROUTE_GOOGLE => 'Route zu dieser Adresse planen (öffnet Google Maps)',
            default => 'Adresse auf der Karte von openstreetmap.org ansehen',
        };
    }
}
