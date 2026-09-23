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

/**
 * Kartenstile: dieselbe Karte von OpenStreetMap in anderer Farbgebung.
 *
 * Die Kacheln kommen weiterhin von tile.openstreetmap.org — umgefärbt wird erst auf dem
 * eigenen Server. So braucht es keinen weiteren Kartendienst, keinen Zugangsschlüssel und
 * keine weitere Adresse, die der Server erreichen muss; die Namensnennung bleibt dieselbe.
 *
 * Die Kacheln von OpenStreetMap sind Palettenbilder mit höchstens 256 Farben. Umgerechnet
 * werden deshalb nur diese Farben, nicht jedes einzelne Pixel — das kostet so gut wie
 * nichts. Echtfarbenbilder werden Pixel für Pixel umgefärbt, jede Farbe nur einmal gerechnet.
 */
class MapStyle
{
    public const STANDARD = 'standard';
    public const FREUNDLICH = 'freundlich';
    public const GEDAEMPFT = 'gedaempft';
    public const HELL = 'hell';
    public const GRAUSTUFEN = 'graustufen';
    public const RETRO = 'retro';
    public const DUNKEL = 'dunkel';
    public const NACHT = 'nacht';

    /** Reihenfolge im Auswahlfeld: die Vorgabe, das Original, dann zurückhaltend, hell, dunkel. */
    public const AUSWAHL = [
        self::FREUNDLICH,
        self::STANDARD,
        self::GEDAEMPFT,
        self::HELL,
        self::GRAUSTUFEN,
        self::RETRO,
        self::DUNKEL,
        self::NACHT,
    ];

    /** Neue Karten bekommen die hellen, klaren Farben, angelehnt an Google Maps. */
    public const VORGABE = self::FREUNDLICH;

    /**
     * Bis Version 1.1 gab es nur die Farben von OpenStreetMap selbst. Karten aus dieser Zeit
     * behalten sie, damit eine vorhandene Karte nach einem Update nicht ungefragt anders aussieht.
     */
    public const BISHER = self::STANDARD;

    /** Stile mit dunklem Grund; der Balken mit der Beschriftung wird dort ebenfalls dunkel. */
    public const DUNKLE = [self::DUNKEL, self::NACHT];

    /**
     * „Freundlich": die typischen Farben der Kacheln (gemessen, links) und was aus ihnen wird
     * (rechts) — helles, neutrales Land, weiße Straßen mit hellgrauem Rand, gelbe Hauptstraßen,
     * klares Blau, frisches Grün, zurückhaltende Gewerbeflächen. Zwischentöne an den Kanten
     * folgen ihren nächsten Nachbarn in dieser Liste.
     */
    private const FREUNDLICH_FARBEN = [
        // Land, Wohngebiete, Gebäude und ihre Umrisse
        '#f2efe9' => '#f6f6f3',
        '#e0dfdf' => '#efefed',
        '#d9d0c9' => '#e6e6ea',
        '#c2b5aa' => '#d6d6dc',
        // Wasser
        '#aad3df' => '#9ccff5',
        '#abd3df' => '#9ccff5',
        // Grünflächen
        '#add19e' => '#b5dfb2',
        '#c8facc' => '#c3eac0',
        '#cdebb0' => '#cdeac4',
        '#c9e1bf' => '#cce7c5',
        '#c8d7ab' => '#cde2c3',
        '#aacbaf' => '#c2dec5',
        '#dffce2' => '#d6f1d7',
        '#88e0be' => '#ade2c0',
        '#88b78e' => '#a7d0ab',
        '#eef0d5' => '#f0f2e5',
        '#b6b592' => '#d3d6c3',
        '#ffffe5' => '#f8f8ec',
        // Gewerbe, Handel, Industrie, Fußgängerzonen: kaum vom Land abgesetzt
        '#ffd6d1' => '#f3f0ef',
        '#f2dad9' => '#f2f0ef',
        '#ebdbe8' => '#eeecef',
        '#dddde8' => '#ececf1',
        '#c7c7b4' => '#e3e4dc',
        // Straßen: Autobahn und Bundesstraße gelb-orange, Hauptstraßen hellgelb
        '#ffffff' => '#ffffff',
        '#e892a2' => '#f6c46b',
        '#f9b29c' => '#fbd07c',
        '#fcd6a4' => '#fde6a3',
        '#f7fabf' => '#fffae2',
        '#ababab' => '#d2d3d6',
        '#b4b3b3' => '#d8d9dc',
        '#f98476' => '#efc6bf',
        // Bahnlinien, Schrift und Symbole: leiser
        '#707070' => '#a6a8ac',
        '#676767' => '#a2a4a8',
        '#7c7b7b' => '#abadb0',
        '#000000' => '#3f4347',
        '#734a08' => '#9c8667',
        '#0092da' => '#6cb0da',
        '#ac39ac' => '#b69ab6',
    ];

