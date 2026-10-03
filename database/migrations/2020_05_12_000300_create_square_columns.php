<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('squarepos_links', function(Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->string('square_id')->nullable();
            $table->string('square_version')->nullable();
            $table->boolean('square_dirty')->default(false);
            $table->string('square_variation_id')->nullable();
            $table->string('square_variation_version')->nullable();
            $table->boolean('square_sync')->nullable();
            $table->string('square_sku')->nullable();
            $table->string('square_image_id')->nullable();
            $table->string('square_stock_mode')->nullable();
            $table->unique(['linkable_type', 'linkable_id']);
            $table->index('square_id');
            $table->index('square_variation_id');
            $table->index('square_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('squarepos_links');
    }
};
