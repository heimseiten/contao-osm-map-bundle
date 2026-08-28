<?php

declare(strict_types=1);

/*
 * This file is part of contao-osm-map-bundle.
 *
 * (c) heimseiten.de - Webdesign aus Köln 2026 <info@heimseiten.de>
 * @license GPL-3.0-or-later
 */

$GLOBALS['TL_LANG']['tl_content']['osm_legend'] = 'Map';
$GLOBALS['TL_LANG']['tl_content']['osm_link_legend'] = 'Links and caption';

$GLOBALS['TL_LANG']['tl_content']['osmPoints'] = [
    'Markers',
    'Each row adds one point to the map. The <b>first row is the main address</b>: both the click on the map and the links in the caption bar refer to it. Addresses work best as “Street Number, Postcode City”, but a postcode or a district will do. Where no address exists — a new development without house numbers, for instance — coordinates can be entered instead: 50.9413035, 6.9581380. The caption appears as a small sign next to the point and may stay empty. After saving, a message states which position was found.',
];
$GLOBALS['TL_LANG']['tl_content']['osmPointsFelder'] = [
    'anschrift' => 'Address or coordinates',
    'beschriftung' => 'Caption (optional)',
];
$GLOBALS['TL_LANG']['tl_content']['osmZoom'] = [
    'Scale',
    'The higher the value, the closer the view. 15 shows the street and its surroundings, 17 individual houses, 12 the whole town. “Automatic” follows the markers that are set and shows all of them.',
];
$GLOBALS['TL_LANG']['tl_content']['osmMarkerColor'] = [
    'Marker colour',
    'Colour of the dots that mark the addresses.',
];
$GLOBALS['TL_LANG']['tl_content']['osmWidth'] = [
    'Map width',
    'In pixels, and the size at which the map is displayed. The file itself is generated at twice that resolution so it stays sharp on high-resolution screens. 1920 covers the full page width. To keep visitors from loading the large file, assign an image size below.',
];
$GLOBALS['TL_LANG']['tl_content']['osmHeight'] = [
    'Map height',
    'In pixels. Together with a width of 1920, a height of 600 gives a flat, wide image.',
];
$GLOBALS['TL_LANG']['tl_content']['osmLinkTyp'] = [
    'Click on the map',
    'Where a click on the map image leads — with several markers, to the main address in the first row. The link opens in a new window. With Google Maps the visitor leaves this website and ends up at Google — nothing is loaded beforehand.',
];
$GLOBALS['TL_LANG']['tl_content']['osmOverlayText'] = [
    'Caption above the bottom edge',
    'Appears as a narrow, semi-transparent bar on the map. These placeholders turn into links: <b>{osm}</b> map at OpenStreetMap · <b>{google}</b> map at Google Maps · <b>{route}</b> directions at OpenStreetMap · <b>{route-google}</b> directions at Google Maps. That way a single sentence can name several destinations. A custom label is written as {osm:View the map}.<br>With several markers, an appended number selects the one meant: <b>{osm-2}</b> refers to the second marker and carries its caption as the link text — for example “Locations: {osm-1} and {osm-2}”. Without a number, the first marker and the name of the service apply. Leave empty for no bar.',
];

$GLOBALS['TL_LANG']['tl_content']['osmLinkTypen'] = [
    'karte_osm' => 'Open map in OpenStreetMap',
    'karte_google' => 'Open map in Google Maps',
    'route_osm' => 'Open directions in OpenStreetMap',
    'route_google' => 'Open directions in Google Maps',
    'keiner' => 'do not link',
];

// The number alone says little, hence a short explanation after it.
$GLOBALS['TL_LANG']['tl_content']['osmZoomStufen'] = [
    'auto' => 'automatic – shows all markers (default)',
    3 => '3 – continent',
    4 => '4',
    5 => '5 – country',
    6 => '6',
    7 => '7 – region',
    8 => '8',
    9 => '9 – district',
    10 => '10 – area around the town',
    11 => '11',
    12 => '12 – whole town',
    13 => '13 – quarter',
    14 => '14 – neighbourhood',
    15 => '15 – street and surroundings',
    16 => '16 – block',
    17 => '17 – individual houses',
    18 => '18 – buildings',
    19 => '19 – buildings, very close',
];
