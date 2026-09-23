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
 * Zeichnet eine einzelne Markierung: einen Pin wie bei Google Maps, einen Punkt oder ein
 * Fähnchen.
 *
 * GD füllt Flächen ohne Kantenglättung, eine Markierung sähe gezackt aus. Deshalb entsteht
 * jede Markierung zuerst vierfach vergrößert auf einem eigenen, durchsichtigen Bild und wird
 * dann verkleinert auf die Karte gesetzt: Beim Verkleinern mittelt GD die Randpixel, die
 * Kanten werden weich, und der Schatten bleibt halbdurchsichtig.
 *
 * Alle Maße in dieser Klasse sind Anzeigepixel, also bei einfacher Dichte. Der Ursprung ist
 * der Ort auf der Karte: die Spitze des Pins, der Fuß der Fahnenstange, die Mitte des Punktes.
 */
class MarkerRenderer
{
    public const PIN = 'pin';
    public const PIN_NUMMER = 'pin_nummer';
    public const PIN_BUCHSTABE = 'pin_buchstabe';
    public const PIN_HAUS = 'pin_haus';
    public const PIN_STERN = 'pin_stern';
    public const PIN_HERZ = 'pin_herz';
    public const PUNKT = 'punkt';
    public const PUNKT_HOF = 'punkt_hof';
    public const FAHNE = 'fahne';

    /** Reihenfolge im Auswahlfeld: erst die Pins, dann die übrigen Formen. */
    public const AUSWAHL = [
        self::PIN,
        self::PIN_NUMMER,
        self::PIN_BUCHSTABE,
        self::PIN_HAUS,
        self::PIN_STERN,
        self::PIN_HERZ,
        self::PUNKT,
        self::PUNKT_HOF,
        self::FAHNE,
    ];

    /** Neue Karten bekommen den Pin. */
    public const VORGABE = self::PIN;

    /**
     * Bis Version 1.1 gab es nur den Punkt. Karten aus dieser Zeit behalten ihn, damit eine
     * vorhandene Karte nach einem Update nicht ungefragt anders aussieht.
     */
    public const BISHER = self::PUNKT;

    /** In dieser Vergrößerung entsteht die Markierung, bevor sie verkleinert wird. */
    private const UEBERABTASTUNG = 4;

    /*
     * Der Pin folgt in seinen Verhältnissen dem Material-Symbol „place" von Google: ein Kreis,
     * der nach unten in zwei leicht bauchigen Kurven zur Spitze ausläuft, darin ein Loch.
     * Hier in doppelter Größe: Kopf mit Radius 14, seine Mitte 26 über der Spitze.
     */
    private const PIN_RADIUS = 14.0;
    private const PIN_KOPF = 26.0;
    private const PIN_LOCH = 5.0;

    /** Breite des dunkleren Randes um Pin und Fähnchen. */
    private const RAND = 1.0;

