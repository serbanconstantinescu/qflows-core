<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: a fake Amazon SES API v2 for the check suite (CheckCommand::suiteAmazonses), run by PHP's built-in server:
    SES_STATE=<a folder> php -S 127.0.0.1:<port> SesTestServer.php
	Every request's AWS Signature V4 checked again (util\AwsSigV4) with the key AKIDCHECKSES / ses-check-secret (else 403 SignatureDoesNotMatch; another key: 403
	UnrecognizedClientException). account; outbound-emails (Simple, Raw - kept decoded in <state>/raw.eml -, Template 'welcome'; a sender of acme.com only, else 400
	MessageRejected); templates (3, pages of PageSize with NextToken) and one; identities (acme.com verified, pages), one (an address: by its encoded path), a new one (DKIM
	tokens); the suppression list (list, one - 404 NotFoundException -, PUT, DELETE). Errors as SES's: { message }, the type in x-amzn-ErrorType.
	<state>/throttle.json: how many 429 next (TooManyRequestsException). Each request appended to <state>/requests.jsonl (method, path, raw path, query, body, signed)
*/

require_once __DIR__ . '/../../util/AwsSigV4.php';

$state = getenv('SES_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$rawPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rawurldecode($rawPath);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$out = function($v, $status = 200, $type = null) {
    http_response_code($status);
    if ($type !== null)
	header("x-amzn-ErrorType: {$type}:http://internal.amazon.com/coral/com.amazonaws.sesv2/");
    header('Content-Type: application/json');
    echo $v === null ? '' : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
$err = fn($status, $type, $message) => $out([ 'message' => $message ], $status, $type);

//the signature, computed again from what came
$signed = false;
if (preg_match('~^AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([a-z0-9-]+)/ses/aws4_request, SignedHeaders=([a-z0-9;-]+), Signature=[0-9a-f]{64}$~', $auth, $m)) {
    $headers = [];
    foreach(explode(';', $m[4]) as $h)
	if ($h !== 'host' && $h !== 'x-amz-date' && $h !== 'x-amz-security-token')
	    $headers[$h] = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $h))] ?? ($h === 'content-type' ? ($_SERVER['CONTENT_TYPE'] ?? '') : '');
    $time = DateTime::createFromFormat('Ymd\THis\Z', (string)($_SERVER['HTTP_X_AMZ_DATE'] ?? ''), new DateTimeZone('UTC'));
    $again = \util\AwsSigV4::headers($method, 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], $headers, $raw, [ 'accessKeyId' => $m[1], 'secretAccessKey' => 'ses-check-secret',
	'region' => $m[3], 'service' => 'ses', 'time' => $time ? $time->getTimestamp() : 0, 'sessionToken' => $_SERVER['HTTP_X_AMZ_SECURITY_TOKEN'] ?? '' ]);
    $signed = $m[1] === 'AKIDCHECKSES' && hash_equals($again['Authorization'], $auth);
}
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'rawPath' => $rawPath, 'query' => $query, 'body' => $body, 'signed' => $signed,
    'region' => $m[3] ?? null ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
if (!str_contains($auth, 'Credential=AKIDCHECKSES/'))
    $err(403, 'UnrecognizedClientException', 'The security token included in the request is invalid.');
