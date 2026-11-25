<?php

/*
    CLD: 2026-11-25T18:00:00+02:00: the client of Amazon S3's REST API and of S3-compatible services (MinIO, Wasabi, Backblaze B2, Cloudflare R2, …), for the S3 credential, steps
    and object trigger. Requests signed with AWS Signature Version 4 (util\AwsSigV4, service s3, x-amz-content-sha256; an upload from a stream: UNSIGNED-PAYLOAD).
	addresses  AWS: https://<bucket>.s3.<region>.amazonaws.com/<key> (a bucket with a dot: path style, its certificate would not match); an endpoint (another service):
	           <endpoint>/<bucket>/<key> (path style, the default) or <bucket>.<endpoint host>; keys encoded per segment
	errors     S3's XML <Error><Code/><Message/></Error>, said with a hint; 503 SlowDown and 500 sent again while the timeout allows
	private    an endpoint on a private network only when the account allows private network addresses; its certificate checked unless Trust the certificate

    $s = new S3($cred, $aid, $timeout);
    $s->request($method, $bucket, $key, $query, $headers, $o)  [ status, headers, body ] of a 2xx answer ($o: body, upload [ stream, size ], sink); \RuntimeException otherwise
    $s->xml($method, $bucket, $key, $query, $headers, $body)    the XML answer as an array (util\S3::xmlArray)
    $s->listObjects($bucket, $prefix, $delimiter, $max)         ListObjectsV2 through its continuation: [ objects, folders, truncated ]
    $s->presign($method, $bucket, $key, $expires, $query)       a presigned address
    $s->url($bucket, $key, $query)                              an object's address
    S3::key($v), S3::bucket($v)                                 a key / bucket name of a setting (an s3:// address or an object of a list too)
    S3::xmlArray($xml)                                          XML as arrays (repeated elements: lists)

    S3::$testing: the check suite's local server (http://127.0.0.1:<port>), path style, instead of AWS
*/

namespace util;

class S3 {
    const MAX_ANSWER = 20 * 1024 * 1024;
    const PAGE = 1000;
    const MAX_RETRIES = 3;
    const BUCKET = '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/';

    public static $testing = null;

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

    public function region() {
	$r = trim((string)($this->cred->region ?? ''));
	return $r !== '' ? $r : 'us-east-1';
    }

    //the endpoint of another service, without a trailing /; '' for AWS
    public function endpoint() {
	return self::$testing ?? rtrim(trim((string)($this->cred->endpoint ?? '')), '/');
    }

    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    //a bucket name of a setting: name, s3://name/…, an item ({ bucket } / { name })
    public static function bucket($value, $label = 'Bucket') {
	if (is_array($value))
	    $value = $value['bucket'] ?? $value['name'] ?? '';
	$v = trim(Expression::toText($value));
	if (preg_match('~^s3://([^/]+)~i', $v, $m))
	    $v = $m[1];
	if (!preg_match(self::BUCKET, $v) || str_contains($v, '..'))
	    throw new \RuntimeException("{$label}: not a bucket name (3 to 63 lower case letters, digits, dots and hyphens): `" . mb_substr($v, 0, 70) . '`');
	return $v;
    }

    //an object key of a setting: a/b.txt (no leading /), s3://bucket/a/b.txt, an item ({ key })
    public static function key($value, $label = 'Key') {
	if (is_array($value))
	    $value = $value['key'] ?? '';
	$v = Expression::toText($value);
	if (preg_match('~^s3://[^/]+/(.*)$~is', trim($v), $m))
	    $v = $m[1];
	$v = ltrim($v, '/');
	if (trim($v) === '' || strlen($v) > 1024 || preg_match('/[\x00-\x1f]/', $v))
	    throw new \RuntimeException("{$label}: not an object key: `" . mb_substr($v, 0, 100) . '`');
	return $v;
    }

    //an object's address (path style for an endpoint, the tests, a bucket with a dot)
    public function url($bucket, $key = '', array $query = []) {
	$path = $key === '' ? '' : implode('/', array_map('rawurlencode', explode('/', (string)$key)));
	$endpoint = $this->endpoint();
	$pathStyle = $endpoint !== '' ? (self::$testing !== null || ($this->cred->addressing ?? 'path') !== 'virtual') : str_contains((string)$bucket, '.');
	if ($endpoint === '')
	    $endpoint = 'https://s3.' . $this->region() . '.amazonaws.com';
	if ($bucket === '' || $bucket === null)
	    $url = $endpoint . '/';
	elseif ($pathStyle)
	    $url = $endpoint . '/' . $bucket . '/' . $path;
	else
	    $url = preg_replace('~^(https?://)~', '$1' . $bucket . '.', $endpoint) . '/' . $path;
	ksort($query, SORT_STRING);
	$q = [];
	foreach($query as $k => $v)
	    $q[] = rawurlencode((string)$k) . ($v === null || $v === '' ? ($v === '' ? '=' : '') : '=' . rawurlencode((string)$v));
	return $url . ($q ? '?' . implode('&', $q) : '');
    }

