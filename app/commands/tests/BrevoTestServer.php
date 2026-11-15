<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: a fake Brevo API v3 for the check suite (CheckCommand::suiteBrevo), run by PHP's built-in server:
    BREVO_STATE=<a folder> php -S 127.0.0.1:<port> BrevoTestServer.php
	`api-key: key-brevo` (else 401). account; smtp/email (a sender of acme.com only; a template 1 or 2; 999: not found), smtp/emails (needs email, templateId or messageId),
	smtp/statistics/events (<state>/events.json), aggregatedReport, smtp/templates (3, one inactive); contacts (by address or ID; upsert; lists 7 and 8; add / remove).
	The largest pages the client may ask (else 400): emails 500, events 2500, templates 1000, contacts 500, lists 50. Errors as Brevo's { code, message }.
	<state>/throttle.json: how many 429 next (Retry-After: 1). Each request appended to <state>/requests.jsonl (method, path, query, body, key)
*/

$state = getenv('BREVO_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$body = json_decode(file_get_contents('php://input'), true);
$key = $_SERVER['HTTP_API_KEY'] ?? null;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body, 'key' => $key ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$out = function($v, $status = 200, array $headers = []) {
    http_response_code($status);
    foreach($headers as $k => $h)
	header("{$k}: {$h}");
    if ($v !== null) {
	header('Content-Type: application/json');
	echo json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
};
$err = fn($status, $code, $message) => $out([ 'code' => $code, 'message' => $message ], $status);

if ($key !== 'key-brevo')
    $err(401, 'unauthorized', 'Key not found');
if (($t = (int)$load('throttle', 0)) > 0) {
    $save('throttle', $t - 1);
    $out([ 'code' => 'too_many_requests', 'message' => 'Too many requests' ], 429, [ 'Retry-After' => '1' ]);
}
$page = function($max) use ($query, $err) {
    $limit = (int)($query['limit'] ?? 50);
    if ($limit > $max)
	$err(400, 'out_of_range', "limit must be at most {$max}");
    return [ $limit, (int)($query['offset'] ?? 0) ];
};
$contacts = $load('contacts', [ 'ana@acme.com' => [ 'id' => 11, 'email' => 'ana@acme.com', 'emailBlacklisted' => false, 'attributes' => [ 'FIRSTNAME' => 'Ana' ], 'listIds' => [ 7 ],
    'modifiedAt' => '2026-11-10T10:00:00.000Z' ], 'bob@acme.com' => [ 'id' => 12, 'email' => 'bob@acme.com', 'emailBlacklisted' => false, 'attributes' => [], 'listIds' => [ 7, 8 ],
    'modifiedAt' => '2026-11-14T10:00:00.000Z' ] ]);
$find = function($ident) use ($contacts) {
    foreach($contacts as $c)
	if (strcasecmp($c['email'], $ident) === 0 || (string)$c['id'] === $ident)
	    return $c;
    return null;
};
$templates = [ [ 'id' => 1, 'name' => 'Welcome', 'subject' => 'Welcome {{ params.name }}', 'isActive' => true ], [ 'id' => 2, 'name' => 'Invoice', 'subject' => 'Your invoice', 'isActive' => true ],
    [ 'id' => 3, 'name' => 'Old', 'subject' => 'Old', 'isActive' => false ] ];

switch(true) {
    case $path === '/v3/account':
	$out([ 'email' => 'ops@acme.com', 'companyName' => 'Acme', 'plan' => [ [ 'type' => 'free', 'credits' => 300 ] ] ]);

    case $path === '/v3/smtp/email' && $method === 'POST':
	$from = (string)($body['sender']['email'] ?? '');
	if (!str_ends_with($from, '@acme.com'))
	    $err(400, 'invalid_parameter', 'Sender is invalid / inactive');
	if (empty($body['to']))
	    $err(400, 'missing_parameter', 'to is missing');
	if (isset($body['templateId']) && !in_array($body['templateId'], [ 1, 2 ], true))
	    $err(404, 'document_not_found', 'Template ID does not exist');
	if (!isset($body['templateId']) && empty($body['htmlContent']) && empty($body['textContent']))
	    $err(400, 'missing_parameter', 'htmlContent or textContent is missing');
	$n = count($load('sent', [])) + 1;
	$id = "<20261115{$n}.check@smtp-relay.mailin.fr>";
	$sent = $load('sent', []);
	foreach($body['to'] as $t)
	    $sent[] = [ 'email' => $t['email'], 'subject' => $body['subject'] ?? 'Welcome', 'templateId' => $body['templateId'] ?? null, 'messageId' => $id, 'uuid' => "u-{$n}",
		'date' => '2026-11-15T10:00:00.000+01:00', 'from' => $from, 'tags' => $body['tags'] ?? [] ];
	$save('sent', $sent);
	$out([ 'messageId' => $id ], 201);

    case $path === '/v3/smtp/emails':
	[ $limit, $offset ] = $page(500);
	if (empty($query['email']) && empty($query['templateId']) && empty($query['messageId']))
	    $err(400, 'missing_parameter', 'Please provide atleast one of email, templateId, messageId');
	$list = array_values(array_filter($load('sent', []), fn($s) => (empty($query['email']) || $s['email'] === $query['email'])
	    && (empty($query['messageId']) || $s['messageId'] === $query['messageId']) && (empty($query['templateId']) || (string)$s['templateId'] === (string)$query['templateId'])));
	$out([ 'count' => count($list), 'transactionalEmails' => array_slice($list, $offset, $limit) ]);

    case $path === '/v3/smtp/statistics/events':
	[ $limit, $offset ] = $page(2500);
	$list = array_values(array_filter($load('events', []), fn($e) => (empty($query['event']) || $e['event'] === $query['event']) && (empty($query['email']) || $e['email'] === $query['email'])));
	$out([ 'events' => array_slice($list, $offset, $limit) ]);

    case $path === '/v3/smtp/statistics/aggregatedReport':
	$out([ 'range' => ($query['startDate'] ?? '') . '|' . ($query['endDate'] ?? '') . '|' . ($query['days'] ?? ''), 'requests' => 120, 'delivered' => 116, 'hardBounces' => 2,
	    'softBounces' => 1, 'uniqueOpens' => 70, 'opens' => 95, 'clicks' => 30, 'uniqueClicks' => 22, 'spamReports' => 0, 'blocked' => 1, 'invalid' => 0, 'unsubscribed' => 1 ]);

    case $path === '/v3/smtp/templates':
	[ $limit, $offset ] = $page(1000);
	$list = ($query['templateStatus'] ?? '') === 'true' ? array_values(array_filter($templates, fn($t) => $t['isActive'])) : $templates;
	$out([ 'count' => count($list), 'templates' => array_slice($list, $offset, $limit) ]);

    case $path === '/v3/contacts' && $method === 'POST':
	$email = strtolower((string)($body['email'] ?? ''));
	if ($c = $find($email)) {
	    if (empty($body['updateEnabled']))
		$err(400, 'duplicate_parameter', 'Contact already exist');
	    $contacts[$c['email']]['attributes'] = ($body['attributes'] ?? []) + $c['attributes'];
	    $contacts[$c['email']]['listIds'] = array_values(array_unique(array_merge($c['listIds'], $body['listIds'] ?? [])));
	    $save('contacts', $contacts);
	    $out(null, 204);
	}
	$id = 10 + count($contacts) + 1;
	$contacts[$email] = [ 'id' => $id, 'email' => $email, 'emailBlacklisted' => false, 'attributes' => $body['attributes'] ?? [], 'listIds' => $body['listIds'] ?? [], 'modifiedAt' => '2026-11-15T10:00:00.000Z' ];
	$save('contacts', $contacts);
	$out([ 'id' => $id ], 201);

    case $path === '/v3/contacts' && $method === 'GET':
	[ $limit, $offset ] = $page(500);
	$list = array_values(array_filter($contacts, fn($c) => empty($query['modifiedSince']) || $c['modifiedAt'] >= $query['modifiedSince']));
	$out([ 'contacts' => array_slice($list, $offset, $limit), 'count' => count($list) ]);

    case $path === '/v3/contacts/lists' && $method === 'GET':
	[ $limit, $offset ] = $page(50);
	$lists = [ [ 'id' => 7, 'name' => 'Customers', 'totalSubscribers' => 2 ], [ 'id' => 8, 'name' => 'Newsletter', 'totalSubscribers' => 1 ] ];
	$out([ 'lists' => array_slice($lists, $offset, $limit), 'count' => count($lists) ]);

    case (bool)preg_match('~^/v3/contacts/lists/(\d+)/contacts$~', $path, $m) && $method === 'GET':
	[ $limit, $offset ] = $page(500);
	$list = array_values(array_filter($contacts, fn($c) => in_array((int)$m[1], $c['listIds'], true)));
	$out([ 'contacts' => array_slice($list, $offset, $limit), 'count' => count($list) ]);

    case (bool)preg_match('~^/v3/contacts/lists/(\d+)/contacts/(add|remove)$~', $path, $m) && $method === 'POST':
	if (!in_array((int)$m[1], [ 7, 8 ], true))
	    $err(404, 'document_not_found', 'List ID does not exist');
	$ok = [];
	$fail = [];
	foreach((array)($body['emails'] ?? []) as $em) {
	    $c = $find($em);
	    if (!$c) {
		$fail[] = $em;
		continue;
	    }
	    $ids = $contacts[$c['email']]['listIds'];
	    $contacts[$c['email']]['listIds'] = $m[2] === 'add' ? array_values(array_unique(array_merge($ids, [ (int)$m[1] ]))) : array_values(array_diff($ids, [ (int)$m[1] ]));
	    $ok[] = $em;
	}
	$save('contacts', $contacts);
	$out([ 'contacts' => [ 'success' => $ok, 'failure' => $fail ] ], 201);

    case (bool)preg_match('~^/v3/contacts/([^/]+)$~', $path, $m):
	$c = $find($m[1]);
	if (!$c)
	    $err(404, 'document_not_found', 'Contact does not exist');
	if ($method === 'GET')
	    $out($c);
	if ($method === 'DELETE') {
	    unset($contacts[$c['email']]);
	    $save('contacts', $contacts);
	    $out(null, 204);
	}
	if ($method === 'PUT') {
	    $x = &$contacts[$c['email']];
	    $x['attributes'] = ($body['attributes'] ?? []) + $x['attributes'];
	    $x['listIds'] = array_values(array_diff(array_unique(array_merge($x['listIds'], $body['listIds'] ?? [])), $body['unlinkListIds'] ?? []));
	    if (isset($body['emailBlacklisted']))
		$x['emailBlacklisted'] = (bool)$body['emailBlacklisted'];
	    unset($x);
	    $save('contacts', $contacts);
	    $out(null, 204);
	}
	break;

    case $path === '/v3/senders':
	$out([ 'senders' => [ [ 'id' => 1, 'name' => 'Acme', 'email' => 'noreply@acme.com', 'active' => true ] ] ]);
}
$err(404, 'not_found', "no route {$method} {$path}");
