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
 * Setzt aus den Kartenkacheln von OpenStreetMap ein einzelnes Bild zusammen, setzt die
 * Markierungen samt Beschriftung und brennt die Namensnennung ein.
 *
 * Läuft nur beim Speichern im Backend. Das fertige Bild liegt danach im Dateimanager und
 * wird von der eigenen Website ausgeliefert — beim Seitenaufruf geht nichts an fremde Server.
 *
 * Die Nutzungsbedingungen für die Kacheln verlangen einen aussagekräftigen User-Agent,
 * verbieten massenhaftes Herunterladen und schreiben die Namensnennung vor. Deshalb:
 * je Karte nur die wenigen benötigten Kacheln, und das Ergebnis wird gespeichert statt
 * bei jedem Aufruf neu geholt.
 *
 * @see https://operations.osmfoundation.org/policies/tiles/
 */
class MapImageRenderer
{
    private const KACHEL = 256;
    private const TILE_URL = 'https://tile.openstreetmap.org/%d/%d/%d.png';

    /** Pflichtangabe nach der Lizenz der OpenStreetMap-Daten (ODbL). */
    private const NAMENSNENNUNG = '© OpenStreetMap-Mitwirkende';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $userAgent,
    ) {
    }

    /**
     * Erzeugt das Kartenbild und gibt die JPEG-Daten zurück.
     *
     * Die Maße $breite/$hoehe sind die ANZEIGEmaße. Das Bild entsteht mit der
     * $dichte-fachen Auflösung, damit es auf hochauflösenden Bildschirmen (Retina) scharf
     * bleibt: Dort besteht ein CSS-Pixel aus zwei Bildpunkten, ein 1:1 gerendertes Bild
     * wirkt daher weich. Für die Karte selbst wird dazu eine Zoomstufe tiefer geladen —
     * derselbe Ausschnitt, aber mit doppelt so vielen Kacheln.
     *
     * @param list<array{lat: float, lon: float, label?: string}> $punkte    zu markierende Orte
     * @param int                                                 $dichte    1 = einfach, 2 = für Retina
     * @param int                                                 $qualitaet JPEG-Qualität 1–100
     *
     * @throws OsmMapException
     */
    public function rendern(
        array $punkte,
        float $lat,
        float $lon,
        int $zoom,
        int $breite,
        int $hoehe,
        string $markerFarbe = '#bf7500',
        int $dichte = 2,
        int $qualitaet = 82,
    ): string {
        if (!\function_exists('imagecreatetruecolor')) {
            throw new OsmMapException('Auf diesem Server fehlt die Bildbibliothek GD; ohne sie lässt sich kein Kartenbild erzeugen.');
        }

        $dichte = $dichte >= 2 ? 2 : 1;

        // Zielmaße des fertigen Bildes
        $zielBreite = $breite * $dichte;
        $zielHoehe = $hoehe * $dichte;

        // Eine Zoomstufe tiefer zeigt denselben Ausschnitt mit doppelt so vielen Pixeln.
        // Bei Stufe 19 ist Schluss — dort wird stattdessen hochgerechnet.
        $kachelZoom = min($zoom + $dichte - 1, 19);
        $faktor = 2 ** ($kachelZoom - $zoom);

        // Maße des Ausschnitts in den geladenen Kacheln
        $rohBreite = (int) round($breite * $faktor);
        $rohHoehe = (int) round($hoehe * $faktor);

        [$kachelX, $kachelY, $versatzX, $versatzY] = $this->kachelPosition($lat, $lon, $kachelZoom);

        // So viele Kacheln je Richtung, dass der gewünschte Ausschnitt sicher abgedeckt ist:
        // die halbe Bildbreite plus eine Kachel Reserve für den Versatz innerhalb der Kachel.
        $spannX = (int) ceil(($rohBreite / 2 + self::KACHEL) / self::KACHEL);
        $spannY = (int) ceil(($rohHoehe / 2 + self::KACHEL) / self::KACHEL);

        $gesamtBreite = (2 * $spannX + 1) * self::KACHEL;
        $gesamtHoehe = (2 * $spannY + 1) * self::KACHEL;

        $flaeche = imagecreatetruecolor($gesamtBreite, $gesamtHoehe);

        if (false === $flaeche) {
            throw new OsmMapException('Das Kartenbild konnte nicht angelegt werden.');
        }

        // Hellgrauer Grund: fehlt am Rand eine Kachel (z. B. am Kartenrand der Welt),
        // bleibt dort eine neutrale Fläche statt Schwarz.
        imagefill($flaeche, 0, 0, imagecolorallocate($flaeche, 242, 242, 242));

        $maxKachel = 2 ** $kachelZoom;

        foreach ($this->kacheln($kachelX, $kachelY, $spannX, $spannY, $kachelZoom, $maxKachel) as [$dx, $dy, $daten]) {
            $kachel = @imagecreatefromstring($daten);

            if (false === $kachel) {
                continue; // einzelne unlesbare Kachel: Grundfläche bleibt stehen
            }

            imagecopy(
                $flaeche,
                $kachel,
                ($dx + $spannX) * self::KACHEL,
                ($dy + $spannY) * self::KACHEL,
                0,
                0,
                self::KACHEL,
                self::KACHEL,
            );
            imagedestroy($kachel);
        }

        // Mittelpunkt innerhalb der zusammengesetzten Fläche
        $mitteX = $spannX * self::KACHEL + $versatzX;
        $mitteY = $spannY * self::KACHEL + $versatzY;

        // Auf die Zielgröße bringen, Mittelpunkt bleibt mittig. Stimmen Ausschnitt und
        // Ziel überein (Regelfall), kopiert imagecopyresampled unverändert.
        $ausschnitt = imagecreatetruecolor($zielBreite, $zielHoehe);
        imagecopyresampled(
            $ausschnitt,
            $flaeche,
            0,
            0,
            (int) round($mitteX - $rohBreite / 2),
            (int) round($mitteY - $rohHoehe / 2),
            $zielBreite,
            $zielHoehe,
            $rohBreite,
            $rohHoehe,
        );
        imagedestroy($flaeche);

        // Markierungen erst im fertigen Zuschnitt setzen: So lässt sich ein Schild, das
        // rechts über den Rand ragen würde, nach links klappen. Gerechnet wird im Maßstab
        // des Zielbildes — es zeigt denselben Ausschnitt wie $zoom, nur $dichte-fach fein.
        $this->markierungenZeichnen($ausschnitt, $punkte, $lat, $lon, $zoom, $zielBreite, $zielHoehe, $markerFarbe, $dichte);
        $this->namensnennungZeichnen($ausschnitt, $zielBreite, $dichte);

        ob_start();
        imagejpeg($ausschnitt, null, $qualitaet);
        $daten = (string) ob_get_clean();
        imagedestroy($ausschnitt);

        return $daten;
    }

    /**
     * Setzt je Punkt eine Markierung und – sofern hinterlegt – ein Beschriftungsschild.
     *
     * @param list<array{lat: float, lon: float, label?: string}> $punkte
     */
    private function markierungenZeichnen(
        \GdImage $bild,
        array $punkte,
        float $mittelLat,
        float $mittelLon,
        int $zoom,
        int $breite,
        int $hoehe,
        string $farbe,
        int $dichte = 1,
    ): void {
        [$r, $g, $b] = $this->farbeZerlegen($farbe);
        $fuellung = imagecolorallocate($bild, $r, $g, $b);
        $weiss = imagecolorallocate($bild, 255, 255, 255);
        $schriftdatei = $this->schriftFinden();

        foreach ($punkte as $punkt) {
            [$x, $y] = $this->bildPosition($punkt['lat'], $punkt['lon'], $mittelLat, $mittelLon, $zoom, $breite, $hoehe, $dichte);

            // Punkte außerhalb des Ausschnitts überspringen
            if ($x < -20 * $dichte || $x > $breite + 20 * $dichte || $y < -20 * $dichte || $y > $hoehe + 20 * $dichte) {
                continue;
            }

            imagefilledellipse($bild, $x, $y, 22 * $dichte, 22 * $dichte, $weiss);
            imagefilledellipse($bild, $x, $y, 16 * $dichte, 16 * $dichte, $fuellung);

            $text = trim((string) ($punkt['label'] ?? ''));

            // Ohne TrueType-Schrift kein Schild: Die eingebauten GD-Schriften können keine
            // Umlaute, „Straße" käme als „Stra?e" heraus.
            if ('' !== $text && null !== $schriftdatei) {
                $this->schildZeichnen($bild, $text, $x, $y, $breite, $schriftdatei, $fuellung, $dichte);
            }
        }
    }

    /**
     * Beschriftungsschild neben einer Markierung: helles Kästchen mit Innenabstand.
     *
     * Es steht rechts vom Punkt; reicht der Platz dort nicht, klappt es nach links.
     */
    private function schildZeichnen(
        \GdImage $bild,
        string $text,
        int $x,
        int $y,
        int $breite,
        string $schriftdatei,
        int $textfarbe,
        int $dichte = 1,
    ): void {
        $groesse = 16 * $dichte;
        $innen = 10 * $dichte;
        $abstand = 16 * $dichte; // Luft zwischen Punkt und Schild

        $rahmen = imagettfbbox($groesse, 0, $schriftdatei, $text);
        $textBreite = $rahmen[2] - $rahmen[0];
        $textHoehe = abs($rahmen[7] - $rahmen[1]);

        $kastenBreite = $textBreite + 2 * $innen;
        $kastenHoehe = $textHoehe + 2 * $innen;

        $links = $x + $abstand;

        if ($links + $kastenBreite > $breite - 4 * $dichte) {
            $links = $x - $abstand - $kastenBreite;   // kein Platz rechts: nach links klappen
        }

        $oben = (int) round($y - $kastenHoehe / 2);

        imagefilledrectangle(
            $bild,
            $links,
            $oben,
            $links + $kastenBreite,
            $oben + $kastenHoehe,
            imagecolorallocatealpha($bild, 255, 255, 255, 25),
        );

        imagettftext($bild, $groesse, 0, $links + $innen, $oben + $innen + $textHoehe, $textfarbe, $schriftdatei, $text);
    }

    /**
     * Bildkoordinaten eines Punktes, bezogen auf den Mittelpunkt des Ausschnitts.
     *
     * @return array{0: int, 1: int}
     */
    private function bildPosition(float $lat, float $lon, float $mittelLat, float $mittelLon, int $zoom, int $breite, int $hoehe, int $dichte = 1): array
    {
        [$px, $py] = $this->weltPixel($lat, $lon, $zoom);
        [$mx, $my] = $this->weltPixel($mittelLat, $mittelLon, $zoom);

        // Ein Weltpixel bei $zoom entspricht $dichte Punkten im Zielbild.
        $px *= $dichte;
        $py *= $dichte;
        $mx *= $dichte;
        $my *= $dichte;

        return [
            (int) round($breite / 2 + ($px - $mx)),
            (int) round($hoehe / 2 + ($py - $my)),
        ];
    }

    /**
     * Lädt die benötigten Kacheln.
     *
     * @return \Generator<array{0: int, 1: int, 2: string}>
     */
    private function kacheln(int $kachelX, int $kachelY, int $spannX, int $spannY, int $zoom, int $maxKachel): \Generator
    {
        $anfragen = [];

        for ($dy = -$spannY; $dy <= $spannY; ++$dy) {
            for ($dx = -$spannX; $dx <= $spannX; ++$dx) {
                $x = $kachelX + $dx;
                $y = $kachelY + $dy;

                // Waagerecht läuft die Welt um, senkrecht nicht.
                $x = (($x % $maxKachel) + $maxKachel) % $maxKachel;

                if ($y < 0 || $y >= $maxKachel) {
                    continue;
                }

                $anfragen[] = [$dx, $dy, $this->httpClient->request('GET', \sprintf(self::TILE_URL, $zoom, $x, $y), [
                    'headers' => ['User-Agent' => $this->userAgent],
                    'timeout' => 20,
                ])];
            }
        }

        $fehler = 0;

        foreach ($anfragen as [$dx, $dy, $antwort]) {
            try {
                yield [$dx, $dy, $antwort->getContent()];
            } catch (\Throwable) {
                ++$fehler;
            }
        }

        // Einzelne Ausfälle sind verschmerzbar, ein Totalausfall nicht.
        if ($fehler > 0 && $fehler === \count($anfragen)) {
            throw new OsmMapException('Die Kartendaten von OpenStreetMap sind zurzeit nicht erreichbar. Bitte später noch einmal speichern.');
        }
    }

    /**
     * Position in Pixeln auf der Weltkarte der jeweiligen Zoomstufe.
     *
     * @return array{0: float, 1: float}
     */
    private function weltPixel(float $lat, float $lon, int $zoom): array
    {
        $n = 2 ** $zoom * self::KACHEL;

        return [
            ($lon + 180) / 360 * $n,
            (1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * $n,
        ];
    }

    /**
     * Kachelnummer und Versatz innerhalb der Kachel für eine Position (Slippy-Map-Schema).
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function kachelPosition(float $lat, float $lon, int $zoom): array
    {
        [$px, $py] = $this->weltPixel($lat, $lon, $zoom);
        $kachelX = (int) floor($px / self::KACHEL);
        $kachelY = (int) floor($py / self::KACHEL);

        return [
            $kachelX,
            $kachelY,
            (int) ($px - $kachelX * self::KACHEL),
            (int) ($py - $kachelY * self::KACHEL),
        ];
    }

    /**
     * Namensnennung auf hellem Balken — Pflicht nach der Lizenz der Daten.
     *
     * Sie sitzt OBEN rechts, nicht unten: Unten liegt die Beschriftung des Elements
     * („Adresse öffnen in …"), die als HTML über dem Bild liegt und die Namensnennung sonst
     * überdecken würde. Ein fester Freiraum am rechten Rand hilft dort nicht — das Bild wird
     * je nach Fensterbreite skaliert, die eingebrannte Schrift schrumpft also mit, ein
     * CSS-Abstand in Pixeln aber nicht. Oben ist die Angabe unabhängig davon immer frei.
     *
     * Bevorzugt mit einer TrueType-Schrift, weil die eingebauten GD-Schriften nur ASCII
     * zuverlässig darstellen: Das Zeichen „©" erscheint dort je nach Server als „Š" oder
     * „Â©". Findet sich keine Schrift, wird auf „(c)" ausgewichen — die Namensnennung
     * bleibt damit in jedem Fall lesbar.
     */
    private function namensnennungZeichnen(\GdImage $bild, int $breite, int $dichte = 1): void
    {
        $schriftdatei = $this->schriftFinden();
        $farbe = imagecolorallocate($bild, 51, 51, 51);

        if (null !== $schriftdatei) {
            $groesse = 11 * $dichte;
            $rahmen = imagettfbbox($groesse, 0, $schriftdatei, self::NAMENSNENNUNG);
            $textBreite = $rahmen[2] - $rahmen[0];
            $textHoehe = abs($rahmen[7] - $rahmen[1]);

            $x = $breite - $textBreite - 6 * $dichte;
            $grundlinie = $textHoehe + 6 * $dichte;

            $this->balkenZeichnen($bild, $x - 5 * $dichte, 0, $breite, $grundlinie + 4 * $dichte);
            imagettftext($bild, $groesse, 0, $x, $grundlinie, $farbe, $schriftdatei, self::NAMENSNENNUNG);

            return;
        }

        // Ohne TrueType: eingebaute Schrift, ASCII-Schreibweise.
        $text = str_replace('©', '(c)', self::NAMENSNENNUNG);
        $schrift = 2;
        $textBreite = imagefontwidth($schrift) * \strlen($text);
        $textHoehe = imagefontheight($schrift);

        $x = $breite - $textBreite - 4;

        $this->balkenZeichnen($bild, $x - 3, 0, $breite, $textHoehe + 6);
        imagestring($bild, $schrift, $x, 3, $text, $farbe);
    }

    private function balkenZeichnen(\GdImage $bild, int $x1, int $y1, int $x2, int $y2): void
    {
        imagefilledrectangle($bild, $x1, $y1, $x2 - 1, $y2, imagecolorallocatealpha($bild, 255, 255, 255, 40));
    }

    /**
     * Sucht eine TrueType-Schrift an den Stellen, an denen üblicherweise eine liegt.
     *
     * Öffentlich, weil auch andere Stellen wissen müssen, ob Beschriftungen möglich sind —
     * ohne TrueType blieben sie stumm weg.
     *
     * @return string|null Pfad oder null, wenn keine gefunden wurde
     */
    public function schriftFinden(): string|null
    {
        static $gesucht = false;
        static $pfad = null;

        if ($gesucht) {
            return $pfad;
        }

        $gesucht = true;

        if (!\function_exists('imagettftext')) {
            return null;
        }

        $kandidaten = [
            // Mitgeliefert: So sind Beschriftungen auf JEDEM Server möglich, auch wenn
            // dort keine Schrift installiert ist (auf reinen Webhostings die Regel).
            \dirname(__DIR__, 2).'/res/fonts/DejaVuSans-latin.ttf',
            // Linux (die meisten Webserver)
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            // macOS (Entwicklungsrechner)
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/Library/Fonts/Arial.ttf',
        ];

        foreach ($kandidaten as $kandidat) {
            if (is_file($kandidat) && is_readable($kandidat)) {
                return $pfad = $kandidat;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function farbeZerlegen(string $farbe): array
    {
        $farbe = ltrim(trim($farbe), '#');

        if (3 === \strlen($farbe)) {
            $farbe = $farbe[0].$farbe[0].$farbe[1].$farbe[1].$farbe[2].$farbe[2];
        }

        if (!preg_match('/^[0-9a-f]{6}$/i', $farbe)) {
            $farbe = 'bf7500';
        }

        return [
            (int) hexdec(substr($farbe, 0, 2)),
            (int) hexdec(substr($farbe, 2, 2)),
            (int) hexdec(substr($farbe, 4, 2)),
        ];
    }
}
