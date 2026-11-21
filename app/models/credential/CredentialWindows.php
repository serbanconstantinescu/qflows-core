<?php

/*
    CLD: 2026-11-21T21:00:00+02:00: a Windows server (Windows Server 2016 and later, Windows 10 / 11) for the Windows step (models\node\NodeAppWindows; util\WinRM: PowerShell over
    WinRM HTTPS, port 5986). Provides `windows`. A user of the server's Remote Management Users group (and of the groups its scripts need, e.g. administrators to restart
    services), NTLM (domain or local accounts) or Basic (local accounts), the certificate trusted or not (a self-signed listener). A server on a private network needs the
    account's "Integrations may connect to private network addresses". test() runs PowerShell: the server's name, its Windows and its uptime
*/

namespace models\credential;

class CredentialWindows extends \models\Credential {
    public static $label = 'Windows server';
    public static $icon = 'bi-windows';
    public static $group = 'Servers';
    public static $provides = [ 'windows' ];
    public static $testable = true;

    const AUTH = [ 'ntlm' => 'NTLM (domain or local account)', 'basic' => 'Basic (local account)' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [ 'key' => 'serverUrl', 'type' => 'text', 'label' => 'Server', 'required' => true, 'default' => '', 'transform' => 'trim', 'help' => 'Its WinRM HTTPS address: https://<host>:5986',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?(/wsman)?/?$', 'skipEmpty' => true, 'message' => 'https://<host>:5986, without a path' ] ],
		'ui' => [ 'placeholder' => 'https://app01.example.local:5986' ] ],
	    [ 'key' => 'username', 'type' => 'text', 'label' => 'Username', 'required' => true, 'default' => '', 'help' => 'DOMAIN\\user, user@domain, or a local user',
		'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'password', 'type' => 'password', 'label' => 'Password', 'required' => true, 'encrypt' => true, 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'authMethod', 'type' => 'select', 'label' => 'Authentication', 'required' => true, 'default' => 'ntlm', 'choices' => self::AUTH, 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'trustCertificate', 'type' => 'switch', 'label' => 'Trust the server\'s certificate', 'default' => false,
		'help' => 'For the listener\'s self-signed certificate', 'ui' => [ 'width' => 6 ] ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\WinRM($this, $aid, $timeout);
    }

    public function test() {
	try {
	    $r = (array)$this->client($this->aid, 30)->ps('windows:test', <<<'PS'
$os = Get-CimInstance Win32_OperatingSystem
[ordered]@{ name = $env:COMPUTERNAME; windows = $os.Caption; version = $os.Version; uptimeHours = [int]((Get-Date) - $os.LastBootUpTime).TotalHours }
PS);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'signed in as ' . $this->username . ' on ' . ($r['name'] ?? '?') . ' (' . ($r['windows'] ?? '') . ' ' . ($r['version'] ?? '') . '), up for ' . (int)($r['uptimeHours'] ?? 0) . ' hours';
    }
}
