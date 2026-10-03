<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS;

use Igniter\Admin\Classes\Widgets;
use Igniter\Cart\Models\Category;
use Igniter\Cart\Models\Menu;
use Igniter\Cart\Models\MenuOption;
use Igniter\Cart\Models\MenuOptionValue;
use Igniter\Cart\Models\Order;
use Igniter\Cart\Models\Stock;
use Igniter\Flame\Support\Facades\Igniter;
use Igniter\System\Classes\BaseExtension;
use IgniterLabs\SquarePOS\BulkActionWidgets\SquareSync;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Commands\SyncCommand;
use IgniterLabs\SquarePOS\Models\Concerns\CategoryAction;
use IgniterLabs\SquarePOS\Models\Concerns\MenuItemAction;
use IgniterLabs\SquarePOS\Models\Concerns\MenuOptionAction;
use IgniterLabs\SquarePOS\Models\Concerns\MenuOptionValueAction;
use IgniterLabs\SquarePOS\Models\Concerns\OrderAction;
use IgniterLabs\SquarePOS\Models\Concerns\StockAction;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\Subscribers\MenuFormValidationSubscriber;
use IgniterLabs\SquarePOS\Subscribers\SquareSubscriber;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Square\Environments;

/**
 * Square POS Extension Information File
 */
class Extension extends BaseExtension
{
    public function register(): void
    {
        $this->registerConsoleCommand('square.sync', SyncCommand::class);

        $this->app->singleton(\Square\SquareClient::class, fn(): \Square\SquareClient => new \Square\SquareClient((string)(Settings::accessToken() ?? ''), null, [
            'baseUrl' => Settings::isSandboxEnvironment()
                ? Environments::Sandbox->value
                : Environments::Production->value,
        ]));

        $this->app->singleton(SquareClient::class, function(): SquareClient {
            if (!Settings::isSandboxEnvironment()) {
                Settings::ensureFreshToken();
            }

            return new SquareClient;
        });

        $this->extendCoreModels();
    }

    public function boot(): void
    {
        Igniter::prunableModel(SyncTask::class);

        Event::subscribe(SquareSubscriber::class);
        Event::subscribe(MenuFormValidationSubscriber::class);

        resolve(Widgets::class)->registerBulkActionWidgets(function(Widgets $manager): void {
            $manager->registerBulkActionWidget(SquareSync::class, [
                'code' => 'square_sync',
            ]);
        });
    }

    public function registerPermissions(): array
    {
        return [
            'IgniterLabs.SquarePOS.Manage' => [
                'description' => 'Manage Square POS for TastyIgniter settings',
                'group' => 'igniter.cart::default.text_permission_menu_group',
            ],
        ];
    }

    public function registerSettings(): array
    {
        return [
            'settings' => [
                'label' => 'igniterlabs.squarepos::default.setting_title',
                'description' => 'igniterlabs.squarepos::default.setting_desc',
                'icon' => 'fa fa-cash-register',
                'model' => Settings::class,
                'permissions' => ['IgniterLabs.SquarePOS.*'],
            ],
        ];
    }

    public function registerMailTemplates(): array
    {
        return [
            'igniterlabs.squarepos::mail.sync_completed' => 'igniterlabs.squarepos::default.text_mail_sync_completed',
        ];
    }

    public function registerSchedule(Schedule $schedule): void
    {
        $schedule->command('square:sync --menus')
            ->when(fn(): bool => Settings::isCatalogSyncEnabled())
            ->cron(Settings::safeInterval('catalog'))
            ->name('squarepos-sync-catalog')
            ->withoutOverlapping();

        $schedule->command('square:sync --inventory')
            ->when(fn(): bool => Settings::isSyncEnabled())
            ->cron(Settings::safeInterval('inventory'))
            ->name('squarepos-sync-inventory')
            ->withoutOverlapping();

        $schedule->command('square:sync --orders')
            ->when(fn(): bool => Settings::syncOrders())
            ->cron(Settings::safeInterval('orders'))
            ->name('squarepos-sync-orders')
            ->withoutOverlapping();
    }

    protected function extendCoreModels(): void
    {
        Category::extend(function($model): void {
            $model->implement[] = CategoryAction::class;
        });

        Menu::extend(function($model): void {
            $model->implement[] = MenuItemAction::class;
        });

        MenuOption::extend(function($model): void {
            $model->implement[] = MenuOptionAction::class;
        });

        MenuOptionValue::extend(function($model): void {
            $model->implement[] = MenuOptionValueAction::class;
        });

        Stock::extend(function($model): void {
            $model->implement[] = StockAction::class;
        });

        Order::extend(function($model): void {
            $model->implement[] = OrderAction::class;
        });

        Event::listen('admin.list.extendQueryBefore', function($widget, $query): void {
            if ($widget->model instanceof Menu) {
                $query->with('squareLink');
            }
        });
    }
}
