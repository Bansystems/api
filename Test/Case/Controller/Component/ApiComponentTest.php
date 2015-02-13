<?php

App::uses('CakeRequest', 'Network');
App::uses('CakeResponse', 'Network');
App::uses('Controller', 'Controller');
App::uses('ComponentCollection', 'Controller');
App::uses('ApiComponent', 'Api.Controller/Component');

class ApiComponentTest extends CakeTestCase {

	/**
	 * Api component instance to test
	 *
	 * @var Controller
	 */
	public $Api;

	/**
	 * Component collection instance for test
	 *
	 * @var ComponentCollection
	 */
	public $collection;

	/**
	 * Controller instance for test
	 *
	 * @var Controller
	 */
	public $controller;

	/**
	 * Request instance for test
	 *
	 * @var CakeRequest
	 */
	public $request;

	/**
	 * Response instance for test
	 *
	 * @var CakeResponse
	 */
	public $response;

	/**
	 * tear down for each cases
	 *
	 * @return void
	 */
	public function tearDown() {
		unset(
			$this->Api,
			$this->collection,
			$this->controller,
			$this->request,
			$this->response
		);
		parent::tearDown();
	}

	/**
	 * Generates the component for test
	 * Options:
	 * - mocks: specify mock methods for each objects
	 * - componentOptopns: could be used for component options.
	 *
	 * @param $options
	 * @return void
	 */
	public function generateComponent($options = []) {
		$options = Hash::merge([
			'mocks' => [
				'controller' => [
					'_stop',
					'redirect',
				],
			],
			'componentOptions' => [
				'setDebuggerTypeAs' => false,
				'setExceptionHandler' => false,
				'setAuthenticate' => false,
			],
			'initialize' => true,
		], $options);
		extract($options);

		$this->request = $this->getMockBuilder('CakeRequest')
			->setConstructorArgs(['/', false])
			->setMethods(Hash::get($mocks, 'request'))
			->getMock();
		$this->response = $this->getMockBuilder('CakeResponse')
			->setMethods(Hash::get($mocks, 'response'))
			->getMock();
		$this->controller = $this->getMockBuilder('Controller')
			->setConstructorArgs([$this->request, $this->response])
			->setMethods(Hash::get($mocks, 'controller'))
			->getMock();
		$this->collection = $this->getMockBuilder('ComponentCollection')
			->setMethods(Hash::get($mocks, 'collection'))
			->getMock();
		$this->collection->init($this->controller);
		$this->Api = $this->getMockBuilder('ApiComponent')
			->setConstructorArgs([$this->collection, $componentOptions])
			->setMethods(Hash::get($mocks, 'Api'))
			->getMock();

		if ($initialize) {
			$this->Api->initialize($this->controller);
		}

		return $this->Api;
	}

	/**
	 * test isApiRequest() method
	 *
	 * @test
	 */
	public function isApiRequest() {
		$this->generateComponent();
		$this->Api->initialize($this->controller);
		$this->request->params['api'] = true;
		$this->assertTrue($this->Api->isApiRequest());

		unset($this->request->params['api']);
		$this->assertFalse($this->Api->isApiRequest());
	}

	/**
	 * test initialize() method
	 *
	 * @test
	 */
	public function initialize() {
		$configMethods = array_keys(get_class_vars('ApiComponent')['configMethods']);
		$this->generateComponent([
			'mocks' => [
				'Api' => array_merge($configMethods, [
					'isApiRequest',
				]),
			],
			'initialize' => false,
		]);

		$this->Api->expects($this->once())
			->method('isApiRequest')
			->will($this->returnValue(true));
		foreach ($configMethods as $configMethod) {
			$this->Api
				->expects($this->once())
				->method($configMethod);
		}
		$this->Api->initialize($this->controller);
	}


	/**
	 * test beforeRender() method
	 *
	 * @test
	 */
	public function beforeRender() {
		$this->generateComponent([
			'mocks' => [
				'Api' => [
					'isApiRequest',
					'failure',
					'setResponse',
					'_getDbLog',
					'getResponse',
				],
			],
			'componentOptions' => [
				'logDb' => true,
			],
		]);

		$this->Api->expects($this->once())
			->method('failure');
		$this->Api->expects($this->once())
			->method('isApiRequest')
			->will($this->returnValue(true));
		$this->Api->expects($this->once())
			->method('_getDbLog')
			->will($this->returnValue('testDbLog'));
		$this->Api->expects($this->once())
			->method('setResponse')
			->with('dbLog', 'testDbLog');
		$this->Api->expects($this->once())
			->method('getResponse')
			->will($this->returnValue([
				'code' => 401,
			]));

		$this->Api->beforeRender($this->controller);
		$this->assertSame(401, $this->response->statusCode());
		$this->assertArrayHasKey('response', $this->controller->viewVars);
		$this->assertSame([
			'code' => 401,
		], $this->controller->viewVars['response']);
		$this->assertArrayHasKey('_serialize', $this->controller->viewVars);
		$this->assertSame('response', $this->controller->viewVars['_serialize']);
		$this->assertSame('Json', $this->controller->viewClass);
	}

