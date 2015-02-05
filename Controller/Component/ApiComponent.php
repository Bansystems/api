<?php

App::uses('Component', 'Controller');
App::uses('ApiError', 'Error');
App::uses('LackParametersException', 'Error');
App::uses('Debugger', 'Utility');

class ApiComponent extends Component {

	protected $_response = [];
	public $controller;
	public $recordMap = [];
	public $useModel = null;

	public $version;
	const RECENT_VERSION = '1.0';

	public function isApiRequest(CakeRequest $request = null) {
		if ($request === null) {
			$request = $this->controller->request;
		}

		return !empty($request->params['api']);
	}

	public function initialize(Controller $controller) {
		$this->controller = $controller;
		if ($this->isApiRequest($controller->request)) {
			Debugger::outputAs('txt');
			$this->_setExceptionHandler($controller);
			$this->_setAuthenticate($controller);
			$this->_handleVersion($controller);
		}

		return parent::initialize($controller);
	}

	public function beforeRender(Controller $controller) {
		if ($this->isApiRequest($controller->request)) {
			if (empty($this->_response)) {
				$this->failure(ApiError::NOT_IMPLEMENTED, 501);
			}
			if (Configure::read('debug') >= 2) {
				// $this->setResponse('dbLog', ConnectionManager::getDataSource('default')->getLog());
			}
			$response = $this->_response;
			$controller->response->statusCode($response['code']);
			$controller->set(compact('response'));
			$controller->set('_serialize', 'response');
			if ($controller->viewClass === 'View') {
				$controller->viewClass = 'Json';
			}
		}

		return parent::beforeRender($controller);
	}

	protected function _setExceptionHandler(Controller $controller) {
		Configure::write('Exception.renderer', 'ApiExceptionRenderer');
	}

	protected function _setAuthenticate(Controller $controller) {
		if (isset($controller->Auth->authenticate['Form'])) {
			$authenticate = &$controller->Auth->authenticate;
		} else {
			$authenticate = &$controller->components['Auth']['authenticate'];
		}
		$authenticate['Api'] = $authenticate['Form'];
		unset($authenticate['Form']);
	}

	protected function _handleVersion($controller) {
		$this->version = static::RECENT_VERSION;
		if ($version = $controller->request->header('APIVersion')) {
			$this->version = $version;
		}
	}

	public function requireParam($paramName) {
		return $this->requireParams([$paramName])[$paramName];
	}

	public function requireParams(array $keys) {
		$params = $this->collectParams($keys);
		$noProvidedKeys = array_diff($keys, array_keys($params));
		if (!empty($noProvidedKeys)) {
			throw new LackParametersException(implode(', ', $noProvidedKeys));
		}

		return $params;
	}

	public function collectParams(array $keys = array()) {
		$request = $this->controller->request;
		if ($request->is('get')) {
			$params = (array)$request->query;
		} else {
			$params = $request->data;
		}

		if (!empty($keys)) {
			$params = array_intersect_key($params, array_flip($keys));
		}

		return $params;
	}

	public function collectParam($paramName) {
		$params = $this->collectParams([$paramName]);
		return array_key_exists($paramName, $params) ? $params[$paramName] : null;
	}

	protected function _walkMap(callable $callback) {
		foreach ($this->recordMap as $alias => $map) {
			$map = Hash::normalize($map);
			$wrap = null;
			if (array_key_exists('_wrap', $map)) {
				$wrap = $map['_wrap'];
				unset($map['_wrap']);
			}
			foreach ($map as $recordField => $paramsField) {
				if (!is_array($paramsField) && !$paramsField) {
					$paramsField = $recordField;
				}
				$callback($paramsField, $alias, $recordField, $wrap);
			}
		}
	}

	protected function _mapKeys(array $options) {
		$options += [
			'includes' => false,
			'excludes' => false,
		];

		$keys = [];
		$this->_walkMap(function($paramsField, $alias, $recordField) use(&$keys) {
			if (is_array($paramsField)) {
				$keys[] = $recordField;
			} else {
				$keys[] = $paramsField;
			}
		});
		if ($options['includes']) {
			$keys = array_intersect($keys, $options['includes']);
		}
		if ($options['excludes']) {
			$keys = array_diff($keys, $options['excludes']);
		}

		return $keys;
	}

	public function requireParamsFromMap($options = []) {
		$keys = $this->_mapKeys($options);
		return $this->requireParams($keys);
	}

	public function collectParamsFromMap($options = []) {
		$keys = $this->_mapKeys($options);
		return $this->collectParams($keys);
	}

	public function normalizeParamForArray($values) {
		$values = $values === '' ? [] : (array)$values;
		if ($values === ['']) {
			$values = [];
		}
		return $values;
	}