    protected function key4() {
	return [ 'accessKeyId' => trim((string)$this->cred->accessKeyId), 'secretAccessKey' => (string)$this->cred->secretAccessKey, 'sessionToken' => (string)($this->cred->sessionToken ?? ''),
	    'region' => $this->region(), 'service' => 's3' ];
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [ 'https' => self::$testing === null, 'public' => self::$testing === null, 'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)($this->cred->trustCertificate ?? false), 'compressed' => false ];
    }

    //S3's code and message of an error answer, with what it means
    const HINTS = [
	'AccessDenied' => 'the key may not do this (its IAM policy, or the bucket\'s)',
	'InvalidAccessKeyId' => 'no such access key',
	'SignatureDoesNotMatch' => 'the secret access key is wrong',
	'NoSuchBucket' => 'no such bucket',
	'NoSuchKey' => 'no such object',
	'BucketNotEmpty' => 'the bucket has objects: delete them first',
	'BucketAlreadyExists' => 'the name is taken (bucket names are global)',
	'BucketAlreadyOwnedByYou' => 'you have that bucket already',
	'PermanentRedirect' => 'the bucket is in another region: set the credential\'s region to it',
	'AuthorizationHeaderMalformed' => 'the region is not the bucket\'s',
	'PreconditionFailed' => 'the object exists',
	'ExpiredToken' => 'the session token expired',
	'EntityTooLarge' => 'too large for one upload',
    ];

    public static function errorOf($body, $status) {
	$e = self::xmlArray((string)$body);
	$code = (string)($e['Code'] ?? '');
	$m = $code !== '' ? $code . (isset(self::HINTS[$code]) ? ': ' . self::HINTS[$code] : (($e['Message'] ?? '') !== '' ? ': ' . $e['Message'] : '')) : '';
	if ($code === 'PermanentRedirect' && !empty($e['Endpoint']))
	    $m .= ' (' . $e['Endpoint'] . ')';
	return mb_substr($m !== '' ? $m : "HTTP status {$status}" . ([ 403 => ': access denied', 404 => ': not found' ][$status] ?? ''), 0, 1000);
    }

    //a request: [ status, headers, body ] of a 2xx (and 304) answer; $o: body (text), upload ([ stream, size ]: UNSIGNED-PAYLOAD), sink (a stream), maxBytes, ok (more statuses
    //returned rather than refused, e.g. [ 404 ] for a HEAD)
    public function request($method, $bucket, $key = '', array $query = [], array $headers = [], array $o = []) {
	$method = strtoupper((string)$method);
	$url = $this->url((string)$bucket, (string)$key, $query);
	$body = isset($o['body']) ? (string)$o['body'] : '';
	for ($try = 0; ; $try++) {
	    $k = $this->key4() + [ 'payloadHeader' => true ] + (isset($o['upload']) ? [ 'payloadHash' => 'UNSIGNED-PAYLOAD' ] : []);
	    $signed = AwsSigV4::headers($method, $url, $headers, isset($o['upload']) ? '' : $body, $k);
	    $req = [ 'headers' => $signed, 'timeout' => $this->left(), 'maxBytes' => (int)($o['maxBytes'] ?? self::MAX_ANSWER) ] + $this->options();
	    if (isset($o['upload']))
		$req['upload'] = $o['upload'];
	    elseif ($body !== '' || in_array($method, [ 'PUT', 'POST' ], true))
		$req['body'] = $body;
	    if (isset($o['sink']))
		$req['sink'] = $o['sink'];
	    $r = Http::request($method, $url, $req);
	    //busy (503 SlowDown, 500): sent again a few times, while the timeout allows (not an upload from a stream: it is read)
	    if (in_array($r['status'], [ 500, 503 ], true) && $try < self::MAX_RETRIES && !isset($o['upload']) && !isset($o['sink']) && $this->left() > 2) {
		sleep(1 << $try);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (((int)($o['maxBytes'] ?? self::MAX_ANSWER)) >> 20) . ' MB' : 'no answer from ' . Http::shown($url) . ": {$r['error']}");
	if (($r['status'] < 200 || $r['status'] >= 300) && $r['status'] !== 304 && !in_array($r['status'], (array)($o['ok'] ?? []), true))
	    throw new \RuntimeException(self::errorOf(isset($o['sink']) ? '' : $r['body'], $r['status']));
	return [ $r['status'], $r['headers'], (string)$r['body'] ];
    }

    //an XML answer as an array
    public function xml($method, $bucket, $key = '', array $query = [], array $headers = [], $body = null) {
	[ , , $answer ] = $this->request($method, $bucket, $key, $query, $headers, $body !== null ? [ 'body' => $body ] : []);
	return self::xmlArray($answer);
    }

    //ListObjectsV2 of $bucket below $prefix (with $delimiter: the "folders" apart), at most $max objects: [ objects, folders, truncated ]
    public function listObjects($bucket, $prefix = '', $delimiter = '', $max = 1000) {
	$max = max(1, (int)$max);
	$objects = [];
	$folders = [];
	$token = null;
	while (true) {
	    $q = [ 'list-type' => '2', 'max-keys' => (string)min(self::PAGE, $max - count($objects) + 1) ] + ($prefix !== '' ? [ 'prefix' => $prefix ] : []) + ($delimiter !== '' ? [ 'delimiter' => $delimiter ] : [])
		+ ($token !== null ? [ 'continuation-token' => $token ] : []);
	    $d = $this->xml('GET', $bucket, '', $q);
	    foreach(self::listOf($d['Contents'] ?? null) as $c) {
		if (count($objects) >= $max)
		    return [ $objects, $folders, true ];
		$objects[] = self::objectOf($c, $bucket);
	    }
	    foreach(self::listOf($d['CommonPrefixes'] ?? null) as $p)
		$folders[] = (string)($p['Prefix'] ?? '');
	    $token = ($d['IsTruncated'] ?? 'false') === 'true' ? (string)($d['NextContinuationToken'] ?? '') : '';
	    if ($token === '')
		return [ $objects, array_values(array_unique($folders)), false ];
	    if (count($objects) >= $max)
		return [ $objects, array_values(array_unique($folders)), true ];
	}
    }

    //an object of a list (Contents)
    public static function objectOf(array $c, $bucket) {
	$key = (string)($c['Key'] ?? '');
	$slash = strrpos($key, '/');
	return [
	    'bucket' => (string)$bucket,
	    'key' => $key,
	    'name' => $slash === false ? $key : substr($key, $slash + 1),
	    'folder' => $slash === false ? '' : substr($key, 0, $slash + 1),
	    'size' => (int)($c['Size'] ?? 0),
	    'modified' => isset($c['LastModified']) ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$c['LastModified'])) : '',
	    'etag' => trim((string)($c['ETag'] ?? ''), '"'),
	    'storageClass' => (string)($c['StorageClass'] ?? ''),
	];
    }

