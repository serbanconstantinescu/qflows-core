<?php

/*
    CLD: 2026-11-25T21:00:00+02:00: a GitHub account, for the GitHub steps and event trigger (util\GitHub): github.com or GitHub Enterprise Server (its API address), signed in
    with a personal access token (fine-grained or classic) or as a GitHub App (its ID, private key, and an installation: given, else the default repository's, else the
    app's only one); secrets encrypted. A default repository (owner/repo) for the steps that name none. Test: who the token is (user), or the app and its installation. Provides `github`
*/

namespace models\credential;

class CredentialGitHub extends \models\Credential {
    public static $label = 'GitHub';
    public static $icon = 'bi-github';
    public static $group = 'Development';
    public static $provides = [ 'github' ];
    public static $testable = true;

    const AUTHS = [ 'token' => 'A personal access token', 'app' => 'A GitHub App' ];

    public function getSchema() {
	$app = [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'app' ];
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'apiUrl',
		'type' => 'text',
		'label' => 'API address',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Empty: github.com. GitHub Enterprise Server: https://<host>/api/v3',
		'validate' => [ [ 'regex', 'pattern' => '^https://[A-Za-z0-9.-]+(:\d{1,5})?(/api/v3)?/?$', 'skipEmpty' => true, 'message' => 'https://<host>/api/v3' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'github.com' ],
	    ],
	    [
		'key' => 'auth',
		'type' => 'select',
		'label' => 'Sign in with',
		'required' => true,
		'default' => 'token',
		'choices' => self::AUTHS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'token',
		'type' => 'password',
		'label' => 'Token',
		'encrypt' => true,
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'token' ] ],
		'help' => 'Settings > Developer settings > Personal access tokens: fine-grained (the repositories and permissions the steps need) or classic (repo, workflow)',
		'ui' => [ 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'token' ] ],
	    ],
	    [
		'key' => 'appId',
		'type' => 'text',
		'label' => 'App ID',
		'default' => '',
		'transform' => 'trim',
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'app' ] ],
		'help' => 'The app\'s settings page: App ID (or its Client ID)',
		'validate' => [ [ 'regex', 'pattern' => '^([0-9]{1,20}|Iv[0-9A-Za-z.]{8,40})$', 'skipEmpty' => true, 'message' => 'A number, or a Client ID (Iv…)' ] ],
		'ui' => [ 'width' => 6, 'showIf' => $app ],
	    ],
	    [
		'key' => 'installationId',
		'type' => 'text',
		'label' => 'Installation ID',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Optional: the number at the end of the installation\'s settings address; empty: the default repository\'s installation, else the app\'s only one',
		'validate' => [ [ 'regex', 'pattern' => '^[0-9]{1,20}$', 'skipEmpty' => true, 'message' => 'A number' ] ],
		'ui' => [ 'width' => 6, 'showIf' => $app ],
	    ],
	    [
		'key' => 'privateKey',
		'type' => 'textarea',
		'label' => 'Private key',
		'encrypt' => true,
		'requiredIf' => [ 'if' => 'auth', 'in' => [ 'app' ] ],
		'help' => 'The app\'s Private keys > Generate a private key: the whole .pem file (-----BEGIN RSA PRIVATE KEY----- …)',
		'ui' => [ 'rows' => 5, 'showIf' => $app ],
	    ],
	    [
		'key' => 'repository',
		'type' => 'text',
		'label' => 'Default repository',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Optional: owner/repository, the steps\' when they name none, e.g. acme/website',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$', 'skipEmpty' => true, 'message' => 'owner/repository' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the certificate',
		'default' => false,
		'help' => 'Enterprise Server with a self-signed certificate',
		'ui' => [ 'width' => 6, 'advanced' => true ],
	    ],
	]);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	if (($this->auth ?? 'token') === 'app' && trim((string)$this->privateKey) !== '' && openssl_pkey_get_private(trim((string)$this->privateKey)) === false) {
	    $this->errors('privateKey', 'not a PEM private key (the app\'s .pem file)');
	    $valid = false;
	}
	return $valid;
    }

    public function client($aid = null, $timeout = 60) {
	return new \util\GitHub($this, $aid ?? $this->aid, $timeout);
    }

    public function test() {
	$g = $this->client(null, 20);
	$where = $g->isCloud() ? 'github.com' : \util\Http::shown($g->api());
	try {
	    if (($this->auth ?? 'token') === 'app') {
		$app = $g->request('GET', '/app', [], null, [ 'jwt' => true ]);
		$repos = $g->request('GET', '/installation/repositories', [ 'per_page' => 1 ]);
		return "connected to {$where} as the app " . ($app['name'] ?? '?') . ', installed with access to ' . \util\Format::plural((int)($repos['total_count'] ?? 0), 'repository', 'repositories');
	    }
	    [ , $h, $me ] = $g->request('GET', '/user', [], null, [ 'full' => true ]);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$scopes = trim((string)($h['x-oauth-scopes'] ?? ''));
	return "connected to {$where} as " . ($me['login'] ?? '?') . ($scopes !== '' ? " (scopes: {$scopes})" : ' (a fine-grained token)');
    }
}
