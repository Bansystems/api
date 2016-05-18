<?php
use Cake\Routing\RouteBuilder;
use Cake\Routing\Router;

Router::plugin(
    'Api',
    ['path' => '/api'],
    function (RouteBuilder $routes) {
        $routes->fallbacks('DashedRoute');
    }
);