    /**
     * Färbt eine Kachel in den gewünschten Stil um.
     */
    public function anwenden(\GdImage $kachel, string $stil): void
    {
        if (!$this->veraendert($stil)) {
            return;
        }

        if (!imageistruecolor($kachel)) {
            $anzahl = imagecolorstotal($kachel);

            for ($i = 0; $i < $anzahl; ++$i) {
                $alt = imagecolorsforindex($kachel, $i);
                [$r, $g, $b] = $this->farbe($stil, $alt['red'], $alt['green'], $alt['blue']);
                imagecolorset($kachel, $i, $r, $g, $b, $alt['alpha']);
            }

            return;
        }

        $breite = imagesx($kachel);
        $hoehe = imagesy($kachel);
        $umgerechnet = [];

        imagealphablending($kachel, false);

        for ($y = 0; $y < $hoehe; ++$y) {
            for ($x = 0; $x < $breite; ++$x) {
                $alt = imagecolorat($kachel, $x, $y);

                if (!isset($umgerechnet[$alt])) {
                    [$r, $g, $b] = $this->farbe($stil, ($alt >> 16) & 0xFF, ($alt >> 8) & 0xFF, $alt & 0xFF);
                    $umgerechnet[$alt] = ($alt & 0x7F000000) | ($r << 16) | ($g << 8) | $b;
                }

                imagesetpixel($kachel, $x, $y, $umgerechnet[$alt]);
            }
        }
    }

    /**
     * Ändert der Stil überhaupt etwas? Beim Standard bleibt die Kachel, wie sie ist.
     */
    public function veraendert(string $stil): bool
    {
        return self::STANDARD !== $stil && \in_array($stil, self::AUSWAHL, true);
    }