    //a presigned address of an object, valid $expires seconds
    public function presign($method, $bucket, $key, $expires, array $query = []) {
	return AwsSigV4::presign($method, $this->url($bucket, $key, $query), $this->key4(), $expires);
    }

    //a value as a list (one element of XML: itself)
    public static function listOf($v) {
	if ($v === null || $v === '')
	    return [];
	return is_array($v) && array_is_list($v) ? $v : [ $v ];
    }

    //XML as arrays: an element's children by name (repeated ones: a list), a text element: its text
    public static function xmlArray($xml) {
	$xml = trim((string)$xml);
	if ($xml === '' || $xml[0] !== '<')
	    return [];
	$prev = libxml_use_internal_errors(true);
	$doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
	libxml_use_internal_errors($prev);
	if ($doc === false)
	    return [];
	$walk = function(\SimpleXMLElement $e) use (&$walk) {
	    if (!$e->count())
		return (string)$e;
	    $out = [];
	    foreach($e->children() as $name => $child) {
		$v = $walk($child);
		if (array_key_exists($name, $out)) {
		    if (!is_array($out[$name]) || !array_is_list($out[$name]) || !isset($out['__list'][$name]))
			$out[$name] = [ $out[$name] ];
		    $out['__list'][$name] = true;
		    $out[$name][] = $v;
		} else
		    $out[$name] = $v;
	    }
	    unset($out['__list']);
	    return $out;
	};
	return (array)$walk($doc);
    }
}
