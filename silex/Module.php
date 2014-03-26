<?php

namespace OpenTribes\Core\Silex;

use DateTime;
use Igorw\Silex\ConfigServiceProvider;
use Mustache\Silex\Provider\MustacheServiceProvider;
use OpenTribes\Core\Mock\Validator\ActivateUser as ActivateUserValidator;
use OpenTribes\Core\Silex\Controller;
use OpenTribes\Core\Silex\Controller\Account;
use OpenTribes\Core\Silex\Controller\Assets;
use OpenTribes\Core\Silex\Repository;
use OpenTribes\Core\Silex\Repository\DBALUser as UserRepository;
use OpenTribes\Core\Silex\Service;
use OpenTribes\Core\Silex\Service\CodeGenerator;
use OpenTribes\Core\Silex\Service\PasswordHasher;
use OpenTribes\Core\Silex\Validator;
use OpenTribes\Core\Silex\Validator\Registration as RegistrationValidator;
use OpenTribes\Core\ValidationDto\ActivateUser as ActivateUserValidatorDto;
use OpenTribes\Core\ValidationDto\Registration as RegistrationValidatorDto;
use Silex\Application;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Provider\ServiceControllerServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\SwiftmailerServiceProvider;
use Silex\Provider\TranslationServiceProvider;
use Silex\Provider\ValidatorServiceProvider;
use Silex\ServiceProviderInterface;
use stdClass;
use Swift_Message;
use Swift_NullTransport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Description of Module
 *
 * @author BlackScorp<witalimik@web.de>
 */
class Module implements ServiceProviderInterface {

    private $env;

    public function __construct($enviroment) {
        $this->env = $enviroment;
    }

    public function boot(Application $app) {
        ;
    }

    public function register(Application $app) {

        $this->registerProviders($app);
        $this->createDependencies($app);
        $this->createRoutes($app);
    }

    private function createDependencies(&$app) {
        $app[Service::PASSWORD_HASHER] = $app->share(function() {
            return new PasswordHasher();
        });
        $app[Service::ACTIVATION_CODE_GENERATOR] = $app->share(function() use($app) {
            return new CodeGenerator($app['activationCodeLength']);
        });
        $app[Repository::USER] = $app->share(function() use($app) {
            return new UserRepository($app['db']);
        });
        $app['validationDto.registration'] = $app->share(function() {
            return new RegistrationValidatorDto;
        });
        $app[Validator::REGISTRATION] = $app->share(function() use($app) {
            return new RegistrationValidator($app['validationDto.registration'], $app['validator']);
        });
        $app['validationDto.activate'] = $app->share(function() use($app) {
            return new ActivateUserValidatorDto;
        });
        $app[Validator::ACTIVATE] = $app->share(function() use($app) {
            return new ActivateUserValidator($app['validationDto.activate']);
        });
        $app[Controller::ACCOUNT] = $app->share(function() use($app) {
            return new Account($app[Repository::USER], $app[Service::PASSWORD_HASHER], $app[Validator::REGISTRATION], $app[Service::ACTIVATION_CODE_GENERATOR], $app[Validator::ACTIVATE]);
        });
        $app[Controller::ASSETS] = $app->share(function() use($app) {
            return new Assets($app['mustache.assets']);
        });
    }

    private function registerProviders(&$app) {

        $app->register(new ValidatorServiceProvider);
        $app->register(new ServiceControllerServiceProvider());
        $app->register(new SessionServiceProvider());
        $app->register(new DoctrineServiceProvider());
        $app->register(new MustacheServiceProvider());
        $app->register(new TranslationServiceProvider());
        $app->register(new SwiftmailerServiceProvider());

        if ($this->env === 'test') {
            $app['swiftmailer.transport'] = $app->share(function() {
                return new Swift_NullTransport();
            });
        }
        $this->loadConfigurations($app);
    }

    private function loadConfigurations(&$app) {
        $files = array(
            'general.php',
            'database.php',
            'email.php'
        );
        foreach ($files as $file) {
            $path = realpath(__DIR__ . '/../config/' . $this->env . '/' . $file);

            $app->register(new ConfigServiceProvider($path));
        }
    }