if (!$signed)
    $err(403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.');
if (($t = (int)$load('throttle', 0)) > 0) {
    $save('throttle', $t - 1);
    $err(429, 'TooManyRequestsException', 'Too Many Requests');
}
$pageOf = function(array $all) use ($query) {
    $size = (int)($query['PageSize'] ?? 100);
    $from = (int)($query['NextToken'] ?? 0);
    $next = $from + $size < count($all) ? (string)($from + $size) : null;
    return [ array_slice($all, $from, $size), $next ];
};
$suppressed = $load('suppressed', [ 'bounced@example.com' => [ 'EmailAddress' => 'bounced@example.com', 'Reason' => 'BOUNCE', 'LastUpdateTime' => '2026-11-01T10:00:00Z' ],
    'angry@example.com' => [ 'EmailAddress' => 'angry@example.com', 'Reason' => 'COMPLAINT', 'LastUpdateTime' => '2026-11-12T10:00:00Z' ] ]);
$fromOk = fn($a) => (bool)preg_match('/@acme\.com>?$/', (string)$a);

switch(true) {
    case $path === '/v2/email/account':
	$out([ 'SendQuota' => [ 'Max24HourSend' => 50000, 'MaxSendRate' => 14, 'SentLast24Hours' => count($load('sent', [])) ], 'SendingEnabled' => true,
	    'ProductionAccessEnabled' => true, 'EnforcementStatus' => 'HEALTHY', 'DedicatedIpAutoWarmupEnabled' => false ]);

    case $path === '/v2/email/outbound-emails' && $method === 'POST':
	if (!$fromOk($body['FromEmailAddress'] ?? ''))
	    $err(400, 'MessageRejected', 'Email address is not verified. The following identities failed the check in region ' . strtoupper($m[3] ?? '') . ': ' . ($body['FromEmailAddress'] ?? ''));
	$c = (array)($body['Content'] ?? []);
	if (isset($c['Template']) && ($c['Template']['TemplateName'] ?? '') !== 'welcome')
	    $err(404, 'NotFoundException', 'Template ' . ($c['Template']['TemplateName'] ?? '') . ' does not exist.');
	if (isset($c['Raw']))
	    file_put_contents("{$state}/raw.eml", base64_decode((string)$c['Raw']['Data']));
	$sent = $load('sent', []);
	$id = '0102019a' . str_pad((string)(count($sent) + 1), 8, '0', STR_PAD_LEFT) . '-check-000000';
	$sent[] = [ 'id' => $id, 'kind' => array_key_first($c) ];
	$save('sent', $sent);
	$out([ 'MessageId' => $id ]);

    case $path === '/v2/email/templates' && $method === 'GET':
	[ $list, $next ] = $pageOf([ [ 'TemplateName' => 'welcome', 'CreatedTimestamp' => '2026-10-01T10:00:00Z' ], [ 'TemplateName' => 'invoice', 'CreatedTimestamp' => '2026-10-02T10:00:00Z' ],
	    [ 'TemplateName' => 'reset', 'CreatedTimestamp' => '2026-10-03T10:00:00Z' ] ]);
	$out(array_filter([ 'TemplatesMetadata' => $list, 'NextToken' => $next ], fn($v) => $v !== null));

    case (bool)preg_match('~^/v2/email/templates/([^/]+)$~', $path, $t):
	$t[1] === 'welcome' ? $out([ 'TemplateName' => 'welcome', 'TemplateContent' => [ 'Subject' => 'Welcome {{name}}', 'Text' => 'Hi {{name}}', 'Html' => '<p>Hi {{name}}</p>' ] ])
	    : $err(404, 'NotFoundException', "Template {$t[1]} does not exist.");

    case $path === '/v2/email/identities' && $method === 'GET':
	[ $list, $next ] = $pageOf([ [ 'IdentityType' => 'DOMAIN', 'IdentityName' => 'acme.com', 'SendingEnabled' => true, 'VerificationStatus' => 'SUCCESS' ],
	    [ 'IdentityType' => 'EMAIL_ADDRESS', 'IdentityName' => 'ops@other.com', 'SendingEnabled' => false, 'VerificationStatus' => 'PENDING' ] ]);
	$out(array_filter([ 'EmailIdentities' => $list, 'NextToken' => $next ], fn($v) => $v !== null));

    case $path === '/v2/email/identities' && $method === 'POST':
	$id = (string)($body['EmailIdentity'] ?? '');
	$out(str_contains($id, '@') ? [ 'IdentityType' => 'EMAIL_ADDRESS', 'VerifiedForSendingStatus' => false ]
	    : [ 'IdentityType' => 'DOMAIN', 'VerifiedForSendingStatus' => false, 'DkimAttributes' => [ 'SigningEnabled' => true, 'Status' => 'PENDING', 'Tokens' => [ 'tok1', 'tok2', 'tok3' ] ] ]);

    case (bool)preg_match('~^/v2/email/identities/([^/]+)$~', $path, $t):
	in_array($t[1], [ 'acme.com', 'ops@other.com' ], true) ? $out([ 'IdentityType' => str_contains($t[1], '@') ? 'EMAIL_ADDRESS' : 'DOMAIN', 'VerifiedForSendingStatus' => $t[1] === 'acme.com',
	    'VerificationStatus' => $t[1] === 'acme.com' ? 'SUCCESS' : 'PENDING', 'DkimAttributes' => [ 'Status' => 'SUCCESS' ] ]) : $err(404, 'NotFoundException', "Email identity {$t[1]} does not exist.");

    case $path === '/v2/email/suppression/addresses' && $method === 'GET':
	$all = array_values(array_filter($suppressed, fn($s) => (empty($query['Reasons']) || $s['Reason'] === $query['Reasons']) && (empty($query['StartDate']) || $s['LastUpdateTime'] >= $query['StartDate'])));
	[ $list, $next ] = $pageOf($all);
	$out(array_filter([ 'SuppressedDestinationSummaries' => $list, 'NextToken' => $next ], fn($v) => $v !== null));

    case $path === '/v2/email/suppression/addresses' && $method === 'PUT':
	$a = strtolower((string)($body['EmailAddress'] ?? ''));
	$suppressed[$a] = [ 'EmailAddress' => $a, 'Reason' => $body['Reason'] ?? 'BOUNCE', 'LastUpdateTime' => '2026-11-15T10:00:00Z' ];
	$save('suppressed', $suppressed);
	$out((object)[]);

    case (bool)preg_match('~^/v2/email/suppression/addresses/([^/]+)$~', $path, $t):
	$a = strtolower($t[1]);
	if (!isset($suppressed[$a]))
	    $err(404, 'NotFoundException', "Email address {$a} does not exist on your suppression list.");
	if ($method === 'DELETE') {
	    unset($suppressed[$a]);
	    $save('suppressed', $suppressed);
	    $out((object)[]);
	}
	$out([ 'SuppressedDestination' => $suppressed[$a] ]);

    case $path === '/v2/email/configuration-sets':
	$out([ 'ConfigurationSets' => [ 'tracking' ] ]);
}
$err(404, 'NotFoundException', "no route {$method} {$path}");
