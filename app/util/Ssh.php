<?php

/*
    CLD: 2026-11-21T21:00:00+02:00: an SSH client for the SSH credential and step (models\credential\CredentialSsh, models\node\NodeAppSsh): the system's OpenSSH client (ssh,
    ssh-keyscan, ssh-keygen; PHP has no SSH of its own here) run with nothing of the user's (-F /dev/null), in a private temporary folder (util\FileDecrypt::workDir):
	- the server: its addresses checked first (util\Http::publicAddresses: public ones, private networks only when the account allows them, never this machine), and ssh
	  connects to that address (HostKeyAlias: the host's name), so DNS cannot change it in between
	- its key pinned: the credential's host key line (`ssh-ed25519 AAAA...`) is the only known host (StrictHostKeyChecking yes); none yet: keyScan() learns it (trust
	  on first use, as the SFTP credential)
	- signing in: a private key (IdentitiesOnly, its passphrase through SSH_ASKPASS) or a password (SSH_ASKPASS: a script of the folder printing an environment variable of
	  this process; never on a command line)
	- the script: sent on stdin to `sh -s` (the remote command line holds no value of the workflow); the parameters first, as shell variables P_<name>='value' (quoted:
	  nothing of them is run)
	- the run: stdout, stderr (at most MAX_OUTPUT each), the exit code; a timeout kills ssh
    $ssh = new Ssh($host, $port, $user, $auth, $aid);      $auth: [ 'password' => ... ] or [ 'key' => <text>, 'passphrase' => ... ]; hostKey(): set the pinned key
    $ssh->run($script, $params, $timeout)                 [ stdout, stderr, exit code ]; \RuntimeException: no connection, a refused sign-in or key, a timeout
    Ssh::keyScan($host, $port, $aid)                      the server's key line (ed25519 first, then ecdsa, then rsa) and its SHA256 fingerprint
    Ssh::$testing: the check suite's local sshd (127.0.0.1): no address guard
*/

namespace util;

class Ssh {
    const MAX_OUTPUT = 1024 * 1024;
    const MAX_SCRIPT = 200000;
    const CONNECT_TIMEOUT = 15;
    //the key types asked, in order of preference
    const KEY_TYPES = [ 'ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa' ];

    public static $testing = false;

    protected $host;
    protected $port;
    protected $user;
    protected $auth;
    protected $aid;
    protected $hostKey = '';

    public function __construct($host, $port, $user, array $auth, $aid) {
	$this->host = trim((string)$host, " []");
	$this->port = (int)$port ?: 22;
	$this->user = (string)$user;
	$this->auth = $auth;
	$this->aid = $aid;
    }

    //the server's key line to trust (`<type> <base64>`)
    public function hostKey($line) {
	$this->hostKey = self::keyLine($line);
	return $this;
    }

    //the address to connect to: the host's first, all of them checked
    protected static function address($host, $aid) {
	if (self::$testing)
	    return $host;
	if (!preg_match('/^[A-Za-z0-9.\-:]+$/', $host))
	    throw new \RuntimeException("not a host name or IP address: {$host}");
	$account = $aid ? \V::app()->om->findById('Account', (string)$aid) : null;
	[ $ips, $why ] = Http::publicAddresses($host, (bool)($account->allow_private_addresses ?? false));
	if (!$ips)
	    throw new \RuntimeException($why . ($account && empty($account->allow_private_addresses) ? ' (a server on a private network: the account must allow private network addresses)' : ''));
	return $ips[0];
    }

    //`<type> <base64>` of a key line (a known_hosts line, or ssh-keyscan's: the host first), or ''
    public static function keyLine($text) {
	foreach(preg_split('/\R/', trim((string)$text)) as $line) {
	    if (preg_match('/(?:^|\s)((?:ssh|ecdsa|rsa)-[a-z0-9@.\-]+)\s+([A-Za-z0-9+\/]+={0,3})(?:\s|$)/', $line, $m))
		return $m[1] . ' ' . $m[2];
	}
	return '';
    }

