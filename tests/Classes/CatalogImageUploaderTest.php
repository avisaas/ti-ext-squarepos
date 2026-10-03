<?php

use Igniter\Cart\Models\Menu;
use Igniter\Flame\Database\Attach\Media;
use IgniterLabs\SquarePOS\Classes\CatalogImageUploader;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Tests\Support\Square;
use Illuminate\Support\Facades\Storage;

it('uploads a local menu image and skips work that is already in sync', function(): void {
    Square::configureSquare();
    $base = Square::makeMenu(['menu_name' => 'AMALA']);
    $path = tempnam(sys_get_temp_dir(), 'menu-image');
    file_put_contents($path, 'jpeg');

    $media = new class extends Media
    {
        public string $fullPath = '';

        public function getFullDiskPath(): string
        {
            return $this->fullPath;
        }

        public function getMimeType()
        {
            return 'image/jpeg';
        }
    };
    $media->fullPath = $path;

    $menu = new class extends Menu
    {
        public $fakeMedia;

        public function getFirstMedia(?string $tag = null, array $filters = []): ?Media
        {
            return $this->fakeMedia;
        }
    };
    $menu->setRawAttributes($base->getAttributes());
    $menu->exists = true;
    $menu->fakeMedia = $media;

    $uploaded = Square::squareObject('IMAGE', 'IMG1');
    $square = Mockery::mock(SquareClient::class);
    $square->shouldReceive('createCatalogImage')->once()->andReturn($uploaded);

    (new CatalogImageUploader)->pushMenuImage($square, $menu);

    expect($menu->squareLink->square_image_id)->toBe('IMG1');

    $menu->squareLink->square_dirty = false;
    $menu->squareLink->save();
    $quiet = Mockery::mock(SquareClient::class);
    $quiet->shouldReceive('createCatalogImage')->never();
    (new CatalogImageUploader)->pushMenuImage($quiet, $menu);

    unlink($path);
});

it('copies a remote file into a temporary path and ignores empty or missing files', function(): void {
    Storage::fake('square-media');
    Storage::disk('square-media')->put('photos/item.jpg', 'bytes');

    $media = new class extends Media
    {
        public string $disk = 'square-media';

        public function getFullDiskPath(): string
        {
            return '/missing/square-image.jpg';
        }

        public function getDiskPath(): string
        {
            return 'photos/item.jpg';
        }

        public function getMimeType()
        {
            return null;
        }
    };

    $uploader = new CatalogImageUploader;
    [$path, $temporary] = (new ReflectionMethod($uploader, 'localPath'))->invoke($uploader, $media);
    expect(is_file($path))->toBeTrue()
        ->and($temporary)->toBe($path);
    unlink($temporary);

    Storage::disk('square-media')->put('photos/empty.jpg', '');
    $empty = new class extends Media
    {
        public string $disk = 'square-media';

        public function getFullDiskPath(): string
        {
            return '/missing/empty.jpg';
        }

        public function getDiskPath(): string
        {
            return 'photos/empty.jpg';
        }
    };
    expect((new ReflectionMethod($uploader, 'localPath'))->invoke($uploader, $empty))->toBe([null, null]);

    $broken = new class extends CatalogImageUploader
    {
        protected function temporaryDirectory(): string
        {
            return '/this/directory/does/not/exist';
        }
    };
    expect((new ReflectionMethod($broken, 'localPath'))->invoke($broken, $media))->toBe([null, null]);

    $square = Mockery::mock(SquareClient::class);
    $menu = new class extends Menu
    {
        public $fakeMedia;

        public function getFirstMedia(?string $tag = null, array $filters = []): ?Media
        {
            return $this->fakeMedia;
        }
    };
    $menu->fakeMedia = $empty;
    (new CatalogImageUploader)->pushMenuImage($square, $menu);
    (new CatalogImageUploader)->pushMenuImage($square, new stdClass);
    (new CatalogImageUploader)->pushMenuImage($square, new class
    {
        public function getFirstMedia($tag = null): null
        {
            return null;
        }
    });
});
