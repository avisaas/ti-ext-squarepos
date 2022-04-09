<?php

namespace IgniterLabs\SquarePOS\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSquareColumns extends Migration
{
    protected static $squareTables = [
        'menus',
        'categories',
        'menu_options',
        'menu_option_values',
        'menu_item_option_values',
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach (self::$squareTables as $squareTable) {
            if (Schema::hasColumn($squareTable, 'square_id'))
                continue;

            Schema::table($squareTable, function (Blueprint $table) use ($squareTable) {
                $table->string('square_id')->nullable()->index();
                $table->string('square_version')->nullable()->index();

                if ($squareTable === 'menus') {
                    $table->string('square_variation_id')->nullable()->index();
                    $table->string('square_variation_version')->nullable()->index();
                }
//
//                if ($squareTable === 'menu_item_option_values') {
//                    $table->string('square_modifier_list_id')->nullable()->index();
//                    $table->string('square_modifier_list_version')->nullable()->index();
//                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        foreach (self::$squareTables as $squareTable) {
            if (!Schema::hasColumn($squareTable, 'square_id'))
                continue;

            Schema::table($squareTable, function (Blueprint $table) use ($squareTable) {
                $table->dropColumn([
                    'square_id',
                    'square_version',
                ]);

                if ($squareTable === 'menus') {
                    $table->dropColumn([
                        'square_variation_id',
                        'square_variation_version',
                    ]);
                }

//                if ($squareTable === 'menu_item_option_values') {
//                    $table->dropColumn([
//                        'square_modifier_list_id',
//                        'square_modifier_list_version',
//                    ]);
//                }
            });
        }
    }
}
