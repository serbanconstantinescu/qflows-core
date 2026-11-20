<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: the client of a Synology NAS (DSM 6.2 and 7.x Web API), for the Synology credential, steps and NAS trigger:
	- the APIs the NAS has: SYNO.API.Info (query.cgi, query=all, once per client): each API's path (entry.cgi on DSM 7) and versions; a call uses the newest version
	  up to the one asked for, an API the NAS does not have refused before any call
	- the session: SYNO.API.Auth login (POST: account, passwd, session webui, format sid, enable_syno_token; otp_code from the credential's two-factor secret, util\TOTP;
	  a code refused once: the next 30 s code tried, time allowing), then _sid and the X-SYNO-TOKEN header on each call; signOut() (logout) at the end of the step's run
	- calls: POST form to webapi/<path> (api, version, method, _sid, the parameters: arrays as JSON, booleans as true / false); DSM answers 200 with
	  { success, data } or { success: false, error: { code, errors: [ { code, path } ] } }: the code made a text (the common codes, Auth's, File Station's), a \RuntimeException
	- File Station's transfers: download() (GET, the file written to a stream; a JSON answer is an error), upload() (multipart, the file last), task() (a background task
	  of File Station read until it finishes)
    File Station, Download Station and Surveillance Station are documented by Synology; SYNO.Core.*, SYNO.Storage.CGI.* and SYNO.Backup.* are the APIs of DSM's own web
    interface: not documented for integrations, they may change between DSM versions.
    Public addresses only, unless the account allows private network addresses; the certificate checked unless the credential trusts it (or with its CA certificate).

    $s = new Synology($cred, $aid, $timeout);
    $s->call($api, $method, $params, $version)    the call's data (array; [] when none)
    $s->apis()                                    the NAS's APIs: [ name => { path, minVersion, maxVersion } ]
    $s->signIn() / $s->signOut()
    $s->download($path, $stream, $maxBytes)       [ size, content type ]
    $s->upload($folder, $name, $stream, $size, $overwrite, $parents)
    $s->task($api, $taskid, $version, $deadline)  File Station's task status, read until finished (or the deadline): its last status + { finished }

    Synology::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace plugins\ITOps\util;

class Synology {
    const MAX_ANSWER = 20 * 1024 * 1024;
    const SESSION = 'webui';
    //seconds between two reads of a background task (the check suite shortens it)
    public static $poll = 2;

    public static $testing = false;

    //the common error codes of every API
    const COMMON_ERRORS = [
	100 => 'unknown error', 101 => 'no parameter of API, method or version', 102 => 'the API does not exist', 103 => 'the method does not exist',
	104 => 'this version is not supported', 105 => 'the signed-in user has no permission for this', 106 => 'the session timed out', 107 => 'the session was ended by another sign-in',
	108 => 'the upload failed', 109 => 'the network connection is unstable or the system is busy', 110 => 'the network connection is unstable or the system is busy',
	111 => 'the network connection is unstable or the system is busy', 114 => 'a parameter is missing', 115 => 'not allowed to upload a file',
	116 => 'not allowed in demo mode', 117 => 'the network connection is unstable or the system is busy', 118 => 'the network connection is unstable or the system is busy',
	119 => 'the session id was not found', 120 => 'a parameter is not valid', 150 => 'the request\'s address is not the one signed in from',
	160 => 'the user\'s application permission does not allow this',
    ];
    //SYNO.API.Auth's
    const AUTH_ERRORS = [
	400 => 'the account or password is refused', 401 => 'the account is disabled', 402 => 'permission denied (is the account allowed to use DSM?)',
	403 => 'the account signs in with a two-factor code: give the credential its two-factor secret', 404 => 'the two-factor code is refused (is the two-factor secret right, and the server\'s clock?)',
	406 => 'two-factor sign-in is enforced for this account: give the credential its two-factor secret', 407 => 'this address is blocked (DSM\'s auto block): unblock qflows\'s address',
	408 => 'the password expired and cannot be changed', 409 => 'the password expired', 410 => 'the password must be changed',
    ];
    //SYNO.FileStation.*'s
    const FILE_ERRORS = [
	400 => 'not a valid file operation', 401 => 'unknown error of the file operation', 402 => 'the system is too busy', 403 => 'this user may not do this file operation',
	404 => 'this group may not do this file operation', 405 => 'this user and group may not do this file operation', 406 => 'cannot read the user or group from the account server',
	407 => 'operation not permitted', 408 => 'no such file or folder', 409 => 'the file system is not supported', 410 => 'cannot connect to the cloud file system',
	411 => 'read-only file system', 412 => 'the name is too long', 413 => 'the name is too long (encrypted folder)', 414 => 'the file or folder exists already',
	415 => 'over the disk quota', 416 => 'no space left on the volume', 417 => 'input / output error', 418 => 'not a valid name or path', 419 => 'not a valid file name',
	420 => 'not a valid file name on a FAT file system', 421 => 'the device or resource is busy', 599 => 'no such task',
	1800 => 'the upload ended early (its size is not the one sent)', 1801 => 'the upload timed out', 1802 => 'the upload has no file name',
	1803 => 'the upload was cancelled', 1804 => 'the file is too large for the file system', 1805 => 'the file exists already',
    ];

