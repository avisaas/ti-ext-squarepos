<?php

namespace IgniterLabs\SquarePOS\Controllers;

use Admin\Classes\AdminController;
use Exception;
use Igniter\Flame\Exception\ApplicationException;
use IgniterLabs\SquarePOS\Classes\Dispatcher;
use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use IgniterLabs\SquarePOS\Models\Settings;
use Illuminate\Support\Facades\Redirect;
use System\Traits\SessionMaker;

class Merchant extends AdminController
{
    use SessionMaker;

    protected $requiredPermissions = ['IgniterLabs.SquarePOS.Manage'];

    protected $settingsUri = 'extensions/edit/igniterlabs/squarepos/settings';

    public function authorize($context)
    {
        $this->putSession('state', $state = str_random(8));
        $this->putSession('redirect', admin_url($this->settingsUri));

        $params['redirect'] = admin_url('igniterlabs/squarepos/merchant/token?state=').$state;

        return Redirect::to(Settings::getAuthorizeUrl($params));
    }

    public function revoke($context)
    {
        Settings::clearConnectionData();

        return Redirect::to(admin_url($this->settingsUri));
    }

    public function token($context)
    {
        try {
            if (strlen(input('error_message'))) {
                flash()->error(input('error_message'));
            } else {
                if ($this->getSession('state') !== input('state')) {
                    throw new ApplicationException('Access denied, invalid state');
                }

                Settings::storeConnectionData(input());
            }
        } catch (Exception $ex) {
            flash()->error($ex->getMessage());
        }

        return Redirect::to($this->getSession('redirect'));
    }

    public function sync($context, $action = null)
    {
        if (Settings::isConnected()) {
            $dispatcher = Dispatcher::create(SyncCatalogJob::class);

            $dispatcher->dispatch();

            flash()->success('Menu items sync queued successfully');
        }

        return Redirect::to(admin_url($this->settingsUri));
    }
}