    /**
     * Rechnet eine einzelne Farbe in den Stil um.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function farbe(string $stil, int $r, int $g, int $b): array
    {
        return match ($stil) {
            self::FREUNDLICH => $this->freundlich($r, $g, $b),
            self::GEDAEMPFT => $this->gedaempft($r, $g, $b),
            self::HELL => $this->hell($r, $g, $b),
            self::GRAUSTUFEN => $this->grau($this->luma($r, $g, $b)),
            self::RETRO => $this->retro($r, $g, $b),
            self::DUNKEL => $this->dunkel($r, $g, $b),
            self::NACHT => $this->nacht($r, $g, $b),
            default => [$r, $g, $b],
        };
    }

    /**
     * Freundlich: Jede Farbe wandert so weit, wie ihre nächsten Nachbarn aus der Liste
     * FREUNDLICH_FARBEN wandern — je näher ein Nachbar, desto mehr zählt er. So folgen auch
     * die Zwischentöne an den Kanten (Straße zu Land, Haus zu Hof) sauber mit, und Farben, die
     * in der Liste nicht vorkommen, bleiben weitgehend, wie sie sind. Dunkles (Schrift,
     * Symbole) wird danach etwas heller und blasser, damit die Karte ruhiger wirkt.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function freundlich(int $r, int $g, int $b): array
    {
        static $paare = null;

        if (null === $paare) {
            $paare = [];

            foreach (self::FREUNDLICH_FARBEN as $von => $nach) {
                $v = sscanf($von, '#%2x%2x%2x');
                $n = sscanf($nach, '#%2x%2x%2x');
                $paare[] = [$v, [$n[0] - $v[0], $n[1] - $v[1], $n[2] - $v[2]]];
            }
        }

        // Gewichte nach Abstand im Farbraum (Glocke mit 10 Stufen Breite): Eine Farbe aus der
        // Liste trifft ihr Ziel so gut wie genau, ein Zwischenton mischt die Wege seiner
        // Nachbarn. Wie weit er ihnen folgt, hängt davon ab, wie nah er ihnen insgesamt ist —
        // fremde Farben, weit weg von allem in der Liste, bleiben, wie sie sind.
        $summe = 0.0;
        $verschiebung = [0.0, 0.0, 0.0];

        foreach ($paare as [$von, $weg]) {
            $abstand = (($r - $von[0]) ** 2 + ($g - $von[1]) ** 2 + ($b - $von[2]) ** 2) / 100;

            if ($abstand > 9) {
                continue;
            }

            $gewicht = exp(-$abstand);
            $summe += $gewicht;
            $verschiebung[0] += $gewicht * $weg[0];
            $verschiebung[1] += $gewicht * $weg[1];
            $verschiebung[2] += $gewicht * $weg[2];
        }

        $folgen = $summe > 0 ? min(1.0, $summe / 0.2) / $summe : 0.0;
        $neu = $this->runden([
            $r + $verschiebung[0] * $folgen,
            $g + $verschiebung[1] * $folgen,
            $b + $verschiebung[2] * $folgen,
        ]);

        [$h, $s, $l] = $this->hsl(...$neu);

        if ($l < 0.5) {
            return $this->rgb($h, $s * 0.6, 0.25 + $l * 0.75);
        }

        // Fast graue, helle Töne (Land, Gebäude und ihre Zwischentöne) neutral statt warm:
        // Die Kacheln haben dort einen beigen Stich, der nach dem Aufhellen rosa wirkt.
        if ($s < 0.2) {
            return $this->rgb($h, $s * 0.25, $l);
        }

        return $neu;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $farbe
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function runden(array $farbe): array
    {
        return array_map(static fn (float $wert): int => (int) round(max(0.0, min(255.0, $wert))), $farbe);
    }

    /**
     * Dieselben Farben, deutlich weniger gesättigt und etwas heller.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function gedaempft(int $r, int $g, int $b): array
    {
        [$h, $s, $l] = $this->hsl($r, $g, $b);

        return $this->rgb($h, $s * 0.4, $l + (1 - $l) * 0.1);
    }

    /**
     * Zartes Grau: Grauwerte, in den hellen Bereich zusammengeschoben. Schrift wird mittelgrau,
     * das Land fast weiß — eine farbige Markierung sticht so deutlich hervor. Die Kurve hebt
     * die Mitteltöne ab, damit Wasser und Gebäude vom Land zu unterscheiden bleiben.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function hell(int $r, int $g, int $b): array
    {
        return $this->grau(0.38 + 0.62 * $this->luma($r, $g, $b) ** 1.6);
    }

    /**
     * Warme Papiertöne: die Farben zurückgenommen und mit einem Sepiaverlauf gemischt, der
     * von dunkelbrauner Tinte bis zu hellem Papier reicht. Wasser wird blaugrün, wie auf
     * alten Karten — mit dem Sepia gemischt, würde es sonst grau.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function retro(int $r, int $g, int $b): array
    {
        [$h, $s, $l] = $this->hsl($r, $g, $b);

        if ($this->istWasser($h, $s, $l)) {
            return $this->rgb(151 / 360, 0.22, $l * 0.97);
        }

        $gedaempft = $this->rgb($h, $s * 0.55, $l);

        $y = $this->luma($r, $g, $b);
        $sepia = [82 + (245 - 82) * $y, 55 + (236 - 55) * $y, 53 + (214 - 53) * $y];

        return $this->mischen($gedaempft, $sepia, 0.55);
    }

    /**
     * Dunkel: Die Helligkeit wird umgekehrt, der Farbton bleibt, nur stark zurückgenommen.
     * Das helle Land wird fast schwarz, dunkle Schrift hell, Wasser noch dunkler als das
     * Land. Weiße Straßen liegen deutlich über dem Land, Gebäude und Plätze knapp darüber.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function dunkel(int $r, int $g, int $b): array
    {
        [$h, $s, $l] = $this->hsl($r, $g, $b);

        if ($this->istWasser($h, $s, $l)) {
            return $this->rgb($h, 0.35, 0.09);
        }

        return $this->rgb($h, $s * 0.22, $this->umkehren($l, schwarz: 0.78, flaechen: 0.19, land: 0.13, weiss: 0.27));
    }

    /**
     * Nacht: wie „Dunkel", aber in dunklem Blau; farbige Flächen behalten einen Hauch ihres
     * Tons.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function nacht(int $r, int $g, int $b): array
    {
        [$h, $s, $l] = $this->hsl($r, $g, $b);

        if ($this->istWasser($h, $s, $l)) {
            return $this->rgb(216 / 360, 0.45, 0.13);
        }

        $hell = $this->umkehren($l, schwarz: 0.74, flaechen: 0.24, land: 0.18, weiss: 0.31);
        $blau = $this->rgb(215 / 360, 0.28, $hell);
        $eigen = $this->rgb($h, min(1.0, $s * 0.45), $hell);

        return $this->mischen($blau, $eigen, min(1.0, $s * 0.5));
    }

    /**
     * Kehrt eine Helligkeit für die dunklen Stile um, in drei Abschnitten:
     *
     * - Schwarz bis 0,75 (Schrift, Symbole, Linien) wird hell: von $schwarz bis $flaechen.
     *   Gebogen statt gerade, damit auch mittelhelle Schrift (Ortsteile, Rathaus) deutlich
     *   heller wird als ihr Saum, der aus Weiß entsteht.
     * - 0,75 bis 0,93 (Gebäude, Plätze, Wohngebiete) bleibt nahe am Land: bis $land.
     * - 0,93 bis Weiß (das Land selbst, Straßen) steigt wieder an: bis $weiss. So heben sich
     *   die weißen Straßen ab, statt als dunkle Rillen zwischen den Häusern zu verschwinden.
     */
    private function umkehren(float $l, float $schwarz, float $flaechen, float $land, float $weiss): float
    {
        return match (true) {
            $l < 0.75 => $schwarz + ($l / 0.75) ** 2 * ($flaechen - $schwarz),
            $l < 0.93 => $flaechen + ($l - 0.75) / 0.18 * ($land - $flaechen),
            default => $land + ($l - 0.93) / 0.07 * ($weiss - $land),
        };
    }

