<?php

/*
    CLD: 2026-11-25T18:00:00+02:00: a fake S3 (path style) for the check suite (CheckCommand::suiteS3), run by PHP's built-in server:
    S3_STATE=<a folder> php -S 127.0.0.1:<port> S3TestServer.php
	every request's AWS Signature Version 4 checked again (util\AwsSigV4, the key AKIDCHECK / secret-check, region eu-central-1; a presigned address: its X-Amz-Signature), else 403
	SignatureDoesNotMatch; another key: 403 InvalidAccessKeyId. Buckets and objects in <state>/db.json (bodies base64), seeded: bucket `docs` with a.txt, inbox/one.pdf,
	inbox/two.pdf, inbox/sub/three.txt; bucket `empty`. ListBuckets, Create / Delete (BucketNotEmpty) / Head bucket, ListObjectsV2 (prefix, delimiter, max-keys,
	continuation-token), Put (If-None-Match: *, x-amz-meta-*, x-amz-storage-class; the body's sha256 or UNSIGNED-PAYLOAD), Get, Head, Delete, DeleteObjects (Content-MD5),
	Copy (x-amz-copy-source), ?tagging. <state>/slow.json: how many 503 SlowDown next. /sns-confirm: the SNS confirmation (noted in <state>/confirmed.txt).
	Each request appended to <state>/requests.jsonl (method, path, query, headers signed, size)
*/

require dirname(__DIR__, 2) . '/util/AwsSigV4.php';

