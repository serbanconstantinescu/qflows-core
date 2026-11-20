<?php

/*
    a fake PatchMon server (2.x) for the check suite (checks.php: patchmon), run by PHP's built-in server:
    PM_STATE=<a folder> php -S 127.0.0.1:<port> PatchMonTestServer.php
	each request appended to <state>/requests.jsonl (method, path, query, body, auth)
	integration API /api/v1/api: Basic patchmon_ae_test:s3cret (host: get, delete) or patchmon_ae_ro:s3cret (host: get only); else 401
	    GET hosts (?hostgroup names, ?include=stats), hosts/<id>/{info,stats,system,network,notes,integrations,packages (?updates_only),package_reports,agent_queue},
	    DELETE hosts/<id>; the hosts in <state>/hosts.json (with their stats), a host not known: 404
	web API /api/v1: POST auth/login (admin / pw: { token }; tfa / pw: { requiresTfa }; else 401), POST auth/logout; with Bearer tok-admin:
	    POST patching/trigger (a host with a run not finished: 409), GET patching/runs/<id> (each read moves a run on: queued -> running -> completed; a dry run
	    pending_validation -> validated), POST patching/runs/<id>/approve, POST patching/runs/<id>/stop (finished: 409), GET patching/runs (?host_id ?status ?limit
	    ?offset), patching/runs/active, patching/dashboard, patching/preview-run?host_id; the runs in <state>/runs.json
*/

$state = getenv('PM_STATE') ?: sys_get_temp_dir();
$full = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $query);
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
$auth = $headers['authorization'] ?? '';
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $full, 'query' => $query, 'body' => $body, 'auth' => $auth ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$answer = function($status, $data = null) {
    http_response_code($status);
    if ($data !== null) {
	header('Content-Type: application/json');
	echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }
    exit;
};
$hosts = is_file("{$state}/hosts.json") ? json_decode(file_get_contents("{$state}/hosts.json"), true) : [];
$runs = is_file("{$state}/runs.json") ? json_decode(file_get_contents("{$state}/runs.json"), true) : [];
$saveRuns = function() use (&$runs, $state) {
    file_put_contents("{$state}/runs.json", json_encode($runs));
};
$host = function($id) use ($hosts, $answer) {
    foreach($hosts as $h)
	if ($h['id'] === $id)
	    return $h;
    $answer(404, [ 'error' => 'Host not found' ]);
};
$uuid = fn() => sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand(0, 0xffffffffffff));

