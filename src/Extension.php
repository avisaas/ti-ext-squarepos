<?php

namespace IgniterLabs\SquarePOS;

use Igniter\Admin\Models\Category;
use Igniter\Admin\Models\Menu;
use Igniter\Admin\Models\MenuOption;
use Igniter\Admin\Models\MenuOptionValue;
use Igniter\Admin\Models\Stock;
use Igniter\System\Classes\BaseExtension;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Commands\SyncCommand;
use IgniterLabs\SquarePOS\Models\Settings;

/**
 * Square POS Extension Information File
 */
class Extension extends BaseExtension
{
    public function register()
    {
        $this->registerConsoleCommand('square.sync', SyncCommand::class);

        $this->app->bind(SquareClient::class, function ($app) {
            return new SquareClient(Settings::connectionData());
        });

        $this->extendCoreModels();
    }

    public function boot()
    {
    }

    public function registerPermissions()
    {
        return [
            'IgniterLabs.SquarePOS.Manage' => [
                'description' => 'Manage Square POS for TastyIgniter settings',
                'group' => 'module',
            ],
        ];
    }

    public function registerSettings()
    {
        return [
            'settings' => [
                'label' => 'igniterlabs.squarepos::default.setting_title',
                'description' => 'igniterlabs.squarepos::default.setting_desc',
                'icon' => 'fa fa-cash-register',
                'model' => \IgniterLabs\SquarePOS\Models\Settings::class,
                'permissions' => ['IgniterLabs.SquarePOS.*'],
            ],
        ];
    }

    public function registerSchedule($schedule)
    {
        //        $schedule->call('square::sync-inventory')->everyMinute();
    }

    protected function extendCoreModels(): void
    {
        Category::extend(function ($model) {
            $model->implement[] = \IgniterLabs\SquarePOS\Actions\CategoryAction::class;
        });

        Menu::extend(function ($model) {
            $model->implement[] = \IgniterLabs\SquarePOS\Actions\MenuItemAction::class;
        });

        MenuOption::extend(function ($model) {
            $model->implement[] = \IgniterLabs\SquarePOS\Actions\MenuOptionAction::class;
        });

        MenuOptionValue::extend(function ($model) {
            $model->implement[] = \IgniterLabs\SquarePOS\Actions\MenuOptionValueAction::class;
        });

        Stock::extend(function ($model) {
            $model->implement[] = \IgniterLabs\SquarePOS\Actions\StockAction::class;
        });
    }
}
