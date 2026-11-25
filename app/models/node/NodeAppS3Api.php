<?php

/*
    CLD: 2026-11-25T18:00:00+02:00: S3 API - any request of S3's REST API, signed with the credential's key (what the other S3 steps do not have: tags, versions, lifecycle,
    policies, CORS, object lock, …): the method, the bucket (empty: the account level, e.g. the buckets), the key, the query (a JSON object; a sub-resource such as
    {"tagging": ""}), headers and a body (XML, JSON or text). The result: { status, headers, body } (the body: XML as an object, JSON as its value, else the text)
*/

namespace models\node;

class NodeAppS3Api extends NodeAppS3 {
    public static $name = 's3-api';
    public static $label = 'S3 API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 's3';

    const OPERATIONS = [ 'request' => 'A REST request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'PUT' => 'PUT', 'POST' => 'POST', 'DELETE' => 'DELETE', 'HEAD' => 'HEAD' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('bucket', 'Bucket', [ 'request' ], [ 'help' => 'Empty: the account level (e.g. GET: the buckets)', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('key', 'Key', [ 'request' ], [ 'help' => 'Optional: an object', 'ui' => [ 'expression' => 'text', 'width' => 5 ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object, e.g. {"tagging": ""} or {"versions": "", "prefix": "logs/"}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('headers', 'Headers', [ 'request' ], [ 'type' => 'textarea', 'help' => 'Optional: a JSON object, e.g. {"Content-Type": "application/xml"}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'Optional: XML, JSON or text', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    //a JSON object setting as [ name => text ]
    private function pairs($key, $label) {
	$v = $this->settingOf($key);
	if (is_string($v)) {
	    if (trim($v) === '')
		return [];
	    $v = json_decode($v, true);
	}
	if ($v === null || $v === [])
	    return [];
	if (!is_array($v) || array_is_list($v))
	    throw new \RuntimeException("{$label}: not a JSON object");
	return array_map(fn($x) => is_bool($x) ? ($x ? 'true' : 'false') : \util\Expression::toText($x), $v);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$b = $text('bucket');
	$bucket = $b === '' ? '' : \util\S3::bucket($b);
	$k = \util\Expression::toText($value('key'));
	$key = trim($k) === '' ? '' : \util\S3::key($k);
	if ($key !== '' && $bucket === '')
	    throw new \RuntimeException('Bucket: a key needs its bucket');
	$headers = $this->pairs('headers', 'Headers');
	foreach(array_keys($headers) as $h)
	    if (!preg_match('/^[A-Za-z0-9-]{1,100}$/', (string)$h) || in_array(strtolower((string)$h), [ 'authorization', 'host', 'x-amz-date', 'x-amz-content-sha256' ], true))
		throw new \RuntimeException("Headers: `{$h}` cannot be set");
	$body = $value('body');
	$body = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : \util\Expression::toText($body);
	[ $status, $h, $answer ] = $this->s3->request($method, $bucket, $key, $this->pairs('query', 'Query'), $headers, $body !== '' ? [ 'body' => $body ] : []);
	$parsed = $answer;
	if (str_starts_with(ltrim($answer), '<'))
	    $parsed = \util\S3::xmlArray($answer);
	elseif (($j = json_decode($answer, true)) !== null)
	    $parsed = $j;
	return [ [ 'status' => $status, 'headers' => $h, 'body' => $parsed ], "{$method} " . ($bucket !== '' ? "{$bucket}/{$key}" : '/') . " ({$status})" ];
    }
}
