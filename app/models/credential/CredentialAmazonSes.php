<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: Amazon SES (Simple Email Service) of an AWS account, for the Amazon SES steps (Email, Account, API; util\AmazonSes, API v2): the region, an access
    key (an IAM user's: the access key ID and the secret, encrypted; a temporary key's session token, encrypted, optional) with the SES permissions the steps use, the
    configuration set of the messages (optional: its event destinations, e.g. SNS for bounces) and the sender the steps use when they name none (a verified identity). Test: the
    account's sending quota. Provides `amazonses`
*/

namespace models\credential;

class CredentialAmazonSes extends \models\Credential {
    public static $label = 'Amazon SES';
    public static $icon = 'bi-send';
    public static $group = 'Email';
    public static $provides = [ 'amazonses' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'region',
		'type' => 'text',
		'label' => 'Region',
		'required' => true,
		'default' => 'eu-west-1',
		'transform' => 'trim',
		'help' => 'The AWS region of your SES identities, e.g. eu-west-1, eu-central-1, us-east-1',
		'validate' => [ [ 'regex', 'pattern' => '^[a-z]{2}(-gov)?-[a-z]+-\d$', 'skipEmpty' => true, 'message' => 'An AWS region code, e.g. eu-west-1' ] ],
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'accessKeyId',
		'type' => 'text',
		'label' => 'Access key ID',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'Of an IAM user with the SES permissions the steps use (e.g. ses:SendEmail)',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Z0-9]{16,128}$', 'skipEmpty' => true, 'message' => 'AKIA… or ASIA…' ] ],
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'secretAccessKey',
		'type' => 'password',
		'label' => 'Secret access key',
		'required' => true,
		'encrypt' => true,
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'sessionToken',
		'type' => 'password',
		'label' => 'Session token',
		'encrypt' => true,
		'help' => 'Only for a temporary key (ASIA…)',
		'ui' => [ 'width' => 4, 'advanced' => true ],
	    ],
	    [
		'key' => 'configurationSet',
		'type' => 'text',
		'label' => 'Configuration set',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Optional: the configuration set of the messages sent (its event destinations, e.g. bounces to SNS)',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9_-]{1,64}$', 'skipEmpty' => true, 'message' => 'Letters, digits, - and _' ] ],
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'fromAddress',
		'type' => 'text',
		'label' => 'Sender',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The default: Name <address> of a verified identity (an address, or one of a verified domain)',
		'ui' => [ 'width' => 4, 'placeholder' => 'Acme <noreply@acme.com>' ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \util\AmazonSes($this, $timeout);
    }

    public function test() {
	try {
	    $a = $this->client(20)->request('GET', '/v2/email/account');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$q = (array)($a['SendQuota'] ?? []);
	return 'connected to Amazon SES (' . trim((string)$this->region) . '): ' . (!empty($a['ProductionAccessEnabled']) ? 'production' : 'sandbox (only to verified addresses)')
	    . (isset($q['Max24HourSend']) ? ', ' . (int)($q['SentLast24Hours'] ?? 0) . ' of ' . (int)$q['Max24HourSend'] . ' sent in 24 h' : '')
	    . (isset($a['SendingEnabled']) && !$a['SendingEnabled'] ? ', sending is paused' : '');
    }
}
