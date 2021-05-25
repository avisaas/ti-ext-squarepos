<?php

namespace IgniterLabs\SquarePOS\Controllers;

use Admin\Classes\AdminController;
use Exception;
use Igniter\Flame\Exception\ApplicationException;
use IgniterLabs\SquarePOS\Classes\Connection;
use IgniterLabs\SquarePOS\Classes\Manager;
use IgniterLabs\SquarePOS\Classes\SyncProcessor;
use Illuminate\Support\Facades\Redirect;
use System\Traits\SessionMaker;

class Merchant extends AdminController
{
    use SessionMaker;

    public function authorize($context)
    {
        $this->putSession('state', $state = str_random(8));
        $this->putSession('redirect', referrer_url());

        $params['redirect'] = admin_url('igniterlabs/squarepos/merchant/token?state=').$state;

        return Redirect::to(Connection::instance()->getAuthorizeUrl($params));
    }

    public function revoke($context)
    {
        $this->putSession('state', $state = str_random(8));
        $this->putSession('redirect', referrer_url());

        $params['redirect'] = admin_url('igniterlabs/squarepos/merchant/token?state=').$state;

        return Redirect::to(Connection::instance()->getRevokeUrl($params));
    }

    public function token($context)
    {
        try {
            if (strlen(input('error_message'))) {
                flash()->error(input('error_message'));
            }
            else {
                if ($this->getSession('state') !== input('state'))
                    throw new ApplicationException('Access denied, invalid state');

                Connection::instance()->handleAuthCallback(input());
            }
        }
        catch (Exception $ex) {
            flash()->error($ex->getMessage());
        }

        return Redirect::to($this->getSession('redirect'));
    }

    public function import()
    {
    }

    public function sync()
    {
        Manager::instance()->processSyncCatalog();

        flash()->success('Queued');

        return Redirect::to(referrer_url());
    }
}
