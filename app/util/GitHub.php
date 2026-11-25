<?php

/*
    CLD: 2026-11-25T21:00:00+02:00: the client of GitHub's REST API (https://api.github.com, or GitHub Enterprise Server's https://<host>/api/v3), for the GitHub credential, steps
    and event trigger.
	auth     a personal access token (fine-grained or classic), or a GitHub App: a JWT (RS256, the app's private key, 9 minutes) gives an installation's access token (an hour;
	         kept for the process, renewed 5 minutes before it ends); the installation: the credential's, else the default repository's, else the app's only one
	answers  JSON; errors { message, errors: [ { field, code, message } ], documentation_url }, said with a hint
	lists    pages through the Link header (rel="next"), per_page 100
	limits   a 403 / 429 with x-ratelimit-remaining 0 (or Retry-After) waits while the step's timeout allows
	private  Enterprise Server on a private network only when the account allows private network addresses; its certificate checked unless Trust the certificate
    $g = new GitHub($cred, $aid, $timeout);
    $g->request($method, $path, $query, $body, $o)   the decoded answer ([] when empty; $o: raw [ body, type ], upload [ stream, size, type ], accept, sink, redirects, maxBytes,
                                                      text: the body as it is, full: [ status, headers, data ], jwt: as the app itself)
    $g->pages($path, $query, $max, $key)             a list's items (or those under $key, e.g. workflow_runs) through its pages: [ items, truncated ]
    $g->repo($v)                                      owner/repo of a setting (a URL, an item, else the credential's default repository)
    GitHub::message($d, $status)                      GitHub's error said

    GitHub::$testing: the check suite's local server (http://127.0.0.1:<port>) instead of the API address (uploads: <it>/uploads)
*/

namespace util;

class GitHub {
    const API = 'https://api.github.com';
    const MAX_ANSWER = 30 * 1024 * 1024;
    const PAGE = 100;
    const MAX_WAIT = 60;
    const REPO = '/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/';

    public static $testing = null;
    //installation tokens of this process: [ key => [ token, until ] ]
    private static $tokens = [];

    protected $cred;
    protected $aid;
    protected $timeout;
    protected $start;

