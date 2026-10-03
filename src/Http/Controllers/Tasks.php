<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Http\Controllers;

use Igniter\Admin\Classes\AdminController;
use Igniter\Admin\Facades\AdminMenu;
use Igniter\Admin\Http\Actions\FormController;
use Igniter\Admin\Http\Actions\ListController;
use IgniterLabs\SquarePOS\Models\SyncTask;

class Tasks extends AdminController
{
    public array $implement = [
        ListController::class,
        FormController::class,
    ];

    public array $listConfig = [
        'list' => [
            'model' => SyncTask::class,
            'title' => 'igniterlabs.squarepos::default.text_tab_tasks',
            'emptyMessage' => 'igniterlabs.squarepos::default.text_empty_tasks',
            'defaultSort' => ['created_at', 'DESC'],
            'showCheckboxes' => false,
            'configFile' => 'synctask',
        ],
    ];

    public array $formConfig = [
        'name' => 'lang:igniterlabs.squarepos::default.text_tab_tasks',
        'model' => SyncTask::class,
        'preview' => [
            'title' => 'lang:igniter::admin.form.preview_title',
            'back' => 'igniterlabs/squarepos/tasks',
        ],
        'configFile' => 'synctask',
    ];

    protected null|string|array $requiredPermissions = ['IgniterLabs.SquarePOS.Manage'];

    public function __construct()
    {
        parent::__construct();

        AdminMenu::setContext('squarepos_tasks', 'restaurant');
    }
}
