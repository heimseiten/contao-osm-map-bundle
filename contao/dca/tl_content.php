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

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Heimseiten\ContaoOsmMapBundle\Service\MapLinks;
use Heimseiten\ContaoOsmMapBundle\Service\MapStyle;
use Heimseiten\ContaoOsmMapBundle\Service\MarkerRenderer;

/*
 * Das Karten-Element. Auszufüllen ist im Normalfall nur die erste Zeile der
 * Markierungen — alles andere hat brauchbare Voreinstellungen.
 *
 * Die Felder osmImage/osmPositionen/osmHash/osmZoomEffektiv füllt das System beim
 * Speichern selbst; sie stehen nicht in der Palette.
 */
$GLOBALS['TL_DCA']['tl_content']['palettes']['osm_map'] =
    '{type_legend},type,headline;'
    .'{osm_legend},osmPoints,osmZoom,osmWidth,osmHeight,osmMobil;'
    .'{osm_look_legend},osmKartenstil,osmMarkerForm,osmMarkerColor;'
    .'{osm_link_legend},osmLinkTyp,osmOverlayText;'
    .'{image_legend},size,overwriteMeta;'
    .'{template_legend:hide},customTpl;'
    .'{protected_legend:hide},protected;'
    .'{expert_legend:hide},guests,cssID;'
    .'{invisible_legend:hide},invisible,start,stop';

$GLOBALS['TL_DCA']['tl_content']['subpalettes']['overwriteMeta'] = 'alt,imageTitle,imageUrl,caption';
// Ohne diesen Eintrag bleibt die Unterpalette unsichtbar: Contao wertet sie nur für
// Felder aus, die als Selektor angemeldet sind — der Haken allein genügt nicht.
$GLOBALS['TL_DCA']['tl_content']['palettes']['__selector__'][] = 'osmMobil';
$GLOBALS['TL_DCA']['tl_content']['subpalettes']['osmMobil'] = 'osmWidthMobil,osmHeightMobil,osmMobilBis,osmSizeMobil';

