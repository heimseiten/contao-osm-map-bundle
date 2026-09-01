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

namespace Heimseiten\ContaoOsmMapBundle\Command;

use Doctrine\DBAL\Connection;
use Heimseiten\ContaoOsmMapBundle\Service\MapBuilder;
use Heimseiten\ContaoOsmMapBundle\Service\OsmMapException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Erzeugt die Kartenbilder aller Karten-Elemente neu.
 *
 * Nützlich, wenn die Bilddateien fehlen (etwa nach einem Umzug auf einen anderen Server)
 * oder wenn viele Karten auf einmal angelegt wurden. Im Alltag ist der Befehl nicht nötig:
 * Beim Speichern eines Elements entsteht das Bild von selbst.
 */
#[AsCommand(
    name: 'heimseiten:osm-map:rebuild',
    description: 'Erzeugt die Bilder aller Karten-Elemente neu.',
)]
class RebuildMapsCommand extends Command
{
    public function __construct(
        private readonly MapBuilder $mapBuilder,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Auch Karten neu erzeugen, deren Bild bereits vorhanden und unverändert ist.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Nur dieses eine Inhaltselement.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sql = "SELECT id, osmPoints, osmZoom, osmZoomEffektiv, osmWidth, osmHeight,
                       osmMobil, osmWidthMobil, osmHeightMobil,
                       osmMarkerColor, osmHash, osmImage, osmImageMobil, osmPositionen
                  FROM tl_content WHERE type = 'osm_map'";
        $parameter = [];

        if ($id = $input->getOption('id')) {
            $sql .= ' AND id = :id';
            $parameter['id'] = (int) $id;
        }

        $elemente = $this->connection->fetchAllAssociative($sql.' ORDER BY id', $parameter);

        if (!$elemente) {
            $io->info('Es gibt keine Karten-Elemente.');

            return Command::SUCCESS;
        }

        $io->title(\sprintf('%d Karten-Element(e)', \count($elemente)));

        $neu = 0;
        $uebersprungen = 0;
        $fehler = 0;

        foreach ($elemente as $element) {
            try {
                $ergebnis = $this->mapBuilder->bauen(
                    [
                        'punkte' => $element['osmPoints'],
                        'zoom' => (string) $element['osmZoom'],
                        'breite' => (int) $element['osmWidth'],
                        'hoehe' => (int) $element['osmHeight'],
                        'markerFarbe' => (string) $element['osmMarkerColor'],
                        'positionen' => (string) $element['osmPositionen'],
                        'gespeicherterZoom' => (int) $element['osmZoomEffektiv'],
                        'breiteMobil' => $element['osmMobil'] ? (int) $element['osmWidthMobil'] : 0,
                        'hoeheMobil' => $element['osmMobil'] ? (int) $element['osmHeightMobil'] : 0,
                    ],
                    $input->getOption('force') ? null : ((string) $element['osmHash'] ?: null),
                    $input->getOption('force') ? null : ($element['osmImage'] ?: null),
                    $input->getOption('force') ? null : ($element['osmImageMobil'] ?: null),
                );
            } catch (OsmMapException $e) {
                $io->writeln(\sprintf('  #%-5s <error>%s</error>', $element['id'], $e->getMessage()));
                ++$fehler;

                continue;
            }

            $this->connection->update('tl_content', [
                'osmImage' => $ergebnis['uuid'],
                'osmImageMobil' => $ergebnis['uuidMobil'],
                'osmPositionen' => json_encode($ergebnis['positionen'], JSON_UNESCAPED_UNICODE),
                'osmHash' => $ergebnis['fingerabdruck'],
                'osmZoomEffektiv' => $ergebnis['zoom'],
            ], ['id' => $element['id']]);

            if ($ergebnis['neu']) {
                $io->writeln(\sprintf(
                    '  #%-5s <info>erzeugt</info>  %d Punkt(e), Maßstab %d — %s',
                    $element['id'],
                    \count($ergebnis['positionen']),
                    $ergebnis['zoom'],
                    $ergebnis['positionen'][0]['anzeigename'] ?? '',
                ));
                ++$neu;
            } else {
                $io->writeln(\sprintf('  #%-5s unverändert', $element['id']));
                ++$uebersprungen;
            }
        }

        $io->newLine();
        $io->writeln(\sprintf('Erzeugt: %d · unverändert: %d · Fehler: %d', $neu, $uebersprungen, $fehler));

        return $fehler > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
