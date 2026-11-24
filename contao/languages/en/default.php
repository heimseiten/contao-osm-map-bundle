<?php

declare(strict_types=1);

/*
 * This file is part of contao-osm-map-bundle.
 *
 * (c) heimseiten.de - Webdesign aus Köln 2026 <info@heimseiten.de>
 * @license GPL-3.0-or-later
 */

// Name des Elements in der Auswahlliste
$GLOBALS['TL_LANG']['CTE']['osm_map'] = ['Map (OpenStreetMap)', 'Shows a map for one or more addresses. The map image is generated on the site\'s own server when saving; no third-party service is contacted when the page is viewed.'];

// Interactive map: texts in the browser
$GLOBALS['TL_LANG']['MSC']['osmInteraktiv'] = [
    'karte' => 'Map',
    'neuerTab' => '(opens in a new tab)',
    'zoomIn' => 'Zoom in',
    'zoomOut' => 'Zoom out',
    'kompass' => 'Reset bearing to north',
    'namensnennung' => 'Toggle attribution',
    'gestenWindows' => 'Use Ctrl + scroll to zoom the map',
    'gestenMac' => 'Use ⌘ + scroll to zoom the map',
    'gestenMobil' => 'Use two fingers to move the map',
    'links' => [
        'route_google' => 'Directions with Google Maps',
        'route_osm' => 'Directions with OpenStreetMap',
        'karte_google' => 'Open in Google Maps',
        'karte_osm' => 'Open in OpenStreetMap',
    ],
];