	/**
	 * test _setAuthenticate() method
	 *
	 * @test
	 */
	public function _setAuthenticate() {
		$this->generateComponent([
			'componentOptions' => [
				'setAuthenticate' => 'Api',
			],
		]);
		$this->controller->Auth = new stdclass;
		$this->controller->Auth->authenticate = [
			'Form' => [
				'fields' => [
					'username' => 'email',
					'password' => 'password',
				],
			],
		];
		$this->Api->dispatchMethod('_setAuthenticate', [$this->controller]);
		$this->assertSame([
			'Api' => [
				'fields' => [
					'username' => 'email',
					'password' => 'password',
				],
			],
		], $this->controller->Auth->authenticate);

		unset($this->controller->Auth);
		$this->controller->components['Auth']['authenticate'] = [
			'Form' => [
				'scope' => [
					'active' => true,
				],
			],
		];
		$this->Api->dispatchMethod('_setAuthenticate', [$this->controller]);
		$this->assertSame([
			'Api' =>[
				'scope' => [
					'active' => true,
				],
			],
		], $this->controller->components['Auth']['authenticate']);
	}

	/**
	 * test _handleVersion() method
	 *
	 * @test
	 */
	public function _handleVersion() {
		$this->generateComponent();
		$this->Api->dispatchMethod('_handleVersion', [$this->controller]);
		$this->assertSame('1.0', $this->Api->version);

		$this->generateComponent();
		Configure::write('TEST_API_VERSION', '2.1');
		$this->Api->dispatchMethod('_handleVersion', [$this->controller]);
		$this->assertSame('2.1', $this->Api->version);

		$modelConfig = ClassRegistry::config('Model');
		ClassRegistry::config('Model', null);
		$this->generateComponent();
		$this->Api->dispatchMethod('_handleVersion', [$this->controller]);
		$this->assertSame('1.0', $this->Api->version);

		$this->generateComponent([
			'mocks' => [
				'request' => [
					'header',
				],
			],
		]);
		$this->request->staticExpects($this->once())
			->method('header')
			->with('APIVersion')
			->will($this->returnValue('3.5'));
		$this->Api->dispatchMethod('_handleVersion', [$this->controller]);
		$this->assertSame('3.5', $this->Api->version);

		ClassRegistry::config('Model', $modelConfig);
		Configure::delete('TEST_API_VERSION');
	}

	/**
	 * test compareVersion() method
	 *
	 * @test
	 */
	public function compareVersion() {
		$this->generateComponent();

		$this->Api->version = '2.0';
		$this->assertSame(1, $this->Api->compareVersion('1.1'));
		$this->assertSame(true, $this->Api->compareVersion('>', '1.1'));
	}

	/**
	 * test requireVersion() method
	 *
	 * @test
	 */
	public function requireVersion() {
		$this->generateComponent();

		$this->Api->version = '2.0';
		$this->Api->requireVersion('1.1');
		$this->Api->requireVersion('2.0');
		try {
			$this->Api->requireVersion('2.1');
			$this->fail('Expected ForbiddenException was not thrown');
		} catch (Exception $e) {
			$this->assertInstanceOf('ForbiddenException', $e);
		}
	}

	/**
	 * test supportVersion() method
	 *
	 * @test
	 */
	public function supportVersion() {
		$this->generateComponent();

		$this->Api->version = '2.0';
		$this->assertSame(true, $this->Api->supportVersion('1.1'));
		$this->assertSame(true, $this->Api->supportVersion('1.11'));
		$this->assertSame(true, $this->Api->supportVersion('2.0'));
		$this->assertSame(false, $this->Api->supportVersion('2.1'));
	}

	/**
	 * test requireParam() method
	 *
	 * @test
	 */
	public function requireParam() {
		$this->generateComponent([
			'mocks' => [
				'Api' => [
					'requireParams',
				],
			],
		]);

		$this->Api->expects($this->once())
			->method('requireParams')
			->with(['test'])
			->will($this->returnValue([
				'test' => 'testValue',
			]));
		$result = $this->Api->requireParam('test');
		$this->assertSame('testValue', $result);
	}