//the integration API
if (str_starts_with($full, '/api/v1/api/')) {
    $key = $auth === 'Basic ' . base64_encode('patchmon_ae_test:s3cret') ? 'full' : ($auth === 'Basic ' . base64_encode('patchmon_ae_ro:s3cret') ? 'ro' : null);
    if (!$key)
	$answer(401, [ 'error' => 'Invalid API credentials' ]);
    $path = substr($full, strlen('/api/v1/api'));
    if ($path === '/hosts' && $method === 'GET') {
	$groups = isset($query['hostgroup']) ? explode(',', $query['hostgroup']) : null;
	$list = [];
	foreach($hosts as $h) {
	    if ($groups !== null && !array_intersect($groups, array_column($h['host_groups'], 'name')))
		continue;
	    $list[] = ($query['include'] ?? '') === 'stats' ? $h : array_intersect_key($h, array_flip([ 'id', 'friendly_name', 'hostname', 'ip', 'host_groups' ]));
	}
	$answer(200, [ 'hosts' => $list, 'total' => count($list) ] + ($groups !== null ? [ 'filtered_by_groups' => $groups ] : []));
    }
    if (preg_match('~^/hosts/([0-9a-f-]+)(?:/([a-z_]+))?$~', $path, $m)) {
	$h = $host($m[1]);
	$what = $m[2] ?? '';
	if ($what === '' && $method === 'DELETE') {
	    if ($key !== 'full')
		$answer(403, [ 'error' => 'Access denied', 'message' => 'This API key does not have permission to delete host' ]);
	    $answer(200, [ 'message' => 'Host deleted successfully', 'deleted' => [ 'id' => $h['id'], 'friendly_name' => $h['friendly_name'], 'hostname' => $h['hostname'] ] ]);
	}
	$pkgs = $h['packages'] ?? [];
	switch($what) {
	    case 'info':
		$answer(200, array_intersect_key($h, array_flip([ 'id', 'friendly_name', 'hostname', 'ip', 'os_type', 'os_version', 'host_groups' ])) + [ 'agent_version' => '2.1.4' ]);
	    case 'stats':
		$answer(200, [ 'host_id' => $h['id'], 'total_installed_packages' => $h['total_packages'], 'outdated_packages' => $h['updates_count'], 'security_updates' => $h['security_updates_count'], 'total_repos' => 4 ]);
	    case 'system':
		$answer(200, [ 'id' => $h['id'], 'kernel_version' => '6.8.0-45-generic', 'needs_reboot' => $h['needs_reboot'], 'reboot_reason' => $h['needs_reboot'] ? 'Kernel update pending' : null ]);
	    case 'network':
		$answer(200, [ 'id' => $h['id'], 'ip' => $h['ip'], 'gateway_ip' => '10.0.0.1', 'dns_servers' => [ '10.0.0.2' ], 'network_interfaces' => [] ]);
	    case 'notes':
		$answer(200, [ 'host_id' => $h['id'], 'notes' => 'Notes of ' . $h['hostname'] ]);
	    case 'integrations':
		$answer(200, [ 'host_id' => $h['id'], 'integrations' => [ 'docker' => [ 'enabled' => false ] ] ]);
	    case 'packages':
		$list = ($query['updates_only'] ?? '') === 'true' ? array_values(array_filter($pkgs, fn($p) => $p['needs_update'])) : $pkgs;
		$answer(200, [ 'host' => [ 'id' => $h['id'], 'hostname' => $h['hostname'], 'friendly_name' => $h['friendly_name'] ], 'packages' => $list, 'total' => count($list) ]);
	    case 'package_reports':
		$reports = array_map(fn($i) => [ 'id' => "r{$i}", 'status' => 'success', 'date' => gmdate('Y-m-d\TH:i:s.000\Z', time() - $i * 3600), 'total_packages' => 300,
		    'outdated_packages' => 2, 'security_updates' => 1 ], range(1, 30));
		$answer(200, [ 'host_id' => $h['id'], 'reports' => array_slice($reports, 0, (int)($query['limit'] ?? 10)), 'total' => min(30, (int)($query['limit'] ?? 10)) ]);
	    case 'agent_queue':
		$answer(200, [ 'host_id' => $h['id'], 'queue_status' => [ 'waiting' => 1, 'active' => 0, 'delayed' => 0, 'failed' => 2 ], 'job_history' => [], 'total_jobs' => 0 ]);
	}
    }
    $answer(404, [ 'error' => 'Not found' ]);
}

//the web API
$path = substr($full, strlen('/api/v1'));
if ($path === '/auth/login' && $method === 'POST') {
    if (($body['password'] ?? '') === 'pw' && ($body['username'] ?? '') === 'admin')
	$answer(200, [ 'token' => 'tok-admin', 'refresh_token' => 'r', 'user' => [ 'username' => 'admin', 'role' => 'admin' ] ]);
    if (($body['password'] ?? '') === 'pw' && ($body['username'] ?? '') === 'tfa')
	$answer(200, [ 'message' => 'TFA verification required', 'requiresTfa' => true, 'tfaTicket' => 't' ]);
    $answer(401, [ 'error' => 'Invalid credentials' ]);
}
if ($auth !== 'Bearer tok-admin')
    $answer(401, [ 'error' => 'Unauthorized' ]);
if ($path === '/auth/logout' && $method === 'POST')
    $answer(200, [ 'message' => 'Logged out' ]);
