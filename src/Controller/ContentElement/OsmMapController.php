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
use Contao\FilesModel;
use Contao\StringUtil;
use Heimseiten\ContaoOsmMapBundle\Service\MapBuilder;
use Heimseiten\ContaoOsmMapBundle\Service\MapLinks;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Heimseiten\ContaoOsmMapBundle\Service\MapStyle;
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
        private readonly MapBuilder $mapBuilder,
        private readonly Packages $assets,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        if (!$model->osmImage) {
            // Noch kein Bild: im Frontend nichts ausgeben, im Vorschaumodus einen Hinweis.
            $template->set('bild', null);
            $template->set('bild_schmal', null);
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

        // Zweite Fassung für schmale Bildschirme, sofern eingerichtet. Sie zeigt denselben
        // Ort in anderem Format — meist quadratisch — und bekommt eine eigene Bildgröße,
        // weil eine auf das breite Bild zugeschnittene Größe das Quadrat beschneiden würde.
        $bildSchmal = null;

        if ($model->osmMobil && $model->osmImageMobil) {
            $bildSchmal = $this->studio
                ->createFigureBuilder()
                ->fromUuid($model->osmImageMobil)
                ->setSize($model->osmSizeMobil)
                ->setLinkHref('')
                ->buildIfResourceExists()
            ;
        }

        $template->set('bild_schmal', $bildSchmal);
        $template->set('schmal_bis', max(1, (int) ($model->osmMobilBis ?: 767)));

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
        // Auf einer dunklen Karte wird auch der Balken dunkel (Klasse osm-map--dunkel).
        $template->set('dunkel', \in_array((string) $model->osmKartenstil, MapStyle::DUNKLE, true));
        $template->set('verlinken', '' !== $bildLink);
        $template->set('link', $bildLink);
        $template->set('link_titel', $this->linkTitel($model, $bildZiel));
        $template->set('anschrift', (string) ($haupt['anzeigename'] ?? ''));

        $template->set('interaktiv', $this->interaktiv($model, $positionen, $zoom));

        // Der Balken über dem unteren Bildrand: Text mit einzeln verlinkten Wörtern.
        $template->set('balken', $this->overlayTextParser->zerlegen(
            (string) $model->osmOverlayText,
            $positionen,
            $zoom,
        ));

        return $template->getResponse();
    }

    /**
     * Angaben für die bewegliche Karte (osm-map-interaktiv.js), oder null ohne Haken bzw.
     * solange die Kartendaten fehlen — dann bleibt es beim Kartenbild.
     *
     * @param list<array{lat: float, lon: float, label?: string, anzeigename?: string}> $positionen
     */
    private function interaktiv(ContentModel $model, array $positionen, int $zoom): array|null
    {
        if (!$model->osmInteraktiv || !$model->osmKartendaten || !$positionen) {
            return null;
        }

        $datei = FilesModel::findByUuid($model->osmKartendaten);

        if (null === $datei || !is_file($this->projectDir.'/'.$datei->path)) {
            return null;
        }

        $texte = $GLOBALS['TL_LANG']['MSC']['osmInteraktiv'] ?? [];
        $linkTyp = (string) ($model->osmOrtLink ?: MapLinks::ROUTE_GOOGLE);
        $zeilen = StringUtil::deserialize($model->osmPoints, true);
        $orte = [];

        foreach ($positionen as $i => $p) {
            $name = trim((string) ($p['label'] ?? ''));

            if ('' === $name) {
                $name = trim(explode(',', (string) ($p['anzeigename'] ?? ''))[0]);
            }

            $anschrift = trim((string) ($zeilen[$i]['anschrift'] ?? ''));
            $ziel = '' !== trim((string) ($p['label'] ?? '')) && '' !== $anschrift ? $p['label'].', '.$anschrift : $anschrift;

            $orte[] = [
                'lat' => (float) $p['lat'],
                'lon' => (float) $p['lon'],
                'name' => $name,
                'link' => MapLinks::KEINER === $linkTyp ? '' : $this->links->urlMitAnschrift($linkTyp, $ziel, (float) $p['lat'], (float) $p['lon'], max($zoom, 16)),
            ];
        }

        $stil = \in_array((string) $model->osmKartenstil, MapStyle::AUSWAHL, true) ? (string) $model->osmKartenstil : MapStyle::VORGABE;

        return [
            'daten' => '/'.$datei->path,
            'basis' => strtok($this->assets->getUrl('interaktiv', 'heimseiten_contao_osm_map'), '?'),
            'stil' => $stil,
            'orte' => $orte,
            'farbe' => (string) ($model->osmMarkerColor ?: '#bf7500'),
            'zoom' => $zoom,
            'maxZoom' => 19,
            'datenZoom' => MapBuilder::MAX_ZOOM,
            'gebiet' => $gebiet = $this->mapBuilder->gebiet($positionen),
            'umfeld' => $this->mapBuilder->umfeld($gebiet),
            'detailAb' => MapBuilder::DETAIL_AB,
            'linkText' => $texte['links'][$linkTyp] ?? '',
            'texte' => array_diff_key($texte, ['links' => true]),
        ];
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
