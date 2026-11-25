<?php

/*
    CLD: 2026-11-26T02:00:00+02:00: an Okta org, for the Okta steps and event trigger (util\Okta): its address, and an API token (Security > API > Tokens: it acts with the role of
    the admin who made it) or an OAuth 2.0 service app (client ID, a private key whose public key is the app's, its key ID, the scopes granted; the app given an admin role);
    secrets encrypted. Test: the org's name (an API token: also who made it). Provides `okta`
*/

namespace models\credential;

class CredentialOkta extends \models\Credential {
    public static $label = 'Okta';
    public static $icon = 'bi-shield-lock';
    public static $group = 'Directories';
    public static $provides = [ 'okta' ];
    public static $testable = true;

    const AUTHS = [ 'token' => 'An API token', 'oauth' => 'An OAuth service app (private key)' ];

    public function getSchema() {
	$oauth = [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'oauth' ];
	return array_merge(parent::getSchema(), [
	    [ 'key' => 'orgUrl', 'type' => 'text', 'label' => 'Org address', 'required' => true, 'default' => '', 'transform' => 'trim',
		'help' => 'e.g. https://acme.okta.com (not the -admin address), or the org\'s custom domain',
		'validate' => [ [ 'regex', 'pattern' => '^https://[A-Za-z0-9.-]+(:\d{1,5})?/?$', 'skipEmpty' => true, 'message' => 'https://<org>.okta.com' ] ], 'ui' => [ 'width' => 6, 'placeholder' => 'https://acme.okta.com' ] ],
	    [ 'key' => 'auth', 'type' => 'select', 'label' => 'Sign in with', 'required' => true, 'default' => 'token', 'choices' => self::AUTHS, 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'apiToken', 'type' => 'password', 'label' => 'API token', 'encrypt' => true, 'requiredIf' => [ 'if' => 'auth', 'in' => [ 'token' ] ],
		'help' => 'Security > API > Tokens > Create token: it acts with its admin\'s role (e.g. a Group or Org Administrator made for qflows)',
		'ui' => [ 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'token' ] ] ],
	    [ 'key' => 'clientId', 'type' => 'text', 'label' => 'Client ID', 'default' => '', 'transform' => 'trim', 'requiredIf' => [ 'if' => 'auth', 'in' => [ 'oauth' ] ],
		'help' => 'An API Services app: its Client ID', 'ui' => [ 'width' => 6, 'showIf' => $oauth ] ],
	    [ 'key' => 'keyId', 'type' => 'text', 'label' => 'Key ID', 'default' => '', 'transform' => 'trim', 'help' => 'Optional: the public key\'s KID in the app', 'ui' => [ 'width' => 6, 'showIf' => $oauth ] ],
	    [ 'key' => 'privateKey', 'type' => 'textarea', 'label' => 'Private key', 'encrypt' => true, 'requiredIf' => [ 'if' => 'auth', 'in' => [ 'oauth' ] ],
		'help' => 'PEM (-----BEGIN PRIVATE KEY----- …): the private half of the app\'s public key', 'ui' => [ 'rows' => 5, 'showIf' => $oauth ] ],
	    [ 'key' => 'scopes', 'type' => 'text', 'label' => 'Scopes', 'default' => '', 'help' => 'Optional: those granted to the app (Okta API Scopes), separated by spaces; empty: users, groups, apps and logs',
		'ui' => [ 'showIf' => $oauth ] ],
	]);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	if (($this->auth ?? 'token') === 'oauth' && trim((string)$this->privateKey) !== '' && openssl_pkey_get_private(trim((string)$this->privateKey)) === false) {
	    $this->errors('privateKey', 'not a PEM private key (unencrypted)');
	    $valid = false;
	}
	if (preg_match('~-admin\.(okta|oktapreview|okta-emea)\.com~i', (string)$this->orgUrl)) {
	    $this->errors('orgUrl', 'the org\'s address, without -admin');
	    $valid = false;
	}
	return $valid;
    }

    public function client($aid = null, $timeout = 60) {
	return new \util\Okta($this, $aid ?? $this->aid, $timeout);
    }

    public function test() {
	$o = $this->client(null, 20);
	try {
	    $org = $o->request('GET', '/org', [], null, [ 'ok' => [ 403 ] ]);
	    $me = ($this->auth ?? 'token') === 'token' ? $o->request('GET', '/users/me', [], null, [ 'ok' => [ 403, 404 ] ]) : [];
	    if (!isset($org['companyName']))
		$o->request('GET', '/users', [ 'limit' => 1 ]);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'connected to ' . (string)($org['companyName'] ?? $o->org()) . (isset($me['profile']['login']) ? ' as ' . $me['profile']['login'] : '');
    }
}