    //SHA256:... of a key line
    public static function fingerprint($line) {
	$parts = explode(' ', self::keyLine($line));
	$blob = base64_decode($parts[1] ?? '', true);
	return $blob === false || $blob === '' ? '' : 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    //the server's key: [ key line, fingerprint ] (ssh-keyscan, the preferred type it offers)
    public static function keyScan($host, $port, $aid, $timeout = 15) {
	$address = self::address(trim((string)$host, " []"), $aid);
	[ $out, $err, $code ] = self::exec([ 'ssh-keyscan', '-T', (string)max(1, min(60, (int)$timeout)), '-p', (string)((int)$port ?: 22), '-t', 'ed25519,ecdsa,rsa', $address ],
	    '', [], $timeout + 5);
	$found = [];
	foreach(preg_split('/\R/', (string)$out) as $line) {
	    $key = self::keyLine($line);
	    if ($key !== '')
		$found[explode(' ', $key)[0]] = $key;
	}
	foreach(self::KEY_TYPES as $type) {
	    if (isset($found[$type]))
		return [ $found[$type], self::fingerprint($found[$type]) ];
	}
	throw new \RuntimeException("no SSH server answered on {$host}:" . ((int)$port ?: 22) . (trim((string)$err) !== '' ? ': ' . trim(preg_replace('/^#.*$/m', '', (string)$err)) : ''));
    }

    //quoted for sh (single quotes)
    public static function quote($value) {
	return "'" . str_replace("'", "'\\''", (string)$value) . "'";
    }

    //the parameters as shell variables P_<name>='value' (lists and objects as JSON text), then the script
    public static function wrap($script, array $params = []) {
	$head = '';
	foreach($params as $name => $value) {
	    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', (string)$name))
		throw new \RuntimeException("Parameters: `{$name}` is not a name (letters, digits, underscore)");
	    $text = is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value);
	    $head .= "P_{$name}=" . self::quote($text) . "\n";
	}
	return $head . str_replace("\r\n", "\n", (string)$script) . "\n";
    }

    //runs $script on the server: [ stdout, stderr, exit code ]
    public function run($script, array $params = [], $timeout = 60) {
	if ($this->hostKey === '')
	    throw new \RuntimeException('the server\'s key is not known yet (Test the credential first)');
	$input = self::wrap($script, $params);
	if (strlen($input) > self::MAX_SCRIPT)
	    throw new \RuntimeException('the script is longer than ' . self::MAX_SCRIPT . ' characters');
	$address = self::address($this->host, $this->aid);
	$dir = FileDecrypt::workDir();
	try {
	    $alias = 'qflows-' . strtolower(preg_replace('/[^A-Za-z0-9.\-]/', '_', $this->host));
	    file_put_contents("{$dir}/known_hosts", "{$alias} {$this->hostKey}\n");
	    $env = [];
	    $args = [ 'ssh', '-F', '/dev/null', '-T', '-p', (string)$this->port, '-l', $this->user,
		'-o', 'UserKnownHostsFile=' . "{$dir}/known_hosts", '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'StrictHostKeyChecking=yes', '-o', "HostKeyAlias={$alias}",
		'-o', 'UpdateHostKeys=no', '-o', 'ConnectTimeout=' . self::CONNECT_TIMEOUT, '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3',
		'-o', 'NumberOfPasswordPrompts=1', '-o', 'LogLevel=ERROR', '-o', 'ControlMaster=no', '-o', 'ControlPath=none', '-o', 'ForwardAgent=no', '-o', 'ClearAllForwardings=yes',
		'-o', 'IdentityAgent=none' ];
	    $secret = null;
	    if (isset($this->auth['key'])) {
		$key = FileDecrypt::secretFile($dir, 'id', str_replace("\r\n", "\n", trim((string)$this->auth['key'])) . "\n");
		array_push($args, '-i', $key, '-o', 'IdentitiesOnly=yes', '-o', 'PreferredAuthentications=publickey', '-o', 'PasswordAuthentication=no');
		$secret = (string)($this->auth['passphrase'] ?? '');
		if ($secret === '')
		    array_push($args, '-o', 'BatchMode=yes');
	    } else {
		array_push($args, '-o', 'PreferredAuthentications=password,keyboard-interactive', '-o', 'PubkeyAuthentication=no');
		$secret = (string)($this->auth['password'] ?? '');
	    }
	    if ($secret !== null && $secret !== '') {
		//SSH_ASKPASS: the secret from this process's environment, never written or on a command line
		$askpass = "{$dir}/askpass";
		file_put_contents($askpass, "#!/bin/sh\nprintf '%s\\n' \"\$QFLOWS_SSH_SECRET\"\n");
		chmod($askpass, 0700);
		$env = [ 'SSH_ASKPASS' => $askpass, 'SSH_ASKPASS_REQUIRE' => 'force', 'DISPLAY' => ':0', 'QFLOWS_SSH_SECRET' => $secret ];
	    }
	    array_push($args, $address, 'sh -s');
	    return self::exec($args, $input, $env, $timeout, true);
	} finally {
	    FileDecrypt::removeDir($dir);
	}
    }