	public function paramsToRecord(array $params) {
		$record = [];
		$this->_walkMap(function($paramsField, $alias, $recordField) use($params, &$record) {
			if (is_array($paramsField)) {
				if (empty($recordField)) {
					throw new DomainException('recordMapの書式が不正');
				}
				if (array_key_exists($recordField, $params)) {
					$values = $this->normalizeParamForArray($params[$recordField]);
					if (!empty($paramsField)) {
						$field = $paramsField[0];
						foreach ($values as $key => $val) {
							$record[$alias][$key][$field] = $value;
						}
					} else {
						$record[$alias] = $values;
					}
				}
			} else {
				if (array_key_exists($paramsField, $params)) {
					$record[$alias][$recordField] = $params[$paramsField];
				}
			}
		});

		return $record;
	}

	public function recordToParams(array $record) {
		$params = [];
		$this->_walkMap(function($paramsField, $alias, $recordField, $wrap) use(&$params, $record) {
			if (is_array($paramsField)) {
				if (isset($record[$alias])) {
					if ($wrap) {
						$params[$wrap][$recordField] = [];
					} else {
						$params[$recordField] = [];
					}
					foreach ($record[$alias] as $i => $_record) {
						$field = !empty($paramsField) ? $paramsField[0] : null;
						$value = is_array($_record) && $field ? $_record[$field] : $_record;
						if ($wrap) {
							$params[$wrap][$recordField][$i] = $value;
						} else {
							$params[$recordField][$i] = $value;
						}
					}
				}
			} elseif (isset($record[$alias]) && array_key_exists($recordField, $record[$alias])) {
				$value = $record[$alias][$recordField];
				if ($wrap) {
					$params[$wrap][$paramsField] = $value;
				} else {
					$params[$paramsField] = $value;
				}
			}
		});

		return $params;
	}

	public function processSaveRecord($data, $options = []) {
		$successCallback = Hash::get($options, 'successCallback');
		unset($options['successCallback']);
		$result = $this->saveRecord($data, $options);
		if ($result) {
			$data = $successCallback ? $successCallback() : null;
			$this->success($data);
		} else {
			$this->processValidationErrors();
		}

		return $result;
	}

	public function processValidationErrors($model = null) {
		$this->raiseValidationErrors();
		$this->setValidationErrors($model);
	}

	public function raiseValidationErrors($validationErrors = null) {
		$this->failure(ApiError::VALIDATION_ERROR, 400);
		if ($validationErrors !== null) {
			$this->setResponse(compact('validationErrors'));
		}
	}

	public function saveRecord($data, array $options = []) {
		$options += [
			'saveCallback' => [$this, '_defaultSaveCallcback'],
		];
		$saveCallback = $options['saveCallback'];
		unset($options['saveCallback']);

		$validateOnly = $this->collectParam('validate_only');
		$validateOnly = $this->convertBoolean($validateOnly);
		$options += ['validate' => ($validateOnly ? 'only' : 'first')];
		return $saveCallback($data, $options);
	}

	public static function convertBoolean($value) {
		if (is_array($value)) {
			foreach ($value as $key => $val) {
				$value[$key] = static::convertBoolean($val);
			}
			return $value;
		}

		$map = [
			true => [
				'true',
				'yes',
				'1',
			],
			false => [
				'false',
				'no',
				'0',
			],
		];
		foreach ($map as $bool => $values) {
			if (in_array($value, $values)) {
				$value = (bool)$bool;
				break;
			}
		}

		return $value;
	}

	protected function _getDefaultModel() {
		$model = $this->useModel ?: $this->controller->modelClass;
		return ClassRegistry::init($model);
	}

	protected function _defaultSaveCallcback($data, array $options) {
		$Model = $this->_getDefaultModel();
		$result = $Model->saveAll($data, $options);
		return $result === true || (is_array($result) && !in_array(false, $result, true));
	}

	public function setValidationErrors($model = null) {
		$validationErrors = $this->collectValidationErrors($model);
		$validationErrors = $this->recordToParams($validationErrors);
		$this->setResponse(compact('validationErrors'));
	}

	public function collectValidationErrors($model = null) {
		if ($model !== null) {
			$Model = ClassRegistry::init($model);
		} else {
			$Model = $this->_getDefaultModel();
		}
		return $this->_collectValidationErrors($Model);
	}

	protected function _collectValidationErrors($Model) {
		$validationErrors = [];
		foreach ($Model->validationErrors as $field => $errors) {
			if (isset($Model->$field) && $Model->$field instanceof Model) {
				$validationErrors = array_merge($validationErrors, $this->_collectValidationErrors($Model->$field));
			}
			$validationErrors[$Model->alias][$field] = $errors;
		}

		return $validationErrors;
	}

	public function setResponse($vars, $value = null) {
		if (!is_array($vars)) {
			$vars = [$vars => $value];
		}
		$this->_response = Hash::merge($this->_response, $vars);
	}

	public function success($data = null) {
		$this->setResponse([
			'success' => true,
			'code' => 200,
		]);
		if ($data !== null) {
			$this->setResponse(compact('data'));
		}
	}

	public function failure($errorCode, $httpStatus = 500) {
		$this->setResponse([
			'success' => false,
			'code' => $httpStatus,
			'errorCode' => $errorCode,
			'errorMessage' => ApiError::message($errorCode),
		]);
	}

	public function getResponse($key = null) {
		if ($key !== null) {
			return Hash::get($this->_response, $key);
		}
		return $this->_response;
	}

}
