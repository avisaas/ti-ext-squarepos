<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('igniterlabs_squarepos_tasks')) {
            return;
        }

        Schema::create('igniterlabs_squarepos_tasks', function(Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('uuid');
            $table->string('type');
            $table->text('config_data')->nullable();
            $table->text('notes')->nullable();
            $table->text('exception')->nullable();
            $table->boolean('is_success')->default(0);

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('igniterlabs_squarepos_tasks');
    }
};
