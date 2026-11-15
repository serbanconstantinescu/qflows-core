<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: AWS Signature Version 4 (the Authorization header of a request to an AWS service): for the Amazon SES credential and steps (util\AmazonSes),
    usable by any AWS service's client. Not S3: its paths are encoded once, the other services' twice in the canonical request (as AWS's documentation says).

    AwsSigV4::headers($method, $url, $headers, $body, $key) => the headers to send: $headers + host, x-amz-date, x-amz-content-sha256 ($key['payloadHeader']),
	x-amz-security-token (a temporary key), authorization
	$url      the full URL, its path already URI-encoded once (rawurlencode of each segment) and its query as it is sent
	$key      [ accessKeyId, secretAccessKey, sessionToken (optional), region, service, time (optional: a Unix time, for the tests), payloadHeader (optional) ]
*/

namespace util;

class AwsSigV4 {
    const ALGORITHM = 'AWS4-HMAC-SHA256';

    //the headers of a signed request
    public static function headers($method, $url, array $headers, $body, array $key) {
	$u = parse_url($url);
	$host = strtolower((string)($u['host'] ?? '')) . (isset($u['port']) ? ':' . $u['port'] : '');
	$time = (int)($key['time'] ?? time());
	$amzDate = gmdate('Ymd\THis\Z', $time);
	$day = gmdate('Ymd', $time);
	$payload = hash('sha256', (string)$body);

	$headers['host'] = $host;
	$headers['x-amz-date'] = $amzDate;
	if (!empty($key['payloadHeader']))
	    $headers['x-amz-content-sha256'] = $payload;
	if (!empty($key['sessionToken']))
	    $headers['x-amz-security-token'] = (string)$key['sessionToken'];

	//the headers signed: names lowercase, sorted; values trimmed, inner spaces as one
	$canon = [];
	foreach($headers as $name => $value)
	    $canon[strtolower(trim((string)$name))] = preg_replace('/\s+/', ' ', trim((string)$value));
	ksort($canon, SORT_STRING);
	$signed = implode(';', array_keys($canon));
	$canonHeaders = '';
	foreach($canon as $name => $value)
	    $canonHeaders .= "{$name}:{$value}\n";

	$request = implode("\n", [ strtoupper((string)$method), self::canonicalPath((string)($u['path'] ?? '/'), $key['service'] ?? ''), self::canonicalQuery((string)($u['query'] ?? '')),
	    $canonHeaders, $signed, $payload ]);
	$scope = "{$day}/{$key['region']}/{$key['service']}/aws4_request";
	$toSign = implode("\n", [ self::ALGORITHM, $amzDate, $scope, hash('sha256', $request) ]);

	$k = hash_hmac('sha256', $day, 'AWS4' . $key['secretAccessKey'], true);
	$k = hash_hmac('sha256', (string)$key['region'], $k, true);
	$k = hash_hmac('sha256', (string)$key['service'], $k, true);
	$k = hash_hmac('sha256', 'aws4_request', $k, true);
	$signature = hash_hmac('sha256', $toSign, $k);

	unset($headers['host']);
	$headers['Authorization'] = self::ALGORITHM . " Credential={$key['accessKeyId']}/{$scope}, SignedHeaders={$signed}, Signature={$signature}";
	return $headers;
    }

    //the path of the canonical request: each segment encoded again (S3: as it is)
    public static function canonicalPath($path, $service) {
	if ($path === '')
	    return '/';
	if ($service === 's3')
	    return $path;
	return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    //the query of the canonical request: the parameters sorted by name (then value), each encoded as RFC 3986 says
    public static function canonicalQuery($query) {
	if ($query === '')
	    return '';
	$pairs = [];
	foreach(explode('&', $query) as $p) {
	    if ($p === '')
		continue;
	    [ $k, $v ] = array_pad(explode('=', $p, 2), 2, '');
	    $pairs[] = [ rawurlencode(rawurldecode(str_replace('+', '%20', $k))), rawurlencode(rawurldecode(str_replace('+', '%20', $v))) ];
	}
	usort($pairs, fn($a, $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
	return implode('&', array_map(fn($p) => "{$p[0]}={$p[1]}", $pairs));
    }
}
