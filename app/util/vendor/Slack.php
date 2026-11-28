<?php

/*
    CLD: 2026-11-25T14:00:00+02:00: the client of Slack's Web API (https://slack.com/api/<method>), for the Slack credential, steps and message trigger.
	auth     the bot token of the workspace's Slack app (xoxb-…, Bearer); the methods' arguments sent as a form (every method takes one; lists and objects as JSON text)
	answers  { ok, … }; ok false: { error: a code, e.g. channel_not_found, missing_scope (needed, provided), not_in_channel }, said with a hint
	lists    cursor paging (limit, response_metadata.next_cursor)
	limits   a 429 waits for Retry-After and is sent again while the step's timeout allows
    Events: Slack signs each request it sends (X-Slack-Signature: v0=<HMAC-SHA256 of "v0:<X-Slack-Request-Timestamp>:<body>">, the app's signing secret); older than 5 minutes: refused.

    $s = new Slack($cred, $timeout);
    $s->call($method, $args)                  the answer (ok true); \RuntimeException with Slack's error
    $s->pages($method, $args, $key, $max)     a list's items ($key of the answer) through the cursor: [ items, truncated ]
    $s->uploadBytes($url, $stream, $size)     a file's bytes to the address files.getUploadURLExternal gave
    $s->download($url, $sink, $maxBytes)      a file of the workspace (url_private_download, Bearer) into a stream: [ size, mime ]
    Slack::verify($raw, $timestamp, $signature, $secret, $now)   an event's signature
    Slack::mrkdwn($markdown)                  Markdown as Slack's mrkdwn (bold, italic, strike, links, headings, lists, code)
    Slack::escape($text)                      a text shown as typed (&, <, > escaped)
    Slack::channelId($v), Slack::ts($v)       a channel's id or #name of a setting, a message's ts of a setting (a ts, a message, its link)

    Slack::$testing: the check suite's local server (http://127.0.0.1:<port>) instead of https://slack.com
*/

namespace util\vendor;

use util\Expression;
use util\Http;

class Slack {
    const MAX_ANSWER = 20 * 1024 * 1024;
    const PAGE = 200;
    const MAX_WAIT = 60;
    //an event older than this (s) is refused (replayed)
    const MAX_AGE = 300;
    const METHOD = '/^[a-z][A-Za-z0-9]*(\.[A-Za-z0-9]+)+$/';