    /**
     * Setzt eine Markierung auf das Bild.
     *
     * @param array{0: int, 1: int, 2: int} $farbe    Farbe der Markierung
     * @param string                        $zeichen  Nummer oder Buchstabe für die Pins mit
     *                                                Beschriftung, sonst leer
     * @param string|null                   $schrift  TrueType-Schrift für $zeichen; ohne sie
     *                                                trägt der Pin stattdessen das Loch
     *
     * @return array{links: int, rechts: int, oben: int, unten: int, mitte: int} Ausdehnung
     *         im Bild. An links, rechts und mitte richtet sich das Beschriftungsschild aus.
     */
    public function zeichnen(
        \GdImage $bild,
        string $form,
        int $x,
        int $y,
        array $farbe,
        int $dichte = 1,
        string $zeichen = '',
        string|null $schrift = null,
    ): array {
        $form = \in_array($form, self::AUSWAHL, true) ? $form : self::BISHER;
        $masse = $this->masse($form);

        $faktor = $dichte * self::UEBERABTASTUNG;

        // Zeichenfläche um die Markierung, in ganzen Anzeigepixeln
        $breite = ($masse['rechts'] - $masse['links']) * $dichte;
        $hoehe = ($masse['unten'] - $masse['oben']) * $dichte;
        $flaeche = $this->leereFlaeche($breite * self::UEBERABTASTUNG, $hoehe * self::UEBERABTASTUNG);

        // Lage des Ursprungs auf der Zeichenfläche. Die halbe Pixelbreite dazu, weil die
        // Markierung auf die MITTE des Bildpunktes ($x, $y) gehört, nicht auf seine Ecke.
        $ursprungX = (-$masse['links'] * $dichte + 0.5) * self::UEBERABTASTUNG;
        $ursprungY = (-$masse['oben'] * $dichte + 0.5) * self::UEBERABTASTUNG;

        // Alles Weitere rechnet in Anzeigepixeln relativ zum Ursprung; diese Funktion
        // übersetzt in die Koordinaten der Zeichenfläche.
        $p = static fn (float $px, float $py): array => [
            $ursprungX + $px * $faktor,
            $ursprungY + $py * $faktor,
        ];

        match ($form) {
            self::PUNKT => $this->punktZeichnen($flaeche, $p, $faktor, $farbe, false),
            self::PUNKT_HOF => $this->punktZeichnen($flaeche, $p, $faktor, $farbe, true),
            self::FAHNE => $this->fahneZeichnen($flaeche, $p, $faktor, $farbe),
            default => $this->pinZeichnen($flaeche, $form, $p, $faktor, $farbe, $zeichen, $schrift),
        };

        // Verkleinert auf die Karte setzen — erst dabei werden die Kanten weich.
        imagecopyresampled(
            $bild,
            $flaeche,
            $x + $masse['links'] * $dichte,
            $y + $masse['oben'] * $dichte,
            0,
            0,
            $breite,
            $hoehe,
            $breite * self::UEBERABTASTUNG,
            $hoehe * self::UEBERABTASTUNG,
        );

        return [
            'links' => $x + $masse['schildLinks'] * $dichte,
            'rechts' => $x + $masse['schildRechts'] * $dichte,
            'oben' => $y + $masse['oben'] * $dichte,
            'unten' => $y + $masse['unten'] * $dichte,
            'mitte' => $y + $masse['mitte'] * $dichte,
        ];
    }

    /**
     * Wie weit die Markierung über ihren Ort hinaufreicht, soweit sie das NICHT auch nach
     * unten tut. Ein Punkt sitzt mittig auf dem Ort, ein Pin steht darüber — beim Wählen des
     * Ausschnitts muss für ihn oben Platz bleiben.
     */
    public function kopfhoehe(string $form): int
    {
        $masse = $this->masse(\in_array($form, self::AUSWAHL, true) ? $form : self::BISHER);

        return max(0, -$masse['oben'] - $masse['unten']);
    }

    /**
     * Was im Kopf des Pins steht: 1, 2, 3 … oder A, B, C … — nach der Reihenfolge der Zeilen.
     *
     * @param int $index Nummer der Markierung, von 0 an gezählt
     */
    public function zeichen(string $form, int $index): string
    {
        if (self::PIN_NUMMER === $form) {
            return (string) ($index + 1);
        }

        if (self::PIN_BUCHSTABE === $form) {
            // Nach Z geht es mit AA, AB … weiter, wie bei den Spalten einer Tabelle.
            $text = '';

            for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
                $text = \chr(65 + ($n - 1) % 26).$text;
            }

            return $text;
        }

