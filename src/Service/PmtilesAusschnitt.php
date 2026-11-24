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
 * Schneidet ein Gebiet aus einer PMTiles-Datei (Version 3) aus und schreibt es als eigene,
 * kleine PMTiles-Datei.
 *
 * Quelle ist die tägliche Weltkarte von Protomaps (Vektorkacheln aus OpenStreetMap-Daten,
 * rund 140 GB). Gelesen wird nur, was das Gebiet braucht: Kopf und Verzeichnisse, dann die
 * Kacheln — alles über HTTP-Range-Abrufe. Es muss also kein Programm auf dem Server laufen
 * (das Werkzeug „pmtiles extract“ ist ein Go-Programm, das sich auf üblichem Webhosting
 * nicht starten lässt).
 *
 * Aufbau der Datei laut Spezifikation (github.com/protomaps/PMTiles/blob/main/spec/v3):
 * Kopf (127 Byte) · Wurzelverzeichnis · Metadaten · Blattverzeichnisse · Kacheldaten.
 * Kacheln sind über eine Hilbert-Kurve nummeriert; benachbarte Kacheln liegen daher auch in
 * der Datei nebeneinander, und wenige zusammengefasste Abrufe genügen.
 */
class PmtilesAusschnitt
{
    /** Tägliche Weltkarte; %s = Datum JJJJMMTT. */
    public const QUELLE = 'https://build.protomaps.com/%s.pmtiles';

    private const KOPF = 127;

    /** Zwei Datenbereiche werden zu einem Abruf zusammengefasst, wenn dazwischen weniger liegt. */
    private const LUECKE = 65536;

    private const GLEICHZEITIG = 8;

    /** @var array<string, list<array{id: int, lauf: int, laenge: int, offset: int}>> */
    private array $verzeichnisse = [];

    private string $quelle = '';

