<?php
namespace Devture\Bundle\ErrorHandlingBundle;

use Symfony\Component\HttpFoundation\Response;

class ServicesProvider implements \Pimple\ServiceProviderInterface, \Silex\Api\BootableProviderInterface {

	private $config;

	public function __construct(array $config) {
		$defaultConfig = array(
			'debug' => false,
			'project_name' => 'Unnamed project',
		);

		$this->config = array_merge($defaultConfig, $config);
	}

	public function register(\Pimple\Container $container) {
		$container['devture_error.views'] = new \ArrayObject(array(
			'401' => 'DevtureErrorHandlingBundle/401.html.twig',
			'404' => 'DevtureErrorHandlingBundle/404.html.twig',
			'405' => 'DevtureErrorHandlingBundle/405.html.twig',
			'generic' => 'DevtureErrorHandlingBundle/generic.html.twig',
			'layout' => 'DevtureErrorHandlingBundle/layout.html.twig',
		));

		//HTTP status codes, for which email notifications won't be sent.
		$container['devture_error.email_notifications_ignore_http_codes'] = new \ArrayObject(
			range(400, 499)
		);

		$container['devture_error.error_handler'] = function ($container) {
			return new \Devture\Bundle\ErrorHandlingBundle\ErrorHandler($container['debug'], $container['devture_error.error_exception_callback']);
		};

		/**
		 * Called for all errors converted to exceptions outside the Silex request/response cycle
		 * and for fatal errors (in all cases).
		 */
		$container['devture_error.error_exception_callback'] = $container->protect(function (\Exception $e) {
			//Nothing more to do (unless email notifications are enabled, see below).
		});

		if (!$container['debug'] && isset($this->config['email_notifications'])) {
			$this->setupEmailNotifications($container, $this->config);
		}
	}

	private function setupEmailNotifications(\Pimple\Container $container, array $config) {
		$notificationsConfig = $config['email_notifications'];

		$container['devture_error.mailer'] = function ($container) use ($notificationsConfig) {
			return $container[$notificationsConfig['mailer_service_id']];
		};

		$container['devture_error.mailer_flush_messages_callback'] = $container->protect(function () use ($container) {
			//$container['devture_error.mailer'] is most likely pointing to the default swiftmailer,
			//which uses a message spool and does not send right away. This clears that spool.
			//If some other mailer is used, this may not work or actually have a negative impact.
			//--
			//Messages are only stored in a queue, to be flushed from an $container->finish() filter.
			//Because sending exception emails is important and has to always work, we'll flush
			//explicitly now. This fixes sending from within console commands, or for Fatal Errors.
			//Fatal Errors are handled from within a register_shutdown_function hanler, which is too
			//late for regular spool-sending to work anyway.
			$container['swiftmailer.spooltransport']->getSpool()->flushQueue($container['swiftmailer.transport']);
		});

		$container['devture_error.send_exception_email'] = $container->protect(function (\Exception $exception) use ($container, $config, $notificationsConfig) {
			try {
				$request = $container['request'];
			} catch (\RuntimeException $e) {
				//Working outside of a request scope.
				$request = null;
			}

			$body = "Exception: " . get_class($exception) . "\n\n";
            $body .= "Error Code: " . $exception->getCode() . "\n\n";
			$body .= "Message: " . $exception->getMessage() . "\n\n";
			if ($request) {
				$body .= "Happened at: " . $request->getRequestUri() . " for " . $request->getClientIp() . "\n\n";
			} else {
				$body .= "Happened outside of a request scope.\n\n";
			}
            $body .= "File: " . $exception->getFile() . "\n";
            $body .= "Line: " . $exception->getLine() . "\n\n";

			$body .= "Trace:\n" . $exception->getTraceAsString() . "\n\n";

			$message = \Swift_Message::newInstance('Error at ' . $config['project_name']);
			$message->setFrom(array($notificationsConfig['sender'] => 'Error reporter'));
			$message->setTo($notificationsConfig['receivers']);
			$message->setBody($body);

			$container['devture_error.mailer']->send($message);

			$container['devture_error.mailer_flush_messages_callback']();
		});

		$container['devture_error.error_exception_callback'] = $container->raw('devture_error.send_exception_email');
	}

	public function boot(\Silex\Application $app) {
		if ($app['debug']) {
			//Silex's `exception_handler` will kick in when in debug mode - it already does a good job at showing errors.
			//We don't want to send notification emails or render a pretty page, so we have nothing to do
			//except enabling the errors-to-exceptions converter.
			$app['devture_error.error_handler']->init();
			return;
		}

		$app['devture_localization.translator.resource_loader']->addResources(dirname(__FILE__) . '/Resources/translations/');
		$app['twig.loader.filesystem']->addPath(__DIR__ . '/Resources/views/');

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
