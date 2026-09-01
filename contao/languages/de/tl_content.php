<?php

declare(strict_types=1);

/*
 * This file is part of contao-osm-map-bundle.
 *
 * (c) heimseiten.de - Webdesign aus Köln 2026 <info@heimseiten.de>
 * @license GPL-3.0-or-later
 */

$GLOBALS['TL_LANG']['tl_content']['osm_legend'] = 'Karte';
$GLOBALS['TL_LANG']['tl_content']['osm_link_legend'] = 'Verlinkung und Beschriftung';

$GLOBALS['TL_LANG']['tl_content']['osmPoints'] = [
    'Markierungen',
    'Jede Zeile ergibt einen Punkt auf der Karte. Die <b>oberste Zeile ist die Hauptanschrift</b>: Auf sie zeigen der Klick auf die Karte und die Verweise im Balken. Anschriften am zuverlässigsten in der Form „Straße Hausnummer, PLZ Ort“; eine Postleitzahl oder ein Ortsteil genügen aber auch. Wo es keine Anschrift gibt — etwa in Neubaugebieten ohne Hausnummer — lassen sich Koordinaten eintragen: 50.9413035, 6.9581380. Die Beschriftung erscheint als kleines Schild neben dem Punkt und darf leer bleiben. Nach dem Speichern wird gemeldet, welche Position gefunden wurde.',
];
$GLOBALS['TL_LANG']['tl_content']['osmPointsFelder'] = [
    'anschrift' => 'Anschrift oder Koordinaten',
    'beschriftung' => 'Beschriftung (optional)',
];
$GLOBALS['TL_LANG']['tl_content']['osmZoom'] = [
    'Maßstab',
    'Je höher der Wert, desto näher die Ansicht. 15 zeigt die Straße mit Umgebung, 17 einzelne Häuser, 12 den ganzen Ort. „Automatisch“ richtet sich nach den gesetzten Markierungen und zeigt alle.',
];
$GLOBALS['TL_LANG']['tl_content']['osmMarkerColor'] = [
    'Farbe der Markierung',
    'Farbe der Punkte, die die Anschriften markieren.',
];
$GLOBALS['TL_LANG']['tl_content']['osmWidth'] = [
    'Breite des Kartenbildes',
    'In Pixeln, und zwar das Maß, in dem die Karte angezeigt wird. Die Datei selbst entsteht mit der doppelten Auflösung, damit sie auf hochauflösenden Bildschirmen scharf bleibt. 1920 deckt die volle Seitenbreite ab. Damit Besucher nicht die große Datei laden, gehört dem Element unten eine Bildgröße zugewiesen.',
];
$GLOBALS['TL_LANG']['tl_content']['osmHeight'] = [
    'Höhe des Kartenbildes',
    'In Pixeln. 600 ergibt zusammen mit 1920 ein flaches, breites Bild.',
];
$GLOBALS['TL_LANG']['tl_content']['osmMobil'] = [
    'Zweites Bild für schmale Bildschirme',
    'Ein breites, flaches Kartenbild wird auf dem Telefon entweder sehr klein oder es zeigt nach dem Zuschneiden die Umgebung statt des Ortes. Mit dieser Einstellung entsteht beim Speichern ein zweites Bild in eigenem Format — meist quadratisch. Der Browser wählt es unterhalb der eingestellten Breite von selbst; übertragen wird immer nur eines der beiden.',
];
$GLOBALS['TL_LANG']['tl_content']['osmWidthMobil'] = [
    'Breite des schmalen Kartenbildes',
    'In Pixeln, wie beim breiten Bild das Anzeigemaß. Zusammen mit derselben Höhe ergibt sich ein quadratisches Bild.',
];
$GLOBALS['TL_LANG']['tl_content']['osmHeightMobil'] = [
    'Höhe des schmalen Kartenbildes',
    'In Pixeln. Gleiche Werte für Breite und Höhe ergeben ein Quadrat — auf dem Telefon die übliche Wahl.',
];
$GLOBALS['TL_LANG']['tl_content']['osmMobilBis'] = [
    'Zweites Bild anzeigen bis',
    'Fensterbreite in Pixeln, bis zu der die schmale Fassung erscheint. Darüber gilt das breite Bild. 767 trennt das Telefon vom Tablet.',
];
$GLOBALS['TL_LANG']['tl_content']['osmSizeMobil'] = [
    'Bildgröße für das schmale Bild',
    'Eine eigene Bildgröße ist nötig, weil das Seitenverhältnis von dem des breiten Bildes abweicht — eine darauf zugeschnittene Größe würde das Quadrat beschneiden. Ohne Angabe wird die Datei unverkleinert ausgeliefert, und sie entsteht in doppelter Auflösung.',
];
$GLOBALS['TL_LANG']['tl_content']['osmLinkTyp'] = [
    'Klick auf die Karte',
    'Wohin ein Klick auf das Kartenbild führt — bei mehreren Markierungen zur Hauptanschrift, also zur obersten Zeile. Der Link öffnet sich in einem neuen Fenster. Bei Google Maps verlässt der Besucher diese Website und landet bei Google — geladen wird vorher nichts.',
];
$GLOBALS['TL_LANG']['tl_content']['osmOverlayText'] = [
    'Beschriftung über dem unteren Bildrand',
    'Erscheint als schmaler, halbdurchsichtiger Balken auf der Karte. Diese Platzhalter werden zu Links: <b>{osm}</b> Karte bei OpenStreetMap · <b>{google}</b> Karte bei Google Maps · <b>{route}</b> Routenplanung bei OpenStreetMap · <b>{route-google}</b> Routenplanung bei Google Maps. So lassen sich auch mehrere Ziele in einem Satz nennen. Eigene Beschriftung mit {osm:Karte ansehen}.<br>Bei mehreren Markierungen wählt eine angehängte Nummer die gemeinte aus: <b>{osm-2}</b> führt zur zweiten Markierung und trägt deren Beschriftung als Linktext — etwa „Standorte: {osm-1} und {osm-2}“. Ohne Nummer gilt die erste Markierung und der Name des Dienstes. Leer lassen, wenn kein Balken erscheinen soll.',
];

$GLOBALS['TL_LANG']['tl_content']['osmLinkTypen'] = [
    'karte_osm' => 'Karte öffnen in OpenStreetMap',
    'karte_google' => 'Karte öffnen in Google Maps',
    'route_osm' => 'Routenplanung öffnen in OpenStreetMap',
    'route_google' => 'Routenplanung öffnen in Google Maps',
    'keiner' => 'nicht verlinken',
];

// Maßstab: die Zahl allein sagt wenig, deshalb eine kurze Erläuterung dahinter.
$GLOBALS['TL_LANG']['tl_content']['osmZoomStufen'] = [
    'auto' => 'automatisch – zeigt alle Markierungen (Vorgabe)',
    3 => '3 – Kontinent',
    4 => '4',
    5 => '5 – Land',
    6 => '6',
    7 => '7 – Region',
    8 => '8',
    9 => '9 – Landkreis',
    10 => '10 – Umgebung des Ortes',
    11 => '11',
    12 => '12 – ganzer Ort',
    13 => '13 – Stadtteil',
    14 => '14 – Viertel',
    15 => '15 – Straße mit Umgebung',
    16 => '16 – Straßenzug',
    17 => '17 – einzelne Häuser',
    18 => '18 – Gebäude',
    19 => '19 – Gebäude, ganz nah',
];
