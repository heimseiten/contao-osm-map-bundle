<?php

declare(strict_types=1);

/*
 * This file is part of contao-osm-map-bundle.
 *
 * (c) heimseiten.de - Webdesign aus Köln 2026 <info@heimseiten.de>
 * @license GPL-3.0-or-later
 */

// Name des Elements in der Auswahlliste
$GLOBALS['TL_LANG']['CTE']['osm_map'] = ['Karte (OpenStreetMap)', 'Zeigt eine Karte zu einer oder mehreren Anschriften. Das Kartenbild entsteht beim Speichern auf dem eigenen Server; beim Aufruf der Seite wird kein fremder Dienst angefragt.'];

// Bewegliche Karte: Texte im Browser
$GLOBALS['TL_LANG']['MSC']['osmInteraktiv'] = [
    'karte' => 'Karte',
    'neuerTab' => '(öffnet in neuem Tab)',
    'zoomIn' => 'Vergrößern',
    'zoomOut' => 'Verkleinern',
    'kompass' => 'Nach Norden ausrichten',
    'namensnennung' => 'Quellenangaben ein- und ausblenden',
    'gestenWindows' => 'Zum Zoomen Strg gedrückt halten und scrollen',
    'gestenMac' => 'Zum Zoomen ⌘ gedrückt halten und scrollen',
    'gestenMobil' => 'Karte mit zwei Fingern bewegen',
    'links' => [
        'route_google' => 'Anfahrt mit Google Maps',
        'route_osm' => 'Anfahrt mit OpenStreetMap',
        'karte_google' => 'In Google Maps öffnen',
        'karte_osm' => 'In OpenStreetMap öffnen',
    ],
];
