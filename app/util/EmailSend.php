<?php

namespace util;

class EmailSend {

    //CLD: 2026-11-28T18:00:00+02:00: the installation's SMTP server (app.env), as transmit() takes it: SMTP_HOST, SMTP_PORT (25), SMTP_SECURE ssl (SSL/TLS) or else
    //STARTTLS when the server offers it and SMTP_AUTOTLS is on (none when off), AUTH with SMTP_USERNAME / SMTP_PASSWORD when SMTP_AUTH; null when SMTP_HOST is not set
    public static function installationServer() {
	if (!defined('SMTP_HOST'))
	    return null;
	$auth = defined('SMTP_AUTH') && !empty(SMTP_AUTH) && defined('SMTP_USERNAME') && defined('SMTP_PASSWORD');
	return [
	    'host' => (string)SMTP_HOST,
	    'port' => defined('SMTP_PORT') && (int)SMTP_PORT ? (int)SMTP_PORT : 25,
	    'security' => defined('SMTP_SECURE') && SMTP_SECURE === 'ssl' ? 'ssl' : (defined('SMTP_AUTOTLS') && SMTP_AUTOTLS !== false ? 'auto' : 'none'),
	    'username' => $auth ? (string)SMTP_USERNAME : '',
	    'password' => $auth ? (string)SMTP_PASSWORD : '',
	    //the installation's always asked for no delivery notices
	    'dsn' => 'NEVER',
	];
    }

    //the name given in EHLO: SMTP_FROM_DOMAIN, else (none, or app.env.example's empty placeholder) the host name
    public static function heloName() {
	return defined('SMTP_FROM_DOMAIN') && SMTP_FROM_DOMAIN !== '' ? (string)SMTP_FROM_DOMAIN : (string)gethostname();
    }

    //an email through the installation's SMTP server (installationServer()); $data { sender, to / cc / bcc: [ address => name ], headers, body } (or the name of a file of it,
    //serialized: read, then deleted when sent); true when sent, -1 when not (logged)
    public static function send($data, $options = []) {
	$fname = false;
	if (is_string($data)) {
	    $fname = $data;
	    if (!is_readable($fname)) {
		\V::app()->log->error("file:$fname is not readable. Return.");
		return;
	    }
	    $data = unserialize(file_get_contents($fname));
	}
	$server = self::installationServer();
	if ($server === null) {
	    \V::app()->log->error("SMTP_HOST is not defined. Cannot send emails. Stop");
	    return -1;
	}
	foreach([ 'to', 'cc', 'bcc' ] as $grp) {
	    if (is_string($data[$grp] ?? null))
		$data[$grp] = unserialize($data[$grp]);
	}
	try {
	    $r = self::transmit($server, $data, $options);
	} catch (\RuntimeException $e) {
	    \V::app()->log->error('SMTP: ' . $e->getMessage());
	    return -1;
	}
	\V::app()->log->debug('SMTP: sent mail from:' . $data['sender'] . ' to: ' . implode(', ', $r['accepted']));
	foreach($r['rejected'] as $to => $why)
	    \V::app()->log->debug("SMTP: error sending mail to: {$to}, error: {$why}");
	if ($fname)
	    unlink($fname);
	return true;
    }

