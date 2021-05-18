<?php namespace IgniterLabs\SquarePOS;

use System\Classes\BaseExtension;

/**
 * Square POS Extension Information File
 */
class Extension extends BaseExtension
{
    public function register()
    {
    }

    public function boot()
    {
    }

    public function registerComponents()
    {
        return [
// Remove this line and uncomment the line below to activate
//            'IgniterLabs\SquarePOS\Components\MyComponent' => 'myComponent',
        ];
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
                'model' => 'IgniterLabs\SquarePOS\Models\Settings',
                'permissions' => ['IgniterLabs.SquarePOS.*'],
            ],
        ];
    }

    public function registerSchedule($schedule)
    {
//        $schedule->job(new Heartbeat)->everyFiveMinutes();
//
//        $schedule->call(function () {
//            Classes\Allocator::allocate();
//        })->everyMinute();
    }
}