	/**
	 * test requireParams() method
	 *
	 * @test
	 */
	public function requireParams() {
		$this->generateComponent([
			'mocks' => [
				'Api' => [
					'collectParams',
				],
			],
		]);
		$this->Api->expects($this->once())
			->method('collectParams')
			->with(['test'])
			->will($this->returnValue([
				'test' => 'testValue',
			]));
		$result = $this->Api->requireParams(['test']);
		$this->assertSame([
			'test' => 'testValue',
		], $result);

		$this->generateComponent([
			'mocks' => [
				'Api' => [
					'collectParams',
				],
			],
		]);
		$this->Api->expects($this->once())
			->method('collectParams')
			->with(['test'])
			->will($this->returnValue([
				'test2' => 'testValue2',
			]));
		try {
			$this->Api->requireParams(['test']);
			$this->fail('Expected LackParametersException was not thrown');
		} catch (Exception $e) {
			$this->assertInstanceOf('LackParametersException', $e);
			$this->assertSame('test', $e->getMessage());
		}
	}

	/**
	 * test collectParams() method
	 *
	 * @test
	 */
	public function collectParams() {
		// GET
		$this->generateComponent([
			'mocks' => [
				'request' => [
					'is',
				],
			],
		]);
		$this->request->expects($this->exactly(2))
			->method('is')
			->with('get')
			->will($this->returnValue(true));
		$result = $this->Api->collectParams(['testQueryKey']);
		$this->assertSame([], $result);
		$this->request->query = [
			'testQueryKey' => 'testQueryValue',
		];
		$result = $this->Api->collectParams(['testQueryKey']);
		$this->assertSame([
			'testQueryKey' => 'testQueryValue',
		], $result);

		// NOT GET
		$this->generateComponent([
			'mocks' => [
				'request' => [
					'is',
				],
			],
		]);
		$this->request->expects($this->exactly(2))
			->method('is')
			->with('get')
			->will($this->returnValue(false));
		$result = $this->Api->collectParams(['testDataKey']);
		$this->assertSame([], $result);
		$this->request->data = [
			'testDataKey' => 'testDataValue',
		];
		$result = $this->Api->collectParams(['testDataKey']);
		$this->assertSame([
			'testDataKey' => 'testDataValue',
		], $result);
	}

	/**
	 * test collectParam() method
	 *
	 * @test
	 */
	public function collectParam() {
		$this->generateComponent([
			'mocks' => [
				'Api' => [
					'collectParams',
				],
			],
		]);

		$this->Api->expects($this->once())
			->method('collectParams')
			->with(['test'])
			->will($this->returnValue([
				'test' => 'testValue',
			]));
		$result = $this->Api->collectParam('test');
		$this->assertSame('testValue', $result);
	}

	/**
	 * test _walkMap() method
	 *
	 * @test
	 */
	public function _walkMap() {
		$this->generateComponent();

		// Normal
		$this->Api->recordMap = [
			'User' => [
				'id',
				'name',
				'email',
			],
		];
		$this->Api->dispatchMethod('_walkMap', [function ($paramsField, $alias, $recordField, $wrap) {
			$this->assertSame($paramsField, $recordField);
			$this->assertContains($paramsField, [
				'id',
				'name',
				'email',
			]);
			$this->assertSame('User', $alias);
			$this->assertSame(null, $wrap);
		}]);

		// _wrap
		$this->Api->recordMap = [
			'User' => [
				'_wrap' => 'user',
				'id',
				'name',
				'email',
			],
		];
		$this->Api->dispatchMethod('_walkMap', [function ($paramsField, $alias, $recordField, $wrap) {
			$this->assertSame($paramsField, $recordField);
			$this->assertContains($paramsField, [
				'id',
				'name',
				'email',
			]);
			$this->assertSame('User', $alias);
			$this->assertSame('user', $wrap);
		}]);

		// alias
		$this->Api->recordMap = [
			'User' => [
				'id' => 'user_id',
				'name' => 'user_name',
				'email' => 'user_email',
			],
		];
		$this->Api->dispatchMethod('_walkMap', [function ($paramsField, $alias, $recordField, $wrap) {
			$this->assertNotSame($paramsField, $recordField);
			$this->assertContains($paramsField, [
				'user_id',
				'user_name',
				'user_email',
			]);
			$this->assertContains($recordField, [
				'id',
				'name',
				'email',
			]);
			$this->assertSame('User', $alias);
			$this->assertSame(null, $wrap);
		}]);

		// array map
		$this->Api->recordMap = [
			'User' => [
				'id' => [],
			],
		];
		$this->Api->dispatchMethod('_walkMap', [function ($paramsField, $alias, $recordField, $wrap) {
			$this->assertNotSame($paramsField, $recordField);
			$this->assertSame([], $paramsField);
			$this->assertSame('id', $recordField);
			$this->assertSame('User', $alias);
			$this->assertSame(null, $wrap);
		}]);
	}

