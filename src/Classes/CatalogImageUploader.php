<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Classes;

use Igniter\Flame\Database\Attach\Media;
use IgniterLabs\SquarePOS\Models\SquareLink;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Square\Types\CatalogImage;
use Square\Types\CatalogObject;
use Square\Types\CatalogObjectImage;

class CatalogImageUploader
{
    public function pushMenuImage(SquareClient $square, $menu): void
    {
        if (!method_exists($menu, 'getFirstMedia')) {
            return;
        }

        $media = $menu->getFirstMedia('thumb');
        if (!$media instanceof Media) {
            return;
        }

        if ($menu->squareLink?->square_image_id && !$menu->squareLink->square_dirty) {
            return;
        }

        [$path, $temporary] = $this->localPath($media);
        if (!$path) {
            return;
        }

        try {
            $imageObject = CatalogObject::image(new CatalogObjectImage([
                'id' => '#temp_image_'.$menu->getKey(),
                'imageData' => new CatalogImage([
                    'name' => $menu->menu_name,
                    'caption' => $menu->menu_name,
                ]),
            ]));

            $uploaded = $square->createCatalogImage(
                (string)Str::uuid(),
                $imageObject,
                $path,
                $menu->squareLink?->square_id,
                $media->getMimeType()
            );

            if ($uploaded) {
                SyncContext::applying(function() use ($menu, $uploaded): void {
                    $link = SquareLink::forOwner($menu);
                    $link->square_image_id = CatalogRecord::unwrap($uploaded)->getId();
                    SquareLink::saveOwner($menu);
                });
            }
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    protected function localPath(Media $media): array
    {
        $fullPath = $media->getFullDiskPath();
        if ($fullPath && is_file($fullPath)) {
            return [$fullPath, null];
        }

        $contents = Storage::disk($media->disk)->get($media->getDiskPath());
        if ($contents === null || $contents === '') {
            return [null, null];
        }

        $directory = $this->temporaryDirectory();
        if (!is_dir($directory)) {
            return [null, null];
        }

        $temporary = tempnam($directory, 'square-image-');
        file_put_contents($temporary, $contents);

        return [$temporary, $temporary];
    }

    protected function temporaryDirectory(): string
    {
        return sys_get_temp_dir();
    }
}