$ended = [ 'completed', 'failed', 'cancelled', 'timed_out', 'agent_disconnected', 'validated' ];
if ($path === '/patching/trigger' && $method === 'POST') {
    $h = $host((string)($body['host_id'] ?? ''));
    foreach($runs as $r)
	if ($r['host_id'] === $h['id'] && !in_array($r['status'], $ended, true) && $r['status'] !== 'pending_approval')
	    $answer(409, [ 'error' => 'This host already has a patch run in progress' ]);
    if (!in_array($body['patch_type'] ?? '', [ 'patch_all', 'patch_package' ], true))
	$answer(400, [ 'error' => 'patch_type must be patch_all or patch_package' ]);
    $id = $uuid();
    $status = !empty($body['pending_approval']) ? 'pending_approval' : (!empty($body['dry_run']) ? 'pending_validation' : 'queued');
    $runs[$id] = [ 'id' => $id, 'host_id' => $h['id'], 'patch_type' => $body['patch_type'], 'package_names' => $body['package_names'] ?? null, 'status' => $status,
	'dry_run' => !empty($body['dry_run']), 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'error_message' => null, 'hosts' => [ 'id' => $h['id'], 'friendly_name' => $h['friendly_name'] ] ];
    $saveRuns();
    $answer(200, [ 'message' => $status === 'pending_approval' ? 'Submitted for approval' : ($status === 'queued' ? 'Patch queued' : 'Dry run queued'), 'patch_run_id' => $id,
	'job_id' => "patch-run-{$id}", 'run_at' => gmdate('Y-m-d\TH:i:s\Z'), 'queued' => $status !== 'pending_approval' ]);
}
if (preg_match('~^/patching/runs/([0-9a-f-]{36})(?:/(approve|stop))?$~', $path, $m)) {
    if (!isset($runs[$m[1]]))
	$answer(404, [ 'error' => 'Patch run not found' ]);
    $run = &$runs[$m[1]];
    if (($m[2] ?? '') === 'approve') {
	if (!in_array($run['status'], [ 'validated', 'pending_validation', 'pending_approval' ], true))
	    $answer(400, [ 'error' => 'Only validated, pending-validation, or pending-approval runs can be approved' ]);
	$id = $uuid();
	$runs[$id] = [ 'id' => $id, 'validation_run_id' => $m[1], 'status' => 'queued', 'dry_run' => false ] + $run;
	$runs[$id]['id'] = $id;
	$saveRuns();
	$answer(200, [ 'message' => 'Approved - new patch run created', 'patch_run_id' => $id, 'validation_run_id' => $m[1], 'run_at' => gmdate('Y-m-d\TH:i:s\Z') ]);
    }
    if (($m[2] ?? '') === 'stop') {
	if (in_array($run['status'], $ended, true))
	    $answer(409, [ 'error' => 'Run already finished' ]);
	$run['status'] = 'cancelled';
	$saveRuns();
	$answer(200, [ 'message' => 'Run stopped' ]);
    }
    $now = $run;
    $run['status'] = [ 'queued' => 'running', 'running' => 'completed', 'pending_validation' => 'validated' ][$run['status']] ?? $run['status'];
    $saveRuns();
    $answer(200, $now);
}
if ($path === '/patching/runs') {
    $list = array_values(array_filter($runs, fn($r) => (!isset($query['host_id']) || $r['host_id'] === $query['host_id']) && (!isset($query['status']) || $r['status'] === $query['status'])));
    $answer(200, [ 'runs' => array_slice($list, (int)($query['offset'] ?? 0), (int)($query['limit'] ?? 50)), 'pagination' => [ 'total' => count($list), 'limit' => (int)($query['limit'] ?? 50),
	'offset' => (int)($query['offset'] ?? 0) ] ]);
}
if ($path === '/patching/runs/active')
    $answer(200, [ 'runs' => array_values(array_filter($runs, fn($r) => in_array($r['status'], [ 'queued', 'running', 'scheduled' ], true))) ]);
if ($path === '/patching/dashboard')
    $answer(200, [ 'summary' => [ 'total_runs' => count($runs), 'running' => 0, 'queued' => 1, 'failed' => 0 ], 'recent_runs' => [], 'active_runs' => [] ]);
if ($path === '/patching/preview-run') {
    $h = $host((string)($query['host_id'] ?? ''));
    $answer(200, [ 'host_id' => $h['id'], 'host_name' => $h['friendly_name'], 'run_at_iso' => '2026-11-20T02:00:00Z', 'policy_name' => 'Nightly', 'patch_delay_type' => 'fixed_time' ]);
}
$answer(404, [ 'error' => 'Not found' ]);