	/**
	 * test _mapKeys() method
	 *
	 * @test
	 */
	public function _mapKeys() {
		$this->generateComponent();

		// Normal
		$this->Api->recordMap = [
			'User' => [
				'id',
				'name',
				'email',
			],
		];
		$options = [];
		$result = $this->Api->dispatchMethod('_mapKeys', [$options]);
		$this->assertSame([
			'id',
			'name',
			'email',
		], $result);

		// alias
		$this->Api->recordMap = [
			'User' => [
				'id' => 'user_id',
				'name' => 'user_name',
				'email' => 'user_email',
			],
		];
		$options = [];
		$result = $this->Api->dispatchMethod('_mapKeys', [$options]);
		$this->assertSame([
			'user_id',
			'user_name',
			'user_email',
		], $result);

		// array map
		$this->Api->recordMap = [
			'User' => [
				'id' => [],
			],
		];
		$options = [];
		$result = $this->Api->dispatchMethod('_mapKeys', [$options]);
		$this->assertSame([
			'id',
		], $result);

		// Includes
		$this->Api->recordMap = [
			'User' => [
				'id',
				'name',
				'email',
			],
		];
		$options = ['includes' => ['name', 'email']];
		$result = $this->Api->dispatchMethod('_mapKeys', [$options]);
		$this->assertSame([
			'name',
			'email',
		], array_values($result));

		// Excludes
		$options = ['excludes' => ['name', 'email']];
		$result = $this->Api->dispatchMethod('_mapKeys', [$options]);
		$this->assertSame([
			'id',
		], array_values($result));

		// Combination
		$options = ['includes' => ['name', 'email'], 'excludes' => ['name']];
		$result = $this->Api->dispatchMethod('_mapKeys', [$options]);
		$this->assertSame([
			'email',
		], array_values($result));
	}

	/**
	 * test requireParamsFromMap() method
	 *
	 * @test
	 */
	public function requireParamsFromMap() {
		$this->generateComponent([
			'mocks' => [
				'request' => [
					'is'
				],
			],
		]);
		$this->request->expects($this->any())
			->method('is')
			->with('get')
			->will($this->returnValue(true));
		$this->Api->recordMap = [
			'User' => [
				'id',
				'name',
				'email',
			],
		];

		try {
			$this->Api->requireParamsFromMap();
			$this->fail('Expected LackParametersException was not thrown');
		} catch (PHPUnit_Framework_AssertionFailedError $e) {
			throw $e;
		} catch (Exception $e) {
			$this->assertInstanceOf('LackParametersException', $e);
			$this->assertSame('id, name, email', $e->getMessage());
		}

		$this->request->query = [
			'id' => 1,
			'name' => 'hiromi',
		];
		try {
			$this->Api->requireParamsFromMap();
			$this->fail('Expected LackParametersException was not thrown');
		} catch (PHPUnit_Framework_AssertionFailedError $e) {
			throw $e;
		} catch (Exception $e) {
			$this->assertInstanceOf('LackParametersException', $e);
			$this->assertSame('email', $e->getMessage());
		}

		$this->request->query['email'] = 'hiromi2424@exmaple.com';
		$result = $this->Api->requireParamsFromMap();
		$this->assertSame([
			'id' => 1,
			'name' => 'hiromi',
			'email' => 'hiromi2424@exmaple.com',
		], $result);
	}

	/**
	 * test collectParamsFromMap() method
	 *
	 * @test
	 */
	public function collectParamsFromMap() {
		$this->generateComponent([
			'mocks' => [
				'request' => [
					'is'
				],
			],
		]);
		$this->request->expects($this->any())
			->method('is')
			->with('get')
			->will($this->returnValue(true));
		$this->Api->recordMap = [
			'User' => [
				'id',
				'name',
				'email',
			],
		];

		$result = $this->Api->collectParamsFromMap();
		$this->assertSame([], $result);

		$this->request->query = [
			'id' => 1,
			'name' => 'hiromi',
		];
		$result = $this->Api->collectParamsFromMap();
		$this->assertSame([
			'id' => 1,
			'name' => 'hiromi',
		], $result);
	}

}
