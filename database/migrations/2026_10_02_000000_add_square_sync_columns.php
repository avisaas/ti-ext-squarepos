<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('squarepos_stocks', function(Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('stock_id')->unique();
            $table->timestamp('square_synced_at')->nullable();
        });

        Schema::create('squarepos_orders', function(Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('square_order_id')->nullable()->index();
            $table->unsignedBigInteger('square_order_version')->nullable();
            $table->string('square_fulfillment_uid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('squarepos_stocks');
        Schema::dropIfExists('squarepos_orders');
    }
};