    //a local command (no shell): [ stdout, stderr, exit code ]; $ssh: ssh's own errors (exit 255) become \RuntimeException
    protected static function exec(array $args, $input, array $env, $timeout, $ssh = false) {
	$env += [ 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'HOME' => sys_get_temp_dir() ];
	$proc = proc_open($args, [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, $env);
	if (!is_resource($proc))
	    throw new \RuntimeException('could not start ' . $args[0] . ' (is OpenSSH\'s client installed?)');
	stream_set_blocking($pipes[0], false);
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	$out = $err = '';
	$pending = (string)$input;
	$end = microtime(true) + max(1, (int)$timeout);
	$timedOut = false;
	while (true) {
	    if ($pending !== '') {
		$n = @fwrite($pipes[0], $pending);
		if ($n === false)
		    $pending = '';
		else
		    $pending = (string)substr($pending, $n);
	    }
	    if ($pending === '' && is_resource($pipes[0]))
		fclose($pipes[0]);
	    $read = array_values(array_filter([ $pipes[1], $pipes[2] ], 'is_resource'));
	    $w = $x = null;
	    if ($read && @stream_select($read, $w, $x, 0, 200000) > 0) {
		foreach($read as $r) {
		    $chunk = (string)fread($r, 65536);
		    if ($r === $pipes[1])
			$out .= strlen($out) < self::MAX_OUTPUT ? $chunk : '';
		    else
			$err .= strlen($err) < self::MAX_OUTPUT ? $chunk : '';
		}
	    }
	    $status = proc_get_status($proc);
	    if (!$status['running']) {
		$out .= (string)stream_get_contents($pipes[1]);
		$err .= (string)stream_get_contents($pipes[2]);
		$code = $status['exitcode'];
		break;
	    }
	    if (microtime(true) > $end) {
		$timedOut = true;
		proc_terminate($proc, 9);
		$code = -1;
		break;
	    }
	}
	foreach($pipes as $p)
	    if (is_resource($p))
		fclose($p);
	proc_close($proc);
	if ($timedOut)
	    throw new \RuntimeException('timed out after ' . (int)$timeout . ' s');
	$out = substr($out, 0, self::MAX_OUTPUT);
	$err = substr($err, 0, self::MAX_OUTPUT);
	if ($ssh && $code === 255)
	    throw new \RuntimeException(self::sshError($err));
	return [ $out, $err, $code ];
    }

    //ssh's own error, in words
    protected static function sshError($stderr) {
	$e = trim((string)$stderr);
	if (preg_match('/REMOTE HOST IDENTIFICATION HAS CHANGED|Host key verification failed/i', $e))
	    return 'the server\'s key is not the one trusted (it changed, or this is another server): check it, then clear the credential\'s server key to learn the new one';
	if (preg_match('/Permission denied/i', $e))
	    return 'the server refused the sign-in (user, password or key)';
	if (preg_match('/Connection refused/i', $e))
	    return 'connection refused (no SSH server on that port)';
	if (preg_match('/timed out/i', $e))
	    return 'the server did not answer (connection timed out)';
	if (preg_match('/incorrect passphrase|bad passphrase|error in libcrypto|invalid format/i', $e))
	    return 'the private key could not be read (format or passphrase)';
	return 'ssh: ' . (mb_substr(preg_replace('/\s+/', ' ', $e), 0, 300) ?: 'failed');
    }
}