$state = getenv('S3_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$uriPath = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$rawQuery = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
parse_str($rawQuery, $query);
$raw = file_get_contents('php://input');
$hdr = function($k) {
    $k = strtoupper(str_replace('-', '_', $k));
    return $_SERVER['HTTP_' . $k] ?? ($k === 'CONTENT_TYPE' ? ($_SERVER['CONTENT_TYPE'] ?? null) : ($k === 'CONTENT_LENGTH' ? ($_SERVER['CONTENT_LENGTH'] ?? null) : null));
};
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => rawurldecode($uriPath), 'query' => $query, 'size' => strlen($raw),
    'type' => $hdr('Content-Type'), 'meta' => $hdr('x-amz-meta-dept'), 'class' => $hdr('x-amz-storage-class'), 'ifNone' => $hdr('If-None-Match'), 'copy' => $hdr('x-amz-copy-source'),
    'sha' => $hdr('x-amz-content-sha256'), 'md5' => $hdr('Content-MD5') ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$xml = function($body, $status = 200, array $headers = []) {
    http_response_code($status);
    header('Content-Type: application/xml');
    foreach($headers as $k => $v)
	header("{$k}: {$v}");
    echo '<?xml version="1.0" encoding="UTF-8"?>' . $body;
    exit;
};
$e = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$error = fn($code, $status, $message = '') => $xml("<Error><Code>{$code}</Code><Message>{$e($message !== '' ? $message : $code)}</Message></Error>", $status);

if ($uriPath === '/sns-confirm') {
    file_put_contents("{$state}/confirmed.txt", $rawQuery);
    $xml('<ConfirmSubscriptionResponse><ConfirmSubscriptionResult><SubscriptionArn>arn:aws:sns:eu-central-1:123456789012:uploads:1</SubscriptionArn></ConfirmSubscriptionResult></ConfirmSubscriptionResponse>');
}

//the signature, computed again
$key = [ 'accessKeyId' => 'AKIDCHECK', 'secretAccessKey' => 'secret-check', 'region' => 'eu-central-1', 'service' => 's3' ];
$url = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
if (isset($query['X-Amz-Signature'])) {
    if (!str_starts_with((string)($query['X-Amz-Credential'] ?? ''), 'AKIDCHECK/'))
	$error('InvalidAccessKeyId', 403);
    $t = DateTime::createFromFormat('Ymd\THis\Z', (string)($query['X-Amz-Date'] ?? ''), new DateTimeZone('UTC'));
    if (!$t || time() > $t->getTimestamp() + (int)($query['X-Amz-Expires'] ?? 0))
	$error('AccessDenied', 403, 'Request has expired');
    //the address without the X-Amz-* parameters, presigned again at that time
    $keep = implode('&', array_filter(explode('&', $rawQuery), fn($p) => !str_starts_with($p, 'X-Amz-')));
    $again = \util\AwsSigV4::presign($method, 'http://' . $_SERVER['HTTP_HOST'] . $uriPath . ($keep !== '' ? "?{$keep}" : ''), $key + [ 'time' => $t->getTimestamp() ], (int)$query['X-Amz-Expires']);
    if (!str_ends_with($again, 'X-Amz-Signature=' . $query['X-Amz-Signature']))
	$error('SignatureDoesNotMatch', 403);
} else {
    $auth = (string)$hdr('Authorization');
    if (!preg_match('~^AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([^/]+)/s3/aws4_request, SignedHeaders=([a-z0-9;-]+), Signature=([0-9a-f]{64})$~', $auth, $m))
	$error('AccessDenied', 403, 'no signature');
    if ($m[1] !== 'AKIDCHECK')
	$error('InvalidAccessKeyId', 403);
    if ($m[3] !== 'eu-central-1')
	$error('AuthorizationHeaderMalformed', 400, "the region '{$m[3]}' is wrong; expecting 'eu-central-1'");
    $signed = [];
    foreach(explode(';', $m[4]) as $name)
	if ($name !== 'host' && $name !== 'x-amz-date')
	    $signed[$name] = (string)$hdr($name);
    $sha = (string)$hdr('x-amz-content-sha256');
    if ($sha !== 'UNSIGNED-PAYLOAD' && $sha !== hash('sha256', $raw))
	$error('XAmzContentSHA256Mismatch', 400);
    $t = DateTime::createFromFormat('Ymd\THis\Z', (string)$hdr('x-amz-date'), new DateTimeZone('UTC'));
    unset($signed['x-amz-content-sha256']);
    $again = \util\AwsSigV4::headers($method, $url, $signed, $raw, $key + [ 'time' => $t ? $t->getTimestamp() : 0, 'payloadHeader' => true, 'payloadHash' => $sha ]);
    if (!str_ends_with($again['Authorization'], 'Signature=' . $m[5]))
	$error('SignatureDoesNotMatch', 403);
}
if (($slow = (int)$load('slow', 0)) > 0) {
    $save('slow', $slow - 1);
    $error('SlowDown', 503, 'Please reduce your request rate.');
}

$db = $load('db', null) ?? [ 'buckets' => [
    'docs' => [ 'created' => '2026-01-01T00:00:00Z', 'objects' => [
	'a.txt' => [ 'body' => base64_encode('hello'), 'type' => 'text/plain', 'modified' => '2026-11-01T10:00:00Z', 'meta' => [], 'class' => 'STANDARD' ],
	'inbox/one.pdf' => [ 'body' => base64_encode('%PDF-1 one'), 'type' => 'application/pdf', 'modified' => '2026-11-02T10:00:00Z', 'meta' => [], 'class' => 'STANDARD' ],
	'inbox/two.pdf' => [ 'body' => base64_encode('%PDF-1 two'), 'type' => 'application/pdf', 'modified' => '2026-11-03T10:00:00Z', 'meta' => [], 'class' => 'STANDARD' ],
	'inbox/sub/three.txt' => [ 'body' => base64_encode('three'), 'type' => 'text/plain', 'modified' => '2026-11-04T10:00:00Z', 'meta' => [], 'class' => 'STANDARD' ],
    ] ],
    'empty' => [ 'created' => '2026-02-01T00:00:00Z', 'objects' => [] ],
] ];
$etag = fn($o) => md5(base64_decode($o['body']));
$parts = explode('/', ltrim($uriPath, '/'), 2);
$bucket = rawurldecode($parts[0] ?? '');
$objKey = rawurldecode($parts[1] ?? '');
$now = gmdate('Y-m-d\TH:i:s\Z');

if ($bucket === '') {
    $list = '';
    foreach($db['buckets'] as $name => $b)
	$list .= "<Bucket><Name>{$e($name)}</Name><CreationDate>{$b['created']}</CreationDate></Bucket>";
    $xml("<ListAllMyBucketsResult><Owner><ID>1</ID></Owner><Buckets>{$list}</Buckets></ListAllMyBucketsResult>");
}
if ($objKey === '') {
    switch($method) {
	case 'PUT':
	    if (isset($db['buckets'][$bucket]))
		$error('BucketAlreadyOwnedByYou', 409);
	    $db['buckets'][$bucket] = [ 'created' => $now, 'objects' => [] ];
	    $save('db', $db);
	    http_response_code(200);
	    exit;
	case 'DELETE':
	    if (!isset($db['buckets'][$bucket]))
		$error('NoSuchBucket', 404);
	    if ($db['buckets'][$bucket]['objects'])
		$error('BucketNotEmpty', 409);
	    unset($db['buckets'][$bucket]);
	    $save('db', $db);
	    http_response_code(204);
	    exit;
	case 'HEAD':
	    http_response_code(isset($db['buckets'][$bucket]) ? 200 : 404);
	    header('x-amz-bucket-region: eu-central-1');
	    exit;
	case 'POST':
	    if (!array_key_exists('delete', $query))
		$error('NotImplemented', 501);
	    if (base64_encode(md5($raw, true)) !== $hdr('Content-MD5'))
		$error('InvalidDigest', 400);
	    $doc = simplexml_load_string($raw);
	    $out = '';
	    foreach($doc->Object as $o) {
		$k = (string)$o->Key;
		if (str_starts_with($k, 'locked/')) {
		    $out .= "<Error><Key>{$e($k)}</Key><Code>AccessDenied</Code><Message>Access Denied</Message></Error>";
		    continue;
		}
		unset($db['buckets'][$bucket]['objects'][$k]);
		$out .= "<Deleted><Key>{$e($k)}</Key></Deleted>";
	    }
	    $save('db', $db);
	    $xml("<DeleteResult>{$out}</DeleteResult>");
	case 'GET':
	    if (!isset($db['buckets'][$bucket]))
		$error('NoSuchBucket', 404);
	    $prefix = (string)($query['prefix'] ?? '');
	    $delim = (string)($query['delimiter'] ?? '');
	    $max = max(1, (int)($query['max-keys'] ?? 1000));
	    $keys = array_keys($db['buckets'][$bucket]['objects']);
	    sort($keys, SORT_STRING);
	    $entries = [];
	    $seenPrefix = [];
	    foreach($keys as $k) {
		if ($prefix !== '' && !str_starts_with($k, $prefix))
		    continue;
		if ($delim !== '' && ($pos = strpos(substr($k, strlen($prefix)), $delim)) !== false) {
		    $p = substr($k, 0, strlen($prefix) + $pos + 1);
		    if (!isset($seenPrefix[$p])) {
			$seenPrefix[$p] = true;
			$entries[] = [ 'prefix', $p ];
		    }
		    continue;
		}
		$entries[] = [ 'key', $k ];
	    }
	    $from = (int)($query['continuation-token'] ?? 0);
	    $page = array_slice($entries, $from, $max);
	    $more = $from + $max < count($entries);
	    $out = '';
	    foreach($page as [ $kind, $v ]) {
		if ($kind === 'prefix')
		    $out .= "<CommonPrefixes><Prefix>{$e($v)}</Prefix></CommonPrefixes>";
		else {
		    $o = $db['buckets'][$bucket]['objects'][$v];
		    $out .= "<Contents><Key>{$e($v)}</Key><LastModified>{$o['modified']}</LastModified><ETag>&quot;{$etag($o)}&quot;</ETag><Size>" . strlen(base64_decode($o['body'])) . "</Size><StorageClass>{$o['class']}</StorageClass></Contents>";
		}
	    }
	    $xml("<ListBucketResult><Name>{$e($bucket)}</Name><Prefix>{$e($prefix)}</Prefix><KeyCount>" . count($page) . "</KeyCount><MaxKeys>{$max}</MaxKeys><IsTruncated>" . ($more ? 'true' : 'false') . '</IsTruncated>'
		. ($more ? '<NextContinuationToken>' . ($from + $max) . '</NextContinuationToken>' : '') . "{$out}</ListBucketResult>");
    }
    $error('NotImplemented', 501);
}

if (!isset($db['buckets'][$bucket]))
    $error('NoSuchBucket', 404);
$objects = &$db['buckets'][$bucket]['objects'];
switch($method) {
    case 'PUT':
	if (($src = $hdr('x-amz-copy-source')) !== null) {
	    [ $sb, $sk ] = explode('/', ltrim(rawurldecode($src), '/'), 2);
	    $o = $db['buckets'][$sb]['objects'][$sk] ?? null;
	    if (!$o)
		$xml('<Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message></Error>', 200);
	    $objects[$objKey] = [ 'modified' => $now ] + $o;
	    $save('db', $db);
	    $xml("<CopyObjectResult><LastModified>{$now}</LastModified><ETag>&quot;{$etag($o)}&quot;</ETag></CopyObjectResult>");
	}
	if (array_key_exists('tagging', $query)) {
	    http_response_code(200);
	    exit;
	}
	if ($hdr('If-None-Match') === '*' && isset($objects[$objKey]))
	    $error('PreconditionFailed', 412, 'At least one of the pre-conditions you specified did not hold');
	$meta = [];
	foreach($_SERVER as $k => $v)
	    if (str_starts_with($k, 'HTTP_X_AMZ_META_'))
		$meta[strtolower(str_replace('_', '-', substr($k, 16)))] = $v;
	$objects[$objKey] = [ 'body' => base64_encode($raw), 'type' => (string)$hdr('Content-Type'), 'modified' => $now, 'meta' => $meta, 'class' => $hdr('x-amz-storage-class') ?? 'STANDARD' ];
	$save('db', $db);
	header('ETag: "' . md5($raw) . '"');
	http_response_code(200);
	exit;
    case 'GET':
    case 'HEAD':
	if (array_key_exists('tagging', $query))
	    $xml('<Tagging><TagSet><Tag><Key>team</Key><Value>ops</Value></Tag><Tag><Key>env</Key><Value>prod</Value></Tag></TagSet></Tagging>');
	$o = $objects[$objKey] ?? null;
	if (!$o) {
	    if ($method === 'HEAD') {
		http_response_code(404);
		exit;
	    }
	    $error('NoSuchKey', 404, 'The specified key does not exist.');
	}
	$body = base64_decode($o['body']);
	header('Content-Type: ' . $o['type']);
	header('Content-Length: ' . strlen($body));
	header('ETag: "' . md5($body) . '"');
	header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', strtotime($o['modified'])));
	header('x-amz-storage-class: ' . $o['class']);
	foreach($o['meta'] as $k => $v)
	    header("x-amz-meta-{$k}: {$v}");
	if (isset($query['response-content-disposition']))
	    header('Content-Disposition: ' . $query['response-content-disposition']);
	if ($method === 'GET')
	    echo $body;
	exit;
    case 'DELETE':
	unset($objects[$objKey]);
	$save('db', $db);
	http_response_code(204);
	exit;
}
$error('NotImplemented', 501);
