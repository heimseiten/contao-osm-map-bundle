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

namespace Heimseiten\ContaoOsmMapBundle\EventListener;

use Contao\BackendUser;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Image\ImageSizes;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Füllt die Auswahlliste des Feldes „Bildgröße für das schmale Bild".
 *
 * Nötig, weil Contao seine eigene Liste nur an fest genannte Felder hängt
 * (ImageSizeOptionsListener: tl_content.size, tl_module.imgSize, tl_page.imgSize,
 * tl_layout.lightboxSize). Ein zusätzliches Feld vom Typ „imageSize" bleibt ohne eigenen
 * Callback leer — und weil die Auswahl dann keinen gültigen Wert kennt, verwirft Contao
 * beim Speichern auch den bereits hinterlegten. Die Einstellung ginge also jedes Mal
 * lautlos verloren.
 */
#[AsCallback(table: 'tl_content', target: 'fields.osmSizeMobil.options')]
class ImageSizeOptions
{
    public function __construct(
        private readonly Security $security,
        private readonly ImageSizes $imageSizes,
    ) {
    }

    public function __invoke(): array
    {
        $benutzer = $this->security->getUser();

        if (!$benutzer instanceof BackendUser) {
            return [];
        }

        // Dieselbe Liste wie beim Contao-eigenen Feld: nur die Größen, die der angemeldete
        // Benutzer verwenden darf.
        return $this->imageSizes->getOptionsForUser($benutzer);
    }
}
