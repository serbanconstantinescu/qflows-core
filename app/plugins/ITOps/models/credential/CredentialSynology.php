<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: a Synology NAS (DSM 6.2 / 7.x), for the Synology steps and NAS trigger (plugins\ITOps\util\Synology): the NAS's address (its DSM
    port, https), a DSM account and password (encrypted; an administrator for the system, storage, users and backup steps), its two-factor secret (encrypted, optional: the
    code is made at each sign-in), the NAS's certificate trusted or checked against a CA certificate. A NAS on a private address needs the account's "Integrations may connect
    to private network addresses". Test: signs in, reads the model and DSM version, signs out. Provides `synology`
*/

namespace plugins\ITOps\models\credential;

class CredentialSynology extends \models\Credential {
    public static $label = 'Synology NAS';
    public static $icon = 'bi-hdd-network';
    public static $group = 'Storage';
    public static $provides = [ 'synology' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'serverUrl',
		'type' => 'text',
		'label' => 'NAS',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'DSM\'s address, as you open it in the browser (its HTTPS port, 5001 by default)',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?/?$', 'skipEmpty' => true, 'message' => 'https://<nas>[:port], without a path' ] ],
		'ui' => [ 'placeholder' => 'https://nas.example.local:5001' ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Account',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'A DSM account for the workflows: an administrator for the system, storage, users and backup steps',
		'validate' => [ [ 'regex', 'pattern' => '^[^\s]{1,255}$', 'skipEmpty' => true, 'message' => 'An account name, without spaces' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'required' => true,
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'otpSecret',
		'type' => 'password',
		'label' => 'Two-factor secret',
		'encrypt' => true,
		'help' => 'Only for an account with 2-factor authentication: the secret key DSM shows when you set it up (the text under the QR code); qflows makes the code at each sign-in',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z2-7=\s-]{16,128}$', 'skipEmpty' => true, 'message' => 'The secret key, letters A-Z and digits 2-7' ] ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the NAS\'s certificate',
		'default' => false,
		'help' => 'Only for a self-signed certificate (DSM\'s own, by default); or give its CA certificate below',
	    ],
	    [
		'key' => 'caCertificate',
		'type' => 'textarea',
		'label' => 'CA certificate',
		'default' => '',
		'help' => 'A private CA\'s certificate (PEM) that signed the NAS\'s certificate',
		'ui' => [ 'rows' => 3, 'code' => true, 'advanced' => true ],
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \plugins\ITOps\util\Synology($this, $aid, $timeout);
    }

    public function test() {
	$s = $this->client($this->aid, 30);
	try {
	    $s->signIn();
	    try {
		$info = $s->call('SYNO.Core.System', 'info', [], 1);
	    } catch (\RuntimeException $e) {
		//not an administrator: signed in all the same
		if (!str_contains($e->getMessage(), '(code 105)'))
		    throw $e;
		return 'signed in as ' . trim((string)$this->username) . ' (not an administrator: the Files steps only)';
	    }
	    $message ='connected: ' . trim(($info['model'] ?? 'Synology') . ', ' . ($info['firmware_ver'] ?? 'DSM'), ', ') . '; signed in as ' . trim((string)$this->username);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	} finally {
	    $s->signOut();
	}
	return $message;
    }
}
