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

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Dbafs;
use Contao\File;
use Contao\FilesModel;
use Contao\StringUtil;

/**
 * Verbindet Adresssuche und Bildaufbau und legt das Ergebnis im Dateimanager ab.
 *
 * Aufgerufen wird das beim Speichern eines Karten-Elements im Backend. Danach ist die
 * Karte eine gewöhnliche Bilddatei: Sie taucht im Dateimanager auf, lässt sich mit den
 * Bildgrößen von Contao weiterverarbeiten und wird von der eigenen Website ausgeliefert.
 */
class MapBuilder
{
    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly MapImageRenderer $renderer,
        private readonly MapExtent $extent,
        private readonly MarkerRenderer $marker,
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
        private readonly string $zielordner,
    ) {
    }

    /**
     * Baut die Karte, sofern nötig.
     *
     * Ist an den Einstellungen nichts anders als beim letzten Mal (gleicher Fingerabdruck)
     * und liegt die Bilddatei noch, passiert nichts — dann wird OpenStreetMap gar nicht
     * erst angefragt.
     *
     * @param array       $einstellungen    punkte, zoom, breite, hoehe, markerFarbe, markerForm,
     *                                      kartenstil, breiteMobil und hoeheMobil
     *                                      (0 = keine zweite Fassung)
     * @param string|null $fingerabdruckAlt gespeicherter Fingerabdruck des letzten Laufs
     * @param string|null $uuidAlt          bisher hinterlegte Bilddatei (Binärform)
     * @param string|null $uuidMobilAlt     bisher hinterlegte Fassung für schmale Bildschirme
     *
     * @return array{uuid: string, uuidMobil: string|null, positionen: list<array{lat: float, lon: float, label: string, anzeigename: string}>, zoom: int, fingerabdruck: string, neu: bool}
     *
     * @throws OsmMapException
     */
    public function bauen(array $einstellungen, string|null $fingerabdruckAlt, string|null $uuidAlt, string|null $uuidMobilAlt = null): array
    {
        $this->framework->initialize();

        $eingaben = $this->punkteLesen($einstellungen['punkte'] ?? null);

        if (!$eingaben) {
            throw new OsmMapException('Für die Karte ist keine Anschrift eingetragen.');
        }

        $zoomWunsch = (string) ($einstellungen['zoom'] ?? 'auto');
        $breite = $this->grenzen((int) ($einstellungen['breite'] ?? 1920), 200, 2560);
        $hoehe = $this->grenzen((int) ($einstellungen['hoehe'] ?? 600), 100, 2560);
        $farbe = (string) ($einstellungen['markerFarbe'] ?? '#bf7500');

        // Unbekannte oder fehlende Werte: so, wie Karten vor der Auswahl aussahen.
        $form = (string) ($einstellungen['markerForm'] ?? '');
        $form = \in_array($form, MarkerRenderer::AUSWAHL, true) ? $form : MarkerRenderer::BISHER;
        $stil = (string) ($einstellungen['kartenstil'] ?? '');
        $stil = \in_array($stil, MapStyle::AUSWAHL, true) ? $stil : MapStyle::BISHER;

        // Zweite Fassung für schmale Bildschirme. Ein breites, flaches Kartenbild wird auf
        // dem Telefon entweder winzig oder es zeigt nach dem Zuschneiden die Umgebung statt
        // des Ortes; deshalb entsteht auf Wunsch ein zweites Bild in eigenem Format —
        // typischerweise quadratisch. Beide Male derselbe Mittelpunkt, nur ein anderer
        // Ausschnitt. 0 bei einem der beiden Maße heißt: keine zweite Fassung.
        $breiteMobil = (int) ($einstellungen['breiteMobil'] ?? 0);
        $hoeheMobil = (int) ($einstellungen['hoeheMobil'] ?? 0);
        $zweiteFassung = $breiteMobil > 0 && $hoeheMobil > 0;

        if ($zweiteFassung) {
            $breiteMobil = $this->grenzen($breiteMobil, 200, 2560);
            $hoeheMobil = $this->grenzen($hoeheMobil, 100, 2560);
        }

        // Der Fingerabdruck umfasst alles, was das Bild beeinflusst. Ändert sich nichts
        // davon, wird OpenStreetMap gar nicht erst angefragt.
        $teile = [
            json_encode($eingaben),
            $zoomWunsch,
            $breite,
            $hoehe,
            $farbe,
            $zweiteFassung ? $breiteMobil.'x'.$hoeheMobil : 'ohne-zweite-fassung',
        ];

        // Form und Kartenstil gibt es seit Version 1.2. Sie zählen nur, wenn sie vom
        // bisherigen Aussehen abweichen — so behalten vorhandene Karten ihren Fingerabdruck
        // und werden nach dem Update nicht ohne Anlass neu erzeugt.
        if (MarkerRenderer::BISHER !== $form) {
            $teile[] = 'form:'.$form;
        }

        if (MapStyle::BISHER !== $stil) {
            $teile[] = 'stil:'.$stil;
        }

        $fingerabdruck = md5(implode('|', $teile));

        if ($fingerabdruck === $fingerabdruckAlt && $uuidAlt && $this->dateiLiegt($uuidAlt)
            && (!$zweiteFassung || ($uuidMobilAlt && $this->dateiLiegt($uuidMobilAlt)))
        ) {
            return [
                'uuid' => $uuidAlt,
                'uuidMobil' => $zweiteFassung ? $uuidMobilAlt : null,
                'positionen' => $this->positionenLesen($einstellungen['positionen'] ?? null),
                'zoom' => (int) ($einstellungen['gespeicherterZoom'] ?? 15),
                'fingerabdruck' => $fingerabdruck,
                'neu' => false,
            ];
        }

        // Alle Orte nachschlagen. Die erste Zeile ist die Hauptanschrift.
        $punkte = [];

        foreach ($eingaben as $eintrag) {
            $treffer = $this->geocoder->suche($eintrag['anschrift']);
            $punkte[] = [
                'lat' => $treffer['lat'],
                'lon' => $treffer['lon'],
                'label' => $eintrag['beschriftung'],
                'anzeigename' => $treffer['anzeigename'],
            ];
        }

        // Mittelpunkt und Maßstab: Bei mehreren Orten liegt die Mitte zwischen ihnen, und
        // „automatisch" wählt die näheste Stufe, bei der noch alle ins Bild passen — mit
        // Platz für die Köpfe, falls die Markierungen Pins sind.
        [$mittelLat, $mittelLon] = \count($punkte) > 1
            ? $this->extent->mittelpunkt($punkte)
            : [$punkte[0]['lat'], $punkte[0]['lon']];

        $kopf = $this->marker->kopfhoehe($form);

        $zoom = 'auto' === $zoomWunsch
            ? $this->extent->passenderZoom($punkte, $breite, $hoehe, kopf: $kopf)
            : $this->grenzen((int) $zoomWunsch, 3, 19);

        $daten = $this->renderer->rendern(
            $punkte,
            $this->mitteFuerKoepfe($punkte, $mittelLat, $zoom, $kopf),
            $mittelLon,
            $zoom,
            $breite,
            $hoehe,
            $farbe,
            markerForm: $form,
            kartenstil: $stil,
        );

        $pfad = $this->dateiAblegen($daten, $eingaben[0]['anschrift'], $fingerabdruck);

        $model = FilesModel::findByPath($pfad);

        if (null === $model) {
            throw new OsmMapException(\sprintf('Das Kartenbild "%s" ließ sich nicht in den Dateimanager übernehmen.', $pfad));
        }

        $uuidMobil = null;

        if ($zweiteFassung) {
            // Bei „automatisch" wird der Maßstab für das zweite Format eigens bestimmt: In
            // ein quadratisches Bild passt seitlich weniger, dafür senkrecht mehr — mit dem
            // Wert des breiten Bildes lägen die Markierungen sonst außerhalb.
            $zoomMobil = 'auto' === $zoomWunsch
                ? $this->extent->passenderZoom($punkte, $breiteMobil, $hoeheMobil, kopf: $kopf)
                : $zoom;

            $datenMobil = $this->renderer->rendern(
                $punkte,
                $this->mitteFuerKoepfe($punkte, $mittelLat, $zoomMobil, $kopf),
                $mittelLon,
                $zoomMobil,
                $breiteMobil,
                $hoeheMobil,
                $farbe,
                markerForm: $form,
                kartenstil: $stil,
            );
            $pfadMobil = $this->dateiAblegen($datenMobil, $eingaben[0]['anschrift'], $fingerabdruck, '-schmal');

            $modelMobil = FilesModel::findByPath($pfadMobil);

            if (null === $modelMobil) {
                throw new OsmMapException(\sprintf('Das Kartenbild "%s" ließ sich nicht in den Dateimanager übernehmen.', $pfadMobil));
            }

            $uuidMobil = $modelMobil->uuid;
        }

        return [
            'uuid' => $model->uuid,
            'uuidMobil' => $uuidMobil,
            // Gespeichert werden ALLE Positionen, nicht die Bildmitte: Der Klick auf die
            // Karte führt zur Hauptanschrift (erste Position), und der Balken kann jede
            // einzelne Markierung verlinken.
            'positionen' => $punkte,
            'zoom' => $zoom,
            'fingerabdruck' => $fingerabdruck,
            'neu' => true,
        ];
    }

    /**
     * Mitte des Ausschnitts, wenn die Markierungen über ihren Ort hinausragen (Pins).
     *
     * Bei mehreren Orten rückt der Ausschnitt um die halbe Kopfhöhe nach oben: Dann haben
     * die Köpfe oben so viel Luft wie die Spitzen unten. Bei einem einzigen Ort bleibt die
     * Spitze in der Bildmitte, wie bei Google Maps.
     *
     * @param list<array{lat: float, lon: float}> $punkte
     */
    private function mitteFuerKoepfe(array $punkte, float $mittelLat, int $zoom, int $kopf): float
    {
        if ($kopf <= 0 || \count($punkte) < 2) {
            return $mittelLat;
        }

        return $this->extent->breiteVerschieben($mittelLat, $zoom, $kopf / 2);
    }

    /**
     * Liest die gespeicherten Positionen (JSON) zurück.
     *
     * @return list<array{lat: float, lon: float, label: string, anzeigename: string}>
     */
    private function positionenLesen(mixed $rohdaten): array
    {
        $werte = \is_string($rohdaten) ? json_decode($rohdaten, true) : $rohdaten;

        if (!\is_array($werte)) {
            return [];
        }

        $positionen = [];

        foreach ($werte as $wert) {
            if (!\is_array($wert) || !isset($wert['lat'], $wert['lon'])) {
                continue;
            }

            $positionen[] = [
                'lat' => (float) $wert['lat'],
                'lon' => (float) $wert['lon'],
                'label' => (string) ($wert['label'] ?? ''),
                'anzeigename' => (string) ($wert['anzeigename'] ?? ''),
            ];
        }

        return $positionen;
    }

    /**
     * Liest die Zeilen des Feldes „Markierungen".
     *
     * @return list<array{anschrift: string, beschriftung: string}>
     */
    private function punkteLesen(mixed $rohdaten): array
    {
        if (\is_string($rohdaten)) {
            $rohdaten = StringUtil::deserialize($rohdaten, true);
        }

        if (!\is_array($rohdaten)) {
            return [];
        }

        $punkte = [];

        foreach ($rohdaten as $zeile) {
            if (!\is_array($zeile)) {
                continue;
            }

            $anschrift = trim((string) ($zeile['anschrift'] ?? ''));

            if ('' === $anschrift) {
                continue; // leere Zeilen im Wizard einfach überspringen
            }

            $punkte[] = [
                'anschrift' => $anschrift,
                'beschriftung' => trim((string) ($zeile['beschriftung'] ?? '')),
            ];
        }

        return $punkte;
    }

    /**
     * Schreibt die Bilddatei und meldet sie dem Dateimanager.
     */
    /**
     * Liegt die Datei zu dieser Kennung noch auf der Platte?
     */
    private function dateiLiegt(string $uuid): bool
    {
        $model = FilesModel::findByUuid($uuid);

        return null !== $model && is_file($this->projectDir.'/'.$model->path);
    }

    private function dateiAblegen(string $daten, string $anschrift, string $fingerabdruck, string $zusatz = ''): string
    {
        $ordner = trim($this->zielordner, '/');
        $verzeichnis = $this->projectDir.'/'.$ordner;

        if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0777, true) && !is_dir($verzeichnis)) {
            throw new OsmMapException(\sprintf('Der Ordner "%s" ließ sich nicht anlegen.', $ordner));
        }

        // Ohne die Markierung ".public" liefert Contao nichts aus dem Ordner aus — die
        // Bilder blieben unsichtbar (der Aufruf endet mit einer Weiterleitung statt mit
        // dem Bild). Der Verweis in public/files entsteht daraus beim nächsten Aufräumen;
        // damit die Karte sofort erscheint, wird er hier gleich mit angelegt.
        $markierung = $verzeichnis.'/.public';

        if (!is_file($markierung)) {
            @touch($markierung);
        }

        // Getrennt prüfen: Die Markierung kann liegen, der Verweis aber fehlen — etwa
        // wenn public/ neu aufgebaut wurde.
        $this->verweisAnlegen($ordner);

        // Sprechender Dateiname aus der Anschrift, dazu der Fingerabdruck: So bleibt die
        // Datei erkennbar, und eine geänderte Karte überschreibt die alte nicht.
        $name = 'karte-'.$this->dateinameAusAnschrift($anschrift).'-'.substr($fingerabdruck, 0, 8).$zusatz.'.jpg';
        $pfad = $ordner.'/'.$name;

        $datei = new File($pfad);
        $datei->write($daten);
        $datei->close();

        Dbafs::addResource($pfad);

        return $pfad;
    }

    /**
     * Legt den Verweis unter public/ an, über den der Webserver den Ordner ausliefert.
     *
     * Contao erzeugt diese Verweise sonst erst beim Aufräumen der Symlinks
     * (contao:symlinks). Für einen Redakteur, der gerade eine Karte gespeichert hat, wäre
     * das zu spät — das Bild bliebe bis dahin unsichtbar.
     */
    private function verweisAnlegen(string $ordner): void
    {
        $ziel = $this->projectDir.'/public/'.$ordner;

        if (is_link($ziel) || is_dir($ziel)) {
            return;
        }

        $elternteil = \dirname($ziel);

        if (!is_dir($elternteil) && !@mkdir($elternteil, 0777, true) && !is_dir($elternteil)) {
            return;
        }

        // Relativer Verweis, damit er einen Umzug des Projekts übersteht.
        // Der Verweis liegt in public/<ordner>; von dort führen so viele Schritte nach
        // oben zur Projektwurzel, wie der Ordnerpfad Ebenen hat, plus einer für public.
        // Beispiel: public/files/karten -> ../../files/karten (wie bei Contaos eigenen
        // Verweisen, etwa public/files/media).
        $tiefe = substr_count(trim($ordner, '/'), '/') + 1;
        @symlink(str_repeat('../', $tiefe).trim($ordner, '/'), $ziel);
    }

    private function dateinameAusAnschrift(string $anschrift): string
    {
        // Umlaute ausschreiben, bevor der Rest wegfällt — sonst würde aus „Münster"
        // ein „m-nster" statt „muenster".
        $name = strtr($anschrift, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
        ]);
        $name = StringUtil::generateAlias($name);
        $name = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-');

        if ('' === $name) {
            $name = 'ohne-anschrift';
        }

        return substr($name, 0, 60);
    }

    private function grenzen(int $wert, int $min, int $max): int
    {
        return max($min, min($max, $wert));
    }
}
