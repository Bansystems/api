<?php

App::uses('FormAuthenticate', 'Controller/Component/Auth');

class ApiAuthenticate extends FormAuthenticate {

	public function unauthenticated(CakeRequest $request, CakeResponse $response) {
		throw new UnauthorizedException;
	}

}
