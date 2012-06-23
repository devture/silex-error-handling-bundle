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
                'project_name' => 'Unnamed project');

        $this->config = array_merge($defaultConfig, $config);
    }

    public function register(Application $app) {
        $app['devture_error.views'] = new \ArrayObject(array(
                '401' => 'DevtureErrorHandlingBundle/401.html.twig',
                '404' => 'DevtureErrorHandlingBundle/404.html.twig',
                '405' => 'DevtureErrorHandlingBundle/405.html.twig',
                'generic' => 'DevtureErrorHandlingBundle/generic.html.twig',
                'layout' => 'DevtureErrorHandlingBundle/layout.html.twig',));

        //HTTP status codes, for which email notifications won't be sent.
        $app['devture_error.email_notifications_ignore_http_codes'] = new \ArrayObject(array(
                401,
                404,
                405));
    }

    public function boot(Application $app) {
        if ($app['debug']) {
            //Silex's `exception_handler` will kick in when in debug mode.
            //It already does a good job at showing errors.
            //We don't want to send notification emails in debug too, so we have nothing to offer.
            return;
        }

        $config = $this->config;

        $app['localization.translator.resource_loader']->addResources(dirname(__FILE__) . '/Resources/translations/');
        $app['twig.loader']->addLoader(new \Twig_Loader_Filesystem(dirname(__FILE__) . '/Resources/views/'));

        if (isset($config['email_notifications'])) {
            $this->setupEmailNotifications($app, $config);
        }

        //This should be last, because it returns a response, which ends it all.
        $app->error(function (\Exception $exception, $code) use ($app) {
            try {
                //Try to render a pretty error page.
                $views = $app['devture_error.views'];
                $viewName = isset($views[$code]) ? $views[$code]
                        : $views['generic'];
                return new Response($app['twig']->render($viewName), $code);
            } catch (\Exception $e) {
                //We'd better return something after all.
                return new Response('Internal error while handling your request', 500);
            }
        });
    }

    private function setupEmailNotifications(Application $app, array $config) {
        $notificationsConfig = $config['email_notifications'];

        $app->error(function (\Exception $exception, $code) use ($app, $config, $notificationsConfig) {
            if (in_array($code, (array) $app['devture_error.email_notifications_ignore_http_codes'])) {
                return;
            }

            $request = $app['request'];

            $body = "Exception: " . $exception->getMessage() . "\n\n";
            $body .= "Happened at: " . $request->getRequestUri() . " for " . $request->getClientIp() . "\n\n";
            $body .= "Trace:\n" . $exception->getTraceAsString() . "\n\n";

            $message = \Swift_Message::newInstance('Error at ' . $config['project_name']);
            $message->setFrom(array(
                    $notificationsConfig['sender'] => 'Error reporter'));
            $message->setTo($notificationsConfig['receivers']);
            $message->setBody($body);
            $app[$notificationsConfig['mailer_service_id']]->send($message);
        });
    }

}
