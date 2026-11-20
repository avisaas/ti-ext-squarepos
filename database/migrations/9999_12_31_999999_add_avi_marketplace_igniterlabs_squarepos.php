<?php

declare(strict_types=1);

use IgniterLabs\SquarePOS\AviMarketplace\Setup;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        rescue(fn () => Setup::run());
    }

    public function down(): void {}
};