    /**
     * Die Wasserflächen von OpenStreetMap: ein helles, kräftiges Blau (#aad3df, in manchen
     * Kacheln #abd3df — jede Kachel hat ihre eigene Palette). Deshalb die Prüfung über
     * Farbton, Sättigung und Helligkeit statt über einen festen Wert. Schrift in Blau (Namen
     * von Flüssen) ist dunkler und fällt nicht darunter.
     */
    private function istWasser(float $h, float $s, float $l): bool
    {
        return $h >= 170 / 360 && $h <= 235 / 360 && $s >= 0.25 && $l >= 0.6;
    }

    /**
     * Wahrgenommene Helligkeit, 0 bis 1 (Gewichtung wie beim Fernsehbild, ITU-R BT.601).
     */
    private function luma(int $r, int $g, int $b): float
    {
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function grau(float $wert): array
    {
        $grau = (int) round(max(0.0, min(1.0, $wert)) * 255);

        return [$grau, $grau, $grau];
    }

    /**
     * @param array{0: int|float, 1: int|float, 2: int|float} $a
     * @param array{0: int|float, 1: int|float, 2: int|float} $b
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function mischen(array $a, array $b, float $anteil): array
    {
        return [
            (int) round(max(0, min(255, $a[0] + ($b[0] - $a[0]) * $anteil))),
            (int) round(max(0, min(255, $a[1] + ($b[1] - $a[1]) * $anteil))),
            (int) round(max(0, min(255, $a[2] + ($b[2] - $a[2]) * $anteil))),
        ];
    }

    /**
     * RGB (0–255) in Farbton, Sättigung und Helligkeit (je 0–1).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function hsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h / 6, $s, $l];
    }

    /**
     * Farbton, Sättigung und Helligkeit (je 0–1) zurück in RGB (0–255).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(float $h, float $s, float $l): array
    {
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));

        if (0.0 === $s) {
            return $this->grau($l);
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        $kanal = static function (float $t) use ($p, $q): int {
            $t -= floor($t);

            $wert = match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };

            return (int) round($wert * 255);
        };

        return [$kanal($h + 1 / 3), $kanal($h), $kanal($h - 1 / 3)];
    }
}