    //CLD: 2026-11-28T18:00:00+02:00: one message through an SMTP server (the installation's: send(); an SMTP server credential's: the Send email (SMTP) step).
    //$server: host, port (25), security (none | starttls: required | ssl: SSL/TLS from the start | auto: STARTTLS when offered), username + password (AUTH when both given),
    //address (the IP connected to, the host's checked one; default the host: its name checked in the certificate), insecure (the certificate not checked), helo
    //(heloName()), dsn (NOTIFY= of each recipient; default NEVER when the server offers DSN, else none).
    //$m: sender, to / cc / bcc ([ address => name ]), headers, body; null: only connected, encrypted and signed in (a credential's test).
    //$options: timeout (s, 300 as RFC 5321 4.5.3.2), debug (util\SMTP::DEBUG_*), logger, doVerp.
    //[ accepted: [ address ], rejected: [ address => the server's reply ], id: the server's queue id or null, tls, auth ]; \RuntimeException when not sent (no connection,
    //TLS, sign-in, sender, no recipient accepted, the message refused)
    public static function transmit(array $server, ?array $m, array $options = []) {
	$options += [
	    'timeout' => 300,
	    'debug' => \util\SMTP::DEBUG_OFF,
	    'logger' => function($message, $level) {
		\V::app()->log->debug("SMTP: $message");
	    },
	    'doVerp' => false,
	];
	$host = trim((string)($server['host'] ?? ''), " []");
	if ($host === '')
	    throw new \RuntimeException('no SMTP server');
	$port = (int)($server['port'] ?? 25) ?: 25;
	$security = (string)($server['security'] ?? 'auto');
	$address = trim((string)($server['address'] ?? $host), '[]');
	$insecure = !empty($server['insecure']);
	$where = "{$host}:{$port}";
	$smtp = new SMTP();
	$smtp->setTimeout($options['timeout']);
	$smtp->setDebugLevel($options['debug']);
	$smtp->setDebugOutput($options['logger']);
	$smtp->setVerp($options['doVerp']);
	//the certificate of the host's name (also when connecting to its checked address)
	$context = [ 'ssl' => [ 'peer_name' => $host, 'verify_peer' => !$insecure, 'verify_peer_name' => !$insecure, 'allow_self_signed' => $insecure ] ];
	$target = ($security === 'ssl' ? 'ssl://' : '') . (str_contains($address, ':') ? "[{$address}]" : $address);
	if (!$smtp->connect($target, $port, $options['timeout'], $context)) {
	    $why = self::tlsWhy($smtp);
	    //SSL/TLS: PHP gives no reason for a refused handshake
	    if ($security === 'ssl' && $why === ': Failed to connect to server')
		$why .= $insecure ? ' (is it the server\'s SSL/TLS port?)' : ' (its certificate not trusted - a self-signed one: trust the server\'s certificate in the credential - or not its SSL/TLS port)';
	    throw new \RuntimeException("no connection to {$where}{$why}");
	}
	try {
	    $helo = (string)($server['helo'] ?? self::heloName());
	    if (!$smtp->hello($helo))
		throw new \RuntimeException("{$where} refused EHLO" . self::why($smtp));
	    $tls = $security === 'ssl';
	    if ($security === 'starttls' || ($security === 'auto' && $smtp->getServerExt('STARTTLS'))) {
		if (!$smtp->getServerExt('STARTTLS'))
		    throw new \RuntimeException("{$where} does not offer STARTTLS (choose another security, or the server's SSL/TLS port)");
		if (!$smtp->startTLS())
		    throw new \RuntimeException("STARTTLS with {$where} failed" . self::tlsWhy($smtp));
		//EHLO again after TLS
		$smtp->hello($helo);
		$tls = true;
	    }
	    $user = (string)($server['username'] ?? '');
	    $pass = (string)($server['password'] ?? '');
	    $auth = $user !== '' && $pass !== '';
	    if ($auth && !$smtp->authenticate($user, $pass))
		throw new \RuntimeException("{$where} refused the sign-in as {$user}" . self::why($smtp));
	    $r = [ 'accepted' => [], 'rejected' => [], 'id' => null, 'tls' => $tls, 'auth' => $auth ];
	    if ($m === null) {
		$smtp->quit();
		return $r;
	    }
	    if (!$smtp->mail((string)$m['sender']))
		throw new \RuntimeException("{$where} refused the sender {$m['sender']}" . self::why($smtp));
	    $dsn = array_key_exists('dsn', $server) ? (string)$server['dsn'] : ($smtp->getServerExt('DSN') ? 'NEVER' : '');
	    foreach([ 'to', 'cc', 'bcc' ] as $grp) {
		foreach(is_array($m[$grp] ?? null) ? $m[$grp] : [] as $to => $name) {
		    if ($smtp->recipient((string)$to, $dsn))
			$r['accepted'][] = (string)$to;
		    else
			$r['rejected'][(string)$to] = ltrim(self::why($smtp), ': ') ?: 'refused';
		}
	    }
	    if (!$r['accepted'])
		throw new \RuntimeException("{$where} accepted no recipient" . ($r['rejected'] ? ': ' . implode('; ', array_map(fn($a, $w) => "{$a}: {$w}", array_keys($r['rejected']), $r['rejected'])) : ''));
	    //the headers come without a trailing CRLF (compose(), Job::reportError): CRLF CRLF ends the last header and adds the blank line before the body
	    if (!$smtp->data(rtrim((string)$m['headers'], "\r\n") . "\r\n\r\n" . $m['body']))
		throw new \RuntimeException("{$where} refused the message" . self::why($smtp));
	    $r['id'] = $smtp->getLastTransactionID() ?: null;
	    $smtp->quit();
	    return $r;
	} finally {
	    $smtp->close();
	}
    }

