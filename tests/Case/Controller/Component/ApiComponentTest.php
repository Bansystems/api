<?php

namespace Api\Test\Controller\Component;

use Cake\Network\Request;
use Cake\Network\Response;
use Cake\Controller;
use Cake\Core\Configure;
use Cake\ComponentRegistry;
use Cake\Event\Event;
use Cake\Utility\Hash;
use Api\Controller\Component\ApiComponent;
use Api\Error\ApiError;

use Cake\TestSuite\TestCase;
class ApiComponentTest extends TestCase {

	/**
	 * Api component instance to test
	 *
	 * @var Controller
	 */
	public $Api;

	/**
	 * Component registry instance for test
	 *
	 * @var ComponentRegistry
	 */
	public $registry;

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
	public $debug;

	public function setUp()
	{
		$this->debug = Configure::read('debug');
		Configure::write('debug', true);
	}

	/**
	 * tear down for each cases
	 *
	 * @return void
	 */
	public function tearDown() {
		unset(
			$this->Api,
			$this->registry,
			$this->controller,
			$this->request,
			$this->response
		);
		parent::tearDown();
		Configure::write('debug', $this->debug);
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
		], $options);
		extract($options);

		$this->request = $this->getMockBuilder('Cake\Network\Request')
			->setConstructorArgs([['url' => '/']])
			->setMethods(Hash::get($mocks, 'request'))
			->getMock();
		$this->response = $this->getMockBuilder('Cake\Network\Response')
			->setMethods(Hash::get($mocks, 'response'))
			->getMock();
		$this->controller = $this->getMockBuilder('Cake\Controller\Controller')
			->setConstructorArgs([$this->request, $this->response])
			->setMethods(Hash::get($mocks, 'controller'))
			->getMock();
		$this->registry = $this->getMockBuilder('Cake\Controller\ComponentRegistry')
			->setConstructorArgs([$this->controller])
			->setMethods(Hash::get($mocks, 'registry'))
			->getMock();
		$this->Api = $this->getMockBuilder('Api\Controller\Component\ApiComponent')
			->setConstructorArgs([$this->registry, $componentOptions])
			->setMethods(Hash::get($mocks, 'Api'))
			->getMock();

		return $this->Api;
	}

	/**
	 * test isApiRequest() method
	 *
	 * @test
	 */
	public function isApiRequest() {
		$this->generateComponent();
		$this->Api->initialize([]);
		$this->request->prefix = 'api';
		$this->assertTrue($this->Api->isApiRequest());

		$this->request->prefix = null;
		$this->assertFalse($this->Api->isApiRequest());
	}

	/**
	 * test initialize() method
	 *
	 * @test
	 */
	public function initialize() {
		$classVars = get_class_vars('Api\Controller\Component\ApiComponent');
		$configMethods = array_keys($classVars['configMethods']);
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
		$this->Api->initialize([]);
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
			'initialize' => true,
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
		$event = new Event('Dummy');
		$this->Api->beforeRender($event);
		$this->assertSame(401, $this->response->statusCode());
		$this->assertArrayHasKey('response', $this->controller->viewVars);
		$this->assertSame([
			'code' => 401,
		], $this->controller->viewVars['response']);
		$this->assertArrayHasKey('_serialize', $this->controller->viewVars);
		$this->assertSame('response', $this->controller->viewVars['_serialize']);
		$this->assertSame('Json', $this->controller->viewBuilder()->className());
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
		$this->controller->Auth = new \stdclass;
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

		Configure::delete('TEST_API_VERSION');

		$this->generateComponent([
			'mocks' => [
				'request' => [
					'header',
				],
			],
		]);
		$this->request->expects($this->once())
			->method('header')
			->with('APIVersion')
			->will($this->returnValue('3.5'));
		$this->Api->dispatchMethod('_handleVersion', [$this->controller]);
		$this->assertSame('3.5', $this->Api->version);

		//ClassRegistry::config('Model', $modelConfig);
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
		} catch (\Exception $e) {
			$this->assertInstanceOf('Cake\Network\Exception\ForbiddenException', $e);
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
		} catch (\Exception $e) {
			$this->assertInstanceOf('Api\Error\LackParametersException', $e);
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

		// Wrap
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

		// Alias
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

		// Array map
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

		// Alias
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

		// Array map
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
		} catch (\PHPUnit_Framework_AssertionFailedError $e) {
			throw $e;
		} catch (\Exception $e) {
			$this->assertInstanceOf('Api\Error\LackParametersException', $e);
			$this->assertSame('id, name, email', $e->getMessage());
		}

		$this->request->query = [
			'id' => 1,
			'name' => 'hiromi',
		];
		try {
			$this->Api->requireParamsFromMap();
			$this->fail('Expected LackParametersException was not thrown');
		} catch (\PHPUnit_Framework_AssertionFailedError $e) {
			throw $e;
		} catch (\Exception $e) {
			$this->assertInstanceOf('Api\Error\LackParametersException', $e);
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

	/**
	 * test normalizeParamForArray() method
	 *
	 * @test
	 */
	public function normalizeParamForArray() {
		$this->generateComponent();
		$this->assertSame([], $this->Api->normalizeParamForArray(''));
		$this->assertSame([], $this->Api->normalizeParamForArray(['']));
		$this->assertSame(['string'], $this->Api->normalizeParamForArray('string'));
		$this->assertSame(['array'], $this->Api->normalizeParamForArray(['array']));
	}

	/**
	 * test paramsToRecord() method
	 *
	 * @test
	 */
	public function paramsToRecord() {
		$this->generateComponent();

		// No map
		$result = $this->Api->paramsToRecord([
			'id' => 1,
			'name' => 'hiromi',
			'email' => 'hiromi2424@exmaple.com',
		]);
		$this->assertSame([], $result);

		// Normal
		$this->Api->recordMap = [
			'User' => [
				'id',
				'name',
				'email',
			],
		];
		$result = $this->Api->paramsToRecord([
			'id' => 1,
			'name' => 'hiromi',
			'email' => 'hiromi2424@exmaple.com',
		]);
		$this->assertSame([
			'User' => [
				'id' => 1,
				'name' => 'hiromi',
				'email' => 'hiromi2424@exmaple.com',
			],
		], $result);

		// Alias
		$this->Api->recordMap = [
			'User' => [
				'id' => 'user_id',
				'name' => 'user_name',
				'email' => 'user_email',
			],
		];
		$result = $this->Api->paramsToRecord([
			'user_id' => 1,
			'user_name' => 'hiromi',
			'user_email' => 'hiromi2424@exmaple.com',
		]);
		$this->assertSame([
			'User' => [
				'id' => 1,
				'name' => 'hiromi',
				'email' => 'hiromi2424@exmaple.com',
			],
		], $result);

		// Array map(assumes hasAndBelongsToMany)
		$this->Api->recordMap = [
			'User' => [
				'id',
				'name',
				'email',
			],
			'Like' => [
				'likes' => [],
			],
		];
		$params = [
			'id' => 1,
			'name' => 'hiromi',
			'email' => 'hiromi2424@exmaple.com',
			'likes' => [1, 2, 3],
		];
		$result = $this->Api->paramsToRecord($params);
		$this->assertSame([
			'User' => [
				'id' => 1,
				'name' => 'hiromi',
				'email' => 'hiromi2424@exmaple.com',
			],
			'Like' => [1, 2, 3],
		], $result);

		// Array map with field(assumes hasMany)
		$this->Api->recordMap['Like'] = [
			'likes' => ['toy_id'],
		];
		$result = $this->Api->paramsToRecord($params);
		$this->assertSame([
			'User' => [
				'id' => 1,
				'name' => 'hiromi',
				'email' => 'hiromi2424@exmaple.com',
			],
			'Like' => [
				['toy_id' => 1],
				['toy_id' => 2],
				['toy_id' => 3],
			],
		], $result);

		$this->Api->recordMap['Like'] = [
			'' => [],
		];
		try {
			$this->Api->paramsToRecord($params);
			$this->fail('Expected DomainException was not thrown');
		} catch (\PHPUnit_Framework_AssertionFailedError $e) {
			throw $e;
		} catch (\Exception $e) {
			$this->assertInstanceOf('\DomainException', $e);
		}
	}

	/**
	 * test recordToParams() method
	 *
	 * @test
	 */
	public function recordToParams() {
		$this->generateComponent();

		$record = [
			'User' => [
				'id' => 1,
				'name' => 'hiromi',
				'email' => 'hiromi2424@exmaple.com',
			],
		];
		// No map
		$result = $this->Api->recordToParams($record);
		$this->assertSame([], $result);

		// Normal
		$this->Api->recordMap = [
			'User' => [
				'id',
				'name',
				'email',
			],
		];
		$result = $this->Api->recordToParams($record);
		$this->assertSame([
			'id' => 1,
			'name' => 'hiromi',
			'email' => 'hiromi2424@exmaple.com',
		], $result);

		// Wrap
		$this->Api->recordMap['User']['_wrap'] = 'user';
		$result = $this->Api->recordToParams($record);
		$this->assertSame([
			'user' => [
				'id' => 1,
				'name' => 'hiromi',
				'email' => 'hiromi2424@exmaple.com',
			],
		], $result);

		// Alias
		$this->Api->recordMap = [
			'User' => [
				'id' => 'user_id',
				'name' => 'user_name',
				'email' => 'user_email',
			],
		];
		$result = $this->Api->recordToParams($record);
		$this->assertSame([
			'user_id' => 1,
			'user_name' => 'hiromi',
			'user_email' => 'hiromi2424@exmaple.com',
		], $result);

		// Array map(assumes hasAndBelongsToMany)
		$this->Api->recordMap = [
			'User' => [
				'id',
				'name',
				'email',
			],
			'Like' => [
				'likes' => [],
			],
		];
		$record = [
			'User' => [
				'id' => 1,
				'name' => 'hiromi',
				'email' => 'hiromi2424@exmaple.com',
			],
			'Like' => [
				['id' => 1, 'toy_id' => 4],
				['id' => 2, 'toy_id' => 5],
				['id' => 3, 'toy_id' => 6],
			],
		];
		$result = $this->Api->recordToParams($record);
		$this->assertSame([
			'id' => 1,
			'name' => 'hiromi',
			'email' => 'hiromi2424@exmaple.com',
			'likes' => [
				['id' => 1, 'toy_id' => 4],
				['id' => 2, 'toy_id' => 5],
				['id' => 3, 'toy_id' => 6],
			],
		], $result);

		// Array map with field(assumes hasMany)
		$this->Api->recordMap['Like'] = [
			'likes' => ['toy_id'],
		];
		$result = $this->Api->recordToParams($record);
		$this->assertSame([
			'id' => 1,
			'name' => 'hiromi',
			'email' => 'hiromi2424@exmaple.com',
			'likes' => [4, 5, 6],
		], $result);
	}

	/**
	 * test convertBoolean() method
	 *
	 * @test
	 */
	public function convertBoolean() {
		$this->assertSame(true, ApiComponent::convertBoolean('yes'));
		$this->assertSame(false, ApiComponent::convertBoolean('no'));
		$this->AssertSame([
			true,
			true,
			true,
			true,
			true,
			false,
			false,
			false,
			false,
			false,
		], ApiComponent::convertBoolean([
			true,
			'yes',
			'true',
			'1',
			1,
			false,
			'false',
			'no',
			'0',
			0,
		]));
	}

	/**
	 * test setResponse() method
	 *
	 * @test
	 */
	public function setResponse() {
		$this->generateComponent();
		$this->Api->setResponse('one', 'two');
		$this->assertSame([
			'one' => 'two',
		], $this->Api->getResponse());

		$this->Api->setResponse(['three' => 3]);
		$this->assertSame([
			'one' => 'two',
			'three' => 3,
		], $this->Api->getResponse());

		$this->Api->setResponse([]);
		$this->assertSame([], $this->Api->getResponse());

		$this->Api->setResponse([
			'Hashed' => [
				'Array' => [1, 2, 3],
			],
		]);
		$this->Api->setResponse([
			'Hashed' => [
				'Array' => [4, 5, 6],
			],
		]);
		$this->assertSame([
			'Hashed' => [
				'Array' => [1, 2, 3, 4, 5, 6],
			],
		], $this->Api->getResponse());

		$this->Api->setResponse(null);
		$this->assertSame([], $this->Api->getResponse());

		$this->Api->setResponse('Nested.Array.key', 'value');
		$this->assertSame([
			'Nested' => [
				'Array' => [
					'key' => 'value',
				],
			],
		], $this->Api->getResponse());

		$this->Api->setResponse('Nested.Array.key2', 'value2');
		$this->assertSame([
			'Nested' => [
				'Array' => [
					'key' => 'value',
					'key2' => 'value2',
				],
			],
		], $this->Api->getResponse());

		$this->Api->setResponse(false);
		$this->assertSame([], $this->Api->getResponse());
	}

	/**
	 * test success() method
	 *
	 * @test
	 */
	public function success() {
		$this->generateComponent();
		$this->Api->success();
		$this->assertSame([
			'success' => true,
			'code' => 200,
		], $this->Api->getResponse());

		// with data
		$this->generateComponent();
		$this->Api->success([
			'user' => [
				'id' => 1,
			],
		]);
		$this->assertSame([
			'success' => true,
			'code' => 200,
			'data' => [
				'user' => [
					'id' => 1,
				],
			],
		], $this->Api->getResponse());
	}

	/**
	 * test failure() method
	 *
	 * @test
	 */
	public function failure() {
		$this->generateComponent();
		$this->Api->failure(ApiError::VALIDATION_ERROR);
		$this->assertSame([
			'success' => false,
			'code' => 500,
			'errorCode' => ApiError::VALIDATION_ERROR,
			'errorMessage' => ApiError::message(ApiError::VALIDATION_ERROR),
		], $this->Api->getResponse());

		$this->generateComponent();
		$this->Api->failure(ApiError::VALIDATION_ERROR, 501);
		$this->assertSame(501, $this->Api->getResponse()['code']);
	}

	/**
	 * test getResponse() method
	 *
	 * @test
	 */
	public function getResponse() {
		$this->generateComponent();
		$this->Api->setResponse([
			'very' => [
				'deep' => [
					'array' => [
						'test',
					],
				],
			],
		]);
		$this->assertSame('test', $this->Api->getResponse('very.deep.array.0'));
	}

}
