<?php

namespace OpenTribes\Core\Silex;

use Silex\Application;
use OpenTribes\Core\Silex\Controller\Account;
use OpenTribes\Core\Silex\Controller\Assets;
use OpenTribes\Core\Silex\Controller\City;
use OpenTribes\Core\Silex\Controller\Map;

/**
 * Description of Controller
 *
 * @author BlackScorp<witalimik@web.de>
 */
abstract class Controller {

    const ACCOUNT = 'controller.core.account';
    const ASSETS  = 'controller.core.assets';
    const CITY    = 'controller.core.city';
    const MAP     = 'controller.core.map';

    public static function create(Application &$app) {
        $app[self::ACCOUNT] = $app->share(function() use($app) {
            return new Account($app[Repository::USER], $app[Service::PASSWORD_HASHER], $app[Validator::REGISTRATION], $app[Service::ACTIVATION_CODE_GENERATOR], $app[Validator::ACTIVATE]);
        });
        $app[self::ASSETS] = $app->share(function() use($app) {
            return new Assets($app['mustache.assets']);
        });

        $app[self::CITY] = $app->share(function() use($app) {
            return new City($app[Repository::USER], $app[Repository::CITY], $app[Repository::MAP_TILES], $app[Service::LOCATION_CALCULATOR]);
        });
        $app[self::MAP] = $app->share(function() use($app) {
            return new Map($app[Repository::MAP_TILES], $app[Repository::CITY], $app[Service::MAP_CALCULATOR]);
        });
    }

}
