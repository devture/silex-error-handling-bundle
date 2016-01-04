<?php
namespace Devture\Bundle\ErrorHandlingBundle;

use Symfony\Component\HttpKernel\Debug\ErrorHandler as SymfonyErrorHandler;
use Symfony\Component\HttpKernel\Debug\ExceptionHandler;

class ErrorHandler extends ExceptionHandler {

	private $errorExceptionCallback;
	private $reservedMemory;

	public function __construct($isDebug, \Closure $errorExceptionCallback) {
		parent::__construct($isDebug);
		$this->errorExceptionCallback = $errorExceptionCallback;
		$this->reservedMemory = str_repeat('x', 10240);
	}

	public function init() {
		//Converts errors/warnings/notice to exceptions.
		//If these errors happen in the request/response cycle (which is most likely),
		//they will be as regular exeptions (\Silex\Application::error() callbacks will be called).
		//For errors triggered in other places, the below exception handler (for fatal errors) is used.
		SymfonyErrorHandler::register();

		//Sets up a global exception handler, because Silex doesn't mess around
		//with the global state and that prevents us from handling fatal errors,
		//or other possible errors outside of the request/response cycle.
		//--
		//Fatal errors are caught from a "register_shutdown_function" handler
		//in the Symfony ErrorHandler and they're passed to the currently
		//registered exception handler (but only if it's an instanceof ExceptionHandler, like this one).
		set_exception_handler(array($this, 'handleThrowable'));
	}

	/**
	 * @param \Throwable $e
	 */
	public function handleThrowable($e) {
		unset($this->reservedMemory);

		//Wrap PHP7 errors in an \Exception derivative, as the symfony/debug ExceptionHadler::handle()
		//cannot handle a top-level \Error, but will happily work with an \Error nested within an \Exception.
		//Using FatalThrowableError, instead of \Exception to prevent losing the debug information while flattening.
		if ($e instanceof \Error) {
			$e = new \Symfony\Component\Debug\Exception\FatalThrowableError($e);
		}

		$errorExceptionCallback = $this->errorExceptionCallback;
		$errorExceptionCallback($e);

		parent::handle($e); //Creates and sends the response
	}

}
