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
 * verbieten massenhaftes Herunterladen, schreiben die Namensnennung vor und verlangen,
 * Kacheln lokal vorzuhalten. Deshalb: je Karte nur die Kacheln, die der Ausschnitt
 * berührt; geladene Kacheln bleiben eine Woche im Zwischenspeicher, und das fertige Bild
 * wird gespeichert statt bei jedem Aufruf neu erzeugt.
 *
 * @see https://operations.osmfoundation.org/policies/tiles/
 */
class MapImageRenderer
{
    private const KACHEL = 256;
    private const TILE_URL = 'https://tile.openstreetmap.org/%d/%d/%d.png';

    /** Pflichtangabe nach der Lizenz der OpenStreetMap-Daten (ODbL). */
    private const NAMENSNENNUNG = '© OpenStreetMap-Mitwirkende';

    /**
     * So lange bleibt eine geladene Kachel im Zwischenspeicher. Die Nutzungsbedingungen
     * verlangen, Kacheln nach den Cache-Angaben des Servers vorzuhalten oder, wo diese nicht
     * ausgewertet werden, mindestens sieben Tage. Wer Kartenstil oder Markierung ausprobiert,
     * erzeugt so nicht jedes Mal dieselben Anfragen.
     */
    private const VORHALTEN = 7 * 24 * 3600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $userAgent,
        private readonly MarkerRenderer $marker,
        private readonly MapStyle $stil,
        private readonly string $projectDir = '',
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
     * @param list<array{lat: float, lon: float, label?: string}> $punkte     zu markierende Orte
     * @param int                                                 $dichte     1 = einfach, 2 = für Retina
     * @param int                                                 $qualitaet  JPEG-Qualität 1–100
     * @param string                                              $markerForm eine der Formen aus MarkerRenderer::AUSWAHL
     * @param string                                              $kartenstil einer der Stile aus MapStyle::AUSWAHL
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
        string $markerForm = MarkerRenderer::BISHER,
        string $kartenstil = MapStyle::STANDARD,
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

        // Linke obere Ecke des Ausschnitts, in Pixeln der Weltkarte dieser Zoomstufe. Die
        // Mitte liegt auf dem ganzen Pixel, in dem der Mittelpunkt liegt.
        [$weltX, $weltY] = $this->weltPixel($lat, $lon, $kachelZoom);
        $links = (int) round(floor($weltX) - $rohBreite / 2);
        $oben = (int) round(floor($weltY) - $rohHoehe / 2);

        // Genau die Kacheln, die der Ausschnitt berührt
        $ersteX = (int) floor($links / self::KACHEL);
        $letzteX = (int) floor(($links + $rohBreite - 1) / self::KACHEL);
        $ersteY = (int) floor($oben / self::KACHEL);
        $letzteY = (int) floor(($oben + $rohHoehe - 1) / self::KACHEL);

        $flaeche = imagecreatetruecolor(($letzteX - $ersteX + 1) * self::KACHEL, ($letzteY - $ersteY + 1) * self::KACHEL);

        if (false === $flaeche) {
            throw new OsmMapException('Das Kartenbild konnte nicht angelegt werden.');
        }

        // Hellgrauer Grund: fehlt am Rand eine Kachel (z. B. am Kartenrand der Welt),
        // bleibt dort eine neutrale Fläche statt Schwarz — im Ton des gewählten Stils.
        [$r, $g, $b] = $this->stil->farbe($kartenstil, 242, 242, 242);
        imagefill($flaeche, 0, 0, imagecolorallocate($flaeche, $r, $g, $b));

        foreach ($this->kacheln($ersteX, $letzteX, $ersteY, $letzteY, $kachelZoom) as [$kx, $ky, $daten]) {
            $kachel = @imagecreatefromstring($daten);

            if (false === $kachel) {
                continue; // einzelne unlesbare Kachel: Grundfläche bleibt stehen
            }

            $this->stil->anwenden($kachel, $kartenstil);

            imagecopy(
                $flaeche,
                $kachel,
                ($kx - $ersteX) * self::KACHEL,
                ($ky - $ersteY) * self::KACHEL,
                0,
                0,
                self::KACHEL,
                self::KACHEL,
            );
        }

        // Auf die Zielgröße bringen, Mittelpunkt bleibt mittig. Stimmen Ausschnitt und
        // Ziel überein (Regelfall), kopiert imagecopyresampled unverändert.
        $ausschnitt = imagecreatetruecolor($zielBreite, $zielHoehe);
        imagecopyresampled(
            $ausschnitt,
            $flaeche,
            0,
            0,
            $links - $ersteX * self::KACHEL,
            $oben - $ersteY * self::KACHEL,
            $zielBreite,
            $zielHoehe,
            $rohBreite,
            $rohHoehe,
        );

        // Die große Fläche aus allen Kacheln wird nicht mehr gebraucht — Speicher gleich
        // freigeben (imagedestroy() wirkt seit PHP 8.0 nicht mehr und ist ab 8.5 veraltet).
        unset($flaeche);