    protected $cred;
    protected $aid;
    protected $timeout;
    protected $start;
    protected $sid = null;
    protected $synoToken = '';
    protected $apis = null;

    public function __construct($cred, $aid, $timeout = 60) {
	$this->cred = $cred;
	$this->aid = $aid;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //the NAS's address as given: a trailing /, a pasted /webapi/... or /#... dropped
    public static function baseUrl($server) {
	return preg_replace('~(/webapi(/.*)?|/#.*|/\?.*)$~i', '', rtrim(trim((string)$server), '/'));
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	    'caCerts' => (string)($this->cred->caCertificate ?? ''),
	];
    }

    //the account signed in as
    public function accountName() {
	return trim((string)$this->cred->username);
    }

    public function left() {
	return $this->timeout - (int)(microtime(true) - $this->start);
    }

    protected function requireTime() {
	$left = $this->left();
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    //a parameter as DSM takes it: booleans true / false, arrays and objects as JSON, null left out
    public static function param($v) {
	if (is_bool($v))
	    return $v ? 'true' : 'false';
	if (is_array($v) || is_object($v))
	    return \util\JSON::encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	return (string)$v;
    }

    protected static function form(array $params) {
	$out = [];
	foreach($params as $k => $v)
	    if ($v !== null)
		$out[$k] = self::param($v);
	return $out;
    }

    //one POST to webapi/<path>: DSM's answer decoded
    protected function post($path, array $form, $api) {
	$left = $this->requireTime();
	$url = self::baseUrl($this->cred->serverUrl) . '/webapi/' . $path;
	$headers = [ 'Accept' => 'application/json' ] + ($this->synoToken !== '' ? [ 'X-SYNO-TOKEN' => $this->synoToken ] : []);
	$r = \util\Http::request('POST', $url, $this->options() + [ 'headers' => $headers, 'form' => self::form($form), 'timeout' => $left, 'maxBytes' => self::MAX_ANSWER ]);
	return $this->decoded($r, $url, $api);
    }

    //an answer of DSM: its data, or its error as a \RuntimeException
    protected function decoded(array $r, $url, $api) {
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . \util\Http::shown($url) . ": {$r['error']}");
	$d = json_decode((string)$r['body'], true);
	if ($r['status'] >= 400 && !is_array($d))
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($r['status'] === 404 ? ': not found (is the NAS\'s address right, its DSM port?)' : ''));
	if (!is_array($d) || !array_key_exists('success', $d))
	    throw new \RuntimeException('the answer is not DSM\'s (is the NAS\'s address right, its DSM port?)');
	if (!$d['success'])
	    throw new \RuntimeException(self::errorText((array)($d['error'] ?? []), $api));
	return is_array($d['data'] ?? null) ? $d['data'] : [];
    }

    //DSM's error { code, errors: [ { code, path } ] }: its text, by the API's family
    public static function errorText(array $e, $api) {
	$code = (int)($e['code'] ?? 0);
	$table = str_starts_with((string)$api, 'SYNO.API.Auth') ? self::AUTH_ERRORS : (str_starts_with((string)$api, 'SYNO.FileStation.') ? self::FILE_ERRORS : []);
	$text = $table[$code] ?? self::COMMON_ERRORS[$code] ?? 'error';
	$details = [];
	foreach(array_slice((array)($e['errors'] ?? []), 0, 5) as $x) {
	    if (!is_array($x))
		continue;
	    $c = (int)($x['code'] ?? 0);
	    $what = $table[$c] ?? self::COMMON_ERRORS[$c] ?? "code {$c}";
	    $details[] = (isset($x['path']) ? "{$x['path']}: " : (isset($x['name']) ? "{$x['name']}: " : '')) . $what;
	}
	return mb_substr("{$api}: {$text} (code {$code})" . ($details ? ': ' . implode('; ', $details) : ''), 0, 600);
    }

    //the NAS's APIs (SYNO.API.Info: query.cgi answers on DSM 6 and 7)
    public function apis() {
	if ($this->apis === null)
	    $this->apis = $this->post('query.cgi', [ 'api' => 'SYNO.API.Info', 'version' => 1, 'method' => 'query', 'query' => 'all' ], 'SYNO.API.Info');
	return $this->apis;
    }

    //[ path, version ] of $api: the newest up to $want (null: its newest)
    protected function route($api, $want = null) {
	$a = $this->apis()[$api] ?? null;
	if (!is_array($a))
	    throw new \RuntimeException("this NAS has no {$api} (is its package installed, its DSM version recent enough?)");
	$min = (int)($a['minVersion'] ?? 1);
	$max = (int)($a['maxVersion'] ?? $min);
	$version = $want === null ? $max : min((int)$want, $max);
	if ($version < $min)
	    throw new \RuntimeException("{$api}: this NAS has versions {$min} to {$max}, not {$want}");
	$path = (string)($a['path'] ?? 'entry.cgi');
	if (!preg_match('~^[A-Za-z0-9_./-]+\.cgi$~', $path) || str_contains($path, '..'))
	    throw new \RuntimeException("{$api}: an unexpected path `{$path}`");
	return [ $path, $version ];
    }

    //the session: [ sid, did ]; the two-factor code from the credential's secret
    public function signIn() {
	$account = trim((string)$this->cred->username);
	if ($account === '' || (string)$this->cred->password === '')
	    throw new \RuntimeException('the credential has no account or password');
	[ $path, $version ] = $this->route('SYNO.API.Auth', 7);
	$form = [ 'api' => 'SYNO.API.Auth', 'version' => $version, 'method' => 'login', 'account' => $account, 'passwd' => (string)$this->cred->password,
	    'session' => self::SESSION, 'format' => 'sid', 'enable_syno_token' => 'yes' ];
	$secret = preg_replace('/[\s=-]/', '', strtoupper((string)($this->cred->otpSecret ?? '')));
	$slice = null;
	for ($try = 0; ; $try++) {
	    if ($secret !== '') {
		$now = time();
		//a second try: the next code (DSM refuses a code used already)
		if ($slice !== null && intdiv($now, 30) === $slice) {
		    $wait = 30 - ($now % 30) + 1;
		    if ($this->left() <= $wait + 5)
			throw new \RuntimeException('SYNO.API.Auth: ' . self::AUTH_ERRORS[404] . ' (code 404)');
		    sleep($wait);
		    $now = time();
		}
		$slice = intdiv($now, 30);
		$form['otp_code'] = (new \util\TOTP('qflows'))->getCode($secret, $now);
	    }
	    try {
		$d = $this->post($path, $form, 'SYNO.API.Auth');
		break;
	    } catch (\RuntimeException $e) {
		if ($secret === '' || $try > 0 || !str_contains($e->getMessage(), '(code 404)'))
		    throw $e;
	    }
	}
	$sid = (string)($d['sid'] ?? '');
	if ($sid === '')
	    throw new \RuntimeException('DSM gave no session (is the NAS\'s address right?)');
	$this->sid = $sid;
	$this->synoToken = (string)($d['synotoken'] ?? '');
	return [ $sid, (string)($d['did'] ?? '') ];
    }

    //ends the session (errors ignored: it expires by itself)
    public function signOut() {
	if ($this->sid === null)
	    return;
	try {
	    [ $path, $version ] = $this->route('SYNO.API.Auth', 7);
	    if ($this->left() >= 1)
		$this->post($path, [ 'api' => 'SYNO.API.Auth', 'version' => $version, 'method' => 'logout', 'session' => self::SESSION, '_sid' => $this->sid ], 'SYNO.API.Auth');
	} catch (\RuntimeException $e) {
	}
	$this->sid = null;
	$this->synoToken = '';
    }

    protected static function checkName($api, $method) {
	if (!preg_match('/^SYNO(\.[A-Za-z0-9_]+)+$/', (string)$api))
	    throw new \RuntimeException("not a DSM API name: `" . mb_substr((string)$api, 0, 80) . '`');
	if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$method))
	    throw new \RuntimeException("not a method name: `" . mb_substr((string)$method, 0, 80) . '`');
    }

    //a call, signed in: its data
    public function call($api, $method, array $params = [], $version = null) {
	self::checkName($api, $method);
	[ $path, $v ] = $this->route($api, $version);
	if ($this->sid === null)
	    $this->signIn();
	unset($params['api'], $params['version'], $params['method'], $params['_sid']);
	return $this->post($path, [ 'api' => $api, 'version' => $v, 'method' => $method ] + $params + [ '_sid' => $this->sid ], $api);
    }

    //a file of the NAS written to $stream: [ size, content type ]
    public function download($path, $stream, $maxBytes) {
	[ $p, $v ] = $this->route('SYNO.FileStation.Download', 2);
	if ($this->sid === null)
	    $this->signIn();
	$url = self::baseUrl($this->cred->serverUrl) . "/webapi/{$p}?" . http_build_query([ 'api' => 'SYNO.FileStation.Download', 'version' => $v, 'method' => 'download',
	    'path' => $path, 'mode' => 'download', '_sid' => $this->sid ], '', '&', PHP_QUERY_RFC3986);
	$r = \util\Http::request('GET', $url, $this->options() + [ 'headers' => $this->synoToken !== '' ? [ 'X-SYNO-TOKEN' => $this->synoToken ] : [],
	    'timeout' => $this->requireTime(), 'maxBytes' => $maxBytes, 'sink' => $stream ]);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the file is larger than ' . ($maxBytes >> 20) . ' MB' : 'no answer from ' . \util\Http::shown($url) . ": {$r['error']}");
	//an error is JSON (a file can be JSON too: only a small one that is DSM's error is taken as one)
	if ($r['mime'] === 'application/json' || $r['mime'] === 'text/plain' || $r['status'] >= 400) {
	    if ($r['size'] < 4096) {
		rewind($stream);
		$d = json_decode((string)stream_get_contents($stream), true);
		if (is_array($d) && ($d['success'] ?? null) === false)
		    throw new \RuntimeException(self::errorText((array)($d['error'] ?? []), 'SYNO.FileStation.Download'));
	    }
	    if ($r['status'] >= 400)
		throw new \RuntimeException("HTTP status {$r['status']}");
	}
	return [ (int)$r['size'], (string)$r['mime'] ];
    }

    //a file uploaded into $folder (multipart: the parameters, then the file); $overwrite: true / false (skip) / null (an existing file refused: 414)
    public function upload($folder, $name, $stream, $size, $overwrite = null, $parents = false) {
	[ $p, $v ] = $this->route('SYNO.FileStation.Upload', 2);
	if ($this->sid === null)
	    $this->signIn();
	$boundary = '----qflows' . bin2hex(random_bytes(12));
	$fields = [ 'path' => $folder, 'create_parents' => $parents ? 'true' : 'false' ] + ($overwrite === null ? [] : [ 'overwrite' => $overwrite ? 'true' : 'false' ]);
	$head = '';
	foreach($fields as $k => $val)
	    $head .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$val}\r\n";
	$quoted = str_replace([ '"', "\r", "\n" ], [ '%22', '', '' ], $name);
	$head .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$quoted}\"\r\nContent-Type: application/octet-stream\r\n\r\n";
	$tail = "\r\n--{$boundary}--\r\n";
	//the body in a temporary stream (on disk past 2 MB): the parts around the file
	$body = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	try {
	    fwrite($body, $head);
	    $copied = stream_copy_to_stream($stream, $body);
	    if ($copied !== (int)$size)
		throw new \RuntimeException("the file could not be read ({$copied} of {$size} bytes)");
	    fwrite($body, $tail);
	    $length = strlen($head) + $copied + strlen($tail);
	    rewind($body);
	    $url = self::baseUrl($this->cred->serverUrl) . "/webapi/{$p}?" . http_build_query([ 'api' => 'SYNO.FileStation.Upload', 'version' => $v, 'method' => 'upload',
		'_sid' => $this->sid ], '', '&', PHP_QUERY_RFC3986);
	    $r = \util\Http::request('POST', $url, $this->options() + [ 'headers' => [ 'Content-Type' => "multipart/form-data; boundary={$boundary}", 'Accept' => 'application/json' ]
		+ ($this->synoToken !== '' ? [ 'X-SYNO-TOKEN' => $this->synoToken ] : []), 'upload' => [ $body, $length ], 'timeout' => $this->requireTime(), 'maxBytes' => 1024 * 1024 ]);
	} finally {
	    fclose($body);
	}
	return $this->decoded($r, $url, 'SYNO.FileStation.Upload');
    }

    //a background task of File Station (CopyMove, Delete, Compress, Extract, Search: their `status`) read until it finishes or the deadline passes: its status + { finished }
    public function task($api, $taskid, $version, $deadline) {
	while(true) {
	    $s = $this->call($api, 'status', [ 'taskid' => $taskid ], $version);
	    $finished = !empty($s['finished']);
	    if ($finished || microtime(true) + self::$poll > $deadline)
		return [ 'finished' => $finished ] + $s;
	    sleep(self::$poll);
	}
    }
}
