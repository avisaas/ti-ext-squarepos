<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('squarepos_tasks')) {
            return;
        }

        Schema::create('squarepos_tasks', function(Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('uuid');
            $table->string('description')->nullable();
            $table->text('config_data')->nullable();
            $table->text('notes')->nullable();
            $table->text('exception')->nullable();
            $table->string('status')->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('squarepos_tasks');
    }
};
