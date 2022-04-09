<?php

namespace IgniterLabs\SquarePOS;

use Admin\Models\Categories_model;
use Admin\Models\Menu_option_values_model;
use Admin\Models\Menu_options_model;
use Admin\Models\Menus_model;
use Admin\Models\Stocks_model;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Commands\SyncCommand;
use IgniterLabs\SquarePOS\Models\Settings;
use System\Classes\BaseExtension;

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
        Categories_model::extend(function ($model) {
            $model->implement[] = \IgniterLabs\SquarePOS\Actions\CategoryAction::class;
        });

        Menus_model::extend(function ($model) {
            $model->implement[] = \IgniterLabs\SquarePOS\Actions\MenuItemAction::class;
        });

        Menu_options_model::extend(function ($model) {
            $model->implement[] = \IgniterLabs\SquarePOS\Actions\MenuOptionAction::class;
        });

        Menu_option_values_model::extend(function ($model) {
            $model->implement[] = \IgniterLabs\SquarePOS\Actions\MenuOptionValueAction::class;
        });

        Stocks_model::extend(function ($model) {
            $model->implement[] = \IgniterLabs\SquarePOS\Actions\StockAction::class;
        });
    }
}
