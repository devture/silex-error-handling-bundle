<?php
namespace Devture\Bundle\ErrorHandlingBundle;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Response;

class ServicesProvider implements ServiceProviderInterface {

    private $config;

    public function __construct(array $config) {
        $defaultConfig = array(
            'debug' => false,
            'project_name' => 'Unnamed project',
        );

        $this->config = array_merge($defaultConfig, $config);
    }

    public function register(Application $app) {
        $app['devture_error.views'] = new \ArrayObject(array(
            '401' => 'DevtureErrorHandlingBundle/401.html.twig',
            '404' => 'DevtureErrorHandlingBundle/404.html.twig',
            '405' => 'DevtureErrorHandlingBundle/405.html.twig',
            'generic' => 'DevtureErrorHandlingBundle/generic.html.twig',
            'layout' => 'DevtureErrorHandlingBundle/layout.html.twig',
        ));

        //HTTP status codes, for which email notifications won't be sent.
        $app['devture_error.email_notifications_ignore_http_codes'] = new \ArrayObject(array(
            401,  404, 405,
        ));

        $app['devture_error.error_handler'] = function ($app) {
            return new \Devture\Bundle\ErrorHandlingBundle\ErrorHandler($app['debug'], $app['devture_error.error_exception_callback']);
        };

        /**
         * Called for all errors converted to exceptions outside the Silex request/response cycle
         * and for fatal errors (in all cases).
         */
        $app['devture_error.error_exception_callback'] = $app->protect(function (\Exception $e) {
            //Nothing more to do (unless email notifications are enabled, see below).
        });

        if (!$app['debug'] && isset($this->config['email_notifications'])) {
            $this->setupEmailNotifications($app, $this->config);
        }
    }

    private function setupEmailNotifications(Application $app, array $config) {
        $notificationsConfig = $config['email_notifications'];

        $app['devture_error.mailer'] = $app->share(function ($app) use ($notificationsConfig) {
            return $app[$notificationsConfig['mailer_service_id']];
        });

        $app['devture_error.mailer_flush_messages_callback'] = $app->protect(function () use ($app) {
            //$app['devture_error.mailer'] is most likely pointing to the default swiftmailer,
            //which uses a message spool and does not send right away. This clears that spool.
            //If some other mailer is used, this may not work or actually have a negative impact.
            //--
            //Messages are only stored in a queue, to be flushed from an $app->finish() filter.
            //Because sending exception emails is important and has to always work, we'll flush
            //explicitly now. This fixes sending from within console commands, or for Fatal Errors.
            //Fatal Errors are handled from within a register_shutdown_function hanler, which is too
            //late for regular spool-sending to work anyway.
            $app['swiftmailer.spooltransport']->getSpool()->flushQueue($app['swiftmailer.transport']);
        });

        $app['devture_error.send_exception_email'] = $app->protect(function (\Exception $exception) use ($app, $config, $notificationsConfig) {
            try {
                $request = $app['request'];
            } catch (\RuntimeException $e) {
                //Working outside of a request scope.
                $request = null;
            }

            $body = "Exception: " . get_class($exception) . "\n\n";
            $body .= "Message: " . $exception->getMessage() . "\n\n";
            if ($request) {
                $body .= "Happened at: " . $request->getRequestUri() . " for " . $request->getClientIp() . "\n\n";
            } else {
                $body .= "Happened outside of a request scope.\n\n";
            }
            $body .= "Trace:\n" . $exception->getTraceAsString() . "\n\n";

            $message = \Swift_Message::newInstance('Error at ' . $config['project_name']);
            $message->setFrom(array($notificationsConfig['sender'] => 'Error reporter'));
            $message->setTo($notificationsConfig['receivers']);
            $message->setBody($body);

            $app['devture_error.mailer']->send($message);

            $app['devture_error.mailer_flush_messages_callback']();
        });

        $app['devture_error.error_exception_callback'] = $app->raw('devture_error.send_exception_email');
    }

    public function boot(Application $app) {
        if ($app['debug']) {
            //Silex's `exception_handler` will kick in when in debug mode - it already does a good job at showing errors.
            //We don't want to send notification emails or render a pretty page, so we have nothing to do
            //except enabling the errors-to-exceptions converter.
            $app['devture_error.error_handler']->init();
            return;
        }

        $app['localization.translator.resource_loader']->addResources(dirname(__FILE__) . '/Resources/translations/');
        $app['twig.loader.filesystem']->addPath(dirname(__FILE__) . '/Resources/views/');

        $app['devture_error.error_handler']->init();

        if (isset($this->config['email_notifications'])) {
            $app->error(function (\Exception $exception, $code) use ($app) {
                if (in_array($code, (array) $app['devture_error.email_notifications_ignore_http_codes'])) {
                    return;
                }
                $app['devture_error.send_exception_email']($exception);
            });
        }

        //This should be last, because it returns a response, which ends it all.
        $app->error(function (\Exception $exception, $code) use ($app) {
            try {
                //Try to render a pretty error page.
                $views = $app['devture_error.views'];
                $viewName = isset($views[$code]) ? $views[$code] : $views['generic'];
                return new Response($app['twig']->render($viewName), $code);
            } catch (\Exception $e) {
                //We'd better return something after all.
                return new Response('Internal error while handling your request', 500);
            }
        });
    }

}
