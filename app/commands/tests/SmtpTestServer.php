<?php

//CLD: 2026-11-28T18:00:00+02:00: a small SMTP server for the check suite's `smtp` checks (commands/CheckCommand::suiteSmtp); not for any other use.
//  php SmtpTestServer.php <state dir> [--cert=<pem> --key=<pem>] [--ssl] [--user=<name> --pass=<password>]
//  prints `PORT <n>` (listening on 127.0.0.1, a free port) and serves one connection after another until it is stopped (or after 10 minutes).
//  --cert / --key: STARTTLS offered; with --ssl: TLS from the start. --user / --pass: AUTH LOGIN and PLAIN offered, a MAIL before signing in refused (530).
//  A recipient whose name starts with `reject` is refused (550 5.1.1). Each message taken: a line of <state dir>/mail.jsonl { from, rcpt, data, auth, tls, notify }

$args = $argv;
array_shift($args);
$dir = (string)array_shift($args);
$opt = [];
foreach($args as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m))
	$opt[$m[1]] = $m[2] ?? true;
}
$cert = $opt['cert'] ?? null;
$ssl = !empty($opt['ssl']);
$user = $opt['user'] ?? null;
$pass = $opt['pass'] ?? null;

$ctx = stream_context_create([ 'ssl' => $cert ? [ 'local_cert' => $cert, 'local_pk' => $opt['key'] ?? null, 'verify_peer' => false, 'allow_self_signed' => true ] : [] ]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$server) {
    fwrite(STDERR, "cannot listen: {$errstr}\n");
    exit(1);
}
echo 'PORT ' . explode(':', stream_socket_get_name($server, false))[1] . "\n";
fflush(STDOUT);

$until = time() + 600;
while(time() < $until) {
    $c = @stream_socket_accept($server, 5);
    if (!$c)
	continue;
    stream_set_timeout($c, 30);
    $tls = false;
    if ($ssl) {
	if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
	    fclose($c);
	    continue;
	}
	$tls = true;
    }
    $say = function($line) use (&$c) {
	fwrite($c, $line . "\r\n");
    };
    $read = function() use (&$c) {
	$l = fgets($c);
	return $l === false ? null : rtrim($l, "\r\n");
    };
    $say('220 smtp.check ESMTP fake');
    $authed = null;
    $from = null;
    $rcpt = [];
    $notify = [];
    while(($line = $read()) !== null) {
	$verb = strtoupper(substr($line, 0, 4));
	if ($verb === 'EHLO') {
	    $caps = [ 'smtp.check', 'SIZE 10485760', '8BITMIME' ];
	    if ($cert && !$tls)
		$caps[] = 'STARTTLS';
	    if ($user !== null)
		$caps[] = 'AUTH LOGIN PLAIN';
	    foreach($caps as $i => $cap)
		$say(($i === count($caps) - 1 ? '250 ' : '250-') . $cap);
	} elseif ($verb === 'HELO') {
	    $say('250 smtp.check');
	} elseif (strtoupper($line) === 'STARTTLS' && $cert && !$tls) {
	    $say('220 2.0.0 Ready to start TLS');
	    if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER))
		break;
	    $tls = true;
	} elseif ($verb === 'AUTH' && $user !== null) {
	    $parts = explode(' ', $line);
	    $method = strtoupper($parts[1] ?? '');
	    if ($method === 'PLAIN') {
		$b64 = $parts[2] ?? null;
		if ($b64 === null) {
		    $say('334 ');
		    $b64 = $read();
		}
		$p = explode("\0", (string)base64_decode((string)$b64));
		[ $u, $pw ] = [ $p[1] ?? '', $p[2] ?? '' ];
	    } elseif ($method === 'LOGIN') {
		$say('334 VXNlcm5hbWU6');
		$u = (string)base64_decode((string)$read());
		$say('334 UGFzc3dvcmQ6');
		$pw = (string)base64_decode((string)$read());
	    } else {
		$say('504 5.5.4 Unrecognized authentication type');
		continue;
	    }
	    if ($u === $user && $pw === $pass) {
		$authed = $u;
		$say('235 2.7.0 Authentication successful');
	    } else
		$say('535 5.7.8 Authentication credentials invalid');
	} elseif (preg_match('/^MAIL FROM:\s*<([^>]*)>/i', $line, $m)) {
	    if ($user !== null && $authed === null)
		$say('530 5.7.0 Authentication required');
	    else {
		$from = $m[1];
		$rcpt = [];
		$notify = [];
		$say('250 2.1.0 Ok');
	    }
	} elseif (preg_match('/^RCPT TO:\s*<([^>]*)>(.*)$/i', $line, $m)) {
	    if ($from === null)
		$say('503 5.5.1 Error: need MAIL command');
	    elseif (str_starts_with(strtolower($m[1]), 'reject'))
		$say("550 5.1.1 <{$m[1]}>: Recipient address rejected: User unknown");
	    else {
		$rcpt[] = $m[1];
		$notify[] = trim($m[2]);
		$say('250 2.1.5 Ok');
	    }
	} elseif (strtoupper($line) === 'DATA') {
	    if (!$rcpt) {
		$say('554 5.5.1 Error: no valid recipients');
		continue;
	    }
	    $say('354 End data with <CR><LF>.<CR><LF>');
	    $data = [];
	    while(($l = $read()) !== null && $l !== '.')
		$data[] = str_starts_with($l, '..') ? substr($l, 1) : $l;
	    file_put_contents("{$dir}/mail.jsonl", json_encode([ 'from' => $from, 'rcpt' => $rcpt, 'data' => implode("\r\n", $data), 'auth' => $authed, 'tls' => $tls,
		'notify' => $notify ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
	    $from = null;
	    $rcpt = [];
	    $say('250 2.0.0 Ok: queued as CHK' . strtoupper(bin2hex(random_bytes(4))));
	} elseif (strtoupper($line) === 'RSET' || strtoupper($line) === 'NOOP') {
	    $from = $verb === 'RSET' ? null : $from;
	    $say('250 2.0.0 Ok');
	} elseif (strtoupper($line) === 'QUIT') {
	    $say('221 2.0.0 Bye');
	    break;
	} else
	    $say('502 5.5.2 Error: command not recognized');
    }
    @fclose($c);
}
