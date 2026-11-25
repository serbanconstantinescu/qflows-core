<?php

/*
    CLD: 2026-11-26T00:00:00+02:00: a fake GitLab API v4 for the check suite (CheckCommand::suiteGitLab), run by PHP's built-in server:
    GITLAB_STATE=<a folder> php -S 127.0.0.1:<port> GitLabTestServer.php
	PRIVATE-TOKEN glpat-check (user qbot; scopes api), else 401. The project acme/web (ID 7, default branch main; by its path encoded or its ID): users ana (11), dan (12);
	milestones; issues (#1, #2), notes; merge requests (!3 mergeable, !4 with conflicts), diffs, approvals, reviewers, merge (405 for !4); files (raw, HEAD, create, update,
	delete), the tree, branches, tags, commits; releases (permalink/latest), uploads (multipart), release links; pipelines (a new one moves on with each GET: pending, running,
	success), jobs, a job's trace, retry, cancel, play. Pages: per_page, page, the Link header. <state>/throttle.json: how many 429 next (Retry-After: 1).
	Data in <state>/db.json. Each request appended to <state>/requests.jsonl (method, path, query, body, token, type)
*/

$state = getenv('GITLAB_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$rawPath = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$token = (string)($_SERVER['HTTP_PRIVATE_TOKEN'] ?? '');
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $rawPath, 'query' => $query, 'body' => $body, 'token' => $token, 'type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'size' => strlen($raw) ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$host = 'http://' . $_SERVER['HTTP_HOST'];
$out = function($v, $status = 200, array $headers = []) {
    http_response_code($status);
    foreach($headers as $k => $h)
	header("{$k}: {$h}");
    if ($v !== null) {
	header('Content-Type: application/json');
	echo json_encode($v, JSON_UNESCAPED_SLASHES);
    }
    exit;
};
$fail = fn($status, $message) => $out([ 'message' => $message ], $status);
$now = gmdate('Y-m-d\TH:i:s.000\Z');

if ($token !== 'glpat-check')
    $fail(401, '401 Unauthorized');
if (($t = (int)$load('throttle', 0)) > 0) {
    $save('throttle', $t - 1);
    $out([ 'message' => 'Retry later' ], 429, [ 'Retry-After' => '1' ]);
}
if (!str_starts_with($rawPath, '/api/v4/'))
    $fail(404, '404 Not Found');
$path = substr($rawPath, 7);

$db = $load('db', null) ?? [
    'issues' => [
	1 => [ 'id' => 101, 'iid' => 1, 'title' => 'Printer down', 'state' => 'opened', 'description' => 'Floor 2', 'labels' => [ 'bug' ], 'assignees' => [], 'author' => 'ana', 'notes' => [], 'created_at' => '2026-11-01T10:00:00.000Z', 'updated_at' => '2026-11-01T10:00:00.000Z' ],
	2 => [ 'id' => 102, 'iid' => 2, 'title' => 'Dark mode', 'state' => 'closed', 'description' => '', 'labels' => [ 'feature' ], 'assignees' => [], 'author' => 'dan', 'notes' => [], 'created_at' => '2026-11-02T10:00:00.000Z', 'updated_at' => '2026-11-03T10:00:00.000Z' ],
    ],
    'mrs' => [
	3 => [ 'id' => 203, 'iid' => 3, 'title' => 'Fix printer driver', 'state' => 'opened', 'description' => 'Closes #1', 'source_branch' => 'fix/printer', 'target_branch' => 'main', 'sha' => str_repeat('a', 40),
	    'detailed_merge_status' => 'mergeable', 'has_conflicts' => false, 'author' => 'dan', 'reviewers' => [], 'approved_by' => [], 'created_at' => '2026-11-04T10:00:00.000Z', 'updated_at' => '2026-11-04T10:00:00.000Z', 'merged_at' => null ],
	4 => [ 'id' => 204, 'iid' => 4, 'title' => 'WIP refactor', 'state' => 'opened', 'description' => '', 'source_branch' => 'wip', 'target_branch' => 'main', 'sha' => str_repeat('b', 40),
	    'detailed_merge_status' => 'conflict', 'has_conflicts' => true, 'author' => 'ana', 'reviewers' => [], 'approved_by' => [], 'created_at' => '2026-11-05T10:00:00.000Z', 'updated_at' => '2026-11-05T10:00:00.000Z', 'merged_at' => null ],
    ],
    'files' => [ 'README.md' => '# Web', 'docs/a.md' => 'A doc', 'data.json' => '{"n": 1}' ],
    'branches' => [ 'main' => str_repeat('c', 40), 'fix/printer' => str_repeat('a', 40), 'wip' => str_repeat('b', 40) ],
    'releases' => [ [ 'tag_name' => 'v1.0.0', 'name' => 'First', 'description' => 'Hello', 'created_at' => '2026-10-01T10:00:00.000Z', 'released_at' => '2026-10-01T10:00:00.000Z', 'links' => [] ] ],
    'pipelines' => [ 900 => [ 'id' => 900, 'iid' => 40, 'status' => 'failed', 'ref' => 'main', 'sha' => str_repeat('c', 40), 'source' => 'push', 'created_at' => '2026-11-06T10:00:00.000Z', 'updated_at' => '2026-11-06T10:05:00.000Z', 'gets' => 0 ] ],
    'uploads' => [],
    'last_commit' => null,
];
$saveDb = function() use (&$db, $save) {
    $save('db', $db);
};
$users = [ 'qbot' => 10, 'ana' => 11, 'dan' => 12 ];
$byId = array_flip($users);
$u = fn($name) => [ 'id' => $users[$name] ?? 0, 'username' => $name ];
$issueView = fn($i) => [ 'id' => $i['id'], 'iid' => $i['iid'], 'title' => $i['title'], 'state' => $i['state'], 'description' => $i['description'], 'labels' => $i['labels'],
    'assignees' => array_map($u, $i['assignees']), 'author' => $u($i['author']), 'milestone' => isset($i['milestone_id']) ? [ 'id' => $i['milestone_id'], 'title' => 'Sprint 1' ] : null,
    'due_date' => $i['due_date'] ?? null, 'confidential' => (bool)($i['confidential'] ?? false), 'user_notes_count' => count($i['notes']), 'web_url' => "https://gitlab.com/acme/web/-/issues/{$i['iid']}",
    'references' => [ 'full' => "acme/web#{$i['iid']}" ], 'created_at' => $i['created_at'], 'updated_at' => $i['updated_at'], 'closed_at' => $i['state'] === 'closed' ? $i['updated_at'] : null ];
$mrView = fn($m) => [ 'id' => $m['id'], 'iid' => $m['iid'], 'title' => $m['title'], 'state' => $m['state'], 'draft' => str_starts_with($m['title'], 'Draft:'), 'description' => $m['description'],
    'source_branch' => $m['source_branch'], 'target_branch' => $m['target_branch'], 'sha' => $m['sha'], 'detailed_merge_status' => $m['detailed_merge_status'], 'has_conflicts' => $m['has_conflicts'],
    'author' => $u($m['author']), 'reviewers' => array_map($u, $m['reviewers']), 'assignees' => [], 'labels' => [], 'head_pipeline' => [ 'status' => 'success' ],
    'web_url' => "https://gitlab.com/acme/web/-/merge_requests/{$m['iid']}", 'references' => [ 'full' => "acme/web!{$m['iid']}" ], 'created_at' => $m['created_at'], 'updated_at' => $m['updated_at'], 'merged_at' => $m['merged_at'] ];
$pipeView = fn($p) => [ 'id' => $p['id'], 'iid' => $p['iid'], 'status' => $p['status'], 'ref' => $p['ref'], 'sha' => $p['sha'], 'source' => $p['source'], 'web_url' => "https://gitlab.com/acme/web/-/pipelines/{$p['id']}",
    'user' => [ 'username' => 'qbot' ], 'created_at' => $p['created_at'], 'updated_at' => $p['updated_at'] ];
$page = function(array $items) use ($query, $rawPath, $host) {
    $per = max(1, min(100, (int)($query['per_page'] ?? 20)));
    $p = max(1, (int)($query['page'] ?? 1));
    $slice = array_slice(array_values($items), ($p - 1) * $per, $per);
    $h = [];
    if ($p * $per < count($items)) {
	$q = $query;
	$q['page'] = $p + 1;
	$h['Link'] = '<' . $host . $rawPath . '?' . http_build_query($q) . '>; rel="next"';
    }
    return [ $slice, $h ];
};

if ($path === '/user')
    $out([ 'id' => 10, 'username' => 'qbot', 'bot' => false ]);
if ($path === '/personal_access_tokens/self')
    $out([ 'id' => 1, 'scopes' => [ 'api' ] ]);
if ($path === '/users') {
    $name = (string)($query['username'] ?? '');
    $out(isset($users[$name]) ? [ $u($name) ] : []);
}
if (!preg_match('~^/projects/(acme%2Fweb|7)(/.*)?$~', $path, $m))
    $fail(404, '404 Project Not Found');
$p = $m[2] ?? '';
if ($p === '')
    $out([ 'id' => 7, 'path_with_namespace' => 'acme/web', 'default_branch' => 'main', 'web_url' => 'https://gitlab.com/acme/web' ]);

//issues
if ($p === '/issues' && $method === 'GET') {
    $all = array_values(array_filter($db['issues'], fn($i) => (!isset($query['state']) || $i['state'] === $query['state']) && (!isset($query['labels']) || !array_diff(explode(',', $query['labels']), $i['labels']))
	&& (!isset($query['search']) || stripos($i['title'] . ' ' . $i['description'], $query['search']) !== false) && (!isset($query['created_after']) || $i['created_at'] > $query['created_after'])
	&& (!isset($query['author_username']) || $i['author'] === $query['author_username'])));
    usort($all, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    [ $slice, $h ] = $page(array_map($issueView, $all));
    $out($slice, 200, $h);
}
if ($p === '/issues' && $method === 'POST') {
    if (trim((string)($body['title'] ?? '')) === '')
	$out([ 'message' => [ 'title' => [ 'can\'t be blank' ] ] ], 400);
    $n = max(array_keys($db['issues'])) + 1;
    $db['issues'][$n] = [ 'id' => 100 + $n, 'iid' => $n, 'title' => $body['title'], 'state' => 'opened', 'description' => (string)($body['description'] ?? ''),
	'labels' => isset($body['labels']) ? explode(',', $body['labels']) : [], 'assignees' => array_map(fn($id) => $byId[$id], (array)($body['assignee_ids'] ?? [])), 'author' => 'qbot', 'notes' => [],
	'created_at' => $now, 'updated_at' => $now ] + (isset($body['milestone_id']) ? [ 'milestone_id' => $body['milestone_id'] ] : []) + (isset($body['due_date']) ? [ 'due_date' => $body['due_date'] ] : [])
	+ (isset($body['confidential']) ? [ 'confidential' => $body['confidential'] ] : []);
    $saveDb();
    $out($issueView($db['issues'][$n]), 201);
}
if ($p === '/milestones')
    $out(($query['title'] ?? '') === 'Sprint 1' ? [ [ 'id' => 55, 'title' => 'Sprint 1' ] ] : []);
$notes = function(array &$item, $rest) use ($method, $body, $now, $u, $out, $page) {
    if ($rest !== '/notes')
	return;
    if ($method === 'POST') {
	$n = [ 'id' => 3000 + count($item['notes']), 'body' => (string)$body['body'], 'author' => $u('qbot'), 'system' => false, 'created_at' => $now, 'updated_at' => $now ];
	$item['notes'][] = $n;
	$GLOBALS['saveDb']();
	$out($n, 201);
    }
    [ $slice, $h ] = $page(array_merge([ [ 'id' => 2999, 'body' => 'changed the description', 'system' => true, 'author' => $u('ana'), 'created_at' => $now ] ], $item['notes']));
    $out($slice, 200, $h);
};
$GLOBALS['saveDb'] = $saveDb;
if (preg_match('~^/issues/(\d+)(/.*)?$~', $p, $mm)) {
    $n = (int)$mm[1];
    if (!isset($db['issues'][$n]))
	$fail(404, '404 Issue Not Found');
    $i = &$db['issues'][$n];
    $rest = $mm[2] ?? '';
    $notes($i, $rest);
    if ($method === 'PUT') {
	foreach([ 'title', 'description', 'milestone_id', 'due_date' ] as $k)
	    if (isset($body[$k]))
		$i[$k] = $body[$k];
	if (isset($body['labels']))
	    $i['labels'] = explode(',', $body['labels']);
	if (isset($body['add_labels']))
	    $i['labels'] = array_values(array_unique(array_merge($i['labels'], explode(',', $body['add_labels']))));
	if (isset($body['remove_labels']))
	    $i['labels'] = array_values(array_diff($i['labels'], explode(',', $body['remove_labels'])));
	if (isset($body['assignee_ids']))
	    $i['assignees'] = array_map(fn($id) => $byId[$id], $body['assignee_ids']);
	if (isset($body['state_event']))
	    $i['state'] = $body['state_event'] === 'close' ? 'closed' : 'opened';
	$i['updated_at'] = $now;
	$saveDb();
	$out($issueView($i));
    }
    $out($issueView($i));
}

//merge requests
if ($p === '/merge_requests' && $method === 'GET') {
    $all = array_values(array_filter($db['mrs'], fn($x) => (!isset($query['state']) || $x['state'] === $query['state']) && (!isset($query['target_branch']) || $x['target_branch'] === $query['target_branch'])
	&& (!isset($query['updated_after']) || $x['updated_at'] > $query['updated_after'])));
    $key = ($query['order_by'] ?? 'created_at') === 'updated_at' ? 'updated_at' : 'created_at';
    usort($all, fn($a, $b) => strcmp($b[$key], $a[$key]));
    [ $slice, $h ] = $page(array_map($mrView, $all));
    $out($slice, 200, $h);
}
if ($p === '/merge_requests' && $method === 'POST') {
    if (!isset($db['branches'][$body['source_branch'] ?? '']))
	$out([ 'message' => [ 'source_branch' => [ 'is invalid' ] ] ], 400);
    $n = max(array_keys($db['mrs'])) + 1;
    $db['mrs'][$n] = [ 'id' => 200 + $n, 'iid' => $n, 'title' => $body['title'], 'state' => 'opened', 'description' => (string)($body['description'] ?? ''), 'source_branch' => $body['source_branch'],
	'target_branch' => $body['target_branch'], 'sha' => $db['branches'][$body['source_branch']], 'detailed_merge_status' => 'mergeable', 'has_conflicts' => false, 'author' => 'qbot', 'reviewers' => [],
	'approved_by' => [], 'created_at' => $now, 'updated_at' => $now, 'merged_at' => null, 'squash' => $body['squash'] ?? false ];
    $saveDb();
    $out($mrView($db['mrs'][$n]), 201);
}
if (preg_match('~^/merge_requests/(\d+)(/.*)?$~', $p, $mm)) {
    $n = (int)$mm[1];
    if (!isset($db['mrs'][$n]))
	$fail(404, '404 Merge Request Not Found');
    $mr = &$db['mrs'][$n];
    $rest = $mm[2] ?? '';
    $mr += [ 'notes' => [] ];
    $notes($mr, $rest);
    if ($rest === '/diffs')
	$out([ [ 'old_path' => 'driver.c', 'new_path' => 'driver.c', 'diff' => '@@ -1 +1 @@', 'new_file' => false, 'deleted_file' => false, 'renamed_file' => false ],
	    [ 'old_path' => 'NEW.md', 'new_path' => 'NEW.md', 'diff' => '+new', 'new_file' => true, 'deleted_file' => false, 'renamed_file' => false ] ]);
    if ($rest === '/pipelines')
	$out([ $pipeView($db['pipelines'][900]) ]);
    if ($rest === '/approve' && $method === 'POST') {
	$mr['approved_by'] = [ 'qbot' ];
	$saveDb();
	$out([ 'approved' => true ], 201);
    }
    if ($rest === '/unapprove' && $method === 'POST') {
	$mr['approved_by'] = [];
	$saveDb();
	$out([ 'approved' => false ], 201);
    }
    if ($rest === '/approvals')
	$out([ 'approved' => (bool)$mr['approved_by'], 'approved_by' => array_map(fn($x) => [ 'user' => $u($x) ], $mr['approved_by']) ]);
    if ($rest === '/merge' && $method === 'PUT') {
	if ($mr['has_conflicts'])
	    $fail(406, 'Branch cannot be merged');
	if (!empty($body['merge_when_pipeline_succeeds'])) {
	    $mr['auto'] = true;
	    $saveDb();
	    $out($mrView($mr));
	}
	$mr['state'] = 'merged';
	$mr['merged_at'] = $now;
	$mr['updated_at'] = $now;
	$mr['merge_body'] = $body;
	$saveDb();
	$out($mrView($mr));
    }
    if ($rest === '' && $method === 'PUT') {
	foreach([ 'title', 'description', 'target_branch' ] as $k)
	    if (isset($body[$k]))
		$mr[$k] = $body[$k];
	if (isset($body['reviewer_ids']))
	    $mr['reviewers'] = array_map(fn($id) => $byId[$id], $body['reviewer_ids']);
	if (isset($body['state_event']))
	    $mr['state'] = $body['state_event'] === 'close' ? 'closed' : 'opened';
	$mr['updated_at'] = $now;
	$saveDb();
	$out($mrView($mr));
    }
    $out($mrView($mr));
}

//repository
if (preg_match('~^/repository/files/([^/]+)(/raw)?$~', $p, $mm)) {
    $fp = rawurldecode($mm[1]);
    if (!empty($mm[2])) {
	if (!isset($db['files'][$fp]))
	    $fail(404, '404 File Not Found');
	header('Content-Type: text/plain');
	echo $db['files'][$fp];
	exit;
    }
    if ($method === 'HEAD') {
	http_response_code(isset($db['files'][$fp]) ? 200 : 404);
	exit;
    }
    if ($method === 'POST' || $method === 'PUT') {
	if ($method === 'POST' && isset($db['files'][$fp]))
	    $fail(400, 'A file with this name already exists');
	if ($method === 'PUT' && !isset($db['files'][$fp]))
	    $fail(400, 'A file with this name doesn\'t exist');
	$db['files'][$fp] = ($body['encoding'] ?? '') === 'base64' ? base64_decode($body['content']) : $body['content'];
	$db['last_commit'] = [ 'message' => $body['commit_message'], 'branch' => $body['branch'] ];
	$saveDb();
	$out([ 'file_path' => $fp, 'branch' => $body['branch'] ], $method === 'POST' ? 201 : 200);
    }
    if ($method === 'DELETE') {
	if (!isset($db['files'][$fp]))
	    $fail(400, 'A file with this name doesn\'t exist');
	unset($db['files'][$fp]);
	$saveDb();
	$out(null, 204);
    }
}
if ($p === '/repository/tree') {
    $prefix = isset($query['path']) ? $query['path'] . '/' : '';
    $items = [];
    foreach(array_keys($db['files']) as $f) {
	if ($prefix !== '' && !str_starts_with($f, $prefix))
	    continue;
	$rest = substr($f, strlen($prefix));
	$first = explode('/', $rest)[0];
	$items[$first] = [ 'id' => sha1($f), 'name' => $first, 'type' => str_contains($rest, '/') ? 'tree' : 'blob', 'path' => $prefix . $first ];
    }
    [ $slice, $h ] = $page(array_values($items));
    $out($slice, 200, $h);
}
if ($p === '/repository/branches' && $method === 'POST') {
    if (isset($db['branches'][$body['branch']]))
	$fail(400, 'Branch already exists');
    $sha = $db['branches'][$body['ref']] ?? null;
    if (!$sha)
	$fail(400, 'Invalid reference name');
    $db['branches'][$body['branch']] = $sha;
    $saveDb();
    $out([ 'name' => $body['branch'], 'commit' => [ 'id' => $sha ] ], 201);
}
if ($p === '/repository/branches') {
    [ $slice, $h ] = $page(array_map(fn($k, $v) => [ 'name' => $k, 'commit' => [ 'id' => $v ], 'protected' => $k === 'main', 'default' => $k === 'main' ], array_keys($db['branches']), array_values($db['branches'])));
    $out($slice, 200, $h);
}
if ($p === '/repository/tags')
    $out([ [ 'name' => 'v1.0.0', 'message' => '', 'commit' => [ 'id' => str_repeat('c', 40) ] ] ]);
if ($p === '/repository/commits')
    $out([ [ 'id' => str_repeat('c', 40), 'title' => 'Initial', 'message' => "Initial\n", 'author_name' => 'Ana', 'author_email' => 'ana@acme.com', 'authored_date' => '2026-11-01T10:00:00.000Z', 'web_url' => 'https://gitlab.com/acme/web/-/commit/c' ] ]);
$relView = fn($r) => [ 'tag_name' => $r['tag_name'], 'name' => $r['name'], 'description' => $r['description'], 'created_at' => $r['created_at'], 'released_at' => $r['released_at'],
    'author' => [ 'username' => 'qbot' ], 'commit' => [ 'id' => str_repeat('c', 40) ], 'assets' => [ 'links' => $r['links'] ], '_links' => [ 'self' => "https://gitlab.com/acme/web/-/releases/{$r['tag_name']}" ] ];
if ($p === '/releases' && $method === 'GET')
    $out(array_map($relView, array_reverse($db['releases'])));
if ($p === '/releases/permalink/latest')
    $out($relView(end($db['releases'])));
if ($p === '/releases' && $method === 'POST') {
    foreach($db['releases'] as $r)
	if ($r['tag_name'] === $body['tag_name'])
	    $fail(409, 'Release already exists');
    $r = [ 'tag_name' => $body['tag_name'], 'name' => $body['name'] ?? $body['tag_name'], 'description' => (string)($body['description'] ?? ''), 'ref' => $body['ref'] ?? null, 'created_at' => $now, 'released_at' => $now, 'links' => [] ];
    $db['releases'][] = $r;
    $saveDb();
    $out($relView($r), 201);
}
if (preg_match('~^/releases/([^/]+)/assets/links$~', $p, $mm) && $method === 'POST') {
    foreach($db['releases'] as &$r)
	if ($r['tag_name'] === rawurldecode($mm[1])) {
	    $l = [ 'id' => 800 + count($r['links']), 'name' => $body['name'], 'url' => $body['url'], 'link_type' => $body['link_type'] ?? 'other' ];
	    $r['links'][] = $l;
	    $saveDb();
	    $out($l, 201);
	}
    $fail(404, '404 Release Not Found');
}
if ($p === '/uploads' && $method === 'POST') {
    if (!str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') || empty($_FILES['file']))
	$fail(400, 'file is missing');
    $content = file_get_contents($_FILES['file']['tmp_name']);
    $db['uploads'][] = [ 'name' => $_FILES['file']['name'], 'size' => strlen($content), 'content' => base64_encode($content) ];
    $saveDb();
    $out([ 'alt' => $_FILES['file']['name'], 'url' => "/uploads/abc/{$_FILES['file']['name']}", 'full_path' => "/-/project/7/uploads/abc/{$_FILES['file']['name']}", 'markdown' => "[x](/uploads/abc/x)" ], 201);
}

//pipelines
if ($p === '/pipeline' && $method === 'POST') {
    if (!isset($db['branches'][$body['ref'] ?? '']))
	$out([ 'message' => [ 'base' => [ 'Reference not found' ] ] ], 400);
    $id = 901 + count($db['pipelines']);
    $db['pipelines'][$id] = [ 'id' => $id, 'iid' => 40 + count($db['pipelines']), 'status' => 'created', 'ref' => $body['ref'], 'sha' => $db['branches'][$body['ref']], 'source' => 'api', 'created_at' => $now,
	'updated_at' => $now, 'gets' => 0, 'variables' => $body['variables'] ?? [] ];
    $saveDb();
    $out($pipeView($db['pipelines'][$id]), 201);
}
if ($p === '/pipelines') {
    $all = array_values(array_filter($db['pipelines'], fn($x) => (!isset($query['status']) || $x['status'] === $query['status']) && (!isset($query['ref']) || $x['ref'] === $query['ref'])
	&& (!isset($query['updated_after']) || $x['updated_at'] > $query['updated_after'])));
    usort($all, fn($a, $b) => $b['id'] <=> $a['id']);
    [ $slice, $h ] = $page(array_map($pipeView, $all));
    $out($slice, 200, $h);
}
if (preg_match('~^/pipelines/(\d+)(/.*)?$~', $p, $mm)) {
    $id = (int)$mm[1];
    if (!isset($db['pipelines'][$id]))
	$fail(404, '404 Not found');
    $pl = &$db['pipelines'][$id];
    $rest = $mm[2] ?? '';
    if ($rest === '') {
	$pl['gets']++;
	$order = [ 'created' => 'pending', 'pending' => 'running', 'running' => 'success' ];
	if (isset($order[$pl['status']]) && $pl['gets'] >= 1) {
	    $pl['status'] = $order[$pl['status']];
	    $pl['updated_at'] = $now;
	}
	$saveDb();
	$out($pipeView($pl));
    }
    if ($rest === '/jobs')
	$out([ [ 'id' => 6001, 'name' => 'test', 'stage' => 'test', 'status' => $pl['status'] === 'failed' ? 'failed' : 'success', 'web_url' => 'https://gitlab.com/acme/web/-/jobs/6001', 'allow_failure' => false,
	    'failure_reason' => $pl['status'] === 'failed' ? 'script_failure' : null ] ]);
    if (in_array($rest, [ '/retry', '/cancel' ], true) && $method === 'POST') {
	$pl['status'] = $rest === '/retry' ? 'pending' : 'canceled';
	$pl['gets'] = 0;
	$saveDb();
	$out($pipeView($pl), 201);
    }
}
if (preg_match('~^/jobs/(\d+)/trace$~', $p)) {
    header('Content-Type: text/plain');
    echo "\x1b[0KRunning with gitlab-runner\nsection_start:1700000000:step_script\r\x1b[0K\x1b[32;1m\$ make test\x1b[0;m\nok 1\nnot ok 2 - boom\nsection_end:1700000001:step_script\r\x1b[0K\x1b[31;1mERROR: Job failed\x1b[0;m\n";
    exit;
}
if (preg_match('~^/jobs/(\d+)/(retry|play)$~', $p, $mm) && $method === 'POST')
    $out([ 'id' => $mm[2] === 'retry' ? 6002 : (int)$mm[1], 'name' => 'test', 'stage' => 'test', 'status' => 'pending', 'web_url' => 'https://gitlab.com/acme/web/-/jobs/6002' ], 201);
$fail(404, '404 Not Found');
