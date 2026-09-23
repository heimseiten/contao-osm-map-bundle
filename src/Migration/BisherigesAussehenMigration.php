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

namespace Heimseiten\ContaoOsmMapBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Heimseiten\ContaoOsmMapBundle\Service\MapStyle;
use Heimseiten\ContaoOsmMapBundle\Service\MarkerRenderer;

/**
 * Vorhandene Karten behalten beim Update ihr bisheriges Aussehen.
 *
 * Bis Version 1.1 gab es nur eine Form der Markierung, den Punkt mit weißem Rand, und nur die
 * Farben von OpenStreetMap selbst. Seit 1.2 ist beides wählbar, und neue Karten bekommen einen
 * Pin wie bei Google Maps und den Kartenstil „Freundlich". Legte erst der Datenbankabgleich die
 * Spalten an, stünden die neuen Vorgaben auch bei jeder vorhandenen Karte — und die sähe nach
 * dem nächsten Speichern anders aus, obwohl niemand etwas geändert hat.
 *
 * Deshalb legt dieser Schritt die Spalten selbst an und trägt bei allen vorhandenen Karten die
 * bisherigen Werte ein. Er läuft für jede Spalte genau einmal: solange sie fehlt.
 */
class BisherigesAussehenMigration extends AbstractMigration
{
    /**
     * Spalte => [Vorgabe für neue Karten, Wert für vorhandene Karten]
     */
    private const SPALTEN = [
        'osmMarkerForm' => [MarkerRenderer::VORGABE, MarkerRenderer::BISHER],
        'osmKartenstil' => [MapStyle::VORGABE, MapStyle::BISHER],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Karte (OpenStreetMap): vorhandene Karten behalten ihr bisheriges Aussehen';
    }

    public function shouldRun(): bool
    {
        return [] !== $this->fehlendeSpalten();
    }

    public function run(): MigrationResult
    {
        $meldungen = [];

        foreach ($this->fehlendeSpalten() as $spalte) {
            [$vorgabe, $bisher] = self::SPALTEN[$spalte];

            // Dieselbe Definition wie im DCA, damit der anschließende Abgleich nichts mehr ändert.
            $this->connection->executeStatement(\sprintf(
                "ALTER TABLE tl_content ADD %s varchar(16) NOT NULL default '%s'",
                $spalte,
                $vorgabe,
            ));

            $anzahl = $this->connection->executeStatement(
                \sprintf("UPDATE tl_content SET %s = :bisher WHERE type = 'osm_map'", $spalte),
                ['bisher' => $bisher],
            );

            $meldungen[] = \sprintf('%s: %d vorhandene Karte(n) behalten „%s"', $spalte, $anzahl, $bisher);
        }

        return $this->createResult(true, implode(' · ', $meldungen));
    }

    /**
     * @return list<string>
     */
    private function fehlendeSpalten(): array
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['tl_content'])) {
            return [];
        }

        $vorhanden = $schemaManager->listTableColumns('tl_content');

        return array_values(array_filter(
            array_keys(self::SPALTEN),
            static fn (string $spalte): bool => !isset($vorhanden[strtolower($spalte)]),
        ));
    }
}
