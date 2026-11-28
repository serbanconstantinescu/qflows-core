<?php

/*
    CLD: 2026-11-28T18:00:00+02:00: an SMTP server (provides `smtp`), for the Send email (SMTP) step (NodeAppSmtpEmail): host, port, security (STARTTLS, SSL/TLS or none),
    a username and password (optional: none = no sign-in), the server's certificate trusted (a self-signed one), the default From address.
    server(): what util\EmailSend::transmit takes; the host's addresses public only, unless the account allows private network addresses (CredentialSmtp::$testing: the
    check suite's local server, no address check). test(): connected, encrypted, signed in, then QUIT (no message sent)
*/

namespace models\credential;

class CredentialSmtp extends \models\Credential {
    public static $label = 'SMTP server';
    public static $icon = 'bi-envelope-at';
    public static $group = 'Email';
    public static $provides = [ 'smtp' ];
    public static $testable = true;

    const SECURITY = [ 'starttls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (unencrypted, port 25)' ];

    public static $testing = false;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'host',
		'type' => 'text',
		'label' => 'SMTP server',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'ui' => [ 'width' => 8, 'placeholder' => 'smtp.example.com' ],
	    ],
	    [
		'key' => 'port',
		'type' => 'number',
		'label' => 'Port',
		'required' => true,
		'default' => 587,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 65535, 'skipEmpty' => true, 'message' => 'Between 1 and 65535' ] ],
		'ui' => [ 'width' => 4, 'step' => 1 ],
	    ],
	    [
		'key' => 'security',
		'type' => 'select',
		'label' => 'Security',
		'required' => true,
		'default' => 'starttls',
		'choices' => self::SECURITY,
		'help' => 'STARTTLS is required when chosen: a server that does not offer it is refused',
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Username',
		'default' => '',
		'help' => 'Empty: no sign-in',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'encrypt' => true,
		'help' => 'Gmail, Microsoft 365: an app password',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'fromAddress',
		'type' => 'email',
		'label' => 'From address',
		'default' => '',
		'help' => 'The address the steps send from when they give none; the server may require its own account\'s',
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the server\'s certificate',
		'default' => false,
		'help' => 'For a self-signed certificate',
	    ],
	]);
    }

    //the server as util\EmailSend::transmit takes it (the host's checked address; $aid: the account whose setting allows private network addresses)
    public function server($aid) {
	$host = trim((string)$this->host, " []");
	if (!preg_match('/^[A-Za-z0-9.\-:]+$/', $host))
	    throw new \RuntimeException('SMTP server: not a host name or IP address: `' . mb_substr($host, 0, 64) . '`');
	$address = $host;
	if (!self::$testing) {
	    $account = $aid ? \V::app()->om->findById('Account', (string)$aid) : null;
	    [ $ips, $why ] = \util\Http::publicAddresses($host, (bool)($account->allow_private_addresses ?? false));
	    if (!$ips)
		throw new \RuntimeException($why . ($account && empty($account->allow_private_addresses) ? ' (a server on a private network: the account must allow private network addresses)' : ''));
	    $address = $ips[0];
	}
	return [
	    'host' => $host,
	    'address' => $address,
	    'port' => (int)$this->port ?: 587,
	    'security' => isset(self::SECURITY[(string)$this->security]) ? (string)$this->security : 'starttls',
	    'username' => trim((string)$this->username),
	    'password' => (string)$this->password,
	    'insecure' => (bool)$this->trustCertificate,
	];
    }

    public function test() {
	try {
	    $s = $this->server($this->aid);
	    $r = \util\EmailSend::transmit($s, null, [ 'timeout' => 20 ]);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return "connected to {$s['host']}:{$s['port']}" . ($r['tls'] ? ', encrypted' : ', not encrypted') . ($r['auth'] ? ", signed in as {$s['username']}" : ', no sign-in');
    }
}
