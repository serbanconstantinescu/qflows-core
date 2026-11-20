<?php

/*
    the client of a PatchMon server (patchmon.net, 2.x), for the PatchMon credential, steps and host trigger. Two APIs:
	- the integration API, https://<server>/api/v1/api/...: `Authorization: Basic <token key>:<token secret>` (Settings > Integrations > New token, usage API, scope
	  host: get, and host: delete for deleting hosts): the hosts and their details, packages, reports, agent queue; deleting a host
	- the web interface's API, https://<server>/api/v1/... (patching): a PatchMon user signs in (POST auth/login { username, password }: { token }; a user with two-factor
	  sign-in answers { requiresTfa }: refused here), the token as `Authorization: Bearer`; signOut() ends the session (POST auth/logout). PatchMon does not document it
	  for integrations: it may change between versions
    Answers: JSON; errors { error } (or { message }) made a \RuntimeException. Public addresses only, unless the account allows private network addresses; the certificate
    checked unless the credential trusts it.

    $p = new PatchMon($cred, $aid, $timeout);
    $p->api($method, $path, $query, $body)     the integration API: $path after /api/v1/api (e.g. /hosts)
    $p->user($method, $path, $query, $body)    the web API, signed in as the credential's user (once per client): $path after /api/v1 (e.g. /patching/runs)
    $p->signOut()                              ends the user's session, if signed in
    $p->hostId($value)                         a host's id: its UUID, a host object, or its host name / friendly name (looked up in the list of hosts)

    PatchMon::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace plugins\ITOps\util;

class PatchMon {
    const MAX_ANSWER = 20 * 1024 * 1024;
    const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static $testing = false;

    protected $cred;
    protected $aid;
    protected $timeout;
    protected $start;
    //the user's session token (user())
    protected $token = null;

    public function __construct($cred, $aid, $timeout = 60) {
	$this->cred = $cred;
	$this->aid = $aid;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //the server's address as given: a trailing /, a pasted /api/v1/... dropped
    public static function baseUrl($server) {
	return preg_replace('~/api(/v1)?(/.*)?$~i', '', rtrim(trim((string)$server), '/'));
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	];
    }

    protected function left() {
	return $this->timeout - (int)(microtime(true) - $this->start);
    }

    //a query string (booleans as true / false, empty values left out)
    public static function query(array $query) {
	$parts = [];
	foreach($query as $k => $v) {
	    if ($v === null || $v === '')
		continue;
	    $parts[] = rawurlencode((string)$k) . '=' . rawurlencode(is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? implode(',', $v) : (string)$v));
	}
	return implode('&', $parts);
    }

    //one request: the decoded answer (null when empty); $who: key | user | login (the errors' wording)
    protected function send($method, $url, array $headers, array $query, $body, $who) {
	$left = $this->left();
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	$o = $this->options() + [ 'headers' => $headers + [ 'Accept' => 'application/json' ], 'timeout' => $left, 'maxBytes' => self::MAX_ANSWER ];
	if ($body !== null)
	    $o['json'] = $body === [] ? (object)[] : $body;
	elseif ($method !== 'GET')
	    $o['body'] = '';
	$url .= $query ? '?' . self::query($query) : '';
	$r = \util\Http::json($method, $url, $o);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . \util\Http::shown($url) . ": {$r['error']}");
	if ($r['status'] >= 400)
	    throw new \RuntimeException(self::errorText($r['data'], $r['status'], $who));
	if ($r['body'] !== '' && $r['data'] === null)
	    throw new \RuntimeException('the answer is not PatchMon JSON (is the server\'s address right?)');
	return $r['body'] === '' ? null : $r['data'];
    }

    protected static function checkPath($path) {
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match('#^/[A-Za-z0-9/_.%~-]*$#', $path) || str_contains($path, '..'))
	    throw new \RuntimeException("not a PatchMon API path: `{$path}`");
	return $path;
    }

    //the integration API (the token key and secret)
    public function api($method, $path, array $query = [], $body = null) {
	$path = self::checkPath($path);
	$auth = 'Basic ' . base64_encode(trim((string)$this->cred->tokenKey) . ':' . trim((string)$this->cred->tokenSecret));
	return $this->send($method, self::baseUrl($this->cred->serverUrl) . '/api/v1/api' . $path, [ 'Authorization' => $auth ], $query, $body, 'key');
    }

    //the web interface's API, signed in as the credential's user
    public function user($method, $path, array $query = [], $body = null) {
	$path = self::checkPath($path);
	if ($this->token === null)
	    $this->signIn();
	return $this->send($method, self::baseUrl($this->cred->serverUrl) . '/api/v1' . $path, [ 'Authorization' => 'Bearer ' . $this->token ], $query, $body, 'user');
    }

    //the user's session: [ token, user ] (user: { username, role, ... } as PatchMon gives it)
    public function signIn() {
	$username = trim((string)$this->cred->username);
	if ($username === '' || (string)$this->cred->password === '')
	    throw new \RuntimeException('the credential has no PatchMon user: patching signs in as a user (add one to the credential)');
	$r = $this->send('POST', self::baseUrl($this->cred->serverUrl) . '/api/v1/auth/login', [], [], [ 'username' => $username, 'password' => (string)$this->cred->password ], 'login');
	if (!empty($r['requiresTfa']))
	    throw new \RuntimeException("the PatchMon user {$username} signs in with two-factor authentication: use a user without it for the workflows");
	$token = (string)($r['token'] ?? $r['access_token'] ?? '');
	if ($token === '')
	    throw new \RuntimeException('PatchMon gave no session token (is the server\'s address right?)');
	$this->token = $token;
	return [ $token, (array)($r['user'] ?? []) ];
    }

    //ends the user's session (errors ignored: the session expires by itself)
    public function signOut() {
	if ($this->token === null)
	    return;
	try {
	    $this->send('POST', self::baseUrl($this->cred->serverUrl) . '/api/v1/auth/logout', [ 'Authorization' => 'Bearer ' . $this->token ], [], null, 'user');
	} catch (\RuntimeException $e) {
	}
	$this->token = null;
    }

    //PatchMon's error: { error } or { message }, with what a status means for the key or the user
    public static function errorText($d, $status, $who = 'key') {
	$text = "HTTP status {$status}";
	$msg = is_array($d) ? trim((string)($d['error'] ?? $d['message'] ?? '')) : '';
	if ($status === 401 && $who === 'key')
	    return $text . ': the API token key or secret is refused (or the token is disabled or expired)';
	if ($status === 401 && $who === 'login')
	    return $text . ': the PatchMon user or password is refused';
	if ($status === 401)
	    return $text . ': the PatchMon session ended' . ($msg !== '' ? " ({$msg})" : '');
	if ($status === 403 && $who === 'key' && $msg === '')
	    return $text . ': the token lacks the scope, or this address is not allowed to use it';
	if ($status === 404 && $msg === '')
	    return $text . ': not found (is the server\'s address right?)';
	return mb_substr($text . ($msg !== '' ? ": {$msg}" : ''), 0, 600);
    }

    //a host's id: a UUID as given; a host object's id; else a host name or friendly name, looked up (case-insensitive) in the list of hosts
    public function hostId($value, $label = 'Host') {
	if (is_array($value))
	    $value = $value['id'] ?? $value['host_id'] ?? $value['hostname'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (preg_match(self::UUID, $v))
	    return strtolower($v);
	if (!preg_match('/^[A-Za-z0-9_.:-]{1,253}$/', $v))
	    throw new \RuntimeException("{$label}: not a host id or name: `" . mb_substr($v, 0, 80) . '`');
	$found = [];
	foreach((array)($this->api('GET', '/hosts')['hosts'] ?? []) as $h)
	    if (is_array($h) && (strcasecmp((string)($h['hostname'] ?? ''), $v) === 0 || strcasecmp((string)($h['friendly_name'] ?? ''), $v) === 0))
		$found[(string)$h['id']] = true;
	if (!$found)
	    throw new \RuntimeException("{$label}: PatchMon has no host named `{$v}`");
	if (count($found) > 1)
	    throw new \RuntimeException("{$label}: several PatchMon hosts are named `{$v}`: use the host's id");
	return array_key_first($found);
    }
}
