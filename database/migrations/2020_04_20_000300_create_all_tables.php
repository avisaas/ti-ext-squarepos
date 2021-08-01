<?php namespace IgniterLabs\SquarePOS\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAllTables extends Migration
{
    public function up()
    {
        if (Schema::hasTable('igniterlabs_squarepos_logs'))
            return;

        Schema::create('igniterlabs_squarepos_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('uuid');

            $table->string('job');
            $table->text('config_data')->nullable();
            $table->text('exception')->nullable();
            $table->boolean('is_success')->default(0);

            $table->timestamps();
        });

//        Schema::create('igniterlabs_squarepos_records', function (Blueprint $table) {
//            $table->increments('id');
//            $table->string('name');
//            $table->string('hash');
//            $table->string('action');
//            $table->text('config_data')->nullable();
//            $table->boolean('is_active')->default(0);
//            $table->timestamps();
//        });
    }

    public function down()
    {
        Schema::dropIfExists('igniterlabs_squarepos_logs');
//        Schema::dropIfExists('igniterlabs_webhook_logs');
    }
}
