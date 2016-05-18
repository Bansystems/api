<?php

namespace Api\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class UserFixture extends TestFixture {

	public $name = 'User';

	public $records = [
		[
			'id' => 1,
			'name' => 'Lorem ipsum dolor sit amet',
			'email' => 'Lorem ipsum dolor sit amet',
			'password' => 'Lorem ipsum dolor sit amet',
			'created' => '2015-01-27 11:54:22',
			'modified' => '2015-01-27 11:54:22',
		],
	];
}