    //why() of a connection or TLS failure: a certificate not trusted said plainly
    protected static function tlsWhy(SMTP $smtp) {
	$why = self::why($smtp);
	if (str_contains($why, 'certificate verify failed'))
	    return ': the server\'s certificate is not trusted (a self-signed one: trust the server\'s certificate in the credential)';
	return preg_replace('/^: \d+ (stream_socket_\w+\(\): )?/', ': ', $why);
    }

    //": <the server's reply or the error>" of util\SMTP's last error ('' when none), else $otherwise
    protected static function why(SMTP $smtp, $otherwise = '') {
	$e = (array)$smtp->getError();
	//a reply of the server: its code, enhanced code and text; else util\SMTP's error (a connection's: its system error as smtp_code_ex)
	$ex = trim((string)($e['smtp_code_ex'] ?? ''));
	$text = trim((string)($e['detail'] ?? '')) !== '' ? trim(($e['smtp_code'] ?? '') . " {$ex} " . trim((string)$e['detail'])) : trim((string)($e['error'] ?? '') . ($ex !== '' ? ": {$ex}" : ''));
	return $text !== '' ? ': ' . mb_substr(preg_replace('/\s+/', ' ', $text), 0, 300) : $otherwise;
    }

    //a header value on one line: the line breaks (and the spaces around them) as one space; no header injection
    public static function headerText($v) {
	return trim(preg_replace('/[ \t]*[\r\n]+[ \t]*/', ' ', (string)$v));
    }

    //a display name and a space: quoted when plain ASCII (it may hold , : ; ...), else encoded; '' when empty
    public static function displayName($n) {
	$n = self::headerText($n);
	if ($n === '')
	    return '';
	return (mb_check_encoding($n, 'ASCII') ? '"' . addcslashes($n, '"\\') . '"' : mb_encode_mimeheader($n, 'UTF-8', 'Q')) . ' ';
    }

    //addresses $l (one or a list) as <a>, <b>
    public static function addressList($l) {
	return implode(', ', array_map(fn($a) => '<' . self::headerText($a) . '>', (array)$l));
    }

    //a message id in angle brackets (Message-ID, In-Reply-To, References)
    public static function angleId($v) {
	return '<' . trim(self::headerText($v), '<> ') . '>';
    }

    //$s quoted-printable, with CRLF line ends
    public static function quotedPrintable($s) {
	return str_replace("\n", "\r\n", str_replace("\r\n", "\n", quoted_printable_encode(str_replace([ "\r\n", "\r" ], "\n", (string)$s))));
    }