        return '';
    }

    /**
     * Ausdehnung einer Form um ihren Ort, in ganzen Anzeigepixeln.
     *
     * links/rechts/oben/unten umschließen alles, was gezeichnet wird, samt Schatten.
     * schildLinks/schildRechts begrenzen den sichtbaren Körper — daneben setzt das Schild
     * an —, und mitte ist die Höhe, auf der das Schild mittig steht.
     *
     * @return array{links: int, rechts: int, oben: int, unten: int, schildLinks: int, schildRechts: int, mitte: int}
     */
    private function masse(string $form): array
    {
        return match ($form) {
            // Wie bisher: weißer Kreis mit 22 Pixeln Durchmesser, darin die Farbe.
            self::PUNKT => ['links' => -12, 'rechts' => 12, 'oben' => -12, 'unten' => 12, 'schildLinks' => -11, 'schildRechts' => 11, 'mitte' => 0],
            self::PUNKT_HOF => ['links' => -27, 'rechts' => 27, 'oben' => -27, 'unten' => 27, 'schildLinks' => -13, 'schildRechts' => 13, 'mitte' => 0],
            self::FAHNE => ['links' => -6, 'rechts' => 26, 'oben' => -48, 'unten' => 4, 'schildLinks' => -3, 'schildRechts' => 25, 'mitte' => -36],
            default => ['links' => -16, 'rechts' => 16, 'oben' => -42, 'unten' => 4, 'schildLinks' => -15, 'schildRechts' => 15, 'mitte' => -26],
        };
    }

    /**
     * @param \Closure(float, float): array{0: float, 1: float} $p
     * @param array{0: int, 1: int, 2: int}                     $farbe
     */
    private function pinZeichnen(\GdImage $flaeche, string $form, \Closure $p, float $faktor, array $farbe, string $zeichen, string|null $schrift): void
    {
        // Weicher Schatten an der Spitze, als stecke der Pin in der Karte.
        $this->schattenZeichnen($flaeche, $p(0, 0.5), 7.5 * $faktor, 2.6 * $faktor);

        $umriss = $this->pinUmriss();

        // Erst der ganze Umriss in der dunkleren Randfarbe, darüber die um den Rand
        // eingezogene Form in der eigentlichen Farbe.
        $this->vieleck($flaeche, $umriss, $p, $this->farbeMischen($farbe, [0, 0, 0], 0.25));
        $this->vieleck($flaeche, $this->einziehen($umriss, self::RAND), $p, $farbe);

        [$kopfX, $kopfY] = $p(0, -self::PIN_KOPF);
        $zeichenfarbe = $this->zeichenfarbe($farbe);

        switch ($form) {
            case self::PIN_NUMMER:
            case self::PIN_BUCHSTABE:
                if ('' !== $zeichen && null !== $schrift) {
                    $this->textZeichnen($flaeche, $zeichen, $kopfX, $kopfY, $faktor, $schrift, $zeichenfarbe);

                    return;
                }
                break; // ohne Schrift: das Loch wie beim schlichten Pin

            case self::PIN_HAUS:
                [$haus, $tuer] = $this->haus();
                $this->vieleck($flaeche, $haus, $p, $zeichenfarbe);
                $this->vieleck($flaeche, $tuer, $p, $farbe);

                return;

            case self::PIN_STERN:
                $this->vieleck($flaeche, $this->stern(), $p, $zeichenfarbe);

                return;

            case self::PIN_HERZ:
                $this->vieleck($flaeche, $this->herz(), $p, $zeichenfarbe);

                return;
        }

        // Der schlichte Pin: ein Loch in der Mitte des Kopfes, dunkler als der Pin —
        // bei einem sehr dunklen Pin wäre ein noch dunkleres Loch unsichtbar, dann hell.
        $loch = $this->helligkeit($farbe) < 0.18
            ? $this->farbeMischen($farbe, [255, 255, 255], 0.85)
            : $this->farbeMischen($farbe, [0, 0, 0], 0.38);

        $this->kreis($flaeche, $kopfX, $kopfY, self::PIN_LOCH * $faktor, $loch);
    }

    /**
     * Der Punkt: weißer Kreis mit 22 Pixeln Durchmesser, darin die Farbe mit 16 Pixeln — so
     * sah jede Markierung bis Version 1.1 aus. Mit Hof liegt darum ein durchscheinender Kreis
     * in derselben Farbe, wie beim eigenen Standort in Karten-Apps.
     *
     * @param \Closure(float, float): array{0: float, 1: float} $p
     * @param array{0: int, 1: int, 2: int}                     $farbe
     */
    private function punktZeichnen(\GdImage $flaeche, \Closure $p, float $faktor, array $farbe, bool $hof): void
    {
        [$mx, $my] = $p(0, 0);

        if ($hof) {
            // Kräftiger Saum, dann die Innenfläche ohne Überblenden darübergeschrieben,
            // damit sich die beiden durchscheinenden Flächen nicht aufaddieren.
            $this->kreis($flaeche, $mx, $my, 26 * $faktor, $farbe, 0.42);
            imagealphablending($flaeche, false);
            $this->kreis($flaeche, $mx, $my, 24.5 * $faktor, $farbe, 0.16);
            imagealphablending($flaeche, true);
            $this->schattenZeichnen($flaeche, $p(0, 1.2), 12 * $faktor, 12 * $faktor, 0.22);
        }

        $this->kreis($flaeche, $mx, $my, 11 * $faktor, [255, 255, 255]);
        $this->kreis($flaeche, $mx, $my, 8 * $faktor, $farbe);
    }

    /**
     * Fähnchen an einer Stange; der Fuß der Stange steht auf dem Ort.
     *
     * @param \Closure(float, float): array{0: float, 1: float} $p
     * @param array{0: int, 1: int, 2: int}                     $farbe
     */
    private function fahneZeichnen(\GdImage $flaeche, \Closure $p, float $faktor, array $farbe): void
    {
        $this->schattenZeichnen($flaeche, $p(0, 0.6), 5.5 * $faktor, 2 * $faktor);

        // Stange mit rundem Knauf. Der helle Saum darum hält sie auch auf den dunklen
        // Kartenstilen sichtbar.
        [$knaufX, $knaufY] = $p(0, -44.5);
        $this->vieleck($flaeche, [[-2.1, 0.5], [2.1, 0.5], [2.1, -44], [-2.1, -44]], $p, [255, 255, 255], 0.85);
        $this->kreis($flaeche, $knaufX, $knaufY, 3.1 * $faktor, [255, 255, 255], 0.85);

        $stange = [72, 72, 72];
        $this->vieleck($flaeche, [[-1.2, 0], [1.2, 0], [1.2, -44], [-1.2, -44]], $p, $stange);
        $this->kreis($flaeche, $knaufX, $knaufY, 2.2 * $faktor, $stange);

        // Das Tuch weht leicht: Ober- und Unterkante als flache Welle.
        $tuch = [];

        for ($i = 0; $i <= 24; ++$i) {
            $tuch[] = [1.0 + $i, -43.0 + 1.6 * sin($i / 24 * 2 * M_PI)];
        }

        for ($i = 24; $i >= 0; --$i) {
            $tuch[] = [1.0 + $i, -28.0 + 1.6 * sin($i / 24 * 2 * M_PI)];
        }

        $this->vieleck($flaeche, $tuch, $p, $this->farbeMischen($farbe, [0, 0, 0], 0.25));
        $this->vieleck($flaeche, $this->einziehen($tuch, self::RAND), $p, $farbe);
    }

    /**
     * Umriss des Pins, von der linken Seite des Kopfes über die Spitze und den Kopf herum.
     *
     * Nach dem Material-Symbol: Die Kurven beginnen auf Höhe der Kopfmitte senkrecht und
     * treffen sich in der Spitze (Kontrollpunkte wie dort, auf doppelte Größe gebracht).
     *
     * @return list<array{0: float, 1: float}>
     */
    private function pinUmriss(): array
    {
        $r = self::PIN_RADIUS;
        $mitte = -self::PIN_KOPF;
        $bauch = $mitte + 10.5; // 5,25 im Original

        $punkte = [];

        // links hinab zur Spitze …
        foreach ($this->bezier([-$r, $mitte], [-$r, $bauch], [0, 0], [0, 0], 24) as $punkt) {
            $punkte[] = $punkt;
        }

        // … rechts wieder hinauf …
        foreach ($this->bezier([0, 0], [0, 0], [$r, $bauch], [$r, $mitte], 24) as $i => $punkt) {
            if ($i > 0) {
                $punkte[] = $punkt; // die Spitze nicht doppelt
            }
        }

        // … und über den Kopf zurück nach links (ohne die beiden Endpunkte, die schon da sind)
        for ($i = 1; $i < 48; ++$i) {
            $winkel = -M_PI * $i / 48;
            $punkte[] = [$r * cos($winkel), $mitte + $r * sin($winkel)];
        }

        return $punkte;
    }

    /**
     * Haus mit überstehendem Dach, dazu die Tür, die in der Farbe des Pins ausgespart wird.
     *
     * @return array{0: list<array{0: float, 1: float}>, 1: list<array{0: float, 1: float}>}
     */
    private function haus(): array
    {
        $haus = [[0.0, -7.4], [7.6, -0.6], [5.4, -0.6], [5.4, 5.5], [-5.4, 5.5], [-5.4, -0.6], [-7.6, -0.6]];
        $tuer = [[-1.8, 5.5], [1.8, 5.5], [1.8, 1.2], [-1.8, 1.2]];

        // Um die Mitte des Hauses verschieben, nicht um die Dachspitze
        $versatz = -self::PIN_KOPF - (-7.4 + 5.5) / 2;

        return [$this->verschieben($haus, $versatz), $this->verschieben($tuer, $versatz)];
    }

    /**
     * Fünfzackiger Stern, eine Spitze nach oben.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function stern(): array
    {
        $punkte = [];

        for ($i = 0; $i < 10; ++$i) {
            $radius = 0 === $i % 2 ? 8.2 : 3.4;
            $winkel = -M_PI / 2 + $i * M_PI / 5;
            $punkte[] = [$radius * cos($winkel), $radius * sin($winkel)];
        }

        // Ein Stern wirkt mittig, wenn die Mitte seines Kreises auf der Kopfmitte liegt —
        // nicht die Mitte zwischen oberster Spitze und den beiden unteren.
        return $this->verschieben($punkte, -self::PIN_KOPF + 0.6);
    }

    /**
     * Herz aus der bekannten Kurve x = 16 sin³t, y = 13 cos t − 5 cos 2t − 2 cos 3t − cos 4t.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function herz(): array
    {
        $punkte = [];
        $massstab = 0.43;

        for ($i = 0; $i < 72; ++$i) {
            $t = 2 * M_PI * $i / 72;
            $punkte[] = [
                $massstab * 16 * sin($t) ** 3,
                // Die Formel zählt nach oben, das Bild nach unten.
                -$massstab * (13 * cos($t) - 5 * cos(2 * $t) - 2 * cos(3 * $t) - cos(4 * $t)),
            ];
        }

        // Die Kurve reicht von −11,85 (Bögen) bis 17 (Spitze) → Mitte bei 2,6 Einheiten.
        return $this->verschieben($punkte, -self::PIN_KOPF - $massstab * 2.6);
    }

    /**
     * @param list<array{0: float, 1: float}> $punkte
     *
     * @return list<array{0: float, 1: float}>
     */
    private function verschieben(array $punkte, float $dy): array
    {
        return array_map(static fn (array $punkt): array => [$punkt[0], $punkt[1] + $dy], $punkte);
    }

    /**
     * Nummer oder Buchstabe mittig in den Kopf des Pins, fett und so groß, dass es passt.
     *
     * @param array{0: int, 1: int, 2: int} $farbe
     */
    private function textZeichnen(\GdImage $flaeche, string $text, float $mitteX, float $mitteY, float $faktor, string $schrift, array $farbe): void
    {
        // Höhe einer Ziffer: 11 Anzeigepixel; breiter als 18 darf der Text nicht werden.
        $probe = imagettfbbox(100, 0, $schrift, '0');
        $groesse = 100 * 11 * $faktor / max(1, abs($probe[7] - $probe[1]));

        $rahmen = imagettfbbox($groesse, 0, $schrift, $text);
        $textBreite = $rahmen[2] - $rahmen[0];

        if ($textBreite > 18 * $faktor) {
            $groesse *= 18 * $faktor / $textBreite;
            $rahmen = imagettfbbox($groesse, 0, $schrift, $text);
        }

        // Die Mitte der sichtbaren Zeichen auf die Mitte des Kopfes legen
        $x = $mitteX - ($rahmen[0] + $rahmen[2]) / 2;
        $grundlinie = $mitteY - ($rahmen[1] + $rahmen[7]) / 2;

        imagettftext($flaeche, $groesse, 0, (int) round($x), (int) round($grundlinie), $this->farbwert($flaeche, $farbe), $schrift, $text);
    }

    /**
     * Weicher, runder Schatten: viele Ellipsen übereinander, jede nur schwach deckend. In
     * der Mitte liegen alle übereinander, zum Rand hin immer weniger — das ergibt den Verlauf.
     *
     * @param array{0: float, 1: float} $mitte
     */
    private function schattenZeichnen(\GdImage $flaeche, array $mitte, float $radiusX, float $radiusY, float $staerke = 0.32): void
    {
        $schichten = 12;
        // Deckkraft je Schicht so, dass in der Mitte insgesamt $staerke erreicht wird
        $deckkraft = 1 - (1 - $staerke) ** (1 / $schichten);

        for ($i = 0; $i < $schichten; ++$i) {
            $anteil = 1 - $i / $schichten;
            $this->ellipse($flaeche, $mitte[0], $mitte[1], $radiusX * $anteil, $radiusY * $anteil, [0, 0, 0], $deckkraft);
        }
    }

    /**
     * Zieht ein Vieleck rundum um $abstand nach innen. Jede Ecke wandert dabei so weit, dass
     * beide angrenzenden Kanten genau den Abstand halten — auch in der spitzen Ecke des Pins.
     *
     * @param list<array{0: float, 1: float}> $punkte
     *
     * @return list<array{0: float, 1: float}>
     */
    private function einziehen(array $punkte, float $abstand): array
    {
        $anzahl = \count($punkte);

        // Umlaufsinn bestimmen, damit „innen" auf der richtigen Seite liegt
        $flaeche = 0.0;

        for ($i = 0; $i < $anzahl; ++$i) {
            [$x1, $y1] = $punkte[$i];
            [$x2, $y2] = $punkte[($i + 1) % $anzahl];
            $flaeche += $x1 * $y2 - $x2 * $y1;
        }

        $richtung = $flaeche > 0 ? 1 : -1;
        $ergebnis = [];

        for ($i = 0; $i < $anzahl; ++$i) {
            $vorher = $punkte[($i - 1 + $anzahl) % $anzahl];
            $jetzt = $punkte[$i];
            $nachher = $punkte[($i + 1) % $anzahl];

            $n1 = $this->innenNormale($vorher, $jetzt, $richtung);
            $n2 = $this->innenNormale($jetzt, $nachher, $richtung);

            // Gehrung: die beiden eingerückten Kanten schneiden sich hier.
            $nenner = max(0.25, 1 + $n1[0] * $n2[0] + $n1[1] * $n2[1]);

            $ergebnis[] = [
                $jetzt[0] + ($n1[0] + $n2[0]) / $nenner * $abstand,
                $jetzt[1] + ($n1[1] + $n2[1]) / $nenner * $abstand,
            ];
        }

        return $ergebnis;
    }

    /**
     * @param array{0: float, 1: float} $von
     * @param array{0: float, 1: float} $nach
     *
     * @return array{0: float, 1: float}
     */
    private function innenNormale(array $von, array $nach, int $richtung): array
    {
        $dx = $nach[0] - $von[0];
        $dy = $nach[1] - $von[1];
        $laenge = sqrt($dx * $dx + $dy * $dy) ?: 1.0;

        return [-$dy / $laenge * $richtung, $dx / $laenge * $richtung];
    }

    /**
     * Punkte entlang einer kubischen Bézierkurve, Anfang und Ende eingeschlossen.
     *
     * @param array{0: float, 1: float} $p0
     * @param array{0: float, 1: float} $p1
     * @param array{0: float, 1: float} $p2
     * @param array{0: float, 1: float} $p3
     *
     * @return list<array{0: float, 1: float}>
     */
    private function bezier(array $p0, array $p1, array $p2, array $p3, int $schritte): array
    {
        $punkte = [];

        for ($i = 0; $i <= $schritte; ++$i) {
            $t = $i / $schritte;
            $u = 1 - $t;

            $punkte[] = [
                $u ** 3 * $p0[0] + 3 * $u ** 2 * $t * $p1[0] + 3 * $u * $t ** 2 * $p2[0] + $t ** 3 * $p3[0],
                $u ** 3 * $p0[1] + 3 * $u ** 2 * $t * $p1[1] + 3 * $u * $t ** 2 * $p2[1] + $t ** 3 * $p3[1],
            ];
        }

        return $punkte;
    }

    /**
     * @param list<array{0: float, 1: float}>                   $punkte in Anzeigepixeln
     * @param \Closure(float, float): array{0: float, 1: float} $p
     * @param array{0: int, 1: int, 2: int}                     $farbe
     */
    private function vieleck(\GdImage $flaeche, array $punkte, \Closure $p, array $farbe, float $deckkraft = 1.0): void
    {
        $liste = [];

        foreach ($punkte as [$x, $y]) {
            [$fx, $fy] = $p($x, $y);
            $liste[] = (int) round($fx);
            $liste[] = (int) round($fy);
        }

        imagefilledpolygon($flaeche, $liste, $this->farbwert($flaeche, $farbe, $deckkraft));
    }

    /**
     * @param array{0: int, 1: int, 2: int} $farbe
     */
    private function kreis(\GdImage $flaeche, float $x, float $y, float $radius, array $farbe, float $deckkraft = 1.0): void
    {
        $this->ellipse($flaeche, $x, $y, $radius, $radius, $farbe, $deckkraft);
    }

    /**
     * @param array{0: int, 1: int, 2: int} $farbe
     */
    private function ellipse(\GdImage $flaeche, float $x, float $y, float $radiusX, float $radiusY, array $farbe, float $deckkraft = 1.0): void
    {
        imagefilledellipse(
            $flaeche,
            (int) round($x),
            (int) round($y),
            max(1, (int) round(2 * $radiusX)),
            max(1, (int) round(2 * $radiusY)),
            $this->farbwert($flaeche, $farbe, $deckkraft),
        );
    }

    private function leereFlaeche(int $breite, int $hoehe): \GdImage
    {
        $flaeche = imagecreatetruecolor(max(1, $breite), max(1, $hoehe));

        // Völlig durchsichtig beginnen; gezeichnet wird danach mit Überblenden, damit sich
        // halbdurchsichtige Schatten und deckende Flächen richtig überlagern.
        imagealphablending($flaeche, false);
        imagefilledrectangle($flaeche, 0, 0, $breite, $hoehe, imagecolorallocatealpha($flaeche, 0, 0, 0, 127));
        imagealphablending($flaeche, true);
        imagesavealpha($flaeche, true);

        return $flaeche;
    }

    /**
     * @param array{0: int, 1: int, 2: int} $farbe
     */
    private function farbwert(\GdImage $flaeche, array $farbe, float $deckkraft = 1.0): int
    {
        // GD zählt die Durchsichtigkeit von 0 (deckend) bis 127 (unsichtbar).
        $alpha = (int) round(127 * (1 - max(0.0, min(1.0, $deckkraft))));

        return imagecolorallocatealpha($flaeche, $farbe[0], $farbe[1], $farbe[2], $alpha);
    }

    /**
     * Farbe für Zeichen im Pin: weiß, solange sich das gut vom Pin abhebt, sonst dunkel.
     *
     * @param array{0: int, 1: int, 2: int} $farbe
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function zeichenfarbe(array $farbe): array
    {
        // Kontrastverhältnis nach WCAG gegen Weiß; ab 3 gilt großer, fetter Text als gut lesbar.
        $kontrast = 1.05 / ($this->helligkeit($farbe) + 0.05);

        return $kontrast >= 3 ? [255, 255, 255] : $this->farbeMischen($farbe, [0, 0, 0], 0.72);
    }

    /**
     * Relative Helligkeit nach WCAG, 0 (schwarz) bis 1 (weiß).
     *
     * @param array{0: int, 1: int, 2: int} $farbe
     */
    public function helligkeit(array $farbe): float
    {
        $kanal = static function (int $wert): float {
            $wert /= 255;

            return $wert <= 0.04045 ? $wert / 12.92 : (($wert + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $kanal($farbe[0]) + 0.7152 * $kanal($farbe[1]) + 0.0722 * $kanal($farbe[2]);
    }

    /**
     * @param array{0: int, 1: int, 2: int} $farbe
     * @param array{0: int, 1: int, 2: int} $zu
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function farbeMischen(array $farbe, array $zu, float $anteil): array
    {
        return [
            (int) round($farbe[0] + ($zu[0] - $farbe[0]) * $anteil),
            (int) round($farbe[1] + ($zu[1] - $farbe[1]) * $anteil),
            (int) round($farbe[2] + ($zu[2] - $farbe[2]) * $anteil),
        ];
    }
}
