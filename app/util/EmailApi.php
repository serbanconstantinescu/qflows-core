<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: the base of the email services' clients (util\Brevo, util\Mailjet, util\AmazonSes), on util\Http: JSON in and out, the time left of the step's
    timeout, a 429 (and a 503 of SES) waited for (Retry-After, else 2 s) and sent again while the timeout allows, the service's message of an error answer.

    $c->request($method, $path, $query, $body)   the answer (decoded JSON; [] when empty); $path below the service's base URL, its segments already encoded
    A service: base() (its URL), headers($method, $url, $payload) (its sign-in), errorText($data, $response) (its message of an error), NAME (in the messages)
    static::$testing: the check suite's local server (http://127.0.0.1:<port>) instead of the service's address
*/

namespace util;

abstract class EmailApi {
    const NAME = '';
    const MAX_ANSWER = 10 * 1024 * 1024;
    const MAX_WAIT = 30;
    const PATH = '#^/[A-Za-z0-9/_.,:~@%+=!-]*$#';
    //the statuses waited for and sent again
    const RETRY = [ 429 ];

    public static $testing = null;

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //the service's base URL (no trailing /)
    abstract public function base();

    //the headers of a request (its sign-in), $payload: the body sent ('' when none)
    abstract protected function headers($method, $url, $payload);

    //the service's message of an error answer ('' when none)
    abstract protected static function errorText($data, array $r);

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    //a request: the answer decoded ([] when empty)
    public function request($method, $path, array $query = [], $body = null) {
	$method = strtoupper((string)$method);
	$path = '/' . ltrim((string)$path, '/');
	if (!preg_match(static::PATH, $path) || str_contains($path, '..'))
	    throw new \RuntimeException('not an API path: `' . mb_substr($path, 0, 100) . '`');
	$q = http_build_query(array_filter($query, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
	$url = $this->base() . $path . ($q !== '' ? "?{$q}" : '');
	$payload = $body === null ? '' : JSON::encode($body === [] ? (object)[] : $body);
	while (true) {
	    $o = [ 'headers' => $this->headers($method, $url, $payload) + ($payload !== '' ? [ 'Content-Type' => 'application/json' ] : []), 'https' => static::$testing === null,
		'public' => static::$testing === null, 'maxBytes' => self::MAX_ANSWER, 'timeout' => $this->left() ];
	    if ($payload !== '' || !in_array($method, [ 'GET', 'DELETE', 'HEAD' ], true))
		$o['body'] = $payload;
	    $r = Http::json($method, $url, $o);
	    if (in_array($r['status'], static::RETRY, true)) {
		$wait = min(self::MAX_WAIT, max(1, (int)($r['headers']['retry-after'] ?? 2)));
		if ($wait >= $this->left())
		    throw new \RuntimeException(static::NAME . "'s rate limit is reached (HTTP status {$r['status']}), and the step's timeout does not leave time to wait");
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown($this->base()) . ": {$r['error']}");
	if ($r['status'] >= 400) {
	    $m = static::errorText($r['data'], $r);
	    $hint = [ 401 => 'the credential is refused', 403 => 'the credential may not do this', 404 => 'not found' ][$r['status']] ?? '';
	    throw new \RuntimeException("HTTP status {$r['status']}" . ($m !== '' ? ": {$m}" : ($hint !== '' ? ": {$hint}" : '')));
	}
	if (trim((string)$r['body']) !== '' && $r['data'] === null)
	    throw new \RuntimeException('the answer is not ' . static::NAME . '\'s JSON');
	return is_array($r['data']) ? $r['data'] : [];
    }

    //a path segment (an id, an address), encoded
    public static function segment($v, $label) {
	$v = trim((string)$v);
	if ($v === '' || strlen($v) > 512 || preg_match('/[\x00-\x1f]/', $v))
	    throw new \RuntimeException("{$label}: " . ($v === '' ? 'none given' : 'not a valid value'));
	return rawurlencode($v);
    }
}
