<?php

namespace Api\Error;

class ApiError {

	const UNAUTHENTICATED = 'unauthenticated';
	const NOT_AUTHENTICATED = 'not_authenticated';
	const NOT_IMPLEMENTED = 'not_implemented';
	const LACK_PARAMETERS = 'lack_parameters';
	const INVALID_PARAMETERS = 'invalid_parameters';
	const VALIDATION_ERROR = 'validation_error';
	const EMAIL_NOTFOUND = 'email_notfound';
	const METHOD_NOT_ALLOWED = 405;
	const UNKNOWN = 'unknown';

	protected static $_messages = [];

	protected static function _initMessages() {
		if (!static::$_messages) {
			static::$_messages = [
				static::UNAUTHENTICATED => 'メールアドレスまたはパスワードが異なります',
				static::NOT_AUTHENTICATED => 'ログインが必要です',
				static::NOT_IMPLEMENTED => 'このAPIは実装されていません',
				static::LACK_PARAMETERS => '必要なパラメータが指定されませんでした',
				static::INVALID_PARAMETERS => 'パラメータが正しくありません',
				static::VALIDATION_ERROR => 'バリデーションエラー',
				static::EMAIL_NOTFOUND => 'メールアドレスが存在しません',
				static::METHOD_NOT_ALLOWED => 'リクエストメソッドが不正です',
				static::UNKNOWN => 'システムエラー',
			];
		}
	}

	public static function messages() {
		static::_initMessages();
		return static::$_messages;
	}

	public static function message($code) {
		static::_initMessages();
		if (isset(static::$_messages[$code])) {
			return static::$_messages[$code];
		}
	}

}
