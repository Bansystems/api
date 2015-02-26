<?php

App::uses('FormAuthenticate', 'Controller/Component/Auth');
App::uses('UnauthorizedException', 'Api.Error');

class ApiAuthenticate extends FormAuthenticate {

	public function unauthenticated(CakeRequest $request, CakeResponse $response) {
		throw new UnauthorizedException;
	}

}