    private function createRoutes(&$app) {

        $app->get('/', function() use($app) {

                    $response = new stdClass();
                    $response->failed = false;
                    $response->proceed = false;
                    return $response;
                })->value(RouteValue::TEMPLATE, 'pages/landing')
                ->before(function(Request $request) {
                    if ($request->getSession()->get('username')) {
                        return new RedirectResponse('/game');
                    }
                });

        $app->mount('/assets', $this->getAssetsRoutes($app));
        $app->mount('/account', $this->getAccountRoutes($app));
        $app->mount('/game', $this->getGameRoutes($app));

        $app->on(KernelEvents::VIEW, function($event) use($app) {
            $appResponse = $event->getControllerResult();
            $request = $event->getRequest();
            $requestType = $event->getRequestType();
            $response = $appResponse;
            if ($requestType === HttpKernelInterface::SUB_REQUEST) {
                $response = new JsonResponse($appResponse);
            }
            if ($request->attributes->has(RouteValue::SUB_REQUESTS)) {
                $subRequests = $request->attributes->get(RouteValue::SUB_REQUESTS);
                $tmpResponse = $appResponse;

                foreach ($subRequests as $values) {
                    $uri = $values['url'];
                    $method = $values['method'];
                    $param = $values['param'];
                    $subRequest = Request::create($uri, $method, $param);
                    $subResponse = $app->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
                    $content = json_decode($subResponse->getContent());
                    $appResponse = (object) array_merge((array) $appResponse, (array) $content);
                }
                $appResponse = (object) array_merge((array) $appResponse, (array) $tmpResponse);
            }
            if ($requestType === HttpKernelInterface::MASTER_REQUEST) {
                if ($request->attributes->has(RouteValue::TEMPLATE)) {
                    $template = $request->attributes->get(RouteValue::TEMPLATE);
                    $body = $app['mustache']->render($template, $appResponse);
                    $response = new Response($body);
                }

                if (is_object($appResponse) && $appResponse->proceed && !$appResponse->failed && $request->attributes->has('successHandler')) {
                    $handler = $request->attributes->get(RouteValue::SUCCESS_HANDLER);
                    $result = $handler($appResponse);
                    if ($result) {
                        $response = $result;
                    }
                }
            }
       
            if(!$response->getExpires()){  
                $response->setExpires(new DateTime());
            }
            $response->headers->set('Content-Encoding','gzip');
            
            $event->setResponse($response);
        });
    }

    private function getGameRoutes(&$app) {
        $game = $app['controllers_factory'];

        $game->get('/', function(Request $request) {
            $response = new stdClass();
            $response->proceed = false;
            $response->username = $request->getSession()->get('username');
            return $response;
        })->value(RouteValue::TEMPLATE, 'pages/game/landing');

        $game->match('/city/new', Controller::CITY . ':newAction')
                ->method('POST|GET');
        return $game;
    }

    private function getAssetsRoutes(&$app) {
        $assets = $app['controllers_factory'];

        $assets->assert('file', '.+');
        $assets->get('{type}/{file}', Controller::ASSETS . ':load');

        return $assets;
    }

    private function getAccountRoutes(&$app) {
        $account = $app['controllers_factory'];

        $account->post('/login', Controller::ACCOUNT . ':loginAction')
                ->value(RouteValue::TEMPLATE, 'pages/landing')
                ->value(RouteValue::SUCCESS_HANDLER, function($appResponse) use ($app) {

                    $app['session']->set('username', $appResponse->username);

                    return new RedirectResponse('/');
                })
                ->value(RouteValue::SUB_REQUESTS, array(
                    array(
                        'url'    => '/',
                        'method' => 'GET',
                        'param'  => array())
        ));
        $account->get('/registration_successfull', function() {
            return '';
        })->value(RouteValue::TEMPLATE, 'pages/registration_successfull');

        $account->match('/create', Controller::ACCOUNT . ':createAction')
                ->method('GET|POST')
                ->value(RouteValue::SUCCESS_HANDLER, function($appResponse) use ($app) {

                    $request = $app['request'];

                    $appResponse->url = $request->getHttpHost();

                    $htmlBody = $app['mustache']->render('mails/html/register', $appResponse);
                    $textBody = $app['mustache']->render('mails/text/register', $appResponse);
                    $message = Swift_Message::newInstance($app['subjects']['registration'])
                            ->setFrom($app['fromMails']['registration'])
                            ->setTo($appResponse->email)
                            ->setBody($htmlBody, 'text/html')
                            ->addPart($textBody, 'text/plain');

                    if ($app['mailer']->send($message)) {
                        $target = 'registration_successfull';
                    } else {
                        $target = 'registration_failed';
                    }
                    return new RedirectResponse($target);
                })
                ->value(RouteValue::TEMPLATE, 'pages/registration');


        $account->match('/activate', Controller::ACCOUNT . ':activateAction')
                ->method('GET|POST')
                ->value(RouteValue::TEMPLATE, 'pages/activation');

        $account->get('/activate/{username}/{activationKey}', Controller::ACCOUNT . ':activateAction')
                ->value(RouteValue::TEMPLATE, 'pages/activation');

        $account->after(Controller::ACCOUNT . ':after');

        return $account;
    }

}
