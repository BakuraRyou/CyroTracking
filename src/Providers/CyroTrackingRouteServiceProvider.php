<?php

namespace CyroTracking\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

class CyroTrackingRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router)
    {
        $router->post('cyro-tracking/meta', 'CyroTracking\Controllers\MetaTrackingController@track');
    }
}
