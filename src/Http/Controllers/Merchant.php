<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Http\Controllers;

use Exception;
use Igniter\Admin\Classes\AdminController;
use Igniter\Flame\Exception\FlashException;
use Igniter\System\Traits\SessionMaker;
use IgniterLabs\SquarePOS\Classes\Dispatcher;
use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use IgniterLabs\SquarePOS\Models\Settings;
use Illuminate\Support\Facades\Redirect;

class Merchant extends AdminController
{
    use SessionMaker;

    protected null|string|array $requiredPermissions = ['IgniterLabs.SquarePOS.Manage'];

    protected $settingsUri = 'extensions/edit/igniterlabs/squarepos/settings';

    public function connect($context)
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
            $errorMessage = (string) input('error_message');
            if (strlen($errorMessage)) {
                flash()->error($errorMessage);
            } elseif ($this->getSession('state') !== input('state')) {
                throw new FlashException('Access denied, invalid state');
            } elseif ((string) input('code') === '') {
                throw new FlashException('Missing authorization code');
            } else {
                Settings::exchangeAuthorizationCode((string) input('code'));
            }
        } catch (Exception $exception) {
            flash()->error($exception->getMessage());
        }

        return Redirect::to($this->getSession('redirect'));
    }

    public function import($context = null)
    {
        if (Settings::isConnected()) {
            Dispatcher::create(SyncCatalogJob::class)->import()->dispatch();

            flash()->success(lang('igniterlabs.squarepos::default.text_import_queued'));
        }

        return Redirect::to(admin_url($this->settingsUri));
    }

    public function export($context = null)
    {
        if (Settings::isConnected()) {
            Dispatcher::create(SyncCatalogJob::class)->export()->dispatch();

            flash()->success(lang('igniterlabs.squarepos::default.text_export_queued'));
        }

        return Redirect::to(admin_url($this->settingsUri));
    }
}
