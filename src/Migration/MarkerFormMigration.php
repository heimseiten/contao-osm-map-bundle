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
use Heimseiten\ContaoOsmMapBundle\Service\MarkerRenderer;

/**
 * Vorhandene Karten behalten beim Update ihren Punkt.
 *
 * Bis Version 1.1 gab es nur eine Form der Markierung, den Punkt mit weißem Rand. Seit 1.2
 * ist sie wählbar, und neue Karten bekommen einen Pin wie bei Google Maps. Legte erst der
 * Datenbankabgleich die Spalte an, stünde die neue Vorgabe auch bei jeder vorhandenen Karte —
 * und die sähe nach dem nächsten Speichern anders aus, obwohl niemand etwas geändert hat.
 *
 * Deshalb legt dieser Schritt die Spalte selbst an und trägt bei allen vorhandenen Karten
 * den Punkt ein. Er läuft genau einmal: solange die Spalte fehlt.
 */
class MarkerFormMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Karte (OpenStreetMap): vorhandene Karten behalten den Punkt als Markierung';
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['tl_content'])) {
            return false;
        }

        $spalten = $schemaManager->listTableColumns('tl_content');

        return !isset($spalten['osmmarkerform']);
    }

    public function run(): MigrationResult
    {
        // Dieselbe Definition wie im DCA, damit der anschließende Abgleich nichts mehr ändert.
        $this->connection->executeStatement(\sprintf(
            "ALTER TABLE tl_content ADD osmMarkerForm varchar(16) NOT NULL default '%s'",
            MarkerRenderer::VORGABE,
        ));

        $anzahl = $this->connection->executeStatement(
            "UPDATE tl_content SET osmMarkerForm = :form WHERE type = 'osm_map'",
            ['form' => MarkerRenderer::BISHER],
        );

        return $this->createResult(true, \sprintf('%d vorhandene Karte(n) behalten den Punkt.', $anzahl));
    }
}