$felder = [
    /*
     * Alle Markierungen der Karte in einem Feld — auch die erste. Die oberste Zeile hat
     * eine Sonderrolle: Sie ist die Hauptanschrift, auf die der Klick auf die Karte zeigt.
     * Im Balken lässt sich über {osm-2} auch jede weitere ansteuern.
     */
    'osmPoints' => [
        'inputType' => 'rowWizard',
        'exclude' => true,
        'fields' => [
            'anschrift' => [
                'label' => &$GLOBALS['TL_LANG']['tl_content']['osmPointsFelder']['anschrift'],
                'inputType' => 'text',
                'eval' => ['style' => 'width:340px', 'decodeEntities' => true],
            ],
            'beschriftung' => [
                'label' => &$GLOBALS['TL_LANG']['tl_content']['osmPointsFelder']['beschriftung'],
                'inputType' => 'text',
                'eval' => ['style' => 'width:260px', 'decodeEntities' => true],
            ],
        ],
        'eval' => [
            'tl_class' => 'clr',
            'mandatory' => true,
            // Eine Zeile ist immer da, damit das Feld nicht leer wirkt.
            'min' => 1,
            'sortable' => true,
            'actions' => ['copy', 'delete'],
        ],
        'sql' => [
            'type' => 'blob',
            'length' => AbstractMySQLPlatform::LENGTH_LIMIT_BLOB,
            'notnull' => false,
        ],
    ],
    'osmZoom' => [
        'inputType' => 'select',
        'exclude' => true,
        'default' => 'auto',
        'options' => array_merge(['auto'], array_map('strval', range(3, 19))),
        'reference' => &$GLOBALS['TL_LANG']['tl_content']['osmZoomStufen'],
        'eval' => ['tl_class' => 'w50', 'includeBlankOption' => false],
        'sql' => "varchar(4) NOT NULL default 'auto'",
    ],
    /*
     * Farbgebung der Karte. Die Kacheln bleiben dieselben von OpenStreetMap, umgefärbt wird
     * beim Speichern auf dem eigenen Server. Neue Karten bekommen „Freundlich"; Karten aus der
     * Zeit vor diesem Feld behalten die Originalfarben — dafür sorgt beim Update die
     * BisherigesAussehenMigration.
     */
    'osmKartenstil' => [
        'inputType' => 'select',
        'exclude' => true,
        'default' => MapStyle::VORGABE,
        'options' => MapStyle::AUSWAHL,
        'reference' => &$GLOBALS['TL_LANG']['tl_content']['osmKartenstile'],
        'eval' => ['tl_class' => 'w50', 'includeBlankOption' => false],
        'sql' => "varchar(16) NOT NULL default '".MapStyle::VORGABE."'",
    ],
    /*
     * Neue Karten bekommen den Pin wie bei Google Maps. Karten aus der Zeit vor diesem Feld
     * behalten den Punkt — dafür sorgt beim Update die BisherigesAussehenMigration.
     */
    'osmMarkerForm' => [
        'inputType' => 'select',
        'exclude' => true,
        'default' => MarkerRenderer::VORGABE,
        'options' => MarkerRenderer::AUSWAHL,
        'reference' => &$GLOBALS['TL_LANG']['tl_content']['osmMarkerFormen'],
        'eval' => ['tl_class' => 'w50 clr', 'includeBlankOption' => false],
        'sql' => "varchar(16) NOT NULL default '".MarkerRenderer::VORGABE."'",
    ],
    'osmMarkerColor' => [
        'inputType' => 'text',
        'exclude' => true,
        'default' => '#bf7500',
        'eval' => ['maxlength' => 7, 'colorpicker' => true, 'isHexColor' => true, 'decodeEntities' => true, 'tl_class' => 'w50 wizard'],
        'sql' => "varchar(7) NOT NULL default ''",
    ],
    'osmWidth' => [
        'inputType' => 'text',
        'exclude' => true,
        'default' => 1920,
        'eval' => ['rgxp' => 'natural', 'maxlength' => 4, 'tl_class' => 'w50 clr'],
        'sql' => 'smallint(5) unsigned NOT NULL default 1920',
    ],
    'osmHeight' => [
        'inputType' => 'text',
        'exclude' => true,
        'default' => 600,
        'eval' => ['rgxp' => 'natural', 'maxlength' => 4, 'tl_class' => 'w50'],
        'sql' => 'smallint(5) unsigned NOT NULL default 600',
    ],
    /*
     * Zweite Fassung des Kartenbildes für schmale Bildschirme.
     *
     * Ein breites, flaches Kartenbild wird auf dem Telefon entweder sehr klein oder es
     * zeigt nach dem Zuschneiden die Umgebung statt des Ortes. Mit dieser Einstellung
     * entsteht beim Speichern ein zweites Bild in eigenem Format — meist quadratisch —,
     * das der Browser unterhalb der eingestellten Breite von sich aus wählt.
     */
    'osmMobil' => [
        'inputType' => 'checkbox',
        'exclude' => true,
        'eval' => ['submitOnChange' => true, 'tl_class' => 'w50 m12'],
        'sql' => "char(1) NOT NULL default ''",
    ],
    'osmWidthMobil' => [
        'inputType' => 'text',
        'exclude' => true,
        'default' => 800,
        'eval' => ['rgxp' => 'natural', 'maxlength' => 4, 'tl_class' => 'w50 clr'],
        'sql' => 'smallint(5) unsigned NOT NULL default 800',
    ],
    'osmHeightMobil' => [
        'inputType' => 'text',
        'exclude' => true,
        'default' => 800,
        'eval' => ['rgxp' => 'natural', 'maxlength' => 4, 'tl_class' => 'w50'],
        'sql' => 'smallint(5) unsigned NOT NULL default 800',
    ],
    'osmMobilBis' => [
        'inputType' => 'text',
        'exclude' => true,
        'default' => 767,
        'eval' => ['rgxp' => 'natural', 'maxlength' => 4, 'tl_class' => 'w50 clr'],
        'sql' => 'smallint(5) unsigned NOT NULL default 767',
    ],
    /*
     * Eigene Bildgröße für die schmale Fassung: Das quadratische Bild hat ein anderes
     * Seitenverhältnis, eine auf das breite Bild zugeschnittene Größe würde es beschneiden.
     * Ohne Angabe liefert Contao die Datei unverkleinert aus — und die entsteht in
     * doppelter Auflösung.
     */
    'osmSizeMobil' => [
        'inputType' => 'imageSize',
        'exclude' => true,
        'reference' => &$GLOBALS['TL_LANG']['MSC'],
        'eval' => ['rgxp' => 'natural', 'includeBlankOption' => true, 'nospace' => true, 'helpwizard' => true, 'tl_class' => 'w50'],
        'sql' => "varchar(64) NOT NULL default ''",
    ],
    'osmLinkTyp' => [
        'inputType' => 'select',
        'exclude' => true,
        'default' => MapLinks::KARTE_OSM,
        'options' => MapLinks::AUSWAHL,
        'reference' => &$GLOBALS['TL_LANG']['tl_content']['osmLinkTypen'],
        'eval' => ['tl_class' => 'w50', 'includeBlankOption' => false],
        'sql' => "varchar(16) NOT NULL default '".MapLinks::KARTE_OSM."'",
    ],
    'osmOverlayText' => [
        'inputType' => 'text',
        'exclude' => true,
        'default' => 'Adresse öffnen in {osm} oder {google}',
        'eval' => ['maxlength' => 255, 'tl_class' => 'long clr', 'decodeEntities' => true],
        'sql' => "varchar(255) NOT NULL default ''",
    ],

    // --- vom System gefüllt, nicht in der Palette ---
    'osmImage' => [
        'sql' => 'binary(16) NULL',
    ],
    // Die zweite Fassung für schmale Bildschirme; leer, wenn keine gewünscht ist.
    'osmImageMobil' => [
        'sql' => 'binary(16) NULL',
    ],
    /*
     * Die gefundenen Koordinaten aller Markierungen als JSON, in der Reihenfolge des
     * Feldes „Markierungen": [{lat, lon, label, anzeigename}, …]. Die erste ist die
     * Hauptanschrift und damit das Ziel des Klicks auf die Karte; der Balken kann über
     * {osm-2} und dergleichen auch die übrigen verlinken.
     */
    'osmPositionen' => [
        'sql' => 'text NULL',
    ],
    'osmHash' => [
        'sql' => "varchar(32) NOT NULL default ''",
    ],
    // Der tatsächlich verwendete Maßstab. Bei „automatisch" wird er berechnet; das
    // Frontend braucht ihn für die Verweise.
    'osmZoomEffektiv' => [
        'sql' => 'smallint(5) unsigned NOT NULL default 15',
    ],
];

foreach ($felder as $name => $konfiguration) {
    if (isset($konfiguration['inputType'])) {
        $konfiguration['label'] = &$GLOBALS['TL_LANG']['tl_content'][$name];
    }

    $GLOBALS['TL_DCA']['tl_content']['fields'][$name] = $konfiguration;
}