    public function __construct($cred, $aid, $timeout = 60) {
	$this->cred = $cred;
	$this->aid = $aid;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //the API's address: the credential's (Enterprise Server), else api.github.com
    public function api() {
	if (self::$testing !== null)
	    return self::$testing;
	$u = rtrim(trim((string)($this->cred->apiUrl ?? '')), '/');
	return $u !== '' ? $u : self::API;
    }

    public function isCloud() {
	return self::$testing === null && $this->api() === self::API;
    }

    //uploads (release assets): uploads.github.com, Enterprise Server's /api/uploads
    public function uploads() {
	if (self::$testing !== null)
	    return self::$testing . '/uploads';
	return $this->isCloud() ? 'https://uploads.github.com' : preg_replace('~/api/v3$~', '', $this->api()) . '/api/uploads';
    }

    //the web address of the API's host (an item's link when GitHub gives none)
    public function web() {
	return $this->isCloud() ? 'https://github.com' : preg_replace('~/api/v3$~', '', $this->api());
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    //the seconds the step's timeout still leaves
    public function remaining() {
	return $this->timeout - (int)(microtime(true) - $this->start);
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [ 'https' => self::$testing === null, 'public' => self::$testing === null, 'private' => !$this->isCloud() && (bool)($account->allow_private_addresses ?? false),
	    'insecure' => !$this->isCloud() && (bool)($this->cred->trustCertificate ?? false), 'compressed' => true ];
    }

    // ------------------------------------------------------------------------------------
    //signing in

    //a JWT of the GitHub App (RS256), 9 minutes
    public function appJwt() {
	$key = openssl_pkey_get_private(trim((string)$this->cred->privateKey));
	if ($key === false)
	    throw new \RuntimeException('the GitHub App\'s private key is not a PEM private key');
	$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
	$head = $b64(json_encode([ 'alg' => 'RS256', 'typ' => 'JWT' ]));
	$body = $b64(json_encode([ 'iat' => time() - 60, 'exp' => time() + 540, 'iss' => trim((string)$this->cred->appId) ]));
	if (!openssl_sign("{$head}.{$body}", $sig, $key, OPENSSL_ALGO_SHA256))
	    throw new \RuntimeException('the JWT could not be signed with the private key');
	return "{$head}.{$body}." . $b64($sig);
    }

    //the token requests are sent with
    protected function token() {
	if (($this->cred->auth ?? 'token') !== 'app')
	    return trim((string)$this->cred->token);
	$installation = trim((string)($this->cred->installationId ?? ''));
	$cacheKey = $this->api() . '|' . trim((string)$this->cred->appId) . '|' . $installation . '|' . trim((string)($this->cred->repository ?? ''));
	if (isset(self::$tokens[$cacheKey]) && self::$tokens[$cacheKey][1] > time() + 300)
	    return self::$tokens[$cacheKey][0];
	$jwt = $this->appJwt();
	if ($installation === '') {
	    $repo = trim((string)($this->cred->repository ?? ''));
	    if ($repo !== '')
		$installation = (string)($this->send('GET', "/repos/{$repo}/installation", [], null, $jwt)['id'] ?? '');
	    else {
		$all = (array)$this->send('GET', '/app/installations', [ 'per_page' => 2 ], null, $jwt);
		if (count($all) !== 1)
		    throw new \RuntimeException(count($all) ? 'the app has several installations: give the installation ID, or a default repository' : 'the app is not installed anywhere');
		$installation = (string)($all[0]['id'] ?? '');
	    }
	}
	if (!preg_match('/^\d{1,20}$/', $installation))
	    throw new \RuntimeException('no installation of the app found');
	$d = $this->send('POST', "/app/installations/{$installation}/access_tokens", [], (object)[], $jwt);
	$until = strtotime((string)($d['expires_at'] ?? '')) ?: time() + 3000;
	self::$tokens[$cacheKey] = [ (string)($d['token'] ?? ''), $until ];
	return self::$tokens[$cacheKey][0];
    }

    // ------------------------------------------------------------------------------------
    //requests

    //what an answer's status means, when GitHub's message alone does not say it
    const HINTS = [ 401 => 'the token is refused (wrong, expired or revoked)', 403 => 'not allowed: the token\'s permissions (scopes), or the organization\'s rules',
	404 => 'not found, or the token may not see it', 409 => 'a conflict', 422 => 'GitHub refused the values' ];

    public static function message($d, $status) {
	$m = is_array($d) ? trim((string)($d['message'] ?? '')) : '';
	$more = [];
	foreach((is_array($d) ? (array)($d['errors'] ?? []) : []) as $e)
	    $more[] = is_array($e) ? trim(($e['field'] ?? '') . ' ' . ($e['message'] ?? $e['code'] ?? '')) : (string)$e;
	$more = array_values(array_filter($more));
	$hint = self::HINTS[$status] ?? '';
	$text = $m !== '' ? $m : $hint;
	if ($more)
	    $text .= ' (' . implode('; ', array_slice($more, 0, 5)) . ')';
	if ($m !== '' && $hint !== '' && in_array($status, [ 401, 404 ], true) && stripos($m, $hint) === false)
	    $text .= ": {$hint}";
	return mb_substr("HTTP status {$status}" . ($text !== '' ? ": {$text}" : ''), 0, 1000);
    }

    //the address of $path (below the API, or an address of the API / uploads)
    public function url($path, array $query = []) {
	$path = (string)$path;
	if (preg_match('~^https?://~i', $path)) {
	    if (!str_starts_with($path, $this->api() . '/') && !str_starts_with($path, $this->uploads() . '/'))
		throw new \RuntimeException('not an address of the GitHub API: `' . mb_substr($path, 0, 100) . '`');
	    $url = $path;
	} else {
	    $path = '/' . ltrim($path, '/');
	    if (str_contains($path, '..') || !preg_match('#^/[A-Za-z0-9/_.,:~@%+=-]*$#', $path))
		throw new \RuntimeException('not an API path: `' . mb_substr($path, 0, 100) . '`');
	    $url = $this->api() . $path;
	}
	$q = http_build_query(array_filter($query, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
	return $url . ($q !== '' ? (str_contains($url, '?') ? '&' : '?') . $q : '');
    }

    //one request with token $token: the answer decoded; $o as request()'s
    protected function send($method, $path, array $query, $body, $token, array $o = []) {
	$url = $this->url($path, $query);
	while (true) {
	    $req = [ 'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Accept' => $o['accept'] ?? 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28' ],
		'timeout' => $this->left(), 'maxBytes' => (int)($o['maxBytes'] ?? self::MAX_ANSWER), 'redirects' => (int)($o['redirects'] ?? 0) ] + $this->options();
	    if (isset($o['raw'])) {
		$req['body'] = $o['raw'][0];
		$req['headers']['Content-Type'] = $o['raw'][1];
	    } elseif (isset($o['upload'])) {
		$req['upload'] = [ $o['upload'][0], (int)$o['upload'][1] ];
		$req['headers']['Content-Type'] = (string)($o['upload'][2] ?? 'application/octet-stream');
	    } elseif ($body !== null)
		$req['json'] = $body;
	    if (isset($o['sink']))
		$req['sink'] = $o['sink'];
	    $r = Http::json($method, $url, $req);
	    $limited = ($r['status'] === 403 || $r['status'] === 429) && (($r['headers']['x-ratelimit-remaining'] ?? null) === '0' || isset($r['headers']['retry-after']));
	    if ($limited && !isset($o['upload'])) {
		$wait = isset($r['headers']['retry-after']) ? (int)$r['headers']['retry-after'] : (int)($r['headers']['x-ratelimit-reset'] ?? 0) - time();
		$wait = max(1, $wait);
		if ($wait > self::MAX_WAIT || $wait >= $this->left())
		    throw new \RuntimeException('GitHub\'s rate limit is reached (HTTP status ' . $r['status'] . '): it resets in ' . $wait . ' s, more than the step\'s timeout leaves');
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (((int)($o['maxBytes'] ?? self::MAX_ANSWER)) >> 20) . ' MB' : 'no answer from ' . Http::shown($url) . ": {$r['error']}");
	if ($r['status'] >= 400)
	    throw new \RuntimeException(self::message($r['data'], $r['status']));
	if (!empty($o['text']))
	    return (string)$r['body'];
	if (!empty($o['full']))
	    return [ $r['status'], $r['headers'], is_array($r['data']) ? $r['data'] : ($r['body'] ?? '') ];
	if (trim((string)$r['body']) !== '' && $r['data'] === null && !isset($o['sink']))
	    return (string)$r['body'];
	return is_array($r['data']) ? $r['data'] : [];
    }

    //a request with the credential's token ($o['jwt']: as the GitHub App itself, e.g. GET /app)
    public function request($method, $path, array $query = [], $body = null, array $o = []) {
	return $this->send(strtoupper((string)$method), $path, $query, $body, !empty($o['jwt']) ? $this->appJwt() : $this->token(), $o);
    }

    //a list's items through its pages (Link rel="next"), at most $max; $key: the items are under it (e.g. workflow_runs, items of the search)
    public function pages($path, array $query, $max, $key = null) {
	$max = max(1, (int)$max);
	$items = [];
	$url = $this->url($path, $query + [ 'per_page' => min(self::PAGE, $max + 1) ]);
	while (true) {
	    [ , $h, $d ] = $this->request('GET', $url, [], null, [ 'full' => true ]);
	    $list = $key !== null ? (array)($d[$key] ?? []) : (is_array($d) && array_is_list($d) ? $d : []);
	    foreach($list as $i) {
		if (count($items) >= $max)
		    return [ $items, true ];
		$items[] = $i;
	    }
	    $next = preg_match('/<([^>]+)>;\s*rel="next"/', (string)($h['link'] ?? ''), $m) ? $m[1] : '';
	    if ($next === '')
		return [ $items, false ];
	    if (count($items) >= $max)
		return [ $items, true ];
	    $url = $next;
	}
    }

    //owner/repo of a setting: owner/repo, its address (https://github.com/owner/repo…), an item ({ full_name } / { repository }); empty: the credential's default
    public function repo($value, $label = 'Repository') {
	if (is_array($value))
	    $value = $value['full_name'] ?? $value['repository'] ?? '';
	$v = trim(Expression::toText($value));
	if ($v === '')
	    $v = trim((string)($this->cred->repository ?? ''));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty, and the credential has no default repository");
	if (preg_match('~^https?://[^/]+/([^/]+/[^/#?]+)~', $v, $m))
	    $v = preg_replace('/\.git$/', '', $m[1]);
	if (!preg_match(self::REPO, $v))
	    throw new \RuntimeException("{$label}: owner/repository, e.g. acme/website: `" . mb_substr($v, 0, 100) . '`');
	return $v;
    }

    //a number (an issue's, a pull request's) of a setting: 12, #12, its address, an item ({ number })
    public static function number($value, $label = 'Number') {
	if (is_array($value))
	    $value = $value['number'] ?? '';
	$v = trim(Expression::toText($value));
	if (preg_match('~/(?:issues|pull|pulls)/(\d+)~', $v, $m))
	    $v = $m[1];
	$v = ltrim($v, '#');
	if (!preg_match('/^\d{1,10}$/', $v) || (int)$v < 1)
	    throw new \RuntimeException("{$label}: a number, e.g. 12: `" . mb_substr($v, 0, 60) . '`');
	return (int)$v;
    }

    //a path in the repository, its segments encoded
    public static function path($value, $label = 'Path', $allowEmpty = false) {
	$v = trim(str_replace('\\', '/', Expression::toText(is_array($value) ? ($value['path'] ?? '') : $value)), '/ ');
	if ($v === '' && $allowEmpty)
	    return '';
	foreach(explode('/', $v) as $p)
	    if ($p === '' || $p === '.' || $p === '..' || preg_match('/[\x00-\x1f]/', $p))
		throw new \RuntimeException("{$label}: not a path in the repository: `" . mb_substr($v, 0, 100) . '`');
	return implode('/', array_map('rawurlencode', explode('/', $v)));
    }

    //a webhook's signature: X-Hub-Signature-256 = sha256=<HMAC-SHA256 of the body with the secret>
    public static function verify($raw, $header, $secret) {
	$secret = (string)$secret;
	$header = strtolower(trim((string)$header));
	if ($secret === '' || !str_starts_with($header, 'sha256='))
	    return false;
	return hash_equals('sha256=' . hash_hmac('sha256', (string)$raw, $secret), $header);
    }
}
