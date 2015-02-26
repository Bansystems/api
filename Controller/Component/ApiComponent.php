<?php

App::uses('Component', 'Controller');
App::uses('ApiError', 'Api.Error');
App::uses('LackParametersException', 'Api.Error');
App::uses('Debugger', 'Utility');

/**
 * Class ApiComponent
 */
class ApiComponent extends Component {

/**
 * アクションの最終レスポンスを格納
 *
 * @var array
 */
	protected $_response = [];

/**
 * 現在のコントローラー
 *
 * @var Controller
 */
	public $controller;

/**
 * リクエストパラメーターとモデルデータ形式のマッピング
 *
 * ### 例
 *
 * {{{
 * $this->Api->recordMap = [
 *     'User' => [
 *         'id',
 *         'name',
 *     ];
 * ];
 * }}}
 *
 * とすると、
 *
 * {{{
 * // $this->request->data = ['id' => '1', 'name' => 'Hoge'];
 * $params = $this->Api->collectParamsFromMap();
 * // $params = [
 * //     'id' => '1',
 * //     'name' => 'Hoge',
 * // ];
 * $record = $this->Api->paramsToRecord($params);
 * // $record = [
 * //     'User' => [
 * //         'id' => '1',
 * //         'name' => 'Hoge',
 * //     ],
 * // ];
 * }}}
 *
 * となります。
 *
 * {{{
 * $params = $this->Api->recordToParams($this->User->find());
 * // $params = [
 * //     'id' => '1',
 * //     'name' => 'Hoge',
 * // ];
 * }}}
 *
 * のように、逆変換もできます。
 * @var array
 */
	public $recordMap = [];

/**
 * Controller::modelClassに該当します。
 * デフォルトで使うモデルを指定します。
 *
 * @var string
 */
	public $useModel = null;

/**
 * リクエストのAPIバージョン
 *
 * @var string
 */
	public $version;

/**
 * 現在のAPIバージョン
 *
 * @var string
 */
	public $recentVersion = '1.0';

/**
 * バージョン未指定の時のAPIバージョン
 *
 * @var string
 */
	public $currentVersion = '1.0';

/**
 * DBログをデバッグ用にレスポンスに含めるか
 * null | false: しない
 * n(integer) >= 1: Configure('debug') >= n の時含める
 *
 * @var integer
 */
	public $logDb = false;

/**
 * JSONレスポンス上でデバッグする際、不要なHTMLを抑制するためデバッガーの出力
 * タイプを変更する
 *
 * @var string
 */
	public $setDebuggerTypeAs = 'txt';

/**
 * 例外レンダラの設定
 *
 * @var string
 */
	public $exceptionRenderer = 'Api.ApiExceptionRenderer';

/**
 * 認証ハンドラの設定
 *
 * @var string
 */
	public $setAuthenticate = 'Api.Api';

/**
 * 初期化時設定メソッドの指定
 *
 * @var array
 */
	public $configMethods = [
		'_setDebuggerTypeAs' => true,
		'_setExceptionHandler' => true,
		'_setAuthenticate' => true,
		'_handleVersion' => true,
	];

/**
 * 現在のリクエストがAPIかどうか
 *
 * @param CakeRequest $request
 * @return bool
 */
	public function isApiRequest(CakeRequest $request = null) {
		if ($request === null) {
			$request = $this->controller->request;
		}

		return !empty($request->params['api']);
	}

/**
 * 初期化
 *
 * @param Controller $controller
 * @return void
 */
	public function initialize(Controller $controller) {
		$this->controller = $controller;
		if ($this->isApiRequest($controller->request)) {
			foreach (Hash::normalize($this->configMethods) as $method => $enabled) {
				if ($enabled) {
					$this->$method($controller);
				}
			}
		}

		return parent::initialize($controller);
	}

/**
 * beforeRender callback
 * レスポンスの描画を行います。
 *
 * @param Controller $controller
 * @return void
 */
	public function beforeRender(Controller $controller) {
		if ($this->isApiRequest($controller->request)) {
			if (empty($this->_response)) {
				$this->failure(ApiError::NOT_IMPLEMENTED, 501);
			}
			if ($this->logDb && Configure::read('debug') >= $this->logDb) {
				$this->setResponse('dbLog', $this->_getDbLog());
			}
			$response = $this->getResponse();
			$controller->response->statusCode($response['code']);
			$controller->set(compact('response'));
			$controller->set('_serialize', 'response');
			if ($controller->viewClass === 'View') {
				$controller->viewClass = 'Json';
			}
		}

		return parent::beforeRender($controller);
	}

/**
 * DBログを返すヘルパーメソッド
 *
 * @return array log of database
 */
	protected function _getDbLog() {
		return ConnectionManager::getDataSource('default')->getLog();
	}

/**
 * Debuggerの出力タイプを変更する
 *
 * @param Controller $controller
 * @return void
 */
	protected function _setDebuggerTypeAs(Controller $controller) {
		if ($this->setDebuggerTypeAs) {
			Debugger::outputAs($this->setDebuggerTypeAs);
		}
	}

/**
 * API用の例外レンダラを設定します
 *
 * @param Controller $controller
 * @return void
 */
	protected function _setExceptionHandler(Controller $controller) {
		if ($this->exceptionRenderer) {
			Configure::write('Exception.renderer', $this->exceptionRenderer);
		}
	}

/**
 * API用のauthenticateクラスを指定するために、FormAuthenticateを上書きします。
 * 認証が失敗した時ログインページへリダイレクトするのを防ぎます。
 * Form以外のAuthenticateクラスには現在対応していません。
 *
 * @param Controller $controller
 * @return void
 */
	protected function _setAuthenticate(Controller $controller) {
		if ($this->setAuthenticate) {
			if (isset($controller->Auth->authenticate['Form'])) {
				$authenticate = &$controller->Auth->authenticate;
			} else {
				$authenticate = &$controller->components['Auth']['authenticate'];
			}
			$authenticate[$this->setAuthenticate] = $authenticate['Form'];
			unset($authenticate['Form']);
		}
	}

/**
 * バージョンコントロールをします。
 * クライアントはヘッダにAPIVersionを含めることで使用するバージョンを指定できます。
 * 指定されなかった場合、ApiComponent::$recetnVersionが使用されます。
 *
 * @param Controller $controller
 * @return void
 */
	protected function _handleVersion($controller) {
		$modelConfig = ClassRegistry::config('Model');
		if (!empty($modelConfig['testing'])) {
			$this->version = $this->recentVersion;
			if ($version = Configure::read('TEST_API_VERSION')) {
				$this->version = $version;
			}
			return;
		}

		$this->version = $this->currentVersion;
		if ($version = $controller->request->header('APIVersion')) {
			$this->version = $version;
		}
	}

/**
 * バージョン比較をします。
 * PHPファンクションversion_compareへのエイリアスです。
 *
 * @param string $compare
 * @param string $version
 * @return mixed compared value
 */
	public function compareVersion($compare, $version = null) {
		if ($version === null) {
			$version = $compare;
			$compare = null;
		}

		$args = [$this->version, $version];
		if ($compare !== null) {
			$args[] = $compare;
		}
		return call_user_func_array('version_compare', $args);
	}

/**
 * 必須バージョンを指定します。
 * サポートされない場合、例外を投げます。
 *
 * @param string $version
 * @return void
 * @throws ForbiddenException
 */
	public function requireVersion($version) {
		if (!$this->supportVersion($version)) {
			throw new ForbiddenException(sprintf('This API requires version %s but spcified version was %s', $version, $this->version));
		}
	}

/**
 * バージョンがサポートされているかどうかを返します。
 *
 * @param string $version
 * @return bool
 */
	public function supportVersion($version) {
		return $this->compareVersion('>=', $version);
	}

/**
 * 必須リクエストパラメーターを指定します。
 * 返り値はパラメータ値です。
 * 必須パラメータが指定されていない場合、LackParametersExceptionが投げられます。
 *
 * @param string $paramName
 * @throws LackParametersException
 * @return mixed param value
 */
	public function requireParam($paramName) {
		return $this->requireParams([$paramName])[$paramName];
	}

/**
 * 必須リクエストパラメーターを複数指定します。
 * 返り値はパラメータ名：パラメータ値のハッシュです。
 * 必須パラメータが指定されていない場合、LackParametersExceptionが投げられます。
 *
 * @param array $keys
 * @throws LackParametersException
 * @return array param values
 */
	public function requireParams(array $keys) {
		$params = $this->collectParams($keys);
		$noProvidedKeys = array_diff($keys, array_keys($params));
		if (!empty($noProvidedKeys)) {
			throw new LackParametersException(implode(', ', $noProvidedKeys));
		}

		return $params;
	}

/**
 * リクエストパラメーターを収集します。
 * 返り値はパラメータ名：パラメータ値のハッシュです。
 *
 * @param array $keys
 * @return array param values
 */
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

/**
 * リクエストパラメーターを返します。
 * 返り値はパラメータ値で、なければnullを返します。
 *
 * @param string $paramName
 * @return mixed param value
 */
	public function collectParam($paramName) {
		$params = $this->collectParams([$paramName]);
		return array_key_exists($paramName, $params) ? $params[$paramName] : null;
	}

/**
 * ApiComponent::$recordMapを走査するためのヘルパーメソッドです。
 *
 * @param callable $callback
 * @return void
 */
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

/**
 * ApiComponent::$recordMapから、リクエストパラメータ名の配列を返すヘルパーメソッドです。
 *
 * 設定
 *
 * - `includes` 返り値に含めるパラメータ名を指定する配列
 * - `excludes` 返り値に含めないパラメータ名を指定する配列
 *
 * @param array $options
 * @return void
 */
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

/**
 * ApiComponent::$recordMapから、必須リクエストパラメータを取得します。
 *
 * 設定
 *
 * - `includes` 返り値に含めるパラメータ名を指定する配列
 * - `excludes` 返り値に含めないパラメータ名を指定する配列
 *
 * @param array $options
 * @return void
 */
	public function requireParamsFromMap($options = []) {
		$keys = $this->_mapKeys($options);
		return $this->requireParams($keys);
	}

/**
 * ApiComponent::$recordMapから、パラメータを取得します。
 *
 * 設定
 *
 * - `includes` 返り値に含めるパラメータ名を指定する配列
 * - `excludes` 返り値に含めないパラメータ名を指定する配列
 *
 * @param array $options
 * @return void
 */
	public function collectParamsFromMap($options = []) {
		$keys = $this->_mapKeys($options);
		return $this->collectParams($keys);
	}

/**
 * habtm用の配列を期待するパラメータ値を、配列に整形します。
 *
 * @param mixed $values
 * @return array normalized array
 */
	public function normalizeParamForArray($values) {
		$values = $values === '' ? [] : (array)$values;
		if ($values === ['']) {
			$values = [];
		}
		return $values;
	}

/**
 * 取得したパラメータをレコード形式に整形します。
 *
 * @param array $params
 * @return array record
 */
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
							$record[$alias][$key][$field] = $val;
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

/**
 * find()などで取得したレコードをレスポンスパラメータ形式に整形します。
 *
 * @param array $record
 * @return array params
 */
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

/**
 * 一般的な保存処理をまとめています。
 * 保存処理をし、失敗すればバリデーションエラーを集めて返します。
 * バリデーションエラーを集めるためにはApiComponent::recordMapの設定が必要になります。
 *
 * 設定：
 * - successCallback: 保存が成功した場合、レスポンスとして返すデータを返り値とするコールバックを指定します。
 * - saveCallback: 保存処理。デフォルトの処理はApiComponent::_defaultSaveCallbackを見てください。
 *
 * @param mixed $data
 * @param array $options
 * @return mixed result of save process
 */
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

/**
 * バリデーションエラーをかき集めてAPIの応答をバリデーションエラーにします。
 *
 * @param string $model
 * @return array params
 */
	public function processValidationErrors($model = null) {
		$this->raiseValidationErrors();
		$this->setValidationErrors($model);
	}

/**
 * APIの応答をバリデーションエラーにします。
 * $validationErrorsが指定された場合、レスポンスにそれを直接含めます。
 *
 * @param array $validationErrors
 * @return array params
 */
	public function raiseValidationErrors($validationErrors = null) {
		$this->failure(ApiError::VALIDATION_ERROR, 400);
		if ($validationErrors !== null) {
			$this->setResponse(compact('validationErrors'));
		}
	}

/**
 * 一般的な保存処理です。
 * リクエストパラメータでvalidate_onlyに真の値が指定されている場合、バリデーションのみを行います。
 *
 * 設定：
 * - saveCallback: 保存処理。デフォルトの処理はApiComponent::_defaultSaveCallbackを見てください。
 *
 * @param mixed $data
 * @param array $options
 * @return mixed result of save process
 * @see ApiComponent::processSaveRecord()
 */
	public function saveRecord($data, array $options = []) {
		$options += [
			'saveCallback' => [$this, '_defaultSaveCallback'],
		];
		$saveCallback = $options['saveCallback'];
		unset($options['saveCallback']);

		$validateOnly = $this->collectParam('validate_only');
		$validateOnly = $this->convertBoolean($validateOnly);
		$options += ['validate' => ($validateOnly ? 'only' : 'first')];
		return $saveCallback($data, $options);
	}

/**
 * リクエストパラメータの値を変換し、真偽値として返します。
 * リクエストパラメータで、真偽値を要求する場合多様な指定を可能にします。
 * 配列が指定された場合、配列の要素各々に対して変換をします。
 *
 * - trueとして判定されるもの
 *   - 'true'
 *   - 'yes'
 *   - 1
 *
 * - falseとして判定されるもの
 *   - 'false'
 *   - 'no'
 *   - 0
 *
 * @param mixed $value array or string
 * @param array $options
 * @return mixed bool or array of bool
 */
	public static function convertBoolean($value) {
		if (is_array($value)) {
			foreach ($value as $key => $val) {
				$value[$key] = static::convertBoolean($val);
			}
			return $value;
		}

		$map = [
			true => [
				true,
				'true',
				'yes',
				'1',
				1,
			],
			false => [
				false,
				'false',
				'no',
				'0',
				0,
			],
		];
		foreach ($map as $bool => $values) {
			if (in_array($value, $values, true)) {
				$value = (bool)$bool;
				break;
			}
		}

		return $value;
	}

/**
 * デフォルトのモデルを返すヘルパーメソッドです。
 *
 * @return Model
 * @see Controller::$modelClass
 * @see ApiComponent::$useModel
 */
	protected function _getDefaultModel() {
		$model = $this->useModel ?: $this->controller->modelClass;
		return ClassRegistry::init($model);
	}

/**
 * デフォルトの保存処理です。
 *
 * @param mixed $data
 * @param array $options
 * @return mixed bool
 * @see ApiComponent::processSaveRecord()
 */
	protected function _defaultSaveCallback($data, array $options) {
		$Model = $this->_getDefaultModel();
		$result = $Model->saveAll($data, $options);
		return $result === true || (is_array($result) && !in_array(false, $result, true));
	}

/**
 * ApiComponent::$recordMapを元にバリデーションエラーを取得し、レスポンスに含めます。
 *
 * @param string $model
 * @return void
 * @see ApiComponent::$recordMap
 */
	public function setValidationErrors($model = null) {
		$validationErrors = $this->collectValidationErrors($model);
		$validationErrors = $this->recordToParams($validationErrors);
		$this->setResponse(compact('validationErrors'));
	}

/**
 * ApiComponent::$recordMapを元にバリデーションエラーを取得し、それを返します。
 *
 * @param string $model
 * @return array validationErrors
 * @see ApiComponent::$recordMap
 */
	public function collectValidationErrors($model = null) {
		if ($model !== null) {
			$Model = ClassRegistry::init($model);
		} else {
			$Model = $this->_getDefaultModel();
		}
		return $this->_collectValidationErrors($Model);
	}

/**
 * collectValidationErrorsのヘルパーメソッドです。
 * 再帰的にバリデーションエラーを取得します。
 *
 * @param Model $Model
 * @return array validationErrors
 * @see ApiComponent::$recordMap
 */
	protected function _collectValidationErrors($Model) {
		$validationErrors = [];
		foreach ($Model->validationErrors as $field => $errors) {
			if (isset($Model->$field) && $Model->$field instanceof Model) {
				$validationErrors = array_merge($validationErrors, $this->_collectValidationErrors($Model->$field));
			} else {
				$validationErrors[$Model->alias][$field] = $errors;
			}
		}

		return $validationErrors;
	}

/**
 * レスポンスをセットします。
 * Configure::write(), CakeSession::write()のような挙動をします。
 * $vars = null|false|[] ならば、レスポンスを空にします。
 *
 * @param mixed $vars
 * @param mixed $value
 * @return void
 */
	public function setResponse($vars, $value = null) {
		if (is_array($vars)) {
			if ($vars === []) {
				$this->_response = [];
			} else {
				$this->_response = Hash::merge($this->_response, $vars);
			}
		} elseif (in_array($vars, [null, false], true) && $value === null) {
			$this->_response = [];
		} else {
			$this->_response = Hash::insert($this->_response, $vars, $value);
		}
	}

/**
 * レスポンスを成功状態にします。
 * $dataを指定するとレスポンスに'data'パラメータとしてセットします。
 *
 * @param mixed $data
 * @return void
 */
	public function success($data = null) {
		$this->setResponse([
			'success' => true,
			'code' => 200,
		]);
		if ($data !== null) {
			$this->setResponse(compact('data'));
		}
	}

/**
 * レスポンスを失敗状態にします。
 * エラーコード、HTTPステータスを指定できます。
 *
 * @param string $errorCode
 * @param mixed $httpStatus
 * @return void
 * @see ApiError
 */
	public function failure($errorCode, $httpStatus = 500) {
		$this->setResponse([
			'success' => false,
			'code' => $httpStatus,
			'errorCode' => $errorCode,
			'errorMessage' => ApiError::message($errorCode),
		]);
	}

/**
 * 設定済みのレスポンスを取得します。
 *
 * @param string $key
 * @return mixed response
 */
	public function getResponse($key = null) {
		if ($key !== null) {
			return Hash::get($this->_response, $key);
		}
		return $this->_response;
	}

}
