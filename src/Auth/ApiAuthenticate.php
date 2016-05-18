<?php

namespace Api\Auth;

use Cake\Auth\FormAuthenticate;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\Network\Exception\UnauthorizedException;

class ApiAuthenticate extends FormAuthenticate {

	public function unauthenticated(Request $request, Response $response) {
		throw new UnauthorizedException;
	}

}