        // Markierungen erst im fertigen Zuschnitt setzen: So lässt sich ein Schild, das
        // rechts über den Rand ragen würde, nach links klappen. Gerechnet wird im Maßstab
        // des Zielbildes — es zeigt denselben Ausschnitt wie $zoom, nur $dichte-fach fein.
        $this->markierungenZeichnen($ausschnitt, $punkte, $lat, $lon, $zoom, $zielBreite, $zielHoehe, $markerFarbe, $markerForm, $dichte);
        $this->namensnennungZeichnen($ausschnitt, $zielBreite, $dichte);

        ob_start();
        imagejpeg($ausschnitt, null, $qualitaet);
        $daten = (string) ob_get_clean();

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
        string $form,
        int $dichte = 1,
    ): void {
        $fuellung = $this->farbeZerlegen($farbe);
        $schriftdatei = $this->schriftFinden();
        $zeichenschrift = $this->zeichenschriftFinden();

        // Das Schild ist weiß, sein Text trägt die Farbe der Markierung. Ist die so hell,
        // dass sie darauf kaum zu lesen wäre (Gelb, Hellgrün), wird der Text dunkler.
        $schildText = $fuellung;

        while ($this->marker->helligkeit($schildText) > 0.3 && array_sum($schildText) > 0) {
            $schildText = $this->marker->farbeMischen($schildText, [0, 0, 0], 0.15);
        }

        $textfarbe = imagecolorallocate($bild, $schildText[0], $schildText[1], $schildText[2]);

        foreach ($punkte as $index => $punkt) {
            [$x, $y] = $this->bildPosition($punkt['lat'], $punkt['lon'], $mittelLat, $mittelLon, $zoom, $breite, $hoehe, $dichte);

            // Punkte außerhalb des Ausschnitts überspringen. Der Rand ist so bemessen, dass
            // ein Pin, dessen Spitze knapp unter dem Bild liegt, mit dem Kopf noch zu sehen ist.
            if ($x < -60 * $dichte || $x > $breite + 60 * $dichte || $y < -60 * $dichte || $y > $hoehe + 60 * $dichte) {
                continue;
            }

            $lage = $this->marker->zeichnen($bild, $form, $x, $y, $fuellung, $dichte, $this->marker->zeichen($form, $index), $zeichenschrift);

            $text = trim((string) ($punkt['label'] ?? ''));

            // Ohne TrueType-Schrift kein Schild: Die eingebauten GD-Schriften können keine
            // Umlaute, „Straße" käme als „Stra?e" heraus.
            if ('' !== $text && null !== $schriftdatei) {
                $this->schildZeichnen($bild, $text, $lage, $breite, $schriftdatei, $textfarbe, $dichte);
            }
        }
    }

    /**
     * Beschriftungsschild neben einer Markierung: helles Kästchen mit Innenabstand.
     *
     * Es steht rechts von der Markierung, auf halber Höhe ihres Kopfes; reicht der Platz
     * dort nicht, klappt es nach links.
     *
     * @param array{links: int, rechts: int, mitte: int} $lage wo die Markierung im Bild steht
     */
    private function schildZeichnen(
        \GdImage $bild,
        string $text,
        array $lage,
        int $breite,
        string $schriftdatei,
        int $textfarbe,
        int $dichte = 1,
    ): void {
        $groesse = 16 * $dichte;
        $innen = 10 * $dichte;
        $luft = 5 * $dichte; // zwischen Markierung und Schild

        $rahmen = imagettfbbox($groesse, 0, $schriftdatei, $text);
        $textBreite = $rahmen[2] - $rahmen[0];
        $textHoehe = abs($rahmen[7] - $rahmen[1]);

        $kastenBreite = $textBreite + 2 * $innen;
        $kastenHoehe = $textHoehe + 2 * $innen;

        $links = $lage['rechts'] + $luft;

        if ($links + $kastenBreite > $breite - 4 * $dichte) {
            $links = $lage['links'] - $luft - $kastenBreite;   // kein Platz rechts: nach links klappen
        }

        $oben = (int) round($lage['mitte'] - $kastenHoehe / 2);

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
     * Liefert die Kacheln eines Bereichs — aus dem Zwischenspeicher, sonst frisch geladen.
     *
     * @return \Generator<array{0: int, 1: int, 2: string}> Spalte, Zeile, Bilddaten
     */
    private function kacheln(int $ersteX, int $letzteX, int $ersteY, int $letzteY, int $zoom): \Generator
    {
        $this->zwischenspeicherAufraeumen();

        $maxKachel = 2 ** $zoom;
        $vorhanden = [];
        $anfragen = [];

        for ($y = $ersteY; $y <= $letzteY; ++$y) {
            // Senkrecht läuft die Welt nicht um: Darüber und darunter bleibt der Grund stehen.
            if ($y < 0 || $y >= $maxKachel) {
                continue;
            }

            for ($x = $ersteX; $x <= $letzteX; ++$x) {
                // Waagerecht läuft die Welt um.
                $nummer = (($x % $maxKachel) + $maxKachel) % $maxKachel;

                if (null !== $daten = $this->ausZwischenspeicher($zoom, $nummer, $y)) {
                    $vorhanden[] = [$x, $y, $daten];

                    continue;
                }

                // Die Anfragen laufen gleichzeitig; gewartet wird erst beim Auslesen.
                $anfragen[] = [$x, $y, $nummer, $this->httpClient->request('GET', \sprintf(self::TILE_URL, $zoom, $nummer, $y), [
                    'headers' => ['User-Agent' => $this->userAgent],
                    'timeout' => 20,
                ])];
            }
        }

        yield from $vorhanden;

        $fehler = 0;

        foreach ($anfragen as [$x, $y, $nummer, $antwort]) {
            try {
                $daten = $antwort->getContent();
            } catch (\Throwable) {
                ++$fehler;

                continue;
            }

            $this->zwischenspeichern($zoom, $nummer, $y, $daten);

            yield [$x, $y, $daten];
        }

        // Einzelne Ausfälle sind verschmerzbar, ein Totalausfall nicht.
        if ($fehler > 0 && $fehler === \count($anfragen) && !$vorhanden) {
            throw new OsmMapException('Die Kartendaten von OpenStreetMap sind zurzeit nicht erreichbar. Bitte später noch einmal speichern.');
        }
    }

    /**
     * Wo eine Kachel im Zwischenspeicher liegt; null, wenn es keinen gibt.
     */
    private function kachelDatei(int $zoom, int $x, int $y): string|null
    {
        if ('' === $this->projectDir) {
            return null;
        }

        return \sprintf('%s/%d/%d/%d.png', $this->zwischenspeicherOrdner(), $zoom, $x, $y);
    }

    private function zwischenspeicherOrdner(): string
    {
        return $this->projectDir.'/var/cache/osm-kacheln';
    }

    private function ausZwischenspeicher(int $zoom, int $x, int $y): string|null
    {
        $datei = $this->kachelDatei($zoom, $x, $y);

        if (null === $datei || !is_file($datei) || filemtime($datei) < time() - self::VORHALTEN) {
            return null;
        }

        $daten = @file_get_contents($datei);

        return false === $daten || '' === $daten ? null : $daten;
    }

    private function zwischenspeichern(int $zoom, int $x, int $y, string $daten): void
    {
        $datei = $this->kachelDatei($zoom, $x, $y);

        // Nur echte PNG-Dateien: Eine Fehlerseite im Speicher bliebe sonst eine Woche lang
        // als graues Loch in jeder Karte stehen.
        if (null === $datei || !str_starts_with($daten, "\x89PNG\r\n\x1A\n")) {
            return;
        }

        $ordner = \dirname($datei);

        if (!is_dir($ordner) && !@mkdir($ordner, 0777, true) && !is_dir($ordner)) {
            return;
        }

        // Erst unter anderem Namen schreiben, dann umbenennen: So liest ein zweiter
        // Speichervorgang, der gleichzeitig läuft, nie eine halb geschriebene Datei.
        $vorlaeufig = $datei.'.'.bin2hex(random_bytes(4));

        if (false !== @file_put_contents($vorlaeufig, $daten)) {
            @rename($vorlaeufig, $datei);
        }
    }

    /**
     * Entfernt abgelaufene Kacheln, höchstens einmal am Tag. Sonst wüchse der Ordner mit
     * jeder Karte, die irgendwann einmal gespeichert wurde.
     */
    private function zwischenspeicherAufraeumen(): void
    {
        $ordner = $this->zwischenspeicherOrdner();
        $merker = $ordner.'/.aufgeraeumt';

        if ('' === $this->projectDir || !is_dir($ordner) || (is_file($merker) && filemtime($merker) > time() - 86400)) {
            return;
        }

        @touch($merker);
        $grenze = time() - self::VORHALTEN;

        $eintraege = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($ordner, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($eintraege as $eintrag) {
            if ($eintrag->isDir()) {
                @rmdir($eintrag->getPathname()); // gelingt nur, wenn der Ordner leer ist
            } elseif ($eintrag->getPathname() !== $merker && $eintrag->getMTime() < $grenze) {
                @unlink($eintrag->getPathname());
            }
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
     * Schrift für Nummer und Buchstabe im Pin: fett, damit sie auf der kleinen Fläche gut zu
     * lesen ist. Mitgeliefert wird DejaVu Sans Bold, gekürzt auf Ziffern und Großbuchstaben;
     * fehlt sie, tut es die gewöhnliche Schrift.
     */
    private function zeichenschriftFinden(): string|null
    {
        $fett = \dirname(__DIR__, 2).'/res/fonts/DejaVuSans-Bold-A-Z-0-9.ttf';

        if (\function_exists('imagettftext') && is_file($fett) && is_readable($fett)) {
            return $fett;
        }

        return $this->schriftFinden();
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
