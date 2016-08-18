# APIコンポーネント

[![Build Status](https://secure.travis-ci.org/hiromi2424/api.png?branch=3.0)](http://travis-ci.org/hiromi2424/api)
[![Coverage Status](https://img.shields.io/codecov/c/github/hiromi2424/api.svg?style=flat-square)](https://codecov.io/github/hiromi2424/api)
[![Downloads](https://poser.pugx.org/hiromi2424/api/d/total.png)](https://packagist.org/packages/hiromi2424/api)
[![Latest Version](https://poser.pugx.org/hiromi2424/api/v/stable.png)](https://packagist.org/packages/hiromi2424/api)
[![License](https://poser.pugx.org/hiromi2424/api/license.svg)](https://packagist.org/packages/hiromi2424/api)

:warning: 3.0ブランチはドキュメント整備中です。実装とテストは更新済ですが、いくつかの2系で使えていた機能が削除されています。

## 要件

    CakePHP 3.1*
    PHP 5.5+

## 概要

CakePHPでREST APIを実現するためのコンポーネントと、その付属ライブラリ群です。
基本的にはコンポーネントを設置し、`Routing.prefixes`に`'api'`を含めるだけで使えます。

### セットアップ

- `app/Config/routes.php` で以下のルートを作成する

```php
Router::prefix('api', function ($routes) {
    // ルート定義
});
```

- `Plugin::load('Api');` OR `Plugin::loadAll()` を `app/config/bootstarp.php` で設定
- 使いたいコントローラ(OR `AppController`)で以下のように設定

```php
$components = [
	'Api.Api'
];
// または
$this->loadComponent('Api.Api');
  ```

### コーディング例

#### 新規登録（メールアドレス）

```php
/**
 * signup api
 *
 * @return void
 */
public function signup()
{
	$this->request->onlyAllow('post');
	$this->Api->recordMap = [
		'User' => [
			'name',
			'email',
			'password',
		],
	];
	$params = $this->Api->requireParamsFromMap();
	$data = $this->Api->paramsToRecord($params);

	$this->Api->processSaveRecord($data, [
		'saveCallback' => [$this->User, 'signup'],
	]);
}
```

#### ログイン

```php
/**
 * login api
 *
 * @return void
 */
public function login()
{
	$this->request->onlyAllow('post');

	$data = $this->Api->requireParams([
		'email',
		'password',
	]);
	$this->request->data = ['User' => $data];
	$this->Auth->logout();
	$this->User->useValidationSet(['Login']);
	$this->User->create($this->request->data);
	$loggedIn = $this->User->validates() && $this->Auth->login();
	if ($loggedIn) {
		$this->Api->success();
	} else {
		$this->Api->raiseValidationErrors();
	}
}
```

#### コメント取得

```php
App::uses('LackParametersException', 'Api.Error');

Class CommentsController extends AppController {

	/**
	 * index api
	 *
	 * @return void
	 */
	public function api_index($postId = null) {
		$this->request->onlyAllow('get');
		if ($postId === null) {
			throw new LackParametersException('postId');
		}

		$this->Api->recordMap = [
			'Comment' => [
				'id',
				'body',
				'created',
				'updated',
			],
			'User' => [
				'_wrap' => 'user',
				'id',
				'name',
			],
		];

		$this->Comment->create();
		$this->Comment->set('post_id', $postId);
		if (!$this->Comment->validates(['fieldList' => ['post_id']])) {
			$this->Api->recordMap = ['Comment' => ['post_id']];
			return $this->Api->processValidationErrors();
		}

		$limit = 20;
		$page = $this->Api->collectParam('page');
		$page = $page ? (int)$page : 1;
		$contain = ['User'];
		$comments = $this->Comment->findAllByPostId($postId, compact('limit', 'page', 'contain'));

		foreach ($comments as &$comment) {
			$comment = $this->Api->recordToParams($comment);
		}
		$this->Api->success(compact([
			'page',
			'limit',
			'comments',
		]));
	}

}
```

### リクエストの取り扱い

リクエストパラメータとして、GETの場合クエリストリングを、その他の場合はPOST BODYを取り扱います。
`$this->request->query` か `$this->request->data` を見ると言い換えることもできます。

### レスポンス概要

- JSONのみサポートします。
- レスポンス構造は固定です。

レスポンス例（成功）

```json
{
    "success": true,
    "code": 200,
    "data": {
        "user": {
            "id": "2081",
            "last_name": "shimizu",
            "first_name": "hiroki"
        },
    }
}
```

レスポンス例（失敗）

```json
{
    "success": false,
    "code": 400,
    "errorCode": "validation_error",
    "errorMessage": "バリデーションエラー",
    "validationErrors": {
        "id": [
            [
                "exists",
                "valid"
            ]
        ]
    }
}
```

### 注意事項

- 汎用的な作りにはなっていません。
- 既存のアプリケーションに適用するのは困難です
- ドキュメント化されていない不完全な機能が一部あります(habtm、hasMany対応など）
	- 通常使用には問題ありません
