<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: a Brevo account (formerly Sendinblue), for the Brevo steps (Email, Contacts, API; util\vendor\Brevo): an API key (SMTP & API > API keys; encrypted) and
    the sender the steps use when they name none (an address of a sender or domain verified in Brevo). Test: the account. Provides `brevo`
*/

namespace models\credential;

class CredentialBrevo extends \models\Credential {
    public static $label = 'Brevo';
    public static $icon = 'bi-send';
    public static $group = 'Email';
    public static $provides = [ 'brevo' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'API key',
		'required' => true,
		'encrypt' => true,
		'help' => 'In Brevo: SMTP & API > API keys (xkeysib-…)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'fromAddress',
		'type' => 'text',
		'label' => 'Sender',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The default: Name <address> of a sender or domain verified in Brevo',
		'ui' => [ 'width' => 6, 'placeholder' => 'Acme <noreply@acme.com>' ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \util\vendor\Brevo($this, $timeout);
    }

    public function test() {
	try {
	    $a = $this->client(20)->request('GET', '/account');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$plan = array_map(fn($p) => trim(($p['type'] ?? '') . (isset($p['credits']) ? ': ' . $p['credits'] . ' credits' : '')), (array)($a['plan'] ?? []));
	return 'connected to Brevo as ' . ($a['email'] ?? '?') . ($a['companyName'] ?? '' ? " ({$a['companyName']})" : '') . ($plan ? ', ' . implode(', ', $plan) : '');
    }
}