    public static $testing = null;

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    public static function base() {
	return self::$testing ?? 'https://slack.com';
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    //what an error code means, when Slack's code alone does not say it
    const HINTS = [
	'invalid_auth' => 'the bot token is refused',
	'not_authed' => 'no bot token',
	'account_inactive' => 'the app was removed from the workspace, or its token revoked',
	'token_revoked' => 'the bot token was revoked',
	'missing_scope' => 'the app lacks a permission (scope)',
	'channel_not_found' => 'no such channel, or the app cannot see it (a private channel: invite the app)',
	'not_in_channel' => 'the app is not a member of the channel: invite it (/invite @app)',
	'is_archived' => 'the channel is archived',
	'users_not_found' => 'no such user',
	'user_not_found' => 'no such user',
	'message_not_found' => 'no such message',
	'cant_update_message' => 'only the app\'s own messages can be changed',
	'cant_delete_message' => 'only the app\'s own messages can be deleted',
	'already_reacted' => 'that reaction is already there',
	'invalid_name' => 'not a valid emoji or channel name',
	'name_taken' => 'a channel with that name exists',
	'already_in_channel' => 'the user is already in the channel',
	'restricted_action' => 'the workspace\'s settings do not allow it',
	'ratelimited' => 'Slack\'s rate limit is reached',
	'unknown_method' => 'no such method',
    ];

    //Slack's error of an answer, with what it means (and the scope a missing_scope needs)
    public static function message($d) {
	if (!is_array($d))
	    return '';
	$code = (string)($d['error'] ?? '');
	$m = $code . (isset(self::HINTS[$code]) ? ': ' . self::HINTS[$code] : '');
	if ($code === 'missing_scope' && !empty($d['needed']))
	    $m .= ' `' . $d['needed'] . '` (add it under OAuth & Permissions, then reinstall the app)';
	$more = array_values(array_filter(array_map('strval', (array)($d['response_metadata']['messages'] ?? []))));
	if ($more)
	    $m .= ' (' . implode('; ', array_slice($more, 0, 3)) . ')';
	return mb_substr($m, 0, 1000);
    }

    //a call of method $method with $args (lists and objects as JSON text): the answer; \RuntimeException when not ok
    public function call($method, array $args = []) {
	$method = (string)$method;
	if (!preg_match(self::METHOD, $method))
	    throw new \RuntimeException('not a Web API method: `' . mb_substr($method, 0, 60) . '`');
	$form = [];
	foreach($args as $k => $v) {
	    if ($v === null)
		continue;
	    $form[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v);
	}
	while (true) {
	    $r = Http::json('POST', self::base() . '/api/' . $method, [ 'headers' => [ 'Authorization' => 'Bearer ' . trim((string)$this->cred->botToken) ], 'form' => $form,
		'https' => self::$testing === null, 'public' => self::$testing === null, 'maxBytes' => self::MAX_ANSWER, 'timeout' => $this->left(), 'compressed' => true ]);
	    if ($r['status'] === 429) {
		$wait = min(self::MAX_WAIT, max(1, (int)($r['headers']['retry-after'] ?? 10)));
		if ($wait >= $this->left())
		    throw new \RuntimeException('Slack\'s rate limit is reached (HTTP status 429), and the step\'s timeout does not leave time to wait');
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from Slack: ' . $r['error']);
	$d = $r['data'];
	if (!is_array($d))
	    throw new \RuntimeException("not Slack's answer (HTTP status {$r['status']})");
	if (empty($d['ok']))
	    throw new \RuntimeException(self::message($d) ?: "HTTP status {$r['status']}");
	return $d;
    }

    //a list's items ($key) through the cursor, at most $max: [ items, truncated ]
    public function pages($method, array $args, $key, $max) {
	$max = max(1, (int)$max);
	$items = [];
	$cursor = null;
	while (true) {
	    $d = $this->call($method, $args + [ 'limit' => min(self::PAGE, $max + 1) ] + ($cursor !== null ? [ 'cursor' => $cursor ] : []));
	    foreach((array)($d[$key] ?? []) as $i) {
		if (count($items) >= $max)
		    return [ $items, true ];
		$items[] = $i;
	    }
	    $cursor = (string)($d['response_metadata']['next_cursor'] ?? '');
	    if ($cursor === '')
		return [ $items, false ];
	    if (count($items) >= $max)
		return [ $items, true ];
	}
    }

    //a file's bytes to the address of files.getUploadURLExternal (no token: the address is the permission)
    public function uploadBytes($url, $stream, $size) {
	if (self::$testing === null && !str_starts_with((string)$url, 'https://files.slack.com/'))
	    throw new \RuntimeException('Slack gave an upload address that is not files.slack.com');
	$r = Http::request('POST', (string)$url, [ 'upload' => [ $stream, (int)$size ], 'headers' => [ 'Content-Type' => 'application/octet-stream' ], 'https' => self::$testing === null,
	    'public' => self::$testing === null, 'timeout' => $this->left() ]);
	if ($r['error'] !== null || $r['status'] >= 400)
	    throw new \RuntimeException('the upload failed: ' . ($r['error'] ?? "HTTP status {$r['status']}"));
    }

    //a file of the workspace (its url_private_download, on files.slack.com) into stream $sink, at most $maxBytes: [ size, mime ]
    public function download($url, $sink, $maxBytes) {
	$url = (string)$url;
	if (!str_starts_with($url, self::$testing !== null ? self::$testing . '/' : 'https://files.slack.com/'))
	    throw new \RuntimeException('not a file address of Slack (files.slack.com): `' . mb_substr($url, 0, 100) . '`');
	$r = Http::request('GET', $url, [ 'headers' => [ 'Authorization' => 'Bearer ' . trim((string)$this->cred->botToken) ], 'sink' => $sink, 'maxBytes' => (int)$maxBytes, 'redirects' => 3,
	    'https' => self::$testing === null, 'public' => self::$testing === null, 'timeout' => $this->left() ]);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the file is larger than ' . ((int)$maxBytes >> 20) . ' MB' : 'the download failed: ' . $r['error']);
	if ($r['status'] >= 400)
	    throw new \RuntimeException("the download failed: HTTP status {$r['status']}");
	//Slack answers a token without files:read with its sign-in page
	if ($r['mime'] === 'text/html')
	    throw new \RuntimeException('Slack answered with a web page, not the file: the app needs the scope files:read');
	return [ (int)$r['size'], (string)$r['mime'] ];
    }

    //an event's signature (X-Slack-Signature: v0=…) of $raw at $timestamp (X-Slack-Request-Timestamp) with the app's signing secret; not older than MAX_AGE
    public static function verify($raw, $timestamp, $signature, $secret, $now = null) {
	$secret = (string)$secret;
	$timestamp = trim((string)$timestamp);
	$signature = strtolower(trim((string)$signature));
	if ($secret === '' || !preg_match('/^\d{1,12}$/', $timestamp) || !str_starts_with($signature, 'v0='))
	    return false;
	if (abs(($now ?? time()) - (int)$timestamp) > self::MAX_AGE)
	    return false;
	return hash_equals('v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . (string)$raw, $secret), $signature);
    }

    //a text shown as typed in a message
    public static function escape($text) {
	return str_replace([ '&', '<', '>' ], [ '&amp;', '&lt;', '&gt;' ], (string)$text);
    }

    //Markdown as Slack's mrkdwn: **bold** / __bold__ -> *bold*, *italic* / _italic_ -> _italic_, ~~strike~~ -> ~strike~, [text](url) -> <url|text>, # heading -> *heading*,
    //- / * items -> •; code (`…`, ``` blocks) kept as it is; &, <, > escaped outside links
    public static function mrkdwn($md) {
	$md = str_replace("\r\n", "\n", (string)$md);
	$parts = preg_split('/(```[\s\S]*?```|`[^`\n]+`)/', $md, -1, PREG_SPLIT_DELIM_CAPTURE);
	$out = '';
	foreach($parts as $i => $p) {
	    if ($i % 2 === 1) {
		$out .= self::escape($p);
		continue;
	    }
	    $links = [];
	    $p = preg_replace_callback('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/', function($m) use (&$links) {
		$links[] = '<' . str_replace([ '|', '>' ], [ '%7C', '%3E' ], $m[2]) . '|' . str_replace([ '|', '>', '<' ], [ '¦', '›', '‹' ], $m[1]) . '>';
		return "\x01" . (count($links) - 1) . "\x02";
	    }, $p);
	    $p = self::escape($p);
	    $p = preg_replace('/^#{1,6}\s+(.+?)\s*#*$/m', "\x03$1\x03", $p);
	    $p = preg_replace('/^(\s*)[-*+]\s+/m', '$1• ', $p);
	    $p = preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/', "\x03$2\x03", $p);
	    $p = preg_replace('/(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\w*])/', '_$1_', $p);
	    $p = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/', '~$1~', $p);
	    $p = str_replace("\x03", '*', $p);
	    $p = preg_replace_callback('/\x01(\d+)\x02/', fn($m) => $links[(int)$m[1]], $p);
	    $out .= $p;
	}
	return $out;
    }

    //a channel of a setting: its id (C…, G…, D…), #name or name (the caller looks names up), or an item ({ id }); '' when empty
    public static function channelId($value) {
	if (is_array($value))
	    $value = $value['id'] ?? $value['channel'] ?? '';
	$v = trim(Expression::toText($value));
	if (preg_match('~/archives/([CGD][A-Z0-9]{2,})~', $v, $m))
	    return $m[1];
	return $v;
    }

    //whether $v is a conversation id rather than a name
    public static function isChannelId($v) {
	return (bool)preg_match('/^[CGD][A-Z0-9]{6,}$/', (string)$v);
    }

    //a message's ts: 1700000000.000100, a message ({ ts }), or its link (…/p1700000000000100)
    public static function ts($value, $label = 'Message') {
	if (is_array($value))
	    $value = $value['ts'] ?? $value['thread_ts'] ?? '';
	$v = trim(Expression::toText($value));
	if (preg_match('~/p(\d{10})(\d{6})(?:\?|$)~', $v, $m))
	    $v = $m[1] . '.' . $m[2];
	if (!preg_match('/^\d{9,11}\.\d{1,6}$/', $v))
	    throw new \RuntimeException("{$label}: not a message's ts (e.g. 1700000000.000100, or its link): `" . mb_substr($v, 0, 60) . '`');
	return $v;
    }

    //the text of a message of Slack, as a person reads it: mentions <@U1> kept as @U1 (or their name in $names), links <url|text> as text (url), entities unescaped
    public static function plain($text, array $names = []) {
	$t = preg_replace_callback('/<@([UW][A-Z0-9]+)(?:\|[^>]*)?>/', fn($m) => '@' . ($names[$m[1]] ?? $m[1]), (string)$text);
	$t = preg_replace_callback('/<#([CG][A-Z0-9]+)(?:\|([^>]*))?>/', fn($m) => '#' . (($m[2] ?? '') !== '' ? $m[2] : $m[1]), $t);
	$t = preg_replace('/<!(here|channel|everyone)(?:\|[^>]*)?>/', '@$1', $t);
	$t = preg_replace_callback('/<((?:https?|mailto):[^|>]+)(?:\|([^>]*))?>/', fn($m) => ($m[2] ?? '') !== '' && $m[2] !== $m[1] ? "{$m[2]} ({$m[1]})" : $m[1], $t);
	return str_replace([ '&lt;', '&gt;', '&amp;' ], [ '<', '>', '&' ], $t);
    }
}
