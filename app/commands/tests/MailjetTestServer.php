<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: a fake Mailjet for the check suite (CheckCommand::suiteMailjet), run by PHP's built-in server:
    MAILJET_STATE=<a folder> php -S 127.0.0.1:<port> MailjetTestServer.php
	Basic mj-key:mj-secret (else 401). /v3/REST/user; /v3.1/send (a sender of acme.com only, else the message's error; a template 501; SandboxMode: nothing kept; MessageIDs from
	1000), /v3/REST/message (+ /{id}), messagehistory/{id}, template; contacts (by address or ID; created; contactdata; managecontact on lists 31 and 32; contactslist) and
	DELETE /v4/contacts/{id}. Limit at most 1000 (else 400). Errors as Mailjet's: { ErrorInfo, ErrorMessage, StatusCode }, a send's { Messages: [ { Status, Errors } ] }.
	<state>/throttle.json: how many 429 next (Retry-After: 1). Each request appended to <state>/requests.jsonl (method, path, query, body, auth)
*/

$state = getenv('MAILJET_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$body = json_decode(file_get_contents('php://input'), true);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body, 'auth' => $auth ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$out = function($v, $status = 200, array $headers = []) {
    http_response_code($status);
    foreach($headers as $k => $h)
	header("{$k}: {$h}");
    header('Content-Type: application/json');
    echo $v === null ? '' : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
$err = fn($status, $message, $info = '') => $out([ 'ErrorInfo' => $info, 'ErrorMessage' => $message, 'StatusCode' => $status ], $status);
$data = fn(array $list, $total = null) => $out([ 'Count' => count($list), 'Data' => array_values($list), 'Total' => $total ?? count($list) ]);

if ($auth !== 'Basic ' . base64_encode('mj-key:mj-secret'))
    $err(401, 'API key authentication/authorization failure. You may be unauthorized to access the API or your API key may be expired. Visit API keys management section to check your keys.');
if (($t = (int)$load('throttle', 0)) > 0) {
    $save('throttle', $t - 1);
    $err(429, 'Too many requests');
}
$limit = (int)($query['Limit'] ?? 10);
$offset = (int)($query['Offset'] ?? 0);
if ($limit > 1000)
    $err(400, 'Limit must be at most 1000');
$contacts = $load('contacts', [ 'ana@acme.com' => [ 'ID' => 101, 'Email' => 'ana@acme.com', 'Name' => 'Ana', 'IsExcludedFromCampaigns' => false, 'lists' => [ 31 ], 'data' => [ 'firstname' => 'Ana' ] ] ]);
$find = function($ident) use ($contacts) {
    foreach($contacts as $c)
	if (strcasecmp($c['Email'], $ident) === 0 || (string)$c['ID'] === $ident)
	    return $c;
    return null;
};
$pub = fn($c) => array_diff_key($c, [ 'lists' => 1, 'data' => 1 ]);

switch(true) {
    case $path === '/v3/REST/user':
	$data([ [ 'ID' => 1, 'Email' => 'ops@acme.com', 'Username' => 'acme' ] ]);

    case $path === '/v3.1/send' && $method === 'POST':
	$m = (array)($body['Messages'][0] ?? []);
	$errors = [];
	if (!str_ends_with((string)($m['From']['Email'] ?? ''), '@acme.com'))
	    $errors[] = [ 'ErrorIdentifier' => 'e1', 'ErrorCode' => 'send-0003', 'StatusCode' => 400, 'ErrorMessage' => 'The sender is not authorised: validate it first', 'ErrorRelatedTo' => [ 'From.Email' ] ];
	if (isset($m['TemplateID']) && $m['TemplateID'] !== 501)
	    $errors[] = [ 'ErrorIdentifier' => 'e2', 'ErrorCode' => 'send-0021', 'StatusCode' => 400, 'ErrorMessage' => 'Template not found', 'ErrorRelatedTo' => [ 'TemplateID' ] ];
	if (!isset($m['TemplateID']) && empty($m['TextPart']) && empty($m['HTMLPart']))
	    $errors[] = [ 'ErrorIdentifier' => 'e3', 'ErrorCode' => 'send-0016', 'StatusCode' => 400, 'ErrorMessage' => 'At least TextPart or HTMLPart must be provided', 'ErrorRelatedTo' => [ 'HTMLPart' ] ];
	if ($errors)
	    $out([ 'Messages' => [ [ 'Status' => 'error', 'Errors' => $errors ] ] ], 400);
	$next = (int)$load('next', 1000);
	$res = [ 'Status' => 'success', 'CustomID' => '' ];
	$sent = $load('sent', []);
	foreach([ 'To', 'Cc', 'Bcc' ] as $k) {
	    $res[$k] = [];
	    foreach((array)($m[$k] ?? []) as $r) {
		$id = $next++;
		$res[$k][] = [ 'Email' => $r['Email'], 'MessageUUID' => "uuid-{$id}", 'MessageID' => $id, 'MessageHref' => "https://api.mailjet.com/v3/REST/message/{$id}" ];
		if (empty($body['SandboxMode']))
		    $sent[] = [ 'ID' => $id, 'ContactAlt' => $r['Email'], 'Subject' => $m['Subject'] ?? 'from template', 'Status' => 'sent', 'ArrivedAt' => '2026-11-15T10:00:00Z' ];
	    }
	}
	$save('next', $next);
	$save('sent', $sent);
	$out([ 'Messages' => [ $res ] ]);

    case $path === '/v3/REST/message':
	$list = array_values(array_filter($load('sent', []), fn($s) => (empty($query['ContactAlt']) || $s['ContactAlt'] === $query['ContactAlt'])
	    && (empty($query['MessageStatus']) || $s['Status'] === $query['MessageStatus'])));
	$data(array_slice($list, $offset, $limit), count($list));

    case (bool)preg_match('~^/v3/REST/message/(\d+)$~', $path, $m):
	foreach($load('sent', []) as $s)
	    if ((string)$s['ID'] === $m[1])
		$data([ $s ]);
	$err(404, 'Object not found');

    case (bool)preg_match('~^/v3/REST/messagehistory/(\d+)$~', $path, $m):
	$data([ [ 'EventAt' => 1763200800, 'EventType' => 'sent', 'State' => '' ], [ 'EventAt' => 1763200900, 'EventType' => 'opened', 'State' => '' ] ]);

    case $path === '/v3/REST/template':
	$all = [ [ 'ID' => 501, 'Name' => 'Welcome', 'OwnerType' => 'user' ], [ 'ID' => 502, 'Name' => 'Receipt', 'OwnerType' => 'user' ] ];
	$data(array_slice($all, $offset, $limit), count($all));

    case $path === '/v3/REST/contact' && $method === 'POST':
	$email = strtolower((string)($body['Email'] ?? ''));
	if ($find($email))
	    $err(400, "MJ18 A Contact resource with value \"{$email}\" for Email already exists.");
	$id = 100 + count($contacts) + 1;
	$contacts[$email] = [ 'ID' => $id, 'Email' => $email, 'Name' => $body['Name'] ?? '', 'IsExcludedFromCampaigns' => !empty($body['IsExcludedFromCampaigns']), 'lists' => [], 'data' => [] ];
	$save('contacts', $contacts);
	$out([ 'Count' => 1, 'Data' => [ $pub($contacts[$email]) ], 'Total' => 1 ], 201);

    case $path === '/v3/REST/contact' && $method === 'GET':
	$list = array_values(array_filter($contacts, fn($c) => empty($query['ContactsList']) || in_array((int)$query['ContactsList'], $c['lists'], true)));
	$data(array_map($pub, array_slice($list, $offset, $limit)), count($list));

    case (bool)preg_match('~^/v3/REST/contact/([^/]+)$~', $path, $m):
	$c = $find($m[1]);
	$c ? $data([ $pub($c) ]) : $err(404, 'Object not found');

    case (bool)preg_match('~^/v3/REST/contactdata/([^/]+)$~', $path, $m):
	$c = $find($m[1]);
	if (!$c)
	    $err(404, 'Object not found');
	if ($method === 'PUT') {
	    foreach((array)($body['Data'] ?? []) as $d)
		$contacts[$c['Email']]['data'][$d['Name']] = $d['Value'];
	    $save('contacts', $contacts);
	    $c = $contacts[$c['Email']];
	}
	$data([ [ 'ContactID' => $c['ID'], 'Data' => array_map(fn($k, $v) => [ 'Name' => $k, 'Value' => $v ], array_keys($c['data']), array_values($c['data'])) ] ]);

    case (bool)preg_match('~^/v3/REST/contactslist/(\d+)/managecontact$~', $path, $m) && $method === 'POST':
	if (!in_array((int)$m[1], [ 31, 32 ], true))
	    $err(404, 'Object not found');
	$email = strtolower((string)($body['Email'] ?? ''));
	$c = $find($email);
	if (!$c) {
	    if (($body['Action'] ?? '') !== 'addforce')
		$err(400, 'Contact not found');
	    $contacts[$email] = $c = [ 'ID' => 100 + count($contacts) + 1, 'Email' => $email, 'Name' => $body['Name'] ?? '', 'IsExcludedFromCampaigns' => false, 'lists' => [], 'data' => [] ];
	}
	$lists = $contacts[$c['Email']]['lists'];
	$contacts[$c['Email']]['lists'] = ($body['Action'] ?? '') === 'remove' ? array_values(array_diff($lists, [ (int)$m[1] ])) : array_values(array_unique(array_merge($lists, [ (int)$m[1] ])));
	foreach((array)($body['Properties'] ?? []) as $k => $v)
	    $contacts[$c['Email']]['data'][$k] = $v;
	$save('contacts', $contacts);
	$data([ [ 'ContactID' => $c['ID'], 'Email' => $c['Email'], 'Action' => $body['Action'] ?? '', 'Name' => $body['Name'] ?? '', 'Properties' => $body['Properties'] ?? (object)[] ] ]);

    case $path === '/v3/REST/contactslist':
	$all = [ [ 'ID' => 31, 'Name' => 'Customers', 'SubscriberCount' => 1 ], [ 'ID' => 32, 'Name' => 'Newsletter', 'SubscriberCount' => 0 ] ];
	$data(array_slice($all, $offset, $limit), count($all));

    case (bool)preg_match('~^/v4/contacts/(\d+)$~', $path, $m) && $method === 'DELETE':
	$c = $find($m[1]);
	if (!$c)
	    $err(404, 'Object not found');
	unset($contacts[$c['Email']]);
	$save('contacts', $contacts);
	$out(null, 200);

    case $path === '/v3/REST/sender':
	$data([ [ 'ID' => 1, 'Email' => 'noreply@acme.com', 'Status' => 'Active' ] ]);
}
$err(404, "no route {$method} {$path}");