    /** @var array<string, int> */
    private array $kopf = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $userAgent,
    ) {
    }

    /**
     * Neueste verfügbare Weltkarte (heute oder einer der letzten Tage).
     */
    public function neuesteQuelle(): string
    {
        for ($tage = 0; $tage < 10; ++$tage) {
            $url = \sprintf(self::QUELLE, gmdate('Ymd', time() - $tage * 86400));

            try {
                $antwort = $this->httpClient->request('HEAD', $url, ['headers' => ['User-Agent' => $this->userAgent], 'timeout' => 20]);

                if (200 === $antwort->getStatusCode()) {
                    return $url;
                }
            } catch (\Throwable) {
                // nächster Tag
            }
        }

        throw new OsmMapException('Die Kartendaten von Protomaps sind gerade nicht erreichbar (build.protomaps.com). Bitte später noch einmal speichern.');
    }

    /**
     * @param list<array{gebiet: array{0: float, 1: float, 2: float, 3: float}, von: int, bis: int}> $ebenen
     *        je Zoombereich ein Gebiet (Westen, Süden, Osten, Norden in Grad) — etwa ein weites
     *        Umfeld für die groben Stufen und das engere Gebiet für die Details
     *
     * @return array{kacheln: int, bytes: int, abrufe: int, quelle: string, maxZoom: int}
     */
    public function ausschneiden(string $quelle, array $ebenen, string $zielpfad): array
    {
        $maxZoom = max(array_column($ebenen, 'bis'));
        $gebiet = [
            min(array_map(static fn ($e) => $e['gebiet'][0], $ebenen)),
            min(array_map(static fn ($e) => $e['gebiet'][1], $ebenen)),
            max(array_map(static fn ($e) => $e['gebiet'][2], $ebenen)),
            max(array_map(static fn ($e) => $e['gebiet'][3], $ebenen)),
        ];

        $this->quelle = $quelle;
        $this->verzeichnisse = [];
        $abrufe = 0;

        // Kopf und Wurzelverzeichnis stehen laut Spezifikation in den ersten 16 384 Byte.
        $anfang = $this->lesen(0, 16384);
        ++$abrufe;
        $this->kopf = $kopf = $this->kopfLesen($anfang);
        $maxZoom = min($maxZoom, $kopf['maxZoom']);

        $wurzel = $this->verzeichnisLesen($this->entpacken(substr($anfang, $kopf['wurzelOffset'], $kopf['wurzelLaenge'])));
        $metadaten = $this->entpacken($this->lesen($kopf['metaOffset'], $kopf['metaLaenge']));
        ++$abrufe;

        // Alle gebrauchten Kacheln: je Zoomstufe das Rechteck, das das Gebiet dieser Stufe überdeckt.
        $gesucht = [];

        foreach ($ebenen as $ebene) {
            for ($z = $ebene['von']; $z <= min($ebene['bis'], $maxZoom); ++$z) {
                [$x0, $y0] = $this->kachel($ebene['gebiet'][0], $ebene['gebiet'][3], $z);
                [$x1, $y1] = $this->kachel($ebene['gebiet'][2], $ebene['gebiet'][1], $z);

                for ($x = $x0; $x <= $x1; ++$x) {
                    for ($y = $y0; $y <= $y1; ++$y) {
                        $gesucht[self::zxyZuId($z, $x, $y)] = true;
                    }
                }
            }
        }

        $gesucht = array_keys($gesucht);
        sort($gesucht);

        // Wo liegen sie in der Quelle? Blattverzeichnisse werden einmal geholt und gemerkt.
        $fundstellen = [];

        foreach ($gesucht as $id) {
            $treffer = $this->finden($id, $wurzel, $abrufe);

            if (null !== $treffer) {
                $fundstellen[$id] = $treffer;
            }
        }

        if (!$fundstellen) {
            throw new OsmMapException('Für dieses Gebiet enthalten die Kartendaten keine Kacheln.');
        }

        // Kacheldaten holen: gleiche Inhalte (z. B. Wald- oder Wasserflächen) nur einmal,
        // nahe beieinanderliegende Bereiche in einem Abruf.
        $bereiche = [];

        foreach ($fundstellen as [$offset, $laenge]) {
            $bereiche[$offset] = $laenge;
        }

        ksort($bereiche);
        $abschnitte = [];

        foreach ($bereiche as $offset => $laenge) {
            $letzter = array_key_last($abschnitte);

            if (null !== $letzter && $offset - $abschnitte[$letzter]['ende'] < self::LUECKE) {
                $abschnitte[$letzter]['ende'] = max($abschnitte[$letzter]['ende'], $offset + $laenge);
            } else {
                $abschnitte[] = ['start' => $offset, 'ende' => $offset + $laenge];
            }
        }

        $roh = [];

        foreach (array_chunk($abschnitte, self::GLEICHZEITIG) as $gruppe) {
            $antworten = [];

            foreach ($gruppe as $i => $abschnitt) {
                $antworten[$i] = $this->anfragen($kopf['datenOffset'] + $abschnitt['start'], $abschnitt['ende'] - $abschnitt['start']);
            }

            foreach ($antworten as $i => $antwort) {
                $roh[$gruppe[$i]['start']] = $this->inhalt($antwort, $gruppe[$i]['ende'] - $gruppe[$i]['start']);
                ++$abrufe;
            }
        }

        $abschnittVon = static function (int $offset) use ($abschnitte): int {
            $lo = 0;
            $hi = \count($abschnitte) - 1;

            while ($lo < $hi) {
                $mitte = ($lo + $hi + 1) >> 1;

                if ($abschnitte[$mitte]['start'] <= $offset) {
                    $lo = $mitte;
                } else {
                    $hi = $mitte - 1;
                }
            }

            return $abschnitte[$lo]['start'];
        };

        // Neue Datei: Kacheln in der Reihenfolge ihrer Nummer, gleiche Inhalte nur einmal.
        $daten = '';
        $neuerOffset = [];
        $eintraege = [];

        foreach ($fundstellen as $id => [$offset, $laenge]) {
            if (!isset($neuerOffset[$offset])) {
                $start = $abschnittVon($offset);
                $neuerOffset[$offset] = \strlen($daten);
                $daten .= substr($roh[$start], $offset - $start, $laenge);
            }

            $ziel = $neuerOffset[$offset];
            $letzter = array_key_last($eintraege);

            if (null !== $letzter
                && $eintraege[$letzter]['offset'] === $ziel
                && $eintraege[$letzter]['id'] + $eintraege[$letzter]['lauf'] === $id
            ) {
                ++$eintraege[$letzter]['lauf'];
            } else {
                $eintraege[] = ['id' => $id, 'lauf' => 1, 'laenge' => $laenge, 'offset' => $ziel];
            }
        }

        [$wurzelNeu, $blaetter] = $this->verzeichnisseBauen($eintraege);
        $metaNeu = gzencode($metadaten, 9);

        $wurzelOffset = self::KOPF;
        $metaOffset = $wurzelOffset + \strlen($wurzelNeu);
        $blattOffset = $metaOffset + \strlen($metaNeu);
        $datenOffset = $blattOffset + \strlen($blaetter);

        $kopfNeu = 'PMTiles'.\chr(3)
            .pack('P*',
                $wurzelOffset, \strlen($wurzelNeu),
                $metaOffset, \strlen($metaNeu),
                $blattOffset, \strlen($blaetter),
                $datenOffset, \strlen($daten),
                \count($fundstellen), \count($eintraege), \count($neuerOffset),
            )
            .pack('C*', 1, 2, $kopf['kachelKompression'], $kopf['kachelTyp'], 0, $maxZoom)
            .pack('V*',
                self::e7($gebiet[0]), self::e7($gebiet[1]), self::e7($gebiet[2]), self::e7($gebiet[3]),
            )
            .pack('C', min($maxZoom, 12))
            .pack('V*', self::e7(($gebiet[0] + $gebiet[2]) / 2), self::e7(($gebiet[1] + $gebiet[3]) / 2));

        if (self::KOPF !== \strlen($kopfNeu)) {
            throw new \LogicException('PMTiles-Kopf hat die falsche Länge.');
        }

        $verzeichnis = \dirname($zielpfad);

        if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0777, true) && !is_dir($verzeichnis)) {
            throw new OsmMapException(\sprintf('Der Ordner "%s" ließ sich nicht anlegen.', $verzeichnis));
        }

        $temp = $zielpfad.'.tmp';

        if (false === file_put_contents($temp, $kopfNeu.$wurzelNeu.$metaNeu.$blaetter.$daten) || !rename($temp, $zielpfad)) {
            @unlink($temp);

            throw new OsmMapException('Die Kartendaten ließen sich nicht speichern.');
        }

        return [
            'kacheln' => \count($fundstellen),
            'bytes' => (int) filesize($zielpfad),
            'abrufe' => $abrufe,
            'quelle' => $quelle,
            'maxZoom' => $maxZoom,
        ];
    }

    /**
     * Kachelnummer laut Spezifikation: alle Kacheln der kleineren Stufen, dann die Lage auf
     * der Hilbert-Kurve dieser Stufe.
     */
    public static function zxyZuId(int $z, int $x, int $y): int
    {
        $vorher = intdiv((1 << (2 * $z)) - 1, 3);
        $d = 0;
        $tx = $x;
        $ty = $y;

        for ($s = (1 << $z) >> 1; $s > 0; $s >>= 1) {
            $rx = ($tx & $s) > 0 ? 1 : 0;
            $ry = ($ty & $s) > 0 ? 1 : 0;
            $d += $s * $s * ((3 * $rx) ^ $ry);

            if (0 === $ry) {
                if (1 === $rx) {
                    $tx = $s - 1 - $tx;
                    $ty = $s - 1 - $ty;
                }

                [$tx, $ty] = [$ty, $tx];
            }
        }

        return $vorher + $d;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function kachel(float $lon, float $lat, int $z): array
    {
        $n = 1 << $z;
        $lat = max(-85.0511, min(85.0511, $lat));
        $x = (int) floor(($lon + 180) / 360 * $n);
        $r = deg2rad($lat);
        $y = (int) floor((1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * $n);

        return [max(0, min($n - 1, $x)), max(0, min($n - 1, $y))];
    }

    /**
     * @param list<array{id: int, lauf: int, laenge: int, offset: int}> $verzeichnis
     *
     * @return array{0: int, 1: int}|null Offset und Länge in den Kacheldaten
     */
    private function finden(int $id, array $verzeichnis, int &$abrufe, int $tiefe = 0): array|null
    {
        if ($tiefe > 3 || !$verzeichnis) {
            return null;
        }

        $lo = 0;
        $hi = \count($verzeichnis) - 1;

        if ($verzeichnis[0]['id'] > $id) {
            return null;
        }

        while ($lo < $hi) {
            $mitte = ($lo + $hi + 1) >> 1;

            if ($verzeichnis[$mitte]['id'] <= $id) {
                $lo = $mitte;
            } else {
                $hi = $mitte - 1;
            }
        }

        $eintrag = $verzeichnis[$lo];

        if (0 === $eintrag['lauf']) {
            $schluessel = $eintrag['offset'].':'.$eintrag['laenge'];

            if (!isset($this->verzeichnisse[$schluessel])) {
                $this->verzeichnisse[$schluessel] = $this->verzeichnisLesen($this->entpacken(
                    $this->lesen($this->kopf['blattOffset'] + $eintrag['offset'], $eintrag['laenge']),
                ));
                ++$abrufe;
            }

            return $this->finden($id, $this->verzeichnisse[$schluessel], $abrufe, $tiefe + 1);
        }

        if ($id < $eintrag['id'] + $eintrag['lauf']) {
            return [$eintrag['offset'], $eintrag['laenge']];
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function kopfLesen(string $roh): array
    {
        if (\strlen($roh) < self::KOPF || 'PMTiles' !== substr($roh, 0, 7) || 3 !== \ord($roh[7])) {
            throw new OsmMapException('Die Quelle ist keine PMTiles-Datei der Version 3.');
        }

        $w = unpack('P11', substr($roh, 8, 88));
        $b = unpack('C6', substr($roh, 96, 6));

        if (2 !== $b[2] && 1 !== $b[2]) {
            throw new OsmMapException('Die Verzeichnisse der Quelle sind mit einem unbekannten Verfahren gepackt.');
        }

        return [
            'wurzelOffset' => $w[1], 'wurzelLaenge' => $w[2],
            'metaOffset' => $w[3], 'metaLaenge' => $w[4],
            'blattOffset' => $w[5], 'blattLaenge' => $w[6],
            'datenOffset' => $w[7], 'datenLaenge' => $w[8],
            'innenKompression' => $b[2], 'kachelKompression' => $b[3], 'kachelTyp' => $b[4],
            'minZoom' => $b[5], 'maxZoom' => $b[6],
        ];
    }

    private function entpacken(string $roh): string
    {
        if (1 === ($this->kopf['innenKompression'] ?? 2)) {
            return $roh;
        }

        $klar = @gzdecode($roh);

        if (false === $klar) {
            throw new OsmMapException('Ein Verzeichnis der Kartendaten ließ sich nicht entpacken.');
        }

        return $klar;
    }

    /**
     * @return list<array{id: int, lauf: int, laenge: int, offset: int}>
     */
    private function verzeichnisLesen(string $roh): array
    {
        $pos = 0;
        $anzahl = self::varint($roh, $pos);
        $eintraege = [];
        $id = 0;

        for ($i = 0; $i < $anzahl; ++$i) {
            $id += self::varint($roh, $pos);
            $eintraege[$i] = ['id' => $id, 'lauf' => 0, 'laenge' => 0, 'offset' => 0];
        }

        for ($i = 0; $i < $anzahl; ++$i) {
            $eintraege[$i]['lauf'] = self::varint($roh, $pos);
        }

        for ($i = 0; $i < $anzahl; ++$i) {
            $eintraege[$i]['laenge'] = self::varint($roh, $pos);
        }

        for ($i = 0; $i < $anzahl; ++$i) {
            $wert = self::varint($roh, $pos);
            $eintraege[$i]['offset'] = 0 === $wert && $i > 0
                ? $eintraege[$i - 1]['offset'] + $eintraege[$i - 1]['laenge']
                : $wert - 1;
        }

        return $eintraege;
    }

    /**
     * Wurzelverzeichnis und, falls es zu groß wird, Blattverzeichnisse (gzip).
     *
     * @param list<array{id: int, lauf: int, laenge: int, offset: int}> $eintraege
     *
     * @return array{0: string, 1: string}
     */
    private function verzeichnisseBauen(array $eintraege): array
    {
        $wurzel = gzencode(self::verzeichnisSchreiben($eintraege), 9);

        if (\strlen($wurzel) <= 16384 - self::KOPF) {
            return [$wurzel, ''];
        }

        for ($groesse = 4096; ; $groesse *= 2) {
            $blaetter = '';
            $verweise = [];

            foreach (array_chunk($eintraege, $groesse) as $teil) {
                $blatt = gzencode(self::verzeichnisSchreiben($teil), 9);
                $verweise[] = ['id' => $teil[0]['id'], 'lauf' => 0, 'laenge' => \strlen($blatt), 'offset' => \strlen($blaetter)];
                $blaetter .= $blatt;
            }

            $wurzel = gzencode(self::verzeichnisSchreiben($verweise), 9);

            if (\strlen($wurzel) <= 16384 - self::KOPF) {
                return [$wurzel, $blaetter];
            }
        }
    }

    /**
     * @param list<array{id: int, lauf: int, laenge: int, offset: int}> $eintraege
     */
    private static function verzeichnisSchreiben(array $eintraege): string
    {
        $aus = self::varintSchreiben(\count($eintraege));
        $letzte = 0;

        foreach ($eintraege as $e) {
            $aus .= self::varintSchreiben($e['id'] - $letzte);
            $letzte = $e['id'];
        }

        foreach ($eintraege as $e) {
            $aus .= self::varintSchreiben($e['lauf']);
        }

        foreach ($eintraege as $e) {
            $aus .= self::varintSchreiben($e['laenge']);
        }

        foreach ($eintraege as $i => $e) {
            $folgt = $i > 0 && $e['offset'] === $eintraege[$i - 1]['offset'] + $eintraege[$i - 1]['laenge'];
            $aus .= self::varintSchreiben($folgt ? 0 : $e['offset'] + 1);
        }

        return $aus;
    }

    private static function varint(string $roh, int &$pos): int
    {
        $wert = 0;
        $verschiebung = 0;

        do {
            if (!isset($roh[$pos])) {
                throw new OsmMapException('Ein Verzeichnis der Kartendaten ist unvollständig.');
            }

            $byte = \ord($roh[$pos++]);
            $wert |= ($byte & 0x7F) << $verschiebung;
            $verschiebung += 7;
        } while ($byte & 0x80);

        return $wert;
    }

    private static function varintSchreiben(int $wert): string
    {
        $aus = '';

        do {
            $byte = $wert & 0x7F;
            $wert >>= 7;
            $aus .= \chr($wert > 0 ? $byte | 0x80 : $byte);
        } while ($wert > 0);

        return $aus;
    }

    /** Grad × 10⁷ als vorzeichenbehaftete 32-Bit-Zahl, für pack('V'). */
    private static function e7(float $grad): int
    {
        $wert = (int) round($grad * 10_000_000);

        return $wert < 0 ? $wert + 0x100000000 : $wert;
    }

    private function lesen(int $offset, int $laenge): string
    {
        return $this->inhalt($this->anfragen($offset, $laenge), $laenge);
    }

    private function anfragen(int $offset, int $laenge): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $this->httpClient->request('GET', $this->quelle, [
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Range' => \sprintf('bytes=%d-%d', $offset, $offset + $laenge - 1),
            ],
            'timeout' => 60,
        ]);
    }

    private function inhalt(\Symfony\Contracts\HttpClient\ResponseInterface $antwort, int $laenge): string
    {
        try {
            $status = $antwort->getStatusCode();
            $inhalt = $antwort->getContent(false);
        } catch (\Throwable $e) {
            throw new OsmMapException('Die Kartendaten ließen sich nicht laden: '.$e->getMessage());
        }

        if (206 !== $status || \strlen($inhalt) !== $laenge) {
            throw new OsmMapException(\sprintf('Die Kartendaten ließen sich nicht laden (HTTP %d, %d von %d Byte).', $status, \strlen($inhalt), $laenge));
        }

        return $inhalt;
    }
}
