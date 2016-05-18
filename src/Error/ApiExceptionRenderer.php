<?php

namespace Api\Error;

use Cake\Network\Response;

class ApiExceptionRenderer {

	public $response = null;
	public $exception = null;

	public function __construct(\Exception $exception) {
		$this->response = new Response();
		try {
			$this->response->statusCode($exception->getCode());
		} catch (CakeException $e) {
			$this->response->statusCode(500);
		}
		$this->exception = $exception;
	}

	public function render() {
		$params = [];
		$errorCode = ApiError::UNKNOWN;

		if ($this->exception instanceof LackParametersException) {
			$errorCode = ApiError::LACK_PARAMETERS;
			$params['lackParameters'] = explode(', ', $this->exception->getMessage());
		} elseif ($this->exception instanceof UnauthenticatedException) {
			$errorCode = ApiError::UNAUTHENTICATED;
		} elseif ($this->exception instanceof UnauthorizedException) {
			$errorCode = ApiError::NOT_AUTHENTICATED;
		}

		$statusCode = $this->response->statusCode();
		$errorMessages = ApiError::messages();
		if (isset($errorMessages[$statusCode])) {
			$errorCode = $statusCode;
		}

		if (Configure::read('debug') > 1 && $errorCode == ApiError::UNKNOWN) {
			$params['debug'] = [
				'message' => $this->exception->getMessage(),
				'trace' => explode("\n", $this->exception->getTraceAsString()),
			];
		}

		$params = [
			'success' => false,
			'code' => $statusCode,
			'errorCode' => $errorCode,
			'errorMessage' => ApiError::message($errorCode),
		] + $params;

		$this->response->body(json_encode($params));
		$this->response->type('json');
		$this->response->send();
	}

}
