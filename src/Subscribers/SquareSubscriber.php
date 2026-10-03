<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Subscribers;

use Igniter\Admin\Widgets\Form;
use Igniter\Cart\Models\Category;
use Igniter\Cart\Models\Menu;
use Igniter\Cart\Models\MenuOption;
use Igniter\Cart\Models\MenuOptionValue;
use Igniter\Cart\Models\Order;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SquareLink;
use Illuminate\Events\Dispatcher;
use Throwable;

class SquareSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $this->registerDirtyTracking();

        $events->listen('admin.form.extendFields', $this->extendMenuForm(...));
        $events->listen('admin.list.extendColumns', $this->extendMenuList(...));
        $events->listen('admin.list.extendBulkActions', $this->extendMenuBulkActions(...));
        $events->listen('igniter.checkout.afterSaveOrder', $this->pushOrder(...));
        $events->listen('admin.statusHistory.added', $this->pushOrderStatus(...));
    }

    public function extendMenuForm(Form $form): void
    {
        if (!$form->model instanceof Menu) {
            return;
        }

        if (!$form->model->squareLink) {
            $link = $form->model->squareLink()->make();
            $link->setRelation('linkable', $form->model);
            $form->model->setRelation('squareLink', $link);
        }

        $form->addTabFields([
            'squareLink[square_sync]' => [
                'tab' => 'igniterlabs.squarepos::default.text_tab_square',
                'label' => 'igniterlabs.squarepos::default.label_square_sync',
                'type' => 'switch',
                'default' => true,
                'comment' => 'igniterlabs.squarepos::default.help_square_sync',
            ],
            'squareLink[square_sku]' => [
                'tab' => 'igniterlabs.squarepos::default.text_tab_square',
                'label' => 'igniterlabs.squarepos::default.label_square_sku',
                'type' => 'text',
                'comment' => 'igniterlabs.squarepos::default.help_square_sku',
            ],
            'squareLink[square_stock_mode]' => [
                'tab' => 'igniterlabs.squarepos::default.text_tab_square',
                'label' => 'igniterlabs.squarepos::default.label_square_stock_mode',
                'type' => 'select',
                'comment' => 'igniterlabs.squarepos::default.help_square_stock_mode',
                'options' => [
                    '' => 'lang:igniterlabs.squarepos::default.text_stock_follow',
                    'push' => 'lang:igniterlabs.squarepos::default.text_stock_push',
                    'square' => 'lang:igniterlabs.squarepos::default.text_stock_square',
                ],
            ],
        ]);
    }

    public function extendMenuList($widget): void
    {
        if (!$widget->model instanceof Menu) {
            return;
        }

        $widget->addColumns([
            'squareLink[square_sync]' => [
                'label' => 'lang:igniterlabs.squarepos::default.label_square_sync',
                'type' => 'switch',
                'sortable' => false,
                'searchable' => false,
            ],
            'squareLink[square_sku]' => [
                'label' => 'lang:igniterlabs.squarepos::default.label_square_sku',
                'invisible' => true,
                'sortable' => false,
                'searchable' => false,
            ],
        ]);
    }

    public function extendMenuBulkActions($widget): void
    {
        if (!$widget->model instanceof Menu) {
            return;
        }

        $widget->bulkActions['square_sync'] = [
            'label' => 'lang:igniterlabs.squarepos::default.button_square_sync',
            'type' => 'dropdown',
            'class' => 'btn btn-light',
            'code' => 'square_sync',
            'menuItems' => [
                'enable' => [
                    'label' => 'lang:igniterlabs.squarepos::default.button_enable_square_sync',
                    'type' => 'button',
                    'class' => 'dropdown-item',
                ],
                'disable' => [
                    'label' => 'lang:igniterlabs.squarepos::default.button_disable_square_sync',
                    'type' => 'button',
                    'class' => 'dropdown-item text-danger',
                ],
            ],
        ];
    }

    public function pushOrder($order): void
    {
        if (!$order instanceof Order || !Settings::syncOrders()) {
            return;
        }

        try {
            $order->pushSquareOrder();
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    public function pushOrderStatus($model): void
    {
        if (!$model instanceof Order || SyncContext::isApplyingRemote() || !Settings::syncOrders()) {
            return;
        }

        try {
            $model->pushSquareStatus();
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    protected function registerDirtyTracking(): void
    {
        $mark = function($model, array $fields, array $squareFields = []): void {
            if (SyncContext::isApplyingRemote()) {
                return;
            }

            $changed = !$model->exists || $model->isDirty($fields);
            if (!$changed && $squareFields && $model->squareLink) {
                $changed = $model->squareLink->isDirty($squareFields);
            }

            if ($changed && $model->squareLink) {
                $model->squareLink->square_dirty = true;
            }
        };

        $persist = function($model): void {
            if (SyncContext::isApplyingRemote()) {
                return;
            }

            $link = $model->relationLoaded('squareLink') ? $model->getRelation('squareLink') : null;
            if (!$link || !$model->getKey() || !$link->isDirty()) {
                return;
            }

            $model->squareLink()->save($link);
        };

        Menu::saving(function($model) use ($mark): void {
            $mark($model, ['menu_name', 'menu_description', 'menu_price', 'menu_status'], ['square_sku', 'square_sync']);
        });
        Menu::saved($persist);

        Category::saving(function($model) use ($mark): void {
            $mark($model, ['name', 'description', 'status', 'priority']);
        });
        Category::saved($persist);

        MenuOption::saving(function($model) use ($mark): void {
            $mark($model, ['option_name', 'display_type', 'priority']);
        });
        MenuOption::saved($persist);

        MenuOptionValue::saving(function($model) use ($mark): void {
            $mark($model, ['name', 'price', 'priority']);
        });
        MenuOptionValue::saved($persist);

        MenuOptionValue::saved(function($value): void {
            if (SyncContext::isApplyingRemote() || !$value->option_id) {
                return;
            }

            if ($value->wasRecentlyCreated || $value->wasChanged(['name', 'price', 'priority'])) {
                SquareLink::markDirty((new MenuOption)->getMorphClass(), $value->option_id);
            }
        });
    }
}