    /*
	a MIME message for send():
	    $m = {
		from (address),
		from_name,
		to / cc / bcc (addresses),
		reply_to,
		subject,
		html,
		text,
		images: [ cid => File ],
		attachments: [ { name, type, content } ] (optional)
	    } -> [ headers, body ];
	    with images: multipart/related (the alternative + the images, inline),
	    else multipart/alternative (text, html); quoted-printable bodies, encoded headers (CR / LF removed: no header injection)
    */
    public static function compose(array $m) {
	$id = bin2hex(random_bytes(8));
	$domain = substr(strrchr((string)$m['from'], '@'), 1) ?: 'localhost';
	//message_id (its own Message-ID), in_reply_to, references, auto_submitted: an answer of an agent, threaded, marked so an auto-responder does not answer it
	$headers = [
	    'From: ' . self::displayName($m['from_name'] ?? '') . '<' . self::headerText($m['from']) . '>',
	    'To: ' . self::addressList($m['to'] ?? []),
	];
	if (!empty($m['cc']))
	    $headers[] = 'Cc: ' . self::addressList($m['cc']);
	if (!empty($m['reply_to']))
	    $headers[] = 'Reply-To: <' . self::headerText($m['reply_to']) . '>';
	$headers = array_merge($headers, [
	    'Subject: ' . mb_encode_mimeheader(self::headerText($m['subject'] ?? ''), 'UTF-8', 'Q'),
	    'Date: ' . date(DATE_RFC2822),
	    'Message-ID: ' . (!empty($m['message_id']) ? self::angleId($m['message_id']) : '<' . $id . '@' . $domain . '>'),
	    'MIME-Version: 1.0',
	]);
	if (!empty($m['in_reply_to']))
	    $headers[] = 'In-Reply-To: ' . self::angleId($m['in_reply_to']);
	if (!empty($m['references']))
	    $headers[] = 'References: ' . implode(' ', array_map(self::angleId(...), (array)$m['references']));
	if (!empty($m['auto_submitted']))
	    $headers[] = 'Auto-Submitted: auto-replied';
	$alt = 'alt-' . $id;
	$alternative = "--{$alt}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . self::quotedPrintable($m['text'] ?? '') . "\r\n"
	    . "--{$alt}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . self::quotedPrintable($m['html'] ?? '') . "\r\n--{$alt}--\r\n";
	//CLD: 2026-11-15T16:00:00+02:00: the message's own part (alternative, or related with the images) as [ its Content-Type, its body ]; with attachments
	//([ { name, type, content } ]: the Amazon SES steps' raw emails) wrapped in multipart/mixed, each attachment base64
	if (empty($m['images'])) {
	    $part = [ "multipart/alternative; boundary=\"{$alt}\"", $alternative ];
	} else {
	    $rel = 'rel-' . $id;
	    $body = "--{$rel}\r\nContent-Type: multipart/alternative; boundary=\"{$alt}\"\r\n\r\n" . $alternative;
	    foreach($m['images'] as $cid => $file) {
		$type = $file->imageMimeType() ?: 'application/octet-stream';
		$fname = self::headerText(preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$file->filename));
		$body .= "--{$rel}\r\nContent-Type: {$type}; name=\"{$fname}\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <{$cid}>\r\n"
		    . "Content-Disposition: inline; filename=\"{$fname}\"\r\n\r\n" . chunk_split(base64_encode($file->getContents()), 76, "\r\n");
	    }
	    $part = [ "multipart/related; boundary=\"{$rel}\"; type=\"multipart/alternative\"", $body . "--{$rel}--\r\n" ];
	}
	if (empty($m['attachments'])) {
	    $headers[] = "Content-Type: {$part[0]}";
	    return [ implode("\r\n", $headers), $part[1] ];
	}
	$mix = 'mix-' . $id;
	$headers[] = "Content-Type: multipart/mixed; boundary=\"{$mix}\"";
	$body = "--{$mix}\r\nContent-Type: {$part[0]}\r\n\r\n" . $part[1];
	foreach($m['attachments'] as $a) {
	    $fname = self::headerText((string)$a['name']);
	    $fname = mb_check_encoding($fname, 'ASCII') ? '"' . addcslashes($fname, '"\\') . '"' : "\"" . mb_encode_mimeheader($fname, 'UTF-8', 'B') . "\"";
	    $type = preg_match('~^[\w.+-]+/[\w.+-]+$~', (string)($a['type'] ?? '')) ? $a['type'] : 'application/octet-stream';
	    $body .= "--{$mix}\r\nContent-Type: {$type}; name={$fname}\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename={$fname}\r\n\r\n"
		. chunk_split(base64_encode((string)$a['content']), 76, "\r\n");
	}
	return [ implode("\r\n", $headers), $body . "--{$mix}--\r\n" ];
    }

    //the job of a queued email (the Send Email step): { aid, from_name, to, cc, bcc, reply_to, subject, html, text, images: [ cid => file id ], attachments: [ file id ] }; sent from
    //the app's address (config mail.from) through the installation's server (send());
    //throws when the SMTP server does not take it (the daemon tries again)
    public static function deliver($data) {
	$data = (array)$data;
	$from = (string)\V::app()->config('mail.from');
	[ $headers, $body ] = self::messageOf($data, $from);
	$result = self::send([ 'sender' => $from, 'headers' => $headers, 'body' => $body ] + self::envelopeOf($data));
	if ($result !== true)
	    throw new \ErrorException('the email "' . ($data['subject'] ?? '') . '" was not accepted by the SMTP server');
    }

    //CLD: 2026-11-28T18:00:00+02:00: the MIME message (compose()) of an email of the Send email steps ({ aid, from_name, to, cc, bcc, reply_to, subject, html, text,
    //images: [ cid => file id ], attachments: [ file id ] }) from $from: [ headers, body ]; an image or attachment file gone is left out (the account's files only)
    public static function messageOf(array $data, $from) {
	$images = [];
	foreach((array)($data['images'] ?? []) as $cid => $fid) {
	    $file = \V::app()->om->findOne('File', [ '_id' => (string)$fid, 'aid' => $data['aid'] ?? null ]);
	    if ($file)
		$images[$cid] = $file;
	}
	$attachments = [];
	foreach((array)($data['attachments'] ?? []) as $fid) {
	    $file = \V::app()->om->findOne('File', [ '_id' => (string)$fid, 'aid' => $data['aid'] ?? null ]);
	    if ($file) {
		$ext = strtolower(pathinfo((string)$file->filename, PATHINFO_EXTENSION));
		$attachments[] = [ 'name' => (string)$file->filename, 'type' => \models\File::CONTENT_TYPES[$ext] ?? 'application/octet-stream', 'content' => (string)$file->getContents() ];
	    }
	}
	return self::compose([ 'from' => (string)$from, 'images' => $images, 'attachments' => $attachments ] + $data);
    }

    //the recipients of an email of the Send email steps, as send() / transmit() take them: [ to / cc / bcc => [ address => '' ] ]
    public static function envelopeOf(array $data) {
	$out = [];
	foreach([ 'to', 'cc', 'bcc' ] as $grp)
	    $out[$grp] = array_fill_keys(array_values(array_filter(array_map('strval', (array)($data[$grp] ?? [])))), '');
	return $out;
    }

    //an email of the app (data\SysEmails: sign-up, later invitations) to $to,
    //filled with $vars (+ app: the app's name),
    //queued as the Send Email step's are (deliver(), run by the daemon); the job's id
    public static function system($name, $to, array $vars = []) {
	$template = \data\SysEmails::$data[$name] ?? null;
	if (!$template)
	    throw new \ErrorException("EmailSend::system: no email `{$name}` (data\\SysEmails)");
	$vars += [ 'app' => (string)\V::app()->config('app.name') ];
	$subject = \util\EmailHtml::fillText($template['subject'], $vars);
	$r = \util\EmailHtml::render($template['email'], $vars, $subject);
	return \models\Job::submit('\util\EmailSend::deliver', [ 'from_name' => $vars['app'], 'to' => (array)$to, 'subject' => $subject, 'html' => $r['html'], 'text' => $r['text'],
	    'images' => $r['images'] ], [ 'delete_on_done' => true ]);
    }
}
