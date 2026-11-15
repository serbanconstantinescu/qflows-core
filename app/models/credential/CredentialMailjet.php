<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: a Mailjet account, for the Mailjet steps (Email, Contacts, API; util\Mailjet): its region (Global or US), the API key and the secret key (Account
    settings > API key management; the secret encrypted), and the sender the steps use when they name none (an address of a sender or domain validated in Mailjet). Test: the
    account's user. Provides `mailjet`
*/

namespace models\credential;

class CredentialMailjet extends \models\Credential {
    public static $label = 'Mailjet';
    public static $icon = 'bi-send';
    public static $group = 'Email';
    public static $provides = [ 'mailjet' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'apiKey',
		'type' => 'text',
		'label' => 'API key',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'In Mailjet: Account settings > API key management (the public key)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'secretKey',
		'type' => 'password',
		'label' => 'Secret key',
		'required' => true,
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'region',
		'type' => 'select',
		'label' => 'Region',
		'required' => true,
		'default' => 'global',
		'choices' => [ 'global' => 'Global (api.mailjet.com)', 'us' => 'US (api.us.mailjet.com)' ],
		'help' => 'Where the account was created',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'fromAddress',
		'type' => 'text',
		'label' => 'Sender',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The default: Name <address> of a sender or domain validated in Mailjet',
		'ui' => [ 'width' => 6, 'placeholder' => 'Acme <noreply@acme.com>' ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \util\Mailjet($this, $timeout);
    }

    public function test() {
	try {
	    $u = $this->client(20)->request('GET', '/v3/REST/user');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$user = (array)($u['Data'][0] ?? []);
	return 'connected to Mailjet as ' . ($user['Email'] ?? $user['Username'] ?? '?');
    }
}
