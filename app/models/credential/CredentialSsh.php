<?php

/*
    CLD: 2026-11-21T21:00:00+02:00: an SSH server (Linux, Unix, network devices with a shell) for the SSH step (models\node\NodeAppSsh; util\Ssh: the system's OpenSSH client).
    Provides `ssh`. Signs in with a password or a private key (OpenSSH or PEM, + its passphrase). The server's key is pinned: its key line (`ssh-ed25519 AAAA...`); empty, the
    first connection (Test, or the first run) learns it and saves it (trust on first use, as the SFTP credential); a server whose key changed afterwards is refused. A server on a
    private network needs the account's "Integrations may connect to private network addresses". test() signs in and reads the host's name, its system and its uptime
*/

namespace models\credential;

class CredentialSsh extends \models\Credential {
    public static $label = 'SSH server';
    public static $icon = 'bi-terminal';
    public static $group = 'Servers';
    public static $provides = [ 'ssh' ];
    public static $testable = true;

    const AUTH = [ 'key' => 'Private key', 'password' => 'Password' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [ 'key' => 'host', 'type' => 'text', 'label' => 'Server', 'required' => true, 'default' => '', 'transform' => 'trim',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9.\-:\[\]]+$', 'skipEmpty' => true, 'message' => 'A host name or IP address (no ssh:// and no user)' ] ],
		'ui' => [ 'width' => 8, 'placeholder' => 'web01.example.com' ] ],
	    [ 'key' => 'port', 'type' => 'number', 'label' => 'Port', 'default' => 22,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 65535, 'skipEmpty' => true, 'message' => 'Between 1 and 65535' ] ], 'ui' => [ 'width' => 4, 'step' => 1 ] ],
	    [ 'key' => 'username', 'type' => 'text', 'label' => 'Username', 'required' => true, 'default' => '',
		'help' => 'The steps run as this user: give it only the rights they need (e.g. sudo for systemctl restart of some services)', 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'auth', 'type' => 'select', 'label' => 'Sign in with', 'required' => true, 'default' => 'key', 'choices' => self::AUTH, 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'password', 'type' => 'password', 'label' => 'Password', 'encrypt' => true, 'ui' => [ 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'password' ] ] ],
	    [ 'key' => 'privateKey', 'type' => 'textarea', 'label' => 'Private key', 'encrypt' => true,
		'help' => 'OpenSSH or PEM: -----BEGIN OPENSSH PRIVATE KEY----- ... (the whole text); its public key goes into the user\'s ~/.ssh/authorized_keys',
		'validate' => [
		    [ 'notPublicKey', 'when' => [ 'auth' => [ 'key' ] ], 'skipEmpty' => true, 'last' => true, 'message' => 'this is a public key: signing in needs the private one' ],
		    [ 'sshPrivateKey', 'when' => [ 'auth' => [ 'key' ] ], 'skipEmpty' => true, 'message' => 'not a private key (-----BEGIN OPENSSH PRIVATE KEY----- or PEM)' ],
		],
		'ui' => [ 'rows' => 6, 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'key' ] ] ],
	    [ 'key' => 'passphrase', 'type' => 'password', 'label' => 'Key passphrase', 'encrypt' => true, 'help' => 'Empty when the key has none',
		'ui' => [ 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'key' ] ] ],
	    [ 'key' => 'hostKey', 'type' => 'textarea', 'label' => 'Server key', 'default' => '',
		'help' => 'Its public key line (ssh-ed25519 AAAA...; ssh-keyscan gives it). Empty: the first connection (Test) learns it and saves it; later connections to a server with another key are refused',
		'ui' => [ 'rows' => 2, 'placeholder' => 'ssh-ed25519 AAAAC3Nza...' ] ],
	]);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	if ($this->auth === 'password' ? (string)$this->password === '' : trim((string)$this->privateKey) === '') {
	    $this->errors($this->auth === 'password' ? 'password' : 'privateKey', 'required');
	    $valid = false;
	}
	if (trim((string)$this->hostKey) !== '' && \util\Ssh::keyLine($this->hostKey) === '') {
	    $this->errors('hostKey', 'not a public key line (ssh-ed25519 AAAA...)');
	    $valid = false;
	}
	return $valid;
    }

    //a client for the server, its key learned first when none is saved
    public function client($aid) {
	$auth = $this->auth === 'password' ? [ 'password' => (string)$this->password ] : [ 'key' => (string)$this->privateKey, 'passphrase' => (string)$this->passphrase ];
	$ssh = new \util\Ssh((string)$this->host, (int)$this->port ?: 22, (string)$this->username, $auth, $aid);
	if (\util\Ssh::keyLine($this->hostKey) === '')
	    $this->learnHostKey($aid);
	return $ssh->hostKey($this->hostKey);
    }

    //trust on first use: the server's key saved (only this field: the object may hold unsaved form data)
    protected function learnHostKey($aid) {
	[ $line, $fingerprint ] = \util\Ssh::keyScan((string)$this->host, (int)$this->port ?: 22, $aid);
	$this->hostKey = $line;
	if ($this->exists())
	    \V::app()->om->updateMany($this->getObjectName(), [ '_id' => $this->_id ], [ '$set' => [ 'hostKey' => $line ] ]);
	\models\AuditLog::record('credential.hostkey', $this, 'server key learned: ' . $fingerprint);
    }

    public function test() {
	try {
	    [ $out, $err, $code ] = $this->client($this->aid)->run('printf "%s\n" "$(hostname)" "$(uname -srm)" "$(uptime -p 2>/dev/null || uptime)"', [], 30);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	if ($code !== 0)
	    throw new \Exception('signed in, but the test command failed (exit ' . $code . '): ' . trim($err));
	$lines = array_values(array_filter(array_map('trim', explode("\n", $out))));
	return 'signed in as ' . $this->username . ' on ' . ($lines[0] ?? '?') . ' (' . ($lines[1] ?? '') . ', ' . ($lines[2] ?? '') . '); server key ' . \util\Ssh::fingerprint($this->hostKey);
    }
}
