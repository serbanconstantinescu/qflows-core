<?php

/*
    a PatchMon server (patchmon.net), for the PatchMon steps and host trigger (plugins\ITOps\util\PatchMon): the server's address, an API token (Settings > Integrations >
    New token, usage API: its key, patchmon_ae_..., and secret, encrypted; scope host: get, plus host: delete to delete hosts) and, for the Patching step only, a PatchMon user
    and password (encrypted; a user with Manage patching and without two-factor sign-in), the server's certificate trusted or not. A server on a private address needs the
    account's "Integrations may connect to private network addresses". Test: the hosts the token sees; the user signs in (and out) when there is one. Provides `patchmon`
*/

namespace plugins\ITOps\models\credential;

class CredentialPatchMon extends \models\Credential {
    public static $label = 'PatchMon';
    public static $icon = 'bi-bandaid';
    public static $group = 'Monitoring';
    public static $provides = [ 'patchmon' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'serverUrl',
		'type' => 'text',
		'label' => 'Server',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The PatchMon server\'s address, as you open it in the browser',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?/?$', 'skipEmpty' => true, 'message' => 'https://<server>[:port], without a path' ] ],
		'ui' => [ 'placeholder' => 'https://patchmon.example.com' ],
	    ],
	    [
		'key' => 'tokenKey',
		'type' => 'text',
		'label' => 'API token key',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'Settings > Integrations > New token, usage API; scope host: get (and delete, to delete hosts)',
		'validate' => [ [ 'regex', 'pattern' => '^[^\s:]{1,200}$', 'skipEmpty' => true, 'message' => 'The token key, e.g. patchmon_ae_...' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'patchmon_ae_...' ],
	    ],
	    [
		'key' => 'tokenSecret',
		'type' => 'password',
		'label' => 'API token secret',
		'required' => true,
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'User (for patching)',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Only for the PatchMon Patching step: a user with Manage patching, without two-factor sign-in',
		'validate' => [ [ 'regex', 'pattern' => '^[^\s]{1,255}$', 'skipEmpty' => true, 'message' => 'A user name or email address, without spaces' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the server\'s certificate',
		'default' => false,
		'help' => 'Only for a self-signed certificate',
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \plugins\ITOps\util\PatchMon($this, $aid, $timeout);
    }

    public function test() {
	$p = $this->client($this->aid, 20);
	try {
	    $hosts = (array)($p->api('GET', '/hosts')['hosts'] ?? []);
	    $message = 'connected: ' . \util\Format::plural(count($hosts), 'host');
	    if (trim((string)$this->username) !== '') {
		$p->signIn();
		$message .= '; signed in as ' . trim((string)$this->username);
	    }
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	} finally {
	    $p->signOut();
	}
	return $message;
    }
}
