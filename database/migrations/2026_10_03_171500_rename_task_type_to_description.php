<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('squarepos_tasks') || !Schema::hasColumn('squarepos_tasks', 'type')) {
            return;
        }

        Schema::table('squarepos_tasks', function(Blueprint $table): void {
            $table->string('description')->nullable()->after('uuid');
        });

        DB::table('squarepos_tasks')->orderBy('id')->get()->each(function(object $row): void {
            $config = json_decode((string)$row->config_data, true);
            $config = is_array($config) ? $config : [];

            if (!isset($config['systemOfRecord']) && is_string($row->type) && $row->type !== '') {
                $config['systemOfRecord'] = $row->type;
            }

            DB::table('squarepos_tasks')->where('id', $row->id)->update([
                'description' => $this->describe($row->type, $config, $row->notes),
                'config_data' => json_encode($config),
            ]);
        });

        Schema::table('squarepos_tasks', function(Blueprint $table): void {
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('squarepos_tasks') || Schema::hasColumn('squarepos_tasks', 'type')) {
            return;
        }

        Schema::table('squarepos_tasks', function(Blueprint $table): void {
            $table->string('type')->nullable()->after('uuid');
        });

        DB::table('squarepos_tasks')->orderBy('id')->get()->each(function(object $row): void {
            $config = json_decode((string)$row->config_data, true);
            $config = is_array($config) ? $config : [];

            DB::table('squarepos_tasks')->where('id', $row->id)->update([
                'type' => $config['systemOfRecord'] ?? 'square',
            ]);
        });

        Schema::table('squarepos_tasks', function(Blueprint $table): void {
            if (Schema::hasColumn('squarepos_tasks', 'description')) {
                $table->dropColumn('description');
            }
        });
    }

    protected function describe(mixed $type, array $config, mixed $notes): string
    {
        $blob = strtolower((string)$notes);
        $systemOfRecord = $config['systemOfRecord'] ?? $type;
        $mode = $config['mode'] ?? 'sync';

        if (str_contains($blob, 'order')) {
            return 'Pushing orders into Square';
        }

        if ((str_contains($blob, 'inventory') || str_contains($blob, 'stock'))
            && !str_contains($blob, 'categor')
            && !str_contains($blob, 'menu item')
            && !str_contains($blob, 'menu option')
        ) {
            return $systemOfRecord === 'square'
                ? 'Pulling inventory from Square'
                : 'Pushing inventory into Square';
        }

        $push = $mode === 'export' || ($mode !== 'import' && $systemOfRecord !== 'square');

        return $push ? 'Pushing catalog into Square' : 'Pulling catalog from Square';
    }
};
