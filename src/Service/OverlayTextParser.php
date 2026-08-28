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
 * Zerlegt den Text des Balkens über der Karte in Textstücke und Links.
 *
 * Der Redakteur schreibt einen gewöhnlichen Satz und setzt dort, wo ein Kartendienst
 * verlinkt sein soll, einen Platzhalter:
 *
 *     Adresse öffnen in {osm} oder {google}
 *
 * Daraus wird: „Adresse öffnen in " + Link „OpenStreetMap" + „ oder " + Link „Google Maps".
 * Eigene Beschriftungen sind möglich: {osm:Karte ansehen}.
 *
 * Bei mehreren Markierungen lässt sich die gemeinte auswählen, indem ihre Nummer angehängt
 * wird — {osm-2} führt zur zweiten Markierung. Ein solcher Platzhalter trägt als Linktext
 * die Beschriftung dieser Markierung, denn bei mehreren Orten ist deren Name die nützliche
 * Auskunft, nicht der Name des Kartendienstes:
 *
 *     Standorte ansehen: {osm-1} und {osm-2}
 *     → „Standorte ansehen: " + Link „Kölner Dom" + „ und " + Link „Schokoladenmuseum"
 *
 * Ohne Nummer bleibt es beim Namen des Dienstes und bei der ersten Markierung — so
 * verhalten sich bestehende Texte unverändert.
 *
 * Ein Textfeld statt eines Auswahlfelds, weil ein Satz mehrere Links enthalten kann —
 * die Voreinstellung nutzt genau das (OpenStreetMap UND Google Maps). Wer nur einen Link
 * möchte, schreibt eben nur einen Platzhalter.
 *
 * Der Balken ist HTML über dem Bild und nicht ins Bild gebrannt: nur so lassen sich
 * einzelne Wörter verlinken, und der Text bleibt lesbar, durchsuchbar und übersetzbar.
 */
class OverlayTextParser
{
    public function __construct(private readonly MapLinks $links)
    {
    }

    /**
     * @param list<array{lat: float, lon: float, label?: string}> $positionen die Markierungen der Karte
     *
     * @return list<array{typ: 'text'|'link', text: string, ziel?: string, dienst?: string}>
     */
    public function zerlegen(string $vorlage, array $positionen, int $zoom): array
    {
        $vorlage = trim($vorlage);

        if ('' === $vorlage || !$positionen) {
            return [];
        }

        $teile = [];
        $rest = $vorlage;

        while (preg_match($this->muster(), $rest, $treffer, PREG_OFFSET_CAPTURE)) {
            $vorText = substr($rest, 0, $treffer[0][1]);

            if ('' !== $vorText) {
                $teile[] = ['typ' => 'text', 'text' => $vorText];
            }

            $ziel = MapLinks::PLATZHALTER[$treffer[1][0]];

            // Die Nummer zählt ab 1 wie im Backend; ohne Nummer gilt die erste Markierung.
            $nummer = isset($treffer[2]) && '' !== $treffer[2][0] ? (int) $treffer[2][0] : 1;
            $punkt = $positionen[$nummer - 1] ?? null;

            if (null === $punkt) {
                // Auf eine Markierung verwiesen, die es nicht (mehr) gibt: Der Platzhalter
                // bleibt als Text stehen, statt still zu verschwinden — so fällt der
                // Fehler beim Ansehen der Seite auf und lässt sich beheben.
                $teile[] = ['typ' => 'text', 'text' => $treffer[0][0]];
                $rest = substr($rest, $treffer[0][1] + \strlen($treffer[0][0]));

                continue;
            }

            $teile[] = [
                'typ' => 'link',
                'text' => $this->beschriftung($treffer, $ziel, $punkt),
                'ziel' => $this->links->url($ziel, $punkt['lat'], $punkt['lon'], $zoom),
                'dienst' => $ziel,
            ];

            $rest = substr($rest, $treffer[0][1] + \strlen($treffer[0][0]));
        }

        if ('' !== $rest) {
            $teile[] = ['typ' => 'text', 'text' => $rest];
        }

        return $teile;
    }

    /**
     * Nennt der Text einen Platzhalter, der zu Google führt?
     *
     * Wichtig für den Hinweis im Backend: Ein Link lädt nichts nach, aber der Betreiber
     * sollte wissen, dass ein Klick seine Besucher zu Google führt.
     */
    public function nenntGoogle(string $vorlage): bool
    {
        if (!preg_match_all($this->muster(), $vorlage, $treffer)) {
            return false;
        }

        foreach ($treffer[1] as $name) {
            if ($this->links->fuehrtZuGoogle(MapLinks::PLATZHALTER[$name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Text des Links: eigene Angabe vor Beschriftung der Markierung vor Name des Dienstes.
     *
     * @param array<int, array{0: string, 1: int}>         $treffer Ergebnis von preg_match
     * @param array{lat: float, lon: float, label?: string} $punkt
     */
    private function beschriftung(array $treffer, string $ziel, array $punkt): string
    {
        // {osm:Eigener Text}
        if (isset($treffer[3]) && '' !== $treffer[3][0]) {
            return $treffer[3][0];
        }

        // {osm-2} — bei einer nummerierten Markierung ist deren Name die Auskunft, auf die
        // es ankommt. Ohne Nummer bliebe unklar, welcher Ort gemeint ist.
        $nummeriert = isset($treffer[2]) && '' !== $treffer[2][0];
        $eigene = trim((string) ($punkt['label'] ?? ''));

        if ($nummeriert && '' !== $eigene) {
            return $eigene;
        }

        return MapLinks::BESCHRIFTUNG[$ziel];
    }

    /**
     * Das Suchmuster für die Platzhalter.
     *
     * Die längeren Namen stehen vorn, sonst schluckt „route" den Anfang von
     * „route-google" und der Rest bliebe als Text stehen.
     */
    private function muster(): string
    {
        $namen = array_keys(MapLinks::PLATZHALTER);
        usort($namen, static fn (string $a, string $b) => \strlen($b) <=> \strlen($a));
        $namen = implode('|', array_map(static fn (string $n) => preg_quote($n, '/'), $namen));

        return '/\{('.$namen.')(?:-(\d+))?(?::([^}]*))?\}/';
    }
}
