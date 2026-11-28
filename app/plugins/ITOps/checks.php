<?php

/*
    the ITOps plugin's checks, run by the core's check suite (commands\CheckCommand, hook check:suites: Plugin): each a closure run as a method of the check command (->check(),
    ->fail(), ->account, ->project, ->runFlow(), ->workflow(), ->startServer(), self::logLine()), one per product against its fake server in tests/:
      gravityzone, veeam, fortigate, sophos, checkmk, patchmon, synology, vmware, proxmox, hyperV, zabbix, wazuh, namecheap, glpi, intune
*/

return [
    //the Bitdefender GravityZone integration against a fake console (tests/GravityZoneTestServer.php; plugins\ITOps\util\GravityZone::$testing: http, no address guard):
    //util\Http's private network addresses, the client (both keys, errors, 429, pages, the access URL), the credential's test, the steps' calls (their parameters as sent, Other
    //parameters, the `error` output, a report saved in Files), the event trigger (publishing sets the push service, unpublishing turns it off; the events received by its address)
    'gravityzone' => function() {
	$H = \util\Http::class;
	$G = \plugins\ITOps\util\GravityZone::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];

	//util\Http: private network addresses
	$this->check('gravityzone: private network addresses (not loopback, link-local, this machine; IPv4-mapped too)', [ true, true, true, true, false, false, false, false, false ],
	    array_map([ \util\Ip::class, 'isPrivateNetwork' ], [ '10.1.2.3', '172.20.0.1', '192.168.5.5', 'fd00::1', '127.0.0.1', '169.254.169.254', '::1', '8.8.8.8', \util\Ip::ownAddresses()[2] ?? '127.0.0.2' ]));
	$this->check('gravityzone: the guard with private addresses: loopback still refused', true,
	    str_contains((string)$H::request('GET', 'http://127.0.0.1:1/', [ 'public' => true, 'private' => true ])['error'], 'link-local and reserved ones are refused'));
	$this->check('gravityzone: the access URL as given, without a pasted /v1.0/jsonrpc/...', [ 'https://cloud.gravityzone.bitdefender.com/api', 'https://gz.local/api' ],
	    [ $G::baseUrl('https://cloud.gravityzone.bitdefender.com/api/'), $G::baseUrl(' https://gz.local/api/v1.0/jsonrpc/network ') ]);

	$dir = sys_get_temp_dir() . '/qflows-check-gz-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('gravityzone', '../../plugins/ITOps/tests/GravityZoneTestServer.php', $dir, [ 'GZ_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$G::$testing = true;
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$creds = [];
	$wfs = [];
	$files = [];
	$account = $om->findById('Account', (string)$this->account->_id);
	$privateBefore = (bool)($account->allow_private_addresses ?? false);
	try {
	    $make = function($key, $type = 'personal', $more = []) use ($om, $port, &$creds) {
		$c = $om->create('CredentialGravityZone', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: GravityZone ' . uniqid(),
		    'accessUrl' => "https://127.0.0.1:{$port}/api", 'keyType' => $type, 'apiKey' => $key ] + $more);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('gravityzone: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		//the fake console is http (the form takes https only)
		$c->accessUrl = "http://127.0.0.1:{$port}/api";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialGravityZone', (string)$c->_id);
	    };
	    $cred = $make('pkey');
	    $service = $make('skey', 'service');
	    $wrong = $make('nope');
	    if (!$cred || !$service || !$wrong)
		return;
	    $gz = fn($c, $timeout = 20) => $c->client($this->account->_id, $timeout);
	    $thrown = function(\Closure $f) {
		try {
		    $f();
		    return 'no error';
		} catch (\Throwable $e) {
		    return $e->getMessage();
		}
	    };

	    //the credential
	    $this->check('gravityzone: the form: an https:// access URL, the key required', [ [ 'accessUrl', 'apiKey' ] ],
		[ array_values(array_intersect([ 'accessUrl', 'apiKey' ], array_keys((array)(function() use ($om) { $c = $om->create('CredentialGravityZone', [ 'name' => 'x', 'accessUrl' => 'http://gz.local/api' ]); $c->validates(); return $c->errors(); })()))) ]);
	    $this->check('gravityzone: Test: the key\'s APIs (personal key: Basic key:, service key: X-API-Key); a wrong key refused', [
		'connected to 127.0.0.1; APIs of the key: network, incidents, reports, push', true, [ 'Basic ' . base64_encode('pkey:'), null ], [ null, 'skey' ] ], [
		$cred->test(), str_contains($thrown(fn() => $wrong->test()), 'The API key is not valid'),
		(function() use ($requests) { $r = $requests(); return [ $r[0]['auth'] ?? null, $r[0]['apiKey'] ?? null ]; })(),
		(function() use ($service, $requests) { $service->test(); $r = $requests(); return [ $r[0]['auth'] ?? null, $r[0]['apiKey'] ?? null ]; })() ]);

	    //the client
	    $call = $gz($cred)->call('licensing', 'getLicenseInfo', [ 'a' => 1 ], '1.2');
	    $sent = $requests()[0]['request'] ?? [];
	    $this->check('gravityzone: a call: JSON-RPC 2.0 to v<version>/jsonrpc/<area>, its result', [ [ 'method' => 'getLicenseInfo', 'params' => [ 'a' => 1 ], 'area' => 'licensing', 'version' => '1.2' ], '2.0', true ],
		[ $call, $sent['jsonrpc'] ?? null, is_string($sent['id'] ?? null) ]);
	    $this->check('gravityzone: errors: GravityZone\'s message and details; bad API / method names refused before any call', [
		'failing: Invalid params: The endpointId is not valid (code -32602)', 'not an API name: `net work`', 'not a method name: `get-x`' ], [
		$thrown(fn() => $gz($cred)->call('network', 'failing')), $thrown(fn() => $gz($cred)->call('net work', 'x')), $thrown(fn() => $gz($cred)->call('network', 'get-x')) ]);
	    $t = microtime(true);
	    $this->check('gravityzone: 429: waited for Retry-After, tried once more', [ [ 'ok' => true ], true ], [ $gz($cred)->call('network', 'limited'), microtime(true) - $t >= 0.9 ]);
	    $requests();
	    [ $items, $total, $truncated ] = $gz($cred)->all('network', 'getEndpointsList', [], 120);
	    $pages = array_map(fn($r) => [ $r['request']['params']['page'], $r['request']['params']['perPage'] ], $requests());
	    [ $all, $total2, $truncated2 ] = $gz($cred)->all('network', 'getEndpointsList', [], 1000);
	    $requests();
	    $this->check('gravityzone: pages: 120 of 250 (truncated, 2 pages read); all 250 (3 pages)', [ [ 120, 250, true, 'ep0119', [ [ 1, 100 ], [ 2, 100 ] ] ], [ 250, 250, false, 'ep0249' ] ],
		[ [ count($items), $total, $truncated, end($items)['id'], $pages ], [ count($all), $total2, $truncated2, end($all)['id'] ] ]);

	    //the account's private addresses (the request options)
	    $account->allow_private_addresses = false;
	    $account->save();
	    $off = $gz($cred)->options()['private'];
	    $account->allow_private_addresses = true;
	    $account->save();
	    $on = $gz($cred)->options()['private'];
	    $cred->trustCertificate = true;
	    $this->check('gravityzone: private addresses follow the account\'s setting; the certificate the credential\'s', [ false, true, true ], [ $off, $on, $gz($cred)->options()['insecure'] ]);
	    $cred->trustCertificate = false;

	    //the steps in workflows: the parameters as sent
	    $id = (string)$cred->_id;
	    $step = function($fq, $cfg, $vars = []) use ($n, $e, $id, $requests) {
		$requests();
		$exe = $this->runFlow("gz {$fq}", [ $n('m', 'trigger::manual'), $n('g', $fq, $cfg + [ 'credential' => $id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'g'), $e('e2', 'g', 'err', 1) ], $vars);
		return [ $exe, array_map(fn($r) => [ $r['area'] . '.' . $r['request']['method'], $r['request']['params'] ], $requests()) ];
	    };
	    [ $exe, $sent ] = $step('app::gz-endpoints', [ 'operation' => 'list', 'managed' => 'managed', 'groupId' => '{{ grp }}', 'maxItems' => 30, 'variable' => 'eps' ], [ 'grp' => 'g1' ]);
	    $eps = $exe?->getVariable('eps');
	    $this->check('gravityzone: Endpoints list: of a group, managed, with subgroups; at most 30', [ 'end', 30, 250, true, [ 'network.getEndpointsList',
		[ 'parentId' => 'g1', 'filters' => [ 'depth' => [ 'allItemsRecursively' => true ] ], 'isManaged' => true, 'page' => 1, 'perPage' => 30 ] ] ],
		[ $exe?->state, $eps['count'] ?? null, $eps['total'] ?? null, $eps['truncated'] ?? null, $sent[0] ?? null ]);
	    [ $exe, $sent ] = $step('app::gz-endpoints', [ 'operation' => 'scan', 'targetIds' => '{{ eps }}', 'scanType' => '2', 'taskName' => 'Nightly',
		'params' => '{"customScanSettings": {"scanDepth": 1}}' ], [ 'eps' => [ [ 'id' => 'ep1' ], [ 'id' => 'ep2' ] ] ]);
	    $this->check('gravityzone: Endpoints scan: targets from a list of objects, the type as a number, Other parameters added', [ 'end', [ 'network.createScanTask',
		[ 'targetIds' => [ 'ep1', 'ep2' ], 'type' => 2, 'name' => 'Nightly', 'customScanSettings' => [ 'scanDepth' => 1 ] ] ] ], [ $exe?->state, $sent[0] ?? null ]);
	    [ $exe, $sent ] = $step('app::gz-incidents', [ 'operation' => 'status', 'kind' => 'extendedIncidents', 'incidentId' => 'inc9', 'status' => '4' ]);
	    [ $exe2, $sent2 ] = $step('app::gz-incidents', [ 'operation' => 'isolate', 'endpointId' => 'ep7' ]);
	    $this->check('gravityzone: Incidents: a status change, an isolation', [ [ 'incidents.changeIncidentStatus', [ 'type' => 'extendedIncidents', 'incidentId' => 'inc9', 'status' => 4 ] ],
		[ 'incidents.createIsolateEndpointTask', [ 'endpointId' => 'ep7' ] ] ], [ $sent[0] ?? null, $sent2[0] ?? null ]);
	    [ $exe, $sent ] = $step('app::gz-blocklist', [ 'operation' => 'add', 'hashType' => '2', 'hashes' => "D41D8CD98F00B204E9800998ECF8427E\n0cc175b9c0f1b6a831c399e269772661", 'source' => 'IOC feed' ]);
	    [ $bad, $none ] = $step('app::gz-blocklist', [ 'operation' => 'add', 'hashType' => '1', 'hashes' => 'd41d8cd98f00b204e9800998ecf8427e' ]);
	    $this->check('gravityzone: Blocklist: MD5 hashes (lower case) with the note; a hash of the wrong kind: the `error` output, nothing sent', [
		[ 'incidents.addToBlocklist', [ 'hashType' => 2, 'hashList' => [ 'd41d8cd98f00b204e9800998ecf8427e', '0cc175b9c0f1b6a831c399e269772661' ], 'sourceInfo' => 'IOC feed' ] ], 'error', [], true ],
		[ $sent[0] ?? null, $bad?->getVariable('route'), $none, str_contains((string)self::logLine($bad, 'GravityZone Blocklist add: '), 'not a SHA-256 hash') ]);
	    [ $exe, $sent ] = $step('app::gz-quarantine', [ 'operation' => 'restore', 'itemIds' => 'q1, q2', 'location' => 'C:\\Restored', 'exclusion' => true ]);
	    [ $exe2, $sent2 ] = $step('app::gz-investigation', [ 'operation' => 'kill', 'endpointId' => 'ep3', 'processId' => '4242' ]);
	    $this->check('gravityzone: Quarantine restore (quarantine/computers), Investigation kill', [
		[ 'quarantine/computers.createRestoreQuarantineItemTask', [ 'quarantineItemsIds' => [ 'q1', 'q2' ], 'addExclusionInPolicy' => true, 'locationToRestore' => 'C:\\Restored' ] ],
		[ 'investigation.killProcess', [ 'endpointId' => 'ep3', 'processId' => 4242 ] ] ], [ $sent[0] ?? null, $sent2[0] ?? null ]);
	    [ $exe, $sent ] = $step('app::gz-reports', [ 'operation' => 'download', 'reportId' => 'r1', 'variable' => 'rep' ]);
	    $rep = $exe?->getVariable('rep');
	    foreach((array)($rep['files'] ?? []) as $f)
		if ($file = $om->findOne('File', [ '_id' => $f['id'] ]))
		    $files[] = $file;
	    $this->check('gravityzone: Reports download: the ZIP unpacked, its CSV saved in Files (an .exe left out)', [ 'end', 1, 'endpoints.csv', 20, "name,status\nPC-1,ok\n" ],
		[ $exe?->state, $rep['count'] ?? null, $rep['files'][0]['filename'] ?? null, $rep['files'][0]['size'] ?? null, isset($files[0]) ? $files[0]->getContents() : null ]);
	    [ $exe, $sent ] = $step('app::gz-api', [ 'area' => 'licensing', 'method' => 'getMonthlyUsage', 'callParams' => '{"targetMonth": "{{ month }}"}', 'variable' => 'usage' ], [ 'month' => '10/2026' ]);
	    [ $err, ] = $step('app::gz-api', [ 'area' => 'network', 'method' => 'failing' ]);
	    $this->check('gravityzone: API: any method, its parameters with expressions; GravityZone\'s error: the `error` output', [
		[ 'method' => 'getMonthlyUsage', 'params' => [ 'targetMonth' => '10/2026' ], 'area' => 'licensing', 'version' => '1.0' ], 'error' ],
		[ $exe?->getVariable('usage'), $err?->getVariable('route') ]);
	    $this->check('gravityzone: a step without a credential of the project refused', true,
		str_contains(implode(' ', $this->errorsOf('gz nocred', [ $n('m', 'trigger::manual'), $n('g', 'app::gz-endpoints', [ 'credential' => '000000000000000000000000' ]), $e('e1', 'm', 'g') ])), 'GravityZone credential'));

	    //the event trigger: publishing / unpublishing (the push service), the events received
	    $init = \plugins\ITOps\models\node\NodeTriggerGravityZone::initConfig();
	    $wf = $this->workflow('gz event', [ $n('t', 'trigger::gravityzone', $init + [ 'credential' => $id, 'connect' => true, 'events' => [ 'av', 'new-incident' ], 'runs' => 'each', 'variable' => 'ev' ]),
		$n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ev.module }}' ]), $e('e1', 't', 's') ]);
	    $this->check('gravityzone: the event trigger\'s workflow is valid; its secret kept', [ true, $init['secret'] ], [ $wf->validates(), $wf->nodes['t']['config']['secret'] ?? null ]);
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $version = $om->create('WfVersion', [ 'aid' => $wf->aid, 'project' => $wf->project, 'wf' => $wf->_id, 'number' => 1, 'name' => (string)$wf->name, 'nodes' => $wf->nodes ]);
	    $version->save();
	    $wf->version = $version->_id;
	    $wf->status = \models\Workflow::PUBLISHED;
	    $wf->save();
	    $hooks = new \ReflectionMethod(\controllers\WorkflowController::class, 'publishHooks');
	    $published = $hooks->invoke(null, $wf, $version, null);
	    $push = json_decode((string)@file_get_contents("{$dir}/push.json"), true);
	    $this->check('gravityzone: publishing sets the push service: jsonRPC to the step\'s address, its secret, the event types chosen', [
		[ 'GravityZone sends its events to this workflow (2 event types)' ], 1, 'jsonRPC', $init['url'], 'Bearer ' . $init['secret'], [ 'av' => true, 'new-incident' => true ] ],
		[ $published, $push['status'] ?? null, $push['serviceType'] ?? null, $push['serviceSettings']['url'] ?? null, $push['serviceSettings']['authorization'] ?? null, $push['subscribeToEventTypes'] ?? null ]);

	    //the address: a POST to this installation's web server (skipped when it cannot be reached from here)
	    $post = function($body, $secret) use ($init) {
		$url = $init['url'];
		$host = (string)parse_url($url, PHP_URL_HOST);
		$ch = curl_init($url);
		curl_setopt_array($ch, [ CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
		    CURLOPT_HTTPHEADER => [ 'Content-Type: application/json', 'Authorization: Bearer ' . $secret ], CURLOPT_RESOLVE => [ "{$host}:443:127.0.0.1" ],
		    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0 ]);
		$answer = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		return [ $status, json_decode((string)$answer, true) ];
	    };
	    $batch = [ 'jsonrpc' => '2.0', 'method' => 'addEvents', 'id' => 'b1', 'params' => [ 'events' => [
		[ 'module' => 'av', 'computer_name' => 'PC-1', 'malware_name' => 'EICAR' ], [ 'module' => 'fw', 'computer_name' => 'PC-2' ], [ 'module' => 'new-incident', 'incident_id' => 'i1' ] ] ] ];
	    [ $status ] = $post($batch, 'wrong');
	    if ($status === 0) {
		echo "  skipped: the event trigger's address (this installation's web server cannot be reached from here)\n";
	    } else {
		$before = $om->count('WfExecution', [ 'wf' => $wf->_id ]);
		[ $ok, $answer ] = $post($batch, $init['secret']);
		$runs = iterator_to_array($om->find('WfExecution', [ 'wf' => $wf->_id, 'trigger' => 'gravityzone' ]), false);
		$got = array_map(fn($x) => [ $x->getVariable('ev')['module'] ?? null, $x->getVariable('ev')['computer_name'] ?? ($x->getVariable('ev')['incident_id'] ?? null) ], $runs);
		sort($got);
		$this->check('gravityzone: the address: a wrong secret refused (no run); the events of the chosen types: one run each (fw left out), answered 200', [
		    401, 0, 200, [ 'received' => 3, 'runs' => 2 ], [ [ 'av', 'PC-1' ], [ 'new-incident', 'i1' ] ] ], [ $status, $before, $ok, $answer['result'] ?? null, $got ]);
		foreach($runs as $x)
		    $x->delete();
	    }

	    $unpublished = $hooks->invoke(null, $wf, null, $version);
	    $push = json_decode((string)@file_get_contents("{$dir}/push.json"), true);
	    $this->check('gravityzone: unpublishing turns the push service off (it still pointed here)', [ [ 'GravityZone no longer sends its events here' ], 0, $init['url'] ],
		[ $unpublished, $push['status'] ?? null, $push['serviceSettings']['url'] ?? null ]);
	} finally {
	    $G::$testing = false;
	    $account->allow_private_addresses = $privateBefore;
	    $account->save();
	    foreach($creds as $c)
		$c->delete();
	    foreach($files as $f)
		$f->delete();
	    foreach($wfs as $wf)
		foreach($om->find('WfVersion', [ 'wf' => $wf->_id ]) as $v)
		    $v->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //Veeam Backup & Replication against a fake server (tests/VeeamTestServer.php; plugins\ITOps\util\Veeam::$testing: http, no address guard): the sign-in (x-api-version,
    //the token kept, renewed after a 401), the credential's test, the steps' requests (a job by name, repeated filters, waiting for a session, the latest restore point, the
    //repositories low on space, Instant Recovery), the event trigger (its first check notes the time; the sessions ended since then start runs, once; Run: the newest one)
    'veeam' => function() {
	$V = \plugins\ITOps\util\Veeam::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$this->check('veeam: the server\'s address without an /api path; lists repeated in the query, booleans as text', [ 'https://vbr:9419', 'resultFilter=Failed&resultFilter=Warning&orderAsc=false' ],
	    [ $V::baseUrl(' https://vbr:9419/api/v1/ '), $V::query([ 'resultFilter' => [ 'Failed', 'Warning' ], 'orderAsc' => false, 'empty' => '' ]) ]);

	$dir = sys_get_temp_dir() . '/qflows-check-veeam-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('veeam', '../../plugins/ITOps/tests/VeeamTestServer.php', $dir, [ 'VEEAM_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$V::$testing = true;
	$V::forgetTokens();
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$sessions = fn(array $list) => file_put_contents("{$dir}/sessions.json", json_encode($list));
	$creds = [];
	$wfs = [];
	$started = [];
	try {
	    $make = function($password) use ($om, $port, &$creds) {
		$c = $om->create('CredentialVeeam', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Veeam ' . uniqid(),
		    'serverUrl' => "https://127.0.0.1:{$port}", 'username' => 'backup', 'password' => $password ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('veeam: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->serverUrl = "http://127.0.0.1:{$port}";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialVeeam', (string)$c->_id);
	    };
	    $cred = $make('secret');
	    $wrong = $make('nope');
	    if (!$cred || !$wrong)
		return;
	    $thrown = function(\Closure $f) {
		try {
		    $f();
		    return 'no error';
		} catch (\Throwable $e) {
		    return $e->getMessage();
		}
	    };
	    $bad = $om->create('CredentialVeeam', [ 'name' => 'x', 'serverUrl' => 'https://vbr:9419/api/v1', 'username' => 'u', 'password' => 'p', 'apiVersion' => '13' ]);
	    $bad->validates();
	    //the Veeam version: one of the releases (VBR 13, 12.0 to 12.3)
	    $this->check('veeam: the form: an https:// address without a path, a Veeam version of the list; VBR 13 (1.3-rev1) by default', [ [ 'serverUrl', 'apiVersion' ], '1.3-rev1' ],
		[ array_values(array_intersect([ 'serverUrl', 'apiVersion' ], array_keys((array)$bad->errors()))), (string)$om->create('CredentialVeeam', [])->apiVersion ]);

	    //sign-in, the token kept, a 401 signs in again once
	    $requests();
	    $test = $cred->test();
	    $sent = $requests();
	    $this->check('veeam: Test: signs in (password grant, x-api-version on every request), reads the server', [
		'connected to VBR01, Veeam Backup & Replication 13.0.1.1071', [ '/api/oauth2/token', '/api/v1/serverInfo' ], [ '1.3-rev1', '1.3-rev1' ], true, 'Bearer a1' ],
		[ $test, array_column($sent, 'path'), array_column($sent, 'version'), str_contains((string)$sent[0]['body'], 'grant_type=password') && str_contains((string)$sent[0]['body'], 'username=backup'),
		$sent[1]['auth'] ?? null ]);
	    $this->check('veeam: a wrong password: Veeam\'s message', true, str_contains($thrown(fn() => $wrong->test()), 'Invalid username or password'));
	    $requests();
	    $cred->client($this->account->_id)->request('GET', '/api/v1/serverInfo');
	    file_put_contents("{$dir}/revoked", "a1\n");
	    $cred->client($this->account->_id)->request('GET', '/api/v1/serverInfo');
	    $this->check('veeam: the token kept between requests; a 401: signed in again once', [ [ 'GET /api/v1/serverInfo', 'GET /api/v1/serverInfo', 'POST /api/oauth2/token', 'GET /api/v1/serverInfo' ], 'Bearer a2' ],
		[ array_map(fn($r) => $r['method'] . ' ' . $r['path'], $sent = $requests()), end($sent)['auth'] ?? null ]);
	    //VBR 12: the API version of each build; a 12.2 server: the version chosen sent, Test names the right one when it differs,
	    //a request the release does not have: said so
	    $this->check('veeam: the API version of a build (12.0 to 13; older: none)', [ '1.3-rev1', '1.2-rev1', '1.2-rev0', '1.1-rev2', '1.1-rev1', '1.1-rev0', null ],
		array_map([ $V, 'versionForBuild' ], [ '13.0.1.1071', '12.3.2.3617', '12.3.0.310', '12.2.0.334', '12.1.0.2131', '12.0.0.1420', '11.0.1.1261' ]));
	    file_put_contents("{$dir}/release.json", json_encode([ 'versions' => [ '1.1-rev2', '1.1-rev1', '1.1-rev0' ], 'build' => '12.2.0.334' ]));
	    $V::forgetTokens();
	    $wrongVersion = $thrown(fn() => $cred->test());
	    $cred12 = $om->findById('CredentialVeeam', (string)$cred->_id);
	    $cred12->apiVersion = '1.1-rev2';
	    $V::forgetTokens();
	    $requests();
	    $ok12 = $cred12->test();
	    $sent12 = array_values(array_unique(array_column($requests(), 'version')));
	    $missing12 = $thrown(fn() => $cred12->client($this->account->_id)->request('GET', '/api/v1/nothing'));
	    $this->check('veeam: a VBR 12.2 server: with VBR 13 chosen, Test names 12.2; with 12.2 chosen, 1.1-rev2 sent and no advice; a request it lacks: said so', [
		true, 'connected to VBR01, Veeam Backup & Replication 12.2.0.334', [ '1.1-rev2' ], true ], [
		str_contains($wrongVersion, 'this server takes Veeam version "Veeam Backup & Replication 12.2"'), $ok12, $sent12,
		str_contains($missing12, 'with API version 1.1-rev2: the server\'s release may not have this request') ]);
	    $cred->apiVersion = '1.3-rev1';
	    $this->check('veeam: Test names the right version also when the server signs in (a 12.2 server, VBR 12.0 chosen)', true,
		str_contains((function() use ($cred12) { $c = clone $cred12; $c->apiVersion = '1.1-rev0'; return $c->test(); })(), 'choose Veeam version "Veeam Backup & Replication 12.2"'));
	    @unlink("{$dir}/release.json");
	    $V::forgetTokens();
	    $requests();
	    $this->check('veeam: a path that is not the API refused before any request', 'not an API path: `/../etc`', $thrown(fn() => $cred->client($this->account->_id)->request('GET', '/../etc')));

	    //the steps
	    $id = (string)$cred->_id;
	    $step = function($fq, $cfg, $vars = []) use ($n, $e, $id, $requests) {
		$requests();
		$exe = $this->runFlow("veeam {$fq}", [ $n('m', 'trigger::manual'), $n('v', $fq, $cfg + [ 'credential' => $id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'v'), $e('e2', 'v', 'err', 1) ], $vars);
		return [ $exe, array_values(array_filter($requests(), fn($r) => $r['path'] !== '/api/oauth2/token')) ];
	    };
	    [ $exe, $sent ] = $step('app::veeam-jobs', [ 'operation' => 'start', 'job' => '{{ name }}', 'activeFull' => true, 'variable' => 'run' ], [ 'name' => 'daily vms' ]);
	    [ $dup, ] = $step('app::veeam-jobs', [ 'operation' => 'start', 'job' => 'Copy' ]);
	    $this->check('veeam: Jobs start: the job by name (any case), an active full; the session started; a name of two jobs: the error output', [
		[ 'GET /api/v1/jobs', 'POST /api/v1/jobs/11111111-1111-1111-1111-111111111111/start' ], [ 'performActiveFull' => true ], 'aaaaaaaa-0000-0000-0000-111111111111', 'error', true ], [
		array_map(fn($r) => $r['method'] . ' ' . $r['path'], $sent), json_decode((string)($sent[1]['body'] ?? ''), true), $exe?->getVariable('run')['id'] ?? null, $dup?->getVariable('route'),
		str_contains((string)self::logLine($dup, 'Veeam Jobs start: '), 'several jobs are named `Copy`') ]);
	    $sessions([]);
	    [ $exe, $sent ] = $step('app::veeam-sessions', [ 'operation' => 'list', 'job' => '22222222-2222-2222-2222-222222222222', 'results' => [ 'Failed', 'Warning' ], 'since' => '2026-10-01T00:00:00Z' ]);
	    $this->check('veeam: Sessions list: newest first, the job, the results (repeated), created since', [ [ 'CreationTime' ], [ 'false' ], [ '22222222-2222-2222-2222-222222222222' ], [ 'Failed', 'Warning' ],
		[ '2026-10-01T00:00:00Z' ] ], [ $sent[0]['query']['orderColumn'] ?? null, $sent[0]['query']['orderAsc'] ?? null, $sent[0]['query']['jobIdFilter'] ?? null,
		$sent[0]['query']['resultFilter'] ?? null, $sent[0]['query']['createdAfterFilter'] ?? null ]);
	    $orig = \plugins\ITOps\models\node\NodeAppVeeamSessions::POLL;
	    [ $exe, $sent ] = $step('app::veeam-sessions', [ 'operation' => 'wait', 'session' => '{{ run }}', 'timeout' => 40, 'variable' => 'done' ],
		[ 'run' => [ 'id' => 'eeeeeeee-0000-0000-0000-000000000001' ] ]);
	    $this->check('veeam: Sessions wait: read until it has stopped (the session from an object\'s id)', [ 'end', 3, 'Stopped', 'Success' ],
		[ $exe?->state, count($sent), $exe?->getVariable('done')['state'] ?? null, $exe?->getVariable('done')['result']['result'] ?? null ]);
	    [ $ok, ] = $step('app::veeam-backups', [ 'operation' => 'latest', 'machine' => 'web01', 'maxAgeHours' => 24, 'variable' => 'b' ]);
	    [ $none, $sent ] = $step('app::veeam-backups', [ 'operation' => 'latest', 'machine' => 'db01', 'variable' => 'b' ]);
	    $this->check('veeam: Backups latest: a recent restore point (2 h); none: found false, not an error; asked newest first, one', [
		[ true, 2.0, true ], [ false, false, 'end' ], [ 'web01', 'db01' ][1], [ '1' ] ], [
		[ $ok?->getVariable('b')['found'] ?? null, $ok?->getVariable('b')['ageHours'] ?? null, $ok?->getVariable('b')['recent'] ?? null ],
		[ $none?->getVariable('b')['found'] ?? null, $none?->getVariable('b')['recent'] ?? null, $none?->state ], $sent[0]['query']['nameFilter'][0] ?? null, $sent[0]['query']['limit'] ?? null ]);
	    [ $exe, ] = $step('app::veeam-infrastructure', [ 'operation' => 'repositories', 'lowFree' => 10, 'variable' => 'repos' ]);
	    $this->check('veeam: Infrastructure repositories: the free percentage, only those under 10%', [ 1, 'Repo A', 5.0 ],
		[ $exe?->getVariable('repos')['count'] ?? null, $exe?->getVariable('repos')['items'][0]['name'] ?? null, $exe?->getVariable('repos')['items'][0]['freePercent'] ?? null ]);
	    [ $exe, $sent ] = $step('app::veeam-restore', [ 'operation' => 'instant', 'restorePoint' => '{{ b.restorePoint }}', 'powerUp' => false, 'reason' => 'Restore test',
		'params' => '{"nicsEnabled": false}', 'variable' => 'mount' ], [ 'b' => [ 'restorePoint' => [ 'id' => 'BBBBBBBB-0000-0000-0000-000000000001' ] ] ]);
	    $this->check('veeam: Restore instant: the restore point from an object, to its original place, Other parameters in the body; the mount', [
		[ 'restorePointId' => 'bbbbbbbb-0000-0000-0000-000000000001', 'type' => 'OriginalLocation', 'powerUp' => false, 'reason' => 'Restore test', 'nicsEnabled' => false ],
		'dddddddd-0000-0000-0000-000000000001' ], [ json_decode((string)($sent[0]['body'] ?? ''), true), $exe?->getVariable('mount')['id'] ?? null ]);
	    [ $exe, $sent ] = $step('app::veeam-api', [ 'method' => 'GET', 'path' => '/api/v1/jobs', 'query' => '{"nameFilter": "{{ pattern }}"}', 'variable' => 'raw' ], [ 'pattern' => 'Copy*' ]);
	    [ $err, ] = $step('app::veeam-api', [ 'method' => 'GET', 'path' => '/api/v1/nothing' ]);
	    $this->check('veeam: API: any path, a query with expressions; Veeam\'s error: the error output with its message', [ 2, 'error', true ],
		[ count($exe?->getVariable('raw')['data'] ?? []), $err?->getVariable('route'), str_contains((string)self::logLine($err, 'Veeam API request: '), 'No such resource') ]);

	    //the event trigger
	    $wf = $this->workflow('veeam event', [ $n('t', 'trigger::veeam', [ 'credential' => $id, 'results' => [ 'Failed', 'Warning' ], 'job' => '', 'runs' => 'each', 'variable' => 'ev',
		'mode' => 'interval', 'every' => 5, 'every_unit' => 'minutes' ]), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ev.result.result }}' ]), $e('e1', 't', 's') ]);
	    $this->check('veeam: the event trigger\'s workflow is valid; checked every 5 minutes by default', [ true, true ], [ $wf->validates(),
		\plugins\ITOps\models\node\NodeTriggerVeeam::matches([ 'mode' => 'interval', 'every' => 5, 'every_unit' => 'minutes' ], new \DateTimeImmutable('2026-11-01 10:05:00')) ]);
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $T = \plugins\ITOps\models\node\NodeTriggerVeeam::class;
	    $node = $wf->nodes['t'];
	    [ $data0, $none0 ] = $T::check($wf, $node, []);
	    $since = $data0['since'] ?? '';
	    $at = fn($s) => gmdate('Y-m-d\TH:i:s\Z', strtotime($since) + $s);
	    $sessions([
		[ 'id' => 'f0000000-0000-0000-0000-000000000000', 'jobId' => '11111111-1111-1111-1111-111111111111', 'name' => 'Before', 'state' => 'Stopped', 'endTime' => $at(-60), 'creationTime' => $at(-120), 'result' => [ 'result' => 'Failed' ] ],
		[ 'id' => 'f0000000-0000-0000-0000-000000000001', 'jobId' => '11111111-1111-1111-1111-111111111111', 'name' => 'Daily VMs', 'state' => 'Stopped', 'endTime' => $at(60), 'creationTime' => $at(1), 'result' => [ 'result' => 'Failed', 'message' => 'disk full' ] ],
		[ 'id' => 'f0000000-0000-0000-0000-000000000002', 'jobId' => '22222222-2222-2222-2222-222222222222', 'name' => 'Copy', 'state' => 'Stopped', 'endTime' => $at(120), 'creationTime' => $at(2), 'result' => [ 'result' => 'Warning' ] ],
		[ 'id' => 'f0000000-0000-0000-0000-000000000003', 'jobId' => '22222222-2222-2222-2222-222222222222', 'name' => 'Copy', 'state' => 'Stopped', 'endTime' => $at(120), 'creationTime' => $at(3), 'result' => [ 'result' => 'Success' ] ],
		[ 'id' => 'f0000000-0000-0000-0000-000000000004', 'jobId' => '11111111-1111-1111-1111-111111111111', 'name' => 'Daily VMs', 'state' => 'Working', 'creationTime' => $at(4), 'result' => [ 'result' => 'None' ] ],
	    ]);
	    $requests();
	    [ $data1, $first ] = $T::check($wf, $node, $data0);
	    $started = array_merge($started, $first);
	    $q = array_values(array_filter($requests(), fn($r) => $r['path'] === '/api/v1/sessions'))[0]['query'] ?? [];
	    [ $data2, $second ] = $T::check($wf, $node, $data1);
	    $started = array_merge($started, $second);
	    $this->check('veeam: event: the first check only notes the time; then the sessions ended since, stopped, Failed / Warning, oldest first: one run each; checked again: none', [
		[], true, [ 'Failed', 'Warning' ], [ 'Stopped' ], [ 'EndTime' ], [ [ 'Failed', 'Daily VMs' ], [ 'Warning', 'Copy' ] ], $at(120), [ 'f0000000-0000-0000-0000-000000000002' ], [] ], [
		$none0, $since !== '', $q['resultFilter'] ?? null, $q['stateFilter'] ?? null, $q['orderColumn'] ?? null,
		array_map(fn($x) => [ $x->getVariable('ev')['result']['result'] ?? null, $x->getVariable('ev')['name'] ?? null ], $first), $data1['since'] ?? null, $data1['seen'] ?? null, $second ]);
	    $this->check('veeam: event: the runs are queued for the daemon (new, with a job), at the trigger', [ [ 'new', 'veeam', 1 ], [ 'new', 'veeam', 1 ] ],
		array_map(fn($x) => [ $x->state, $x->trigger, $om->count('Job', [ 'job_spec' => '\util\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) ], $first));
	    $lock = \models\TriggerState::acquire($wf, 't');
	    $this->check('veeam: event: a check while another holds the step is skipped', [ true, null ], [ $lock !== null, \plugins\ITOps\models\node\NodeTriggerVeeam::runScheduled($wf, $node, '') ]);
	    $lock?->release();
	    $now = $T::startNow($wf, $node);
	    $started = array_merge($started, $now['executions'] ?? []);
	    $this->check('veeam: event: Run in the designer: the newest matching session', [ 1, 'Copy', 'Warning' ], [ count($now['executions'] ?? []),
		($now['executions'][0] ?? null)?->getVariable('ev')['name'] ?? null, ($now['executions'][0] ?? null)?->getVariable('ev')['result']['result'] ?? null ]);
	} finally {
	    $V::$testing = false;
	    $V::forgetTokens();
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		\models\TriggerState::beforeWorkflowDelete($wf);
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //FortiGate against a fake FortiOS (tests/FortiGateTestServer.php; plugins\ITOps\util\FortiGate::$testing: http, no address guard): the client (the token, the VDOM, FortiOS's
    //errors named), the credential's test, the steps' requests (blocking and unblocking IPs: done already is fine; a subnet from a CIDR; bans with their expiry; a policy
    //disabled; the configuration backup in Files), the event trigger (its FortiOS CLI; a stitch's webhook starts a run, a wrong secret does not)
    'fortigate' => function() {
	$F = \plugins\ITOps\util\FortiGate::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$A = \plugins\ITOps\models\node\NodeAppFgtAddresses::class;
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $e) {
		return $e->getMessage();
	    }
	};
	$this->check('fortigate: an address without /api; a subnet from an IP, a CIDR, "ip mask"; a bad one refused; FortiOS\'s error numbers named', [
	    'https://fw:8443', '10.1.2.3 255.255.255.255', '10.1.2.0 255.255.255.0', '10.1.0.0 255.255.0.0', true, 'HTTP status 500: an object of that name already exists (-5)' ], [
	    $F::baseUrl('https://fw:8443/api/v2/'), $A::subnet('10.1.2.3'), $A::subnet('10.1.2.0/24'), $A::subnet('10.1.0.0 255.255.0.0'),
	    str_contains($thrown(fn() => $A::subnet('10.1.2.0/33')), 'not a subnet'), $F::errorText([ 'status' => 'error', 'http_status' => 500, 'error' => -5 ], 500) ]);
	$this->check('fortigate: IPs from text or a list of objects (srcip), checked; one not an IP refused', [ [ '198.51.100.1', '2001:db8::1' ], [ '192.0.2.5' ], true ], [
	    \util\Ip::addresses("198.51.100.1, 2001:db8::1\n198.51.100.1", 'IPs'), \util\Ip::addresses([ [ 'srcip' => '192.0.2.5' ] ], 'IPs'),
	    str_contains($thrown(fn() => \util\Ip::addresses('1.2.3.4; evil.example', 'IPs')), 'not an IP address: `evil.example`') ]);

	$dir = sys_get_temp_dir() . '/qflows-check-fgt-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('fortigate', '../../plugins/ITOps/tests/FortiGateTestServer.php', $dir, [ 'FGT_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$F::$testing = true;
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$objects = fn() => json_decode((string)@file_get_contents("{$dir}/objects.json"), true);
	$creds = [];
	$files = [];
	$wfs = [];
	$runs = [];
	try {
	    $make = function($token, $vdom = '') use ($om, $port, &$creds) {
		$c = $om->create('CredentialFortiGate', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: FortiGate ' . uniqid(),
		    'serverUrl' => "https://127.0.0.1:{$port}", 'apiToken' => $token, 'vdom' => $vdom ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('fortigate: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->serverUrl = "http://127.0.0.1:{$port}";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialFortiGate', (string)$c->_id);
	    };
	    $cred = $make('fgtoken', 'root');
	    $wrong = $make('nope');
	    if (!$cred || !$wrong)
		return;
	    $bad = $om->create('CredentialFortiGate', [ 'name' => 'x', 'serverUrl' => 'http://fw', 'apiToken' => 't', 'vdom' => 'a b' ]);
	    $bad->validates();
	    $requests();
	    $this->check('fortigate: the form (https, a VDOM name); Test: the firewall, the token as Bearer, the VDOM; a wrong token: said so', [
		[ 'serverUrl', 'vdom' ], 'connected to FGT-HQ (FortiGate 100F), FortiOS v7.4.4 build 2662, serial FG100FTK20000001, VDOM root', 'root', true ], [
		array_values(array_intersect([ 'serverUrl', 'vdom' ], array_keys((array)$bad->errors()))), $cred->test(), $requests()[0]['query']['vdom'] ?? null,
		str_contains($thrown(fn() => $wrong->test()), 'the API token is refused') ]);

	    $id = (string)$cred->_id;
	    $step = function($fq, $cfg, $vars = []) use ($n, $e, $id, $requests) {
		$requests();
		$exe = $this->runFlow("fgt {$fq}", [ $n('m', 'trigger::manual'), $n('f', $fq, $cfg + [ 'credential' => $id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'f'), $e('e2', 'f', 'err', 1) ], $vars);
		return [ $exe, array_map(fn($r) => $r['method'] . ' ' . $r['path'], $requests()) ];
	    };
	    [ $exe, $sent ] = $step('app::fgt-addresses', [ 'operation' => 'block', 'ips' => '{{ ips }}', 'group' => 'Blocked', 'comment' => 'IOC' ],
		[ 'ips' => [ '203.0.113.9', '2001:db8::66' ] ]);
	    $o = $objects();
	    [ $again, ] = $step('app::fgt-addresses', [ 'operation' => 'block', 'ips' => '203.0.113.9', 'group' => 'Blocked' ]);
	    $this->check('fortigate: block: an address per IP (IPv6 in address6; one that exists kept), each in the group (v4 / v6); blocking again: fine', [
		'end', [ 'POST /api/v2/cmdb/firewall/address', 'POST /api/v2/cmdb/firewall/addrgrp/Blocked/member', 'POST /api/v2/cmdb/firewall/address6', 'POST /api/v2/cmdb/firewall/addrgrp6/Blocked/member' ],
		[ 'r22-block-203.0.113.9' ], [ 'r22-block-2001:db8::66' ], '2001:db8::66/128', 2, 'end' ], [
		$exe?->state, $sent, $o['addrgrp']['Blocked'] ?? null, $o['addrgrp6']['Blocked'] ?? null, $o['address6']['r22-block-2001:db8::66']['ip6'] ?? null,
		$exe?->getVariable('addresses')['count'] ?? null, $again?->state ]);
	    [ $exe, $sent ] = $step('app::fgt-addresses', [ 'operation' => 'unblock', 'ips' => '203.0.113.9, 198.51.100.200', 'group' => 'Blocked', 'deleteAddress' => true ]);
	    $o = $objects();
	    $this->check('fortigate: unblock: out of the group and deleted; one never blocked: fine', [ 'end', [], false ],
		[ $exe?->state, $o['addrgrp']['Blocked'] ?? null, isset($o['address']['r22-block-203.0.113.9']) ]);
	    [ $exe, ] = $step('app::fgt-addresses', [ 'operation' => 'create', 'name' => 'Office LAN', 'addrType' => 'ipmask', 'value' => '10.20.0.0/16', 'comment' => 'HQ' ]);
	    [ $dup, ] = $step('app::fgt-addresses', [ 'operation' => 'create', 'name' => 'Office LAN', 'addrType' => 'ipmask', 'value' => '10.20.0.0/16' ]);
	    [ $list, $sent ] = $step('app::fgt-addresses', [ 'operation' => 'list', 'contains' => 'office' ]);
	    $this->check('fortigate: create a subnet from a CIDR; again: the error output named; list: the name filter, the VDOM', [
		'10.20.0.0 255.255.0.0', 'error', true, [ 'Office LAN' ] ], [
		$objects()['address']['Office LAN']['subnet'] ?? null, $dup?->getVariable('route'), str_contains((string)self::logLine($dup, 'FortiGate Addresses create: '), 'already exists (-5)'),
		array_column($list?->getVariable('addresses')['items'] ?? [], 'name') ]);
	    [ $exe, ] = $step('app::fgt-banned', [ 'operation' => 'ban', 'ips' => '192.0.2.44', 'minutes' => 30 ]);
	    $this->check('fortigate: ban: the IP for 30 minutes (expiry in seconds)', [ 'end', [ '192.0.2.44' => 1800 ] ], [ $exe?->state, $objects()['banned'] ?? null ]);
	    [ $exe, ] = $step('app::fgt-banned', [ 'operation' => 'unban', 'ips' => '192.0.2.44' ]);
	    $this->check('fortigate: unban', [], $objects()['banned'] ?? null);
	    [ $exe, ] = $step('app::fgt-policies', [ 'operation' => 'disable', 'policyId' => '{{ 7 }}' ]);
	    [ $nan, ] = $step('app::fgt-policies', [ 'operation' => 'disable', 'policyId' => 'seven' ]);
	    $this->check('fortigate: a policy disabled; a policy ID that is not a number: the error output', [ 'disable', 'error' ], [ $objects()['policy'][7]['status'] ?? null, $nan?->getVariable('route') ]);
	    [ $exe, ] = $step('app::fgt-system', [ 'operation' => 'backup', 'variable' => 'bk' ]);
	    $bk = $exe?->getVariable('bk');
	    $file = !empty($bk['id']) ? $om->findOne('File', [ '_id' => $bk['id'] ]) : null;
	    if ($file)
		$files[] = $file;
	    $this->check('fortigate: the configuration backup in Files (<hostname>-<time>.txt)', [ 'end', true, true ],
		[ $exe?->state, (bool)preg_match('/^FGT-HQ-\d{8}-\d{6}\.txt$/', (string)($bk['filename'] ?? '')), $file && str_starts_with($file->getContents(), '#config-version=FG100F') ]);
	    [ $exe, ] = $step('app::fgt-api', [ 'method' => 'GET', 'path' => '/api/v2/cmdb/firewall/policy', 'variable' => 'raw' ]);
	    [ $err, ] = $step('app::fgt-api', [ 'method' => 'GET', 'path' => '/api/v2/../../etc/passwd' ]);
	    $this->check('fortigate: API: the whole answer; a path outside the API refused', [ 'success', 'Deny blocked', 'error' ],
		[ $exe?->getVariable('raw')['status'] ?? null, $exe?->getVariable('raw')['results'][0]['name'] ?? null, $err?->getVariable('route') ]);

	    //the event trigger: its CLI, a stitch's webhook
	    $init = \plugins\ITOps\models\node\NodeTriggerFortiGate::initConfig();
	    $host = (string)parse_url($init['url'], PHP_URL_HOST);
	    $this->check('fortigate: event: the CLI makes the webhook action: the URI without scheme, the port, %%log%%, the secret as Bearer', [ true, true, true, true ], [
		str_contains($init['setup'], 'set uri "' . $host . '/fortigate/' . $init['token'] . '"'), str_contains($init['setup'], 'set port ' . ((int)(parse_url($init['url'], PHP_URL_PORT) ?: 443))),
		str_contains($init['setup'], 'set http-body "%%log%%"'), str_contains($init['setup'], 'set value "Bearer ' . $init['secret'] . '"') ]);
	    $wf = $this->workflow('fgt event', [ $n('t', 'trigger::fortigate', $init + [ 'variable' => 'ev' ]), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ev.logid }}' ]), $e('e1', 't', 's') ]);
	    $this->check('fortigate: event: the workflow is valid; the secret and setup kept', [ true, $init['secret'], $init['setup'] ],
		[ $wf->validates(), $wf->nodes['t']['config']['secret'] ?? null, $wf->nodes['t']['config']['setup'] ?? null ]);
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $version = $om->create('WfVersion', [ 'aid' => $wf->aid, 'project' => $wf->project, 'wf' => $wf->_id, 'number' => 1, 'name' => (string)$wf->name, 'nodes' => $wf->nodes ]);
	    $version->save();
	    $wf->version = $version->_id;
	    $wf->status = \models\Workflow::PUBLISHED;
	    $wf->save();
	    $post = function($secret) use ($init, $host) {
		$ch = curl_init($init['url']);
		curl_setopt_array($ch, [ CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode([ 'logid' => '0100032002', 'type' => 'event', 'subtype' => 'system', 'srcip' => '198.51.100.7',
		    'msg' => 'Administrator login failed' ]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
		    CURLOPT_HTTPHEADER => [ 'Content-Type: application/json', 'Authorization: Bearer ' . $secret ], CURLOPT_RESOLVE => [ "{$host}:443:127.0.0.1" ],
		    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0 ]);
		$answer = json_decode((string)curl_exec($ch), true);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		return [ $status, $answer ];
	    };
	    [ $status ] = $post('wrong');
	    if ($status === 0) {
		echo "  skipped: the event trigger's address (this installation's web server cannot be reached from here)\n";
	    } else {
		[ $ok, $answer ] = $post($init['secret']);
		$runs = iterator_to_array($om->find('WfExecution', [ 'wf' => $wf->_id, 'trigger' => 'fortigate' ]), false);
		$this->check('fortigate: event: a wrong secret refused; the stitch\'s log starts one run with its fields', [ 401, 200, 1, '0100032002', '198.51.100.7', true ],
		    [ $status, $ok, count($runs), $runs[0]?->getVariable('ev')['logid'] ?? null, $runs[0]?->getVariable('ev')['srcip'] ?? null, isset($runs[0]?->getVariable('ev')['received_at']) ]);
	    }
	} finally {
	    $F::$testing = false;
	    foreach($runs as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		foreach($om->find('WfVersion', [ 'wf' => $wf->_id ]) as $v)
		    $v->delete();
	    foreach($files as $f)
		$f->delete();
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //Sophos Firewall against a fake firewall (tests/SophosTestServer.php; plugins\ITOps\util\Sophos::$testing: http, no address guard): the client (entities to and from
    //XML, a repeated element a list, the login, the codes said with what to do), the credential's test, the steps' requests (blocking and unblocking IPs: a host per IP, the group's
    //HostList sent back whole, IPv6 in its own group, done already is fine; a network from a CIDR; a rule disabled; a user made inactive; domains in a URL group), the API step
    'sophos' => function() {
	$S = \plugins\ITOps\util\Sophos::class;
	$H = \plugins\ITOps\models\node\NodeAppSfosHosts::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $e) {
		return $e->getMessage();
	    }
	};
	$xml = simplexml_load_string('<IPHostGroup><Name>G</Name><HostList><Host>a</Host><Host>b</Host></HostList><Description/></IPHostGroup>');
	$this->check('sophos: an address without /webconsole; an entity to XML (a list repeated, escaped) and back (repeated children a list); a code said with what to do', [
	    'https://fw:4444', '<IPHostGroup><Name>A &amp; B</Name><HostList><Host>a</Host><Host>b</Host></HostList></IPHostGroup>',
	    [ 'Name' => 'G', 'HostList' => [ 'Host' => [ 'a', 'b' ] ], 'Description' => '' ], [ 'x' ], true ], [
	    $S::baseUrl('https://fw:4444/webconsole/APIController'), $S::toXml('IPHostGroup', [ 'Name' => 'A & B', 'HostList' => [ 'Host' => [ 'a', 'b' ] ] ]), $S::toArray($xml), $S::items('x'),
	    str_contains($thrown(fn() => $S::checkStatus(simplexml_load_string('<Status code="534">no</Status>'))), 'add qflows\'s address') ]);
	$this->check('sophos: an IP host from an IP, a CIDR, "ip mask", an IPv6 network; a blocked IPv6 host\'s name; domains from URLs', [
	    [ 'HostType' => 'IP', 'IPAddress' => '10.1.2.3' ], [ 'HostType' => 'Network', 'IPAddress' => '10.1.0.0', 'Subnet' => '255.255.0.0' ],
	    [ 'HostType' => 'Network', 'IPAddress' => '10.1.2.0', 'Subnet' => '255.255.255.0' ], [ [ 'HostType' => 'Network', 'IPAddress' => '2001:db8::', 'Subnet' => '64' ], 'IPv6' ],
	    'r22-block-2001-db8--66', [ 'bad.example.com', 'www.example.org' ], true ], [
	    $H::ipHost('10.1.2.3')[0], $H::ipHost('10.1.0.0/16')[0], $H::ipHost('10.1.2.0 255.255.255.0')[0], $H::ipHost('2001:db8::/64'), $H::blockName('r22-block-', '2001:db8::66'),
	    \plugins\ITOps\models\node\NodeAppSfosWeb::domains("Bad.Example.com, https://www.example.org/path\nbad.example.com", 'Domains', 10),
	    str_contains($thrown(fn() => \plugins\ITOps\models\node\NodeAppSfosWeb::domains('ok.example; not a domain!', 'Domains', 10)), 'not a domain') ]);

	$dir = sys_get_temp_dir() . '/qflows-check-sfos-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('sophos', '../../plugins/ITOps/tests/SophosTestServer.php', $dir, [ 'SFOS_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$S::$testing = true;
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$objects = fn() => json_decode((string)@file_get_contents("{$dir}/objects.json"), true);
	$creds = [];
	try {
	    $make = function($password) use ($om, $port, &$creds) {
		$c = $om->create('CredentialSophos', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Sophos ' . uniqid(),
		    'serverUrl' => "https://127.0.0.1:{$port}", 'username' => 'admin', 'password' => $password ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('sophos: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->serverUrl = "http://127.0.0.1:{$port}";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialSophos', (string)$c->_id);
	    };
	    $cred = $make('sfpass');
	    $wrong = $make('nope');
	    if (!$cred || !$wrong)
		return;
	    $bad = $om->create('CredentialSophos', [ 'name' => 'x', 'serverUrl' => 'http://fw:4444', 'username' => 'admin', 'password' => 'p' ]);
	    $bad->validates();
	    $this->check('sophos: the form (https); Test: the hostname and API version (the password decrypted); a wrong password: said so', [
		[ 'serverUrl' ], 'connected to SFW-HQ, API version 2000.1', true ], [
		array_values(array_intersect([ 'serverUrl', 'username', 'password' ], array_keys((array)$bad->errors()))), $cred->test(),
		str_contains($thrown(fn() => $wrong->test()), 'Authentication Failure: the user name or password is refused') ]);

	    $id = (string)$cred->_id;
	    $step = function($fq, $cfg, $vars = []) use ($n, $e, $id, $requests) {
		$requests();
		$exe = $this->runFlow("sfos {$fq}", [ $n('m', 'trigger::manual'), $n('f', $fq, $cfg + [ 'credential' => $id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'f'), $e('e2', 'f', 'err', 1) ], $vars);
		return [ $exe, array_map(fn($r) => $r[0] . ' ' . $r[1], $requests()) ];
	    };
	    [ $exe, $sent ] = $step('app::sfos-hosts', [ 'operation' => 'block', 'ips' => '{{ ips }}', 'group' => 'Blocked', 'group6' => 'Blocked6', 'comment' => 'IOC' ],
		[ 'ips' => [ '203.0.113.9', '198.51.100.20', '2001:db8::66' ] ]);
	    $o = $objects();
	    [ $again, $sentAgain ] = $step('app::sfos-hosts', [ 'operation' => 'block', 'ips' => '203.0.113.9', 'group' => 'Blocked' ]);
	    [ $no6, ] = $step('app::sfos-hosts', [ 'operation' => 'block', 'ips' => '2001:db8::77', 'group' => 'Blocked' ]);
	    $this->check('sophos: block: a host per IP (one that exists kept), the groups updated whole (IPv6 in its own); again: nothing sent; IPv6 without an IPv6 group: the error output', [
		'end', [ 'r22-block-203.0.113.9', 'r22-block-198.51.100.20' ], 'r22-block-2001-db8--66', 'IPv6', 'IOC', 3, 'end', [ 'Get IPHostGroup', 'Get IPHost' ], 'error' ], [
		$exe?->state, $o['IPHostGroup']['Blocked']['HostList']['Host'] ?? null, $o['IPHostGroup']['Blocked6']['HostList']['Host'] ?? null,
		$o['IPHost']['r22-block-2001-db8--66']['IPFamily'] ?? null, $o['IPHost']['r22-block-198.51.100.20']['Description'] ?? null,
		$exe?->getVariable('hosts')['count'] ?? null, $again?->state, $sentAgain, $no6?->getVariable('route') ]);
	    [ $exe, ] = $step('app::sfos-hosts', [ 'operation' => 'unblock', 'ips' => '203.0.113.9, 192.0.2.250', 'group' => 'Blocked', 'deleteHost' => true ]);
	    $o = $objects();
	    $this->check('sophos: unblock: out of the group, the host removed; one never blocked: fine', [ 'end', 'r22-block-198.51.100.20', false, true ],
		[ $exe?->state, $o['IPHostGroup']['Blocked']['HostList']['Host'] ?? null, isset($o['IPHost']['r22-block-203.0.113.9']), isset($o['IPHost']['r22-block-198.51.100.20']) ]);
	    [ $exe, ] = $step('app::sfos-hosts', [ 'operation' => 'create', 'name' => 'Office LAN', 'hostType' => 'ip', 'value' => '10.20.0.0/16', 'comment' => 'HQ' ]);
	    [ $dup, ] = $step('app::sfos-hosts', [ 'operation' => 'create', 'name' => 'Office LAN', 'hostType' => 'ip', 'value' => '10.20.0.0/16' ]);
	    [ $list, $sent ] = $step('app::sfos-hosts', [ 'operation' => 'list', 'contains' => 'office' ]);
	    [ $fq, ] = $step('app::sfos-hosts', [ 'operation' => 'create', 'name' => 'Updates', 'hostType' => 'fqdn', 'value' => 'updates.example.com', 'params' => '{"Description": "x"}' ]);
	    $this->check('sophos: create a network from a CIDR; again: the error output with the firewall\'s message; list: the name filter; an FQDN host with Other fields', [
		'255.255.0.0', 'error', true, [ 'Office LAN' ], [ 'Get IPHost' ], [ 'Name' => 'Updates', 'FQDN' => 'updates.example.com', 'Description' => 'x' ] ], [
		$objects()['IPHost']['Office LAN']['Subnet'] ?? null, $dup?->getVariable('route'), str_contains((string)self::logLine($dup, 'Sophos Firewall Hosts create: '), 'status 502: Operation failed. Entity having same name already exists.'),
		array_column($list?->getVariable('hosts')['items'] ?? [], 'Name'), $sent, $objects()['FQDNHost']['Updates'] ?? null ]);
	    [ $exe, ] = $step('app::sfos-hosts', [ 'operation' => 'add', 'name' => 'Web server', 'group' => 'Blocked' ]);
	    $this->check('sophos: add a host to a group: the members kept', [ [ 'r22-block-198.51.100.20', 'Web server' ] ], [ $objects()['IPHostGroup']['Blocked']['HostList']['Host'] ?? null ]);

	    [ $exe, ] = $step('app::sfos-rules', [ 'operation' => 'disable', 'rule' => '{{ "Drop " + "blocked" }}' ]);
	    $rule = $objects()['FirewallRule']['Drop blocked'] ?? [];
	    [ $missing, ] = $step('app::sfos-rules', [ 'operation' => 'disable', 'rule' => 'Nope' ]);
	    $this->check('sophos: a rule disabled, sent back whole; one that does not exist: the error output', [ 'Disable', 'Drop', 'error' ],
		[ $rule['Status'] ?? null, $rule['NetworkPolicy']['Action'] ?? null, $missing?->getVariable('route') ]);
	    [ $exe, ] = $step('app::sfos-users', [ 'operation' => 'disable', 'user' => 'jdoe' ]);
	    [ $again, ] = $step('app::sfos-users', [ 'operation' => 'disable', 'user' => 'jdoe' ]);
	    $this->check('sophos: a user made inactive; again: done already', [ 'Inactive', 'Open Group', 'disabled already' ],
		[ $objects()['User']['jdoe']['Status'] ?? null, $objects()['User']['jdoe']['Group'] ?? null, $again?->getVariable('users')['message'] ?? null ]);
	    [ $exe, ] = $step('app::sfos-web', [ 'operation' => 'block', 'domains' => 'bad.example.com, https://evil.example.net/x', 'group' => 'Blocked sites' ]);
	    $urls = $objects()['WebFilterURLGroup']['Blocked sites']['URLlist']['URL'] ?? null;
	    [ $exe, ] = $step('app::sfos-web', [ 'operation' => 'unblock', 'domains' => 'old.example.com', 'group' => 'Blocked sites' ]);
	    $this->check('sophos: web: domains added to the URL group (the list kept), then one taken out', [ [ 'old.example.com', 'bad.example.com', 'evil.example.net' ], [ 'bad.example.com', 'evil.example.net' ] ],
		[ $urls, $objects()['WebFilterURLGroup']['Blocked sites']['URLlist']['URL'] ?? null ]);
	    [ $exe, ] = $step('app::sfos-system', [ 'operation' => 'zones', 'maxItems' => 1 ]);
	    $this->check('sophos: system: zones, At most', [ 1, 2, true ], [ $exe?->getVariable('system')['count'] ?? null, $exe?->getVariable('system')['total'] ?? null, $exe?->getVariable('system')['truncated'] ?? null ]);
	    [ $exe, ] = $step('app::sfos-api', [ 'xml' => '<Get><User/></Get>', 'variable' => 'raw' ]);
	    [ $err, ] = $step('app::sfos-api', [ 'xml' => '<Login><Username>x</Username></Login>' ]);
	    $this->check('sophos: API: the answer as JSON; a request with its own login refused', [ '2000.1', 'jdoe', 'error' ],
		[ $exe?->getVariable('raw')['APIVersion'] ?? null, $exe?->getVariable('raw')['User']['Username'] ?? null, $err?->getVariable('route') ]);
	} finally {
	    $S::$testing = false;
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //Checkmk against a fake site (tests/CheckmkTestServer.php; plugins\ITOps\util\Checkmk::$testing: http, no address guard): the client (the API's address, the automation
    //user's Bearer, Livestatus queries, Checkmk's problems with their fields), the credential's test, the steps (current problems without the acknowledged / in downtime ones,
    //a host's state, acknowledgements of several services, a downtime's window, a host created and the changes activated with If-Match, nothing pending is fine), the problem
    //trigger (its first check notes the problems; new ones and state changes start runs, once; acknowledged ones do not; a recovery when chosen)
    'checkmk' => function() {
	$C = \plugins\ITOps\util\Checkmk::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$this->check('checkmk: the API address (a pasted site path dropped); the query: columns repeated, the filter as JSON; a problem with its fields', [
	    'https://mon.example/prod/check_mk/api/1.0', 'columns=name&columns=state&query=%7B%22op%22%3A%22%3D%22%2C%22left%22%3A%22name%22%2C%22right%22%3A%22web01%22%7D',
	    'HTTP status 400: Bad Request; These fields have problems: host_name; host_name: Host exists.' ], [
	    $C::apiUrl('https://mon.example/old/check_mk/api/1.0/', 'prod'), $C::query([ 'columns' => [ 'name', 'state' ], 'query' => $C::eq('name', 'web01') ]),
	    $C::errorText([ 'title' => 'Bad Request', 'detail' => 'These fields have problems: host_name', 'fields' => [ 'host_name' => [ 'Host exists.' ] ] ], 400) ]);

	$dir = sys_get_temp_dir() . '/qflows-check-cmk-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('checkmk', '../../plugins/ITOps/tests/CheckmkTestServer.php', $dir, [ 'CMK_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$C::$testing = true;
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$monitor = fn(array $hosts, array $services) => file_put_contents("{$dir}/monitoring.json", json_encode([ 'hosts' => $hosts, 'services' => $services ]));
	$svc = fn($host, $desc, $state, $ack = 0, $down = 0) => [ 'host_name' => $host, 'description' => $desc, 'state' => $state, 'plugin_output' => "{$desc} output",
	    'last_state_change' => 1793500000, 'acknowledged' => $ack, 'scheduled_downtime_depth' => $down ];
	$hst = fn($name, $state, $ack = 0, $down = 0) => [ 'name' => $name, 'state' => $state, 'plugin_output' => "{$name} output", 'last_state_change' => 1793500000,
	    'acknowledged' => $ack, 'scheduled_downtime_depth' => $down ];
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $e) {
		return $e->getMessage();
	    }
	};
	$creds = [];
	$wfs = [];
	$started = [];
	try {
	    $make = function($secret) use ($om, $port, &$creds) {
		$c = $om->create('CredentialCheckmk', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Checkmk ' . uniqid(),
		    'serverUrl' => "https://127.0.0.1:{$port}", 'site' => 'mysite', 'username' => 'automation', 'secret' => $secret ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('checkmk: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->serverUrl = "http://127.0.0.1:{$port}";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialCheckmk', (string)$c->_id);
	    };
	    $cred = $make('s3cret');
	    $wrong = $make('nope');
	    if (!$cred || !$wrong)
		return;
	    $bad = $om->create('CredentialCheckmk', [ 'name' => 'x', 'serverUrl' => 'https://mon/mysite', 'site' => 'my site', 'username' => 'a', 'secret' => 's' ]);
	    $bad->validates();
	    $requests();
	    $this->check('checkmk: the form (the server without a path, a site name); Test: the site, version and edition, the automation user as Bearer; a wrong secret: said so', [
		[ 'serverUrl', 'site' ], 'connected to site mysite, Checkmk 2.3.0p20 (cre)', 'Bearer automation s3cret', true ], [
		array_values(array_intersect([ 'serverUrl', 'site' ], array_keys((array)$bad->errors()))), $cred->test(), $requests()[0]['auth'] ?? null,
		str_contains($thrown(fn() => $wrong->test()), 'the automation user or its secret is refused') ]);

	    $id = (string)$cred->_id;
	    $step = function($fq, $cfg, $vars = []) use ($n, $e, $id, $requests) {
		$requests();
		$exe = $this->runFlow("cmk {$fq}", [ $n('m', 'trigger::manual'), $n('c', $fq, $cfg + [ 'credential' => $id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'c'), $e('e2', 'c', 'err', 1) ], $vars);
		return [ $exe, $requests() ];
	    };
	    $monitor([ $hst('web01', 0), $hst('db01', 1) ], [ $svc('web01', 'CPU load', 1), $svc('web01', 'Disk /', 2, 1), $svc('db01', 'PING', 2, 0, 1), $svc('web01', 'Uptime', 0) ]);
	    [ $exe, $sent ] = $step('app::cmk-status', [ 'operation' => 'problems', 'variable' => 'p' ]);
	    $p = $exe?->getVariable('p');
	    $this->check('checkmk: Status problems: hosts not UP and services WARN or worse, without the acknowledged and in-downtime ones; each with its state as text', [
		[ [ 'host', 'db01', null, 'DOWN' ], [ 'service', 'web01', 'CPU load', 'WARN' ] ], '2026-11-01T', true ], [
		array_map(fn($x) => [ $x['kind'], $x['host_name'], $x['service'], $x['stateText'] ], $p['items'] ?? []), substr((string)($p['items'][0]['since'] ?? ''), 0, 11),
		str_contains((string)($sent[1]['query']['query'][0] ?? ''), '"left":"acknowledged"') ]);
	    [ $exe, ] = $step('app::cmk-status', [ 'operation' => 'host', 'host' => 'web01', 'variable' => 'h' ]);
	    [ $none, ] = $step('app::cmk-status', [ 'operation' => 'host', 'host' => 'ghost' ]);
	    $this->check('checkmk: Status host: its state and its services; a host Checkmk does not monitor: the error output', [ 'UP', 3, 'error' ],
		[ $exe?->getVariable('h')['host']['stateText'] ?? null, count($exe?->getVariable('h')['services'] ?? []), $none?->getVariable('route') ]);
	    [ $exe, $sent ] = $step('app::cmk-ack', [ 'operation' => 'ackService', 'host' => 'web01', 'services' => "CPU load\nDisk /", 'comment' => 'Ticket {{ t }}', 'notify' => true ], [ 't' => 'T-42' ]);
	    $this->check('checkmk: Acknowledge services: one request per service, the comment with an expression, sticky by default', [
		[ 'CPU load', 'Disk /' ], 'Ticket T-42', true, true ], [ array_column(array_column($sent, 'body'), 'service_description'), $sent[0]['body']['comment'] ?? null,
		$sent[0]['body']['sticky'] ?? null, $sent[0]['body']['notify'] ?? null ]);
	    [ $exe, $sent ] = $step('app::cmk-ack', [ 'operation' => 'downHost', 'host' => 'db01', 'start' => '2026-11-02T22:00:00Z', 'minutes' => 90, 'comment' => 'Patching' ]);
	    $this->check('checkmk: Schedule a host downtime: from the start, for the minutes', [ 'host', 'db01', '2026-11-02T22:00:00Z', '2026-11-02T23:30:00Z' ],
		[ $sent[0]['body']['downtime_type'] ?? null, $sent[0]['body']['host_name'] ?? null, $sent[0]['body']['start_time'] ?? null, $sent[0]['body']['end_time'] ?? null ]);
	    [ $exe, $sent ] = $step('app::cmk-hosts', [ 'operation' => 'create', 'host' => 'app02', 'folder' => '/servers', 'ip' => '10.0.0.9', 'alias' => 'App 2' ]);
	    [ $dup, ] = $step('app::cmk-hosts', [ 'operation' => 'create', 'host' => 'app02', 'activateAfter' => false ]);
	    [ $act, ] = $step('app::cmk-hosts', [ 'operation' => 'activate' ]);
	    $this->check('checkmk: Hosts create: the folder and attributes, then the changes activated (If-Match *); again: the error output with the field\'s message; nothing pending: fine', [
		[ 'POST', '/servers', [ 'alias' => 'App 2', 'ipaddress' => '10.0.0.9' ] ], [ 'POST', '*' ], 'error', true, 'end' ], [
		[ $sent[0]['method'] ?? null, $sent[0]['body']['folder'] ?? null, $sent[0]['body']['attributes'] ?? null ], [ $sent[1]['method'] ?? null, $sent[1]['ifMatch'] ?? null ],
		$dup?->getVariable('route'), str_contains((string)self::logLine($dup, 'Checkmk Hosts create: '), 'host_name: Host "app02" already exists.'), $act?->state ]);
	    [ $exe, ] = $step('app::cmk-api', [ 'method' => 'GET', 'path' => '/version', 'variable' => 'v' ]);
	    $this->check('checkmk: API: any request', '2.3.0p20', $exe?->getVariable('v')['versions']['checkmk'] ?? null);

	    //the problem trigger
	    $wf = $this->workflow('cmk problem', [ $n('t', 'trigger::checkmk', [ 'credential' => $id, 'minState' => '2', 'recoveries' => true, 'runs' => 'each', 'variable' => 'ev',
		'mode' => 'interval', 'every' => 2, 'every_unit' => 'minutes' ]), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ev.event }}' ]), $e('e1', 't', 's') ]);
	    $this->check('checkmk: the problem trigger\'s workflow is valid', true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $T = \plugins\ITOps\models\node\NodeTriggerCheckmk::class;
	    $node = $wf->nodes['t'];
	    $monitor([ $hst('web01', 0) ], [ $svc('web01', 'Disk /', 2) ]);
	    [ $d0, $r0 ] = $T::check($wf, $node, []);
	    $monitor([ $hst('web01', 0), $hst('db01', 1) ], [ $svc('web01', 'Disk /', 2), $svc('web01', 'CPU load', 1), $svc('web01', 'Memory', 2), $svc('app01', 'HTTP', 2, 1) ]);
	    [ $d1, $r1 ] = $T::check($wf, $node, $d0);
	    [ $d2, $r2 ] = $T::check($wf, $node, $d1);
	    $monitor([ $hst('web01', 0) ], [ $svc('web01', 'Disk /', 2), $svc('web01', 'Memory', 2), $svc('web01', 'CPU load', 2) ]);
	    [ $d3, $r3 ] = $T::check($wf, $node, $d2);
	    $started = array_merge($started, $r0, $r1, $r2, $r3);
	    $show = fn($runs) => array_map(fn($x) => [ $x->getVariable('ev')['event'] ?? null, $x->getVariable('ev')['host_name'] ?? null, $x->getVariable('ev')['service'] ?? null,
		$x->getVariable('ev')['stateText'] ?? null ], $runs);
	    $this->check('checkmk: problem trigger: the first check notes what is wrong; then new problems (CRIT and worse, a host DOWN; not WARN, not acknowledged) start runs, once; '
		. 'a service that turns CRIT, and a recovery', [
		[], [ [ 'problem', 'db01', null, 'DOWN' ], [ 'problem', 'web01', 'Memory', 'CRIT' ] ], [], [ [ 'problem', 'web01', 'CPU load', 'CRIT' ], [ 'recovery', 'db01', null, 'DOWN' ] ] ],
		[ $show($r0), $show($r1), $show($r2), $show($r3) ]);
	    $this->check('checkmk: problem trigger: the runs queued, at the trigger', [ 'new', 'checkmk' ], [ $r1[0]?->state, $r1[0]?->trigger ]);
	    //a problem noted by the first check (Disk /) that clears: no recovery (it started no run); one that started a run (Memory) and clears: its recovery
	    $monitor([ $hst('web01', 0) ], [ $svc('web01', 'CPU load', 2) ]);
	    [ $d4, $r4 ] = $T::check($wf, $node, $d3);
	    $started = array_merge($started, $r4);
	    $this->check('checkmk: problem trigger: no recovery for a problem the first check only noted; one for a problem that started a run', [ [ 'recovery', 'web01', 'Memory', 'CRIT' ] ], $show($r4));
	    $now = $T::startNow($wf, $node);
	    $started = array_merge($started, $now['executions'] ?? []);
	    $this->check('checkmk: problem trigger: Run in the designer: a current problem', [ 1, 'problem' ], [ count($now['executions'] ?? []), ($now['executions'][0] ?? null)?->getVariable('ev')['event'] ?? null ]);
	} finally {
	    $C::$testing = false;
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		\models\TriggerState::beforeWorkflowDelete($wf);
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //PatchMon against a fake server (tests/PatchMonTestServer.php; plugins\ITOps\util\PatchMon::$testing: http, no address guard): the client (the address, the query, the
    //errors for the token, the user and the session), the credential's form and test (the token's hosts; the user signs in and out; two-factor refused), the steps (hosts by state
    //and group, a host by name, a delete refused to a read-only token, security updates of a host, a patch run waited for, submitted for approval, approved, stopped, without a
    //user, runs, the API as token and as user), the host trigger (its first check notes the hosts; then security updates, a reboot, a new host, a host that stops reporting and one
    //patched start runs, once; a host not enrolled yet does not)
    'patchmon' => function() {
	$P = \plugins\ITOps\util\PatchMon::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$this->check('patchmon: the address (a pasted /api/v1/... dropped); the query; the errors of the token, the user and a session', [
	    'https://pm.example', 'hostgroup=Web%20Servers%2CDB&updates_only=true', 'HTTP status 401: the API token key or secret is refused (or the token is disabled or expired)',
	    'HTTP status 401: the PatchMon user or password is refused', 'HTTP status 409: This host already has a patch run in progress' ], [
	    $P::baseUrl(' https://pm.example/api/v1/api/hosts/ '), $P::query([ 'hostgroup' => 'Web Servers,DB', 'include' => null, 'updates_only' => true ]),
	    $P::errorText([ 'error' => 'Invalid API credentials' ], 401, 'key'), $P::errorText([ 'error' => 'Invalid credentials' ], 401, 'login'),
	    $P::errorText([ 'error' => 'This host already has a patch run in progress' ], 409, 'user') ]);

	$dir = sys_get_temp_dir() . '/qflows-check-pm-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('patchmon', '../../plugins/ITOps/tests/PatchMonTestServer.php', $dir, [ 'PM_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$P::$testing = true;
	$pollBefore = \plugins\ITOps\models\node\NodeAppPmPatching::$poll;
	\plugins\ITOps\models\node\NodeAppPmPatching::$poll = 0;
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$H1 = '11111111-1111-4111-8111-111111111111';
	$H2 = '22222222-2222-4222-8222-222222222222';
	$H3 = '33333333-3333-4333-8333-333333333333';
	$H4 = '44444444-4444-4444-8444-444444444444';
	$host = fn($id, $name, $group, $update, $sec, $upd, $reboot, $reporting = 'reporting', $status = 'active') => [ 'id' => $id, 'friendly_name' => $name, 'hostname' => "{$name}.example.local",
	    'ip' => '10.0.0.' . $id[0], 'host_groups' => [ [ 'id' => "g-{$group}", 'name' => $group ] ], 'os_type' => 'Ubuntu', 'os_version' => '24.04 LTS',
	    'last_update' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 600), 'status' => $status, 'effective_status' => $reporting === 'stale' ? 'inactive' : $status,
	    'reporting_state' => $reporting, 'update_state' => $update, 'needs_reboot' => $reboot, 'updates_count' => $upd, 'security_updates_count' => $sec, 'total_packages' => 300,
	    'packages' => [ [ 'name' => 'openssl', 'current_version' => '3.0.2-1', 'available_version' => '3.0.2-2', 'needs_update' => true, 'is_security_update' => true ],
		[ 'name' => 'nginx', 'current_version' => '1.18', 'available_version' => '1.24', 'needs_update' => true, 'is_security_update' => false ],
		[ 'name' => 'bash', 'current_version' => '5.2', 'available_version' => null, 'needs_update' => false, 'is_security_update' => false ] ] ];
	$hosts = fn(array $list) => file_put_contents("{$dir}/hosts.json", json_encode($list));
	$hosts([ $host($H1, 'web01', 'Production', 'up_to_date', 0, 0, false), $host($H2, 'db01', 'Production', 'security_required', 3, 5, true),
	    $host($H3, 'app01', 'Development', 'up_to_date', 0, 0, false, 'stale', 'pending') ]);
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $e) {
		return $e->getMessage();
	    }
	};
	$creds = [];
	$wfs = [];
	$started = [];
	try {
	    $make = function($key, $user = '', $password = '') use ($om, $port, &$creds) {
		$c = $om->create('CredentialPatchMon', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: PatchMon ' . uniqid(),
		    'serverUrl' => "https://127.0.0.1:{$port}", 'tokenKey' => $key, 'tokenSecret' => 's3cret', 'username' => $user, 'password' => $password ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('patchmon: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->serverUrl = "http://127.0.0.1:{$port}";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialPatchMon', (string)$c->_id);
	    };
	    $cred = $make('patchmon_ae_test', 'admin', 'pw');
	    $ro = $make('patchmon_ae_ro');
	    $wrong = $make('patchmon_ae_nope');
	    $tfa = $make('patchmon_ae_test', 'tfa', 'pw');
	    if (!$cred || !$ro || !$wrong || !$tfa)
		return;
	    $bad = $om->create('CredentialPatchMon', [ 'name' => 'x', 'serverUrl' => 'https://pm.example/api/v1', 'tokenKey' => 'a b', 'tokenSecret' => 's' ]);
	    $bad->validates();
	    $requests();
	    $test = $cred->test();
	    $sent = $requests();
	    $this->check('patchmon: the form (the server without a path, a key without spaces); Test: the token\'s hosts, the user signed in and out; a wrong secret, a user with '
		. 'two-factor sign-in: said so; no user: the token only', [
		[ 'serverUrl', 'tokenKey' ], 'connected: 3 hosts; signed in as admin', [ 'Basic ' . base64_encode('patchmon_ae_test:s3cret'), '/api/v1/auth/login', '/api/v1/auth/logout' ],
		true, true, 'connected: 3 hosts' ], [
		array_values(array_intersect([ 'serverUrl', 'tokenKey' ], array_keys((array)$bad->errors()))), $test, [ $sent[0]['auth'] ?? null, $sent[1]['path'] ?? null, $sent[2]['path'] ?? null ],
		str_contains($thrown(fn() => $wrong->test()), 'the API token key or secret is refused'), str_contains($thrown(fn() => $tfa->test()), 'two-factor authentication'), $ro->test() ]);

	    $id = (string)$cred->_id;
	    $step = function($fq, $cfg, $vars = [], $credId = null) use ($n, $e, $id, $requests) {
		$requests();
		$exe = $this->runFlow("pm {$fq}", [ $n('m', 'trigger::manual'), $n('c', $fq, $cfg + [ 'credential' => $credId ?? $id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'c'), $e('e2', 'c', 'err', 1) ], $vars);
		return [ $exe, $requests() ];
	    };
	    [ $exe, $sent ] = $step('app::pm-hosts', [ 'operation' => 'list', 'state' => 'security', 'stats' => false, 'hostgroup' => 'Production', 'variable' => 'h' ]);
	    [ $all, ] = $step('app::pm-hosts', [ 'operation' => 'list', 'state' => 'not_reporting', 'variable' => 'h' ]);
	    $this->check('patchmon: Hosts list: a State reads the stats; the groups sent; the hosts in that state; not reporting leaves out a host not enrolled yet', [
		[ 'stats', 'Production' ], [ 'db01' ], 0 ], [
		[ $sent[0]['query']['include'] ?? null, $sent[0]['query']['hostgroup'] ?? null ], array_column($exe?->getVariable('h')['items'] ?? [], 'friendly_name'),
		$all?->getVariable('h')['count'] ?? null ]);
	    [ $exe, $sent ] = $step('app::pm-hosts', [ 'operation' => 'system', 'host' => '{{ name }}', 'variable' => 's' ], [ 'name' => 'DB01.example.local' ]);
	    [ $none, ] = $step('app::pm-hosts', [ 'operation' => 'get', 'host' => 'ghost' ]);
	    $this->check('patchmon: a host by its host name (any case): looked up, then its system; an unknown name: the error output', [
		[ '/api/v1/api/hosts', "/api/v1/api/hosts/{$H2}/system" ], true, 'error' ], [
		array_column($sent, 'path'), $exe?->getVariable('s')['needs_reboot'] ?? null, $none?->getVariable('route') ]);
	    [ $exe, ] = $step('app::pm-hosts', [ 'operation' => 'delete', 'host' => $H1 ], [], (string)$ro->_id);
	    [ $ok, ] = $step('app::pm-hosts', [ 'operation' => 'delete', 'host' => $H1 ]);
	    $this->check('patchmon: Hosts delete: refused to a read-only token (the error output, PatchMon\'s message); deleted with host: delete', [ 'error', true, 'end' ], [
		$exe?->getVariable('route'), str_contains((string)self::logLine($exe, 'PatchMon Hosts delete: '), 'does not have permission to delete host'), $ok?->state ]);
	    [ $exe, $sent ] = $step('app::pm-packages', [ 'operation' => 'packages', 'host' => $H2, 'which' => 'security', 'variable' => 'p' ]);
	    [ $named, ] = $step('app::pm-packages', [ 'operation' => 'packages', 'host' => $H2, 'which' => 'all', 'name' => 'NG', 'variable' => 'p' ]);
	    [ $rep, ] = $step('app::pm-packages', [ 'operation' => 'reports', 'host' => $H2, 'limit' => 3, 'variable' => 'r' ]);
	    $this->check('patchmon: Packages: security updates (updates_only, then the security ones); all, by name; the last reports', [
		'true', [ 'openssl' ], [ 'nginx' ], 3 ], [
		$sent[0]['query']['updates_only'] ?? null, array_column($exe?->getVariable('p')['items'] ?? [], 'name'), array_column($named?->getVariable('p')['items'] ?? [], 'name'),
		count($rep?->getVariable('r')['items'] ?? []) ]);

	    //patching, signed in as the user
	    [ $exe, $sent ] = $step('app::pm-patching', [ 'operation' => 'patch', 'host' => 'web01', 'what' => 'packages', 'packages' => "openssl\nnginx, openssl", 'when' => 'immediate',
		'wait' => true, 'variable' => 'run' ]);
	    $run = $exe?->getVariable('run');
	    $trigger = array_values(array_filter($sent, fn($r) => $r['path'] === '/api/v1/patching/trigger'))[0] ?? [];
	    $this->check('patchmon: Patching patch: signed in, the packages (each once) now; waited for: the run read until it ends; signed out last; the user\'s token on the web API', [
		[ $H1, 'patch_package', [ 'openssl', 'nginx' ], 'immediate' ], 'completed', true, true, '/api/v1/auth/logout', 'Bearer tok-admin' ], [
		[ $trigger['body']['host_id'] ?? null, $trigger['body']['patch_type'] ?? null, $trigger['body']['package_names'] ?? null, $trigger['body']['schedule_override'] ?? null ],
		$run['status'] ?? null, $run['ok'] ?? null, $run['finished'] ?? null, end($sent)['path'] ?? null, $trigger['auth'] ?? null ]);
	    [ $exe, $sent ] = $step('app::pm-patching', [ 'operation' => 'patch', 'host' => $H2, 'what' => 'all', 'when' => 'approval', 'wait' => true, 'variable' => 'run' ]);
	    $pending = $exe?->getVariable('run');
	    [ $appr, ] = $step('app::pm-patching', [ 'operation' => 'approve', 'run' => '{{ pending }}', 'variable' => 'a' ], [ 'pending' => $pending ]);
	    [ $busy, ] = $step('app::pm-patching', [ 'operation' => 'patch', 'host' => $H2, 'variable' => 'x' ]);
	    [ $stop, ] = $step('app::pm-patching', [ 'operation' => 'stop', 'run' => $appr?->getVariable('a')['patch_run_id'] ?? '', 'variable' => 's' ]);
	    $this->check('patchmon: Patching: submitted for approval (not waited for); approved from the result: a new run; another run on the host meanwhile: the error output with '
		. 'PatchMon\'s message; the new run stopped', [ [ false, 'Submitted for approval' ], true, 'error', true, 'end' ], [
		[ $pending['queued'] ?? null, $pending['message'] ?? null ], ($appr?->getVariable('a')['validation_run_id'] ?? null) === ($pending['patch_run_id'] ?? 'x'),
		$busy?->getVariable('route'), str_contains((string)self::logLine($busy, 'PatchMon Patching patch: '), 'already has a patch run in progress'), $stop?->state ]);
	    [ $nouser, ] = $step('app::pm-patching', [ 'operation' => 'summary' ], [], (string)$ro->_id);
	    [ $dry, ] = $step('app::pm-patching', [ 'operation' => 'patch', 'host' => $H1, 'what' => 'all', 'when' => 'dry' ]);
	    [ $runs, $sent ] = $step('app::pm-patching', [ 'operation' => 'runs', 'host' => 'db01', 'status' => 'cancelled', 'variable' => 'l' ]);
	    $this->check('patchmon: Patching: a credential without a user: said so; a dry run of all updates: refused before sending; List runs by host and status', [
		true, true, [ 1, 'cancelled' ], $H2 ], [
		str_contains((string)self::logLine($nouser, 'PatchMon Patching summary: '), 'the credential has no PatchMon user'),
		str_contains((string)self::logLine($dry, 'PatchMon Patching patch: '), 'a dry run patches packages'),
		[ $runs?->getVariable('l')['count'] ?? null, $runs?->getVariable('l')['items'][0]['status'] ?? null ],
		(array_values(array_filter($sent, fn($r) => $r['path'] === '/api/v1/patching/runs'))[0] ?? [])['query']['host_id'] ?? null ]);
	    [ $tok, ] = $step('app::pm-api', [ 'as' => 'token', 'method' => 'GET', 'path' => '/api/hosts', 'query' => '{"include": "stats"}', 'variable' => 'v' ]);
	    [ $usr, ] = $step('app::pm-api', [ 'as' => 'user', 'method' => 'GET', 'path' => '/patching/dashboard', 'variable' => 'v' ]);
	    [ $out, ] = $step('app::pm-api', [ 'as' => 'token', 'method' => 'GET', 'path' => '/patching/dashboard' ]);
	    $this->check('patchmon: API: as the token (the integration API), as the user (the web API); the token outside /api: the error output', [ 3, true, 'error' ], [
		$tok?->getVariable('v')['total'] ?? null, isset($usr?->getVariable('v')['summary']), $out?->getVariable('route') ]);

	    //the host trigger
	    $wf = $this->workflow('pm host', [ $n('t', 'trigger::patchmon', [ 'credential' => $id, 'events' => [ 'security', 'reboot', 'stale', 'new', 'patched' ], 'runs' => 'each',
		'variable' => 'ev', 'mode' => 'interval', 'every' => 10, 'every_unit' => 'minutes' ]), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ev.event }}' ]), $e('e1', 't', 's') ]);
	    $this->check('patchmon: the host trigger\'s workflow is valid', true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $T = \plugins\ITOps\models\node\NodeTriggerPatchMon::class;
	    $node = $wf->nodes['t'];
	    $hosts([ $host($H1, 'web01', 'Production', 'up_to_date', 0, 0, false), $host($H2, 'db01', 'Production', 'security_required', 3, 5, true),
		$host($H3, 'app01', 'Development', 'up_to_date', 0, 0, false, 'stale', 'pending') ]);
	    [ $d0, $r0 ] = $T::check($wf, $node, []);
	    $hosts([ $host($H1, 'web01', 'Production', 'security_required', 2, 4, true), $host($H2, 'db01', 'Production', 'security_required', 3, 5, true),
		$host($H3, 'app01', 'Development', 'up_to_date', 0, 0, false, 'stale', 'pending'), $host($H4, 'mail01', 'Production', 'up_to_date', 0, 0, false) ]);
	    [ $d1, $r1 ] = $T::check($wf, $node, $d0);
	    [ $d2, $r2 ] = $T::check($wf, $node, $d1);
	    $hosts([ $host($H1, 'web01', 'Production', 'security_required', 2, 4, true), $host($H2, 'db01', 'Production', 'up_to_date', 0, 0, false, 'stale'),
		$host($H3, 'app01', 'Development', 'up_to_date', 0, 0, false, 'stale', 'pending'), $host($H4, 'mail01', 'Production', 'up_to_date', 0, 0, false) ]);
	    [ $d3, $r3 ] = $T::check($wf, $node, $d2);
	    $started = array_merge($started, $r0, $r1, $r2, $r3);
	    $show = fn($runs) => array_map(fn($x) => [ $x->getVariable('ev')['event'] ?? null, $x->getVariable('ev')['friendly_name'] ?? null ], $runs);
	    $this->check('patchmon: host trigger: the first check notes the hosts; then security updates and a reboot (one run each), a new host; nothing new: none; patched and stale; '
		. 'a host not enrolled yet never stale', [
		[], [ [ 'security', 'web01' ], [ 'reboot', 'web01' ], [ 'new', 'mail01' ] ], [], [ [ 'patched', 'db01' ], [ 'stale', 'db01' ] ] ],
		[ $show($r0), $show($r1), $show($r2), $show($r3) ]);
	    $this->check('patchmon: host trigger: the runs queued, at the trigger; the host\'s previous state given', [ 'new', 'patchmon', 0 ],
		[ $r1[0]?->state, $r1[0]?->trigger, $r1[0]?->getVariable('ev')['previous']['security_updates_count'] ?? null ]);
	    $now = $T::startNow($wf, $node);
	    $started = array_merge($started, $now['executions'] ?? []);
	    $this->check('patchmon: host trigger: Run in the designer: a host now in a chosen state', [ 1, 'security', 'web01' ], [ count($now['executions'] ?? []),
		($now['executions'][0] ?? null)?->getVariable('ev')['event'] ?? null, ($now['executions'][0] ?? null)?->getVariable('ev')['friendly_name'] ?? null ]);
	} finally {
	    $P::$testing = false;
	    \plugins\ITOps\models\node\NodeAppPmPatching::$poll = $pollBefore;
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		\models\TriggerState::beforeWorkflowDelete($wf);
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //CLD: 2026-11-20T12:00:00+02:00: Synology DSM against a fake DS923+ (tests/SynologyTestServer.php; plugins\ITOps\util\Synology::$testing: http, no address guard): the client
    //(the address, parameters as DSM takes them, DSM's codes said; the APIs read first, a missing one refused; the session's token on each call, signed out last; a two-factor
    //code from the secret), the credential's test (an administrator, another user, a wrong password), the steps (files: shares, a folder, a search, upload renamed, download into
    //Files, folders, rename, copy / move / delete waited for, compress, a sharing link; system; storage problems; shared folders; users and groups; Hyper Backup waited for; the
    //API), the NAS trigger (the first check notes; the events once; recovered; unreachable and reachable again; Run in the designer), the notification trigger (categories,
    //the address rebuilt on import, the endpoint: the key, a test message left out, a storage one started)
    'synology' => function() {
	$S = \plugins\ITOps\util\Synology::class;
	$N = \plugins\ITOps\models\node\NodeTriggerSynologyNotification::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $e) {
		return $e->getMessage();
	    }
	};
	$this->check('synology: the address (a pasted /webapi/... or /#... dropped); parameters as DSM takes them; DSM\'s codes said by the API\'s family', [
	    'https://nas.local:5001', 'https://nas.local:5001', 'true', '["/docs/a b.pdf"]', 'SYNO.API.Auth: the account or password is refused (code 400)',
	    'SYNO.FileStation.CopyMove: unknown error (code 1000): /docs/x: no such file or folder', 'SYNO.Core.User: the signed-in user has no permission for this (code 105)' ], [
	    $S::baseUrl(' https://nas.local:5001/webapi/entry.cgi '), $S::baseUrl('https://nas.local:5001/#/signin'), $S::param(true), $S::param([ '/docs/a b.pdf' ]),
	    $S::errorText([ 'code' => 400 ], 'SYNO.API.Auth'), $S::errorText([ 'code' => 1000, 'errors' => [ [ 'code' => 408, 'path' => '/docs/x' ] ] ], 'SYNO.FileStation.CopyMove'),
	    $S::errorText([ 'code' => 105 ], 'SYNO.Core.User') ]);
	$P = \plugins\ITOps\models\node\NodeAppSynology::class;
	$this->check('synology: paths: the shared folder first, duplicate slashes folded; .. and the top refused; several one per line or a list of items', [
	    '/docs/a, b.pdf', true, true, [ '/docs/a', '/photos/b' ] ], [
	    $P::path('docs//a, b.pdf/'), str_contains($thrown(fn() => $P::path('/docs/../etc')), 'not a path'), str_contains($thrown(fn() => $P::path('/')), 'a shared folder'),
	    $P::paths([ [ 'path' => '/docs/a' ], '/photos/b' ]) ]);
	$this->check('synology: notifications: categories from DSM\'s words; problem', [ [ 'storage', true ], [ 'disk', true ], [ 'backup', true ], [ 'backup', false ], [ 'security', true ],
	    [ 'update', false ], [ 'power', false ], [ 'test', false ], [ 'other', false ] ], array_map([ $N, 'categorize' ], [
	    'Volume 1 (Storage Pool 1) on NAS01 has entered degraded mode', 'Drive 3 in NAS01 is failing. Back up your data', 'Hyper Backup task Offsite on NAS01 failed',
	    'Backup task Nightly on NAS01 has completed', 'IP address 203.0.113.9 of NAS01 has been blocked by SSH', 'A new DSM update is available for NAS01',
	    'NAS01 was restarted', 'This is a test message from NAS01', 'Hello' ]));
	$this->check('synology: a notification\'s text: the `text` parameter, else another; the other fields kept; a form body', [
	    [ 'Volume 1 crashed', 'storage', true, [ 'host' => 'NAS01' ] ], [ 'Drive 2 is failing', 'disk' ] ], [
	    (fn($x) => [ $x['text'], $x['category'], $x['problem'], $x['fields'] ])($N::notification([ 'text' => 'Volume 1 crashed', 'host' => 'NAS01' ], '')),
	    (fn($x) => [ $x['text'], $x['category'] ])($N::notification([ 'message' => 'Drive 2 is failing' ], '')) ]);

	$dir = sys_get_temp_dir() . '/qflows-check-syno-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('synology', '../../plugins/ITOps/tests/SynologyTestServer.php', $dir, [ 'SYNO_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$S::$testing = true;
	$pollBefore = [ $S::$poll, \plugins\ITOps\models\node\NodeAppSynoBackup::$poll ];
	$S::$poll = 0;
	\plugins\ITOps\models\node\NodeAppSynoBackup::$poll = 0;
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$put = fn($name, $data) => file_put_contents("{$dir}/{$name}.json", json_encode($data, JSON_UNESCAPED_SLASHES));
	$fs = fn() => json_decode((string)@file_get_contents("{$dir}/fs.json"), true) ?: [];
	$f = fn($text) => [ 'dir' => false, 'data' => base64_encode($text), 'mtime' => 1790000000 ];
	$put('fs', [ '/docs' => [ 'dir' => true ], '/photos' => [ 'dir' => true ], '/docs/a.pdf' => $f('%PDF-1.4 a'), '/docs/b.txt' => $f('bee'), '/docs/reports' => [ 'dir' => true ],
	    '/docs/reports/q3.pdf' => $f('%PDF q3'), '/docs/note.txt' => $f('old') ]);
	$storage = fn($pool = 'normal', $disk = 'normal', $smart = 'normal', $used = 400) => [
	    'volumes' => [ [ 'id' => 'volume_1', 'vol_path' => '/volume1', 'status' => $pool === 'normal' ? 'normal' : 'degraded', 'fs_type' => 'btrfs', 'size' => [ 'total' => '1000', 'used' => (string)$used ], 'pool_path' => 'reuse_1' ] ],
	    'storagePools' => [ [ 'id' => 'reuse_1', 'status' => $pool, 'device_type' => 'shr_with_1_disk_protect', 'size' => [ 'total' => '1000', 'used' => '1000' ], 'disks' => [ 'sata1', 'sata2' ] ] ],
	    'disks' => [ [ 'id' => 'sata1', 'longName' => 'Drive 1', 'model' => 'HAT5300', 'vendor' => 'Synology', 'serial' => 'S1', 'status' => 'normal', 'smart_status' => 'normal', 'temp' => 33, 'size_total' => '4000' ],
		[ 'id' => 'sata2', 'longName' => 'Drive 2', 'model' => 'HAT5300', 'vendor' => 'Synology', 'serial' => 'S2', 'status' => $disk, 'smart_status' => $smart, 'temp' => 35, 'size_total' => '4000' ] ] ];
	$put('storage', $storage());
	$put('tasks', [ '1' => [ 'task_id' => 1, 'name' => 'Offsite', 'state' => 'backupable', 'status' => 'none', 'last_bkp_result' => 'done', 'last_bkp_time' => '2026/11/19 02:00' ],
	    '2' => [ 'task_id' => 2, 'name' => 'Local', 'state' => 'backupable', 'status' => 'none', 'last_bkp_result' => 'done', 'last_bkp_time' => '2026/11/19 03:00' ] ]);
	$creds = [];
	$wfs = [];
	$files = [];
	$started = [];
	try {
	    $make = function($user, $password = 'pw', $otp = '') use ($om, $port, &$creds) {
		$c = $om->create('CredentialSynology', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Synology ' . uniqid(),
		    'serverUrl' => "https://127.0.0.1:{$port}", 'username' => $user, 'password' => $password, 'otpSecret' => $otp ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('synology: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->serverUrl = "http://127.0.0.1:{$port}";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialSynology', (string)$c->_id);
	    };
	    $cred = $make('admin');
	    $user = $make('user');
	    $wrong = $make('admin', 'nope');
	    $otp = $make('otp', 'pw', 'JBSW Y3DP EHPK 3PXP');
	    $noOtp = $make('otp');
	    if (!$cred || !$user || !$wrong || !$otp || !$noOtp)
		return;
	    $bad = $om->create('CredentialSynology', [ 'name' => 'x', 'serverUrl' => 'http://nas.local:5000', 'username' => 'a b', 'password' => 'p', 'otpSecret' => 'not-base32!' ]);
	    $bad->validates();
	    $requests();
	    $test = $cred->test();
	    $sent = $requests();
	    $login = array_values(array_filter($sent, fn($r) => $r['method'] === 'login'))[0] ?? [];
	    $info = array_values(array_filter($sent, fn($r) => $r['api'] === 'SYNO.Core.System'))[0] ?? [];
	    $this->check('synology: the form (https, an account without spaces, a base32 secret); Test: the APIs read, signed in (version 7, the webui session, a sid), the model and '
		. 'DSM version with the session\'s token, signed out last', [
		[ 'serverUrl', 'username', 'otpSecret' ], 'connected: DS923+, DSM 7.2.2-72806 Update 3; signed in as admin', [ '/webapi/query.cgi', '7', 'webui', 'sid', null ],
		[ 'sid-admin', 'tok-admin' ], 'logout' ], [
		array_values(array_intersect([ 'serverUrl', 'username', 'otpSecret' ], array_keys((array)$bad->errors()))), $test,
		[ $sent[0]['path'] ?? null, $login['version'] ?? null, $login['params']['session'] ?? null, $login['params']['format'] ?? null, $login['params']['otp_code'] ?? null ],
		[ $info['sid'] ?? null, $info['token'] ?? null ], end($sent)['method'] ?? null ]);
	    $this->check('synology: Test: another user (not an administrator) signed in all the same; a wrong password; two-factor: refused without the secret, the code made from it', [
		'signed in as user (not an administrator: the Files steps only)', true, true, 'connected: DS923+, DSM 7.2.2-72806 Update 3; signed in as otp', 6 ], [
		$user->test(), str_contains($thrown(fn() => $wrong->test()), 'the account or password is refused'), str_contains($thrown(fn() => $noOtp->test()), 'two-factor secret'),
		$otp->test(), strlen((string)((array_values(array_filter($requests(), fn($r) => isset($r['params']['otp_code'])))[0] ?? [])['params']['otp_code'] ?? '')) ]);

	    $id = (string)$cred->_id;
	    $step = function($fq, $cfg, $vars = [], $credId = null) use ($n, $e, $id, $requests) {
		$requests();
		$exe = $this->runFlow("syno {$fq}", [ $n('m', 'trigger::manual'), $n('c', $fq, $cfg + [ 'credential' => $credId ?? $id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'c'), $e('e2', 'c', 'err', 1) ], $vars);
		return [ $exe, $requests() ];
	    };
	    $of = fn($sent, $api, $method) => array_values(array_filter($sent, fn($r) => $r['api'] === $api && $r['method'] === $method))[0] ?? [];

	    //files
	    [ $exe, ] = $step('app::syno-files', [ 'operation' => 'shares', 'variable' => 'f' ]);
	    [ $ls, $sent ] = $step('app::syno-files', [ 'operation' => 'list', 'folder' => '/docs', 'pattern' => '*.pdf', 'filetype' => 'file', 'variable' => 'f' ]);
	    [ $usr, ] = $step('app::syno-files', [ 'operation' => 'list', 'folder' => '/docs', 'variable' => 'f' ], [], (string)$user->_id);
	    $this->check('synology: Files: shared folders with their space; a folder by pattern, files only (the parameters sent); another user too', [
		[ [ 'docs', 'photos' ], 500 ], [ [ 'a.pdf' ], '/docs/a.pdf', 10, '/volume1/docs/a.pdf' ], [ '/docs', '*.pdf', 'file', '0', '500' ], 4 ], [
		[ array_column($exe?->getVariable('f')['items'] ?? [], 'name'), $exe?->getVariable('f')['items'][0]['free'] ?? null ],
		[ array_column($ls?->getVariable('f')['items'] ?? [], 'name'), $ls?->getVariable('f')['items'][0]['path'] ?? null, $ls?->getVariable('f')['items'][0]['size'] ?? null,
		    $ls?->getVariable('f')['items'][0]['realPath'] ?? null ],
		(fn($r) => [ $r['params']['folder_path'] ?? null, $r['params']['pattern'] ?? null, $r['params']['filetype'] ?? null, $r['params']['offset'] ?? null, $r['params']['limit'] ?? null ])($of($sent, 'SYNO.FileStation.List', 'list')),
		$usr?->getVariable('f')['count'] ?? null ]);
	    [ $exe, $sent ] = $step('app::syno-files', [ 'operation' => 'search', 'folder' => '/docs', 'extension' => '.pdf', 'variable' => 's' ]);
	    $this->check('synology: Files search: under the folder and its subfolders, by extension; read until finished; stopped and cleaned', [ [ '/docs/a.pdf', '/docs/reports/q3.pdf' ], true,
		[ 'pdf', 'true' ], [ 'stop', 'clean' ] ], [ array_column($exe?->getVariable('s')['items'] ?? [], 'path'), $exe?->getVariable('s')['finished'] ?? null,
		[ $of($sent, 'SYNO.FileStation.Search', 'start')['params']['extension'] ?? null, $of($sent, 'SYNO.FileStation.Search', 'start')['params']['recursive'] ?? null ],
		array_values(array_intersect(array_column($sent, 'method'), [ 'stop', 'clean' ])) ]);
	    [ $up, ] = $step('app::syno-files', [ 'operation' => 'upload', 'folder' => '/docs', 'source' => 'text', 'name' => 'note.txt', 'content' => 'hello {{ who }}', 'variable' => 'u' ], [ 'who' => 'NAS' ]);
	    [ $rep, ] = $step('app::syno-files', [ 'operation' => 'upload', 'folder' => '/docs', 'source' => 'text', 'name' => 'b.txt', 'content' => 'new bee', 'conflict' => 'replace', 'variable' => 'u' ]);
	    [ $no, ] = $step('app::syno-files', [ 'operation' => 'upload', 'folder' => '/docs', 'source' => 'text', 'name' => 'a.pdf', 'content' => 'x', 'conflict' => 'fail' ]);
	    [ $deep, ] = $step('app::syno-files', [ 'operation' => 'upload', 'folder' => '/docs/new/deep', 'source' => 'text', 'name' => 'x.json', 'content' => '{{ obj }}', 'variable' => 'u' ], [ 'obj' => [ 'a' => 1 ] ]);
	    $now = $fs();
	    $this->check('synology: Files upload: a name that exists renamed (note (2).txt); replaced; refused (the error output, DSM\'s words); its folders made; an object as JSON', [
		'/docs/note (2).txt', 'hello NAS', 'new bee', 'error', true, true ], [
		$up?->getVariable('u')['path'] ?? null, base64_decode($now['/docs/note (2).txt']['data'] ?? ''), base64_decode($now['/docs/b.txt']['data'] ?? ''), $no?->getVariable('route'),
		str_contains((string)self::logLine($no, 'Synology Files upload: '), 'the file exists already'), str_contains(base64_decode($now['/docs/new/deep/x.json']['data'] ?? ''), '"a": 1') ]);
	    [ $dl, ] = $step('app::syno-files', [ 'operation' => 'download', 'path' => '/docs/reports/q3.pdf', 'variable' => 'd' ]);
	    [ $dlNo, ] = $step('app::syno-files', [ 'operation' => 'download', 'path' => '/docs/none.pdf', 'variable' => 'd' ]);
	    $file = ($fid = $dl?->getVariable('d')['id'] ?? null) ? $om->findOne('File', [ '_id' => $fid ]) : null;
	    if ($file)
		$files[] = $file;
	    $this->check('synology: Files download: saved in the project Files; a missing file: the error output', [ 'q3.pdf', '%PDF q3', 'error' ],
		[ $dl?->getVariable('d')['filename'] ?? null, $file?->getContents(), $dlNo?->getVariable('route') ]);
	    [ $mk, ] = $step('app::syno-files', [ 'operation' => 'createFolder', 'folder' => '/docs', 'name' => '2026', 'variable' => 'r' ]);
	    [ $rn, ] = $step('app::syno-files', [ 'operation' => 'rename', 'path' => '/docs/b.txt', 'name' => 'bee.txt', 'variable' => 'r' ]);
	    [ $cp, ] = $step('app::syno-files', [ 'operation' => 'copy', 'paths' => "/docs/a.pdf\n/docs/bee.txt", 'target' => '/docs/2026', 'variable' => 'r' ]);
	    [ $mv, $sent ] = $step('app::syno-files', [ 'operation' => 'move', 'paths' => '{{ list }}', 'target' => '/photos', 'variable' => 'r' ], [ 'list' => [ [ 'path' => '/docs/reports' ] ] ]);
	    $move = $of($sent, 'SYNO.FileStation.CopyMove', 'start');
	    [ $rm, ] = $step('app::syno-files', [ 'operation' => 'delete', 'paths' => '/docs/2026/bee.txt', 'variable' => 'r' ]);
	    [ $zip, ] = $step('app::syno-files', [ 'operation' => 'compress', 'paths' => '/docs/a.pdf', 'destFile' => '/docs/a.zip', 'variable' => 'z' ]);
	    $now = $fs();
	    $this->check('synology: Files: a folder made, renamed, copied (waited for), moved (remove_src), deleted, compressed: the NAS\'s files after', [
		'/docs/2026', '/docs/bee.txt', [ '/docs/2026/a.pdf' ], [ '["/docs/reports"]', 'true', '/photos' ], [ true, false, false ], true, [ true, true ] ], [
		$mk?->getVariable('r')['path'] ?? null, $rn?->getVariable('r')['path'] ?? null, array_values(array_filter(array_keys($now), fn($x) => str_starts_with($x, '/docs/2026/'))),
		[ $move['params']['path'] ?? null, $move['params']['remove_src'] ?? null, $move['params']['dest_folder_path'] ?? null ],
		[ isset($now['/photos/reports/q3.pdf']), isset($now['/docs/reports']), isset($now['/docs/2026/bee.txt']) ], isset($now['/docs/a.zip']),
		[ $cp?->getVariable('r')['finished'] ?? null, $zip?->getVariable('z')['finished'] ?? null ] ]);
	    [ $badDate, ] = $step('app::syno-files', [ 'operation' => 'shareLink', 'path' => '/docs/a.pdf', 'expires' => 'next week' ]);
	    [ $link, $sent ] = $step('app::syno-files', [ 'operation' => 'shareLink', 'path' => '/docs/a.pdf', 'expires' => '2026-12-31', 'linkPassword' => 's3', 'variable' => 'l' ]);
	    $this->check('synology: Files sharing link: a date not YYYY-MM-DD refused; the link with its expiry and password', [ 'error', 'https://gofile.me/abc/L1', [ '2026-12-31', true ] ], [
		$badDate?->getVariable('route'), $link?->getVariable('l')['url'] ?? null,
		[ $of($sent, 'SYNO.FileStation.Sharing', 'create')['params']['date_expired'] ?? null, $link?->getVariable('l')['has_password'] ?? null ] ]);

	    //system, storage
	    [ $si, ] = $step('app::syno-system', [ 'operation' => 'info', 'variable' => 'i' ]);
	    [ $siUser, ] = $step('app::syno-system', [ 'operation' => 'info' ], [], (string)$user->_id);
	    $put('update', [ 'available' => true, 'version' => 'DSM 7.2.2-72806 Update 4' ]);
	    [ $upd, ] = $step('app::syno-system', [ 'operation' => 'update', 'variable' => 'u' ]);
	    [ $pk, ] = $step('app::syno-system', [ 'operation' => 'packages', 'variable' => 'p' ]);
	    [ $pkBad, ] = $step('app::syno-system', [ 'operation' => 'startPackage', 'package' => 'Nope' ]);
	    [ $boot, $sent ] = $step('app::syno-system', [ 'operation' => 'reboot' ]);
	    $this->check('synology: System: info; another user: the error output (no permission); an update; packages and their state; an unknown package; restart refused without '
		. 'the confirmation (nothing sent)', [ 'DS923+', 'error', true, [ true, 'DSM 7.2.2-72806 Update 4' ], [ [ 'HyperBackup', true ], [ 'SynologyDrive', false ] ], 'error', 'error', [] ], [
		$si?->getVariable('i')['model'] ?? null, $siUser?->getVariable('route'), str_contains((string)self::logLine($siUser, 'Synology System info: '), 'has no permission'),
		[ $upd?->getVariable('u')['available'] ?? null, $upd?->getVariable('u')['version'] ?? null ],
		array_map(fn($x) => [ $x['id'], $x['running'] ], $pk?->getVariable('p')['items'] ?? []), $pkBad?->getVariable('route'), $boot?->getVariable('route'),
		array_values(array_filter($sent, fn($r) => $r['method'] === 'reboot')) ]);
	    $put('storage', $storage('degraded', 'failing', 'abnormal', 950));
	    [ $ov, ] = $step('app::syno-storage', [ 'operation' => 'overview', 'variable' => 'o' ]);
	    [ $vols, ] = $step('app::syno-storage', [ 'operation' => 'volumes', 'variable' => 'v' ]);
	    [ $mkShare, $sent ] = $step('app::syno-storage', [ 'operation' => 'createShare', 'share' => 'projects', 'volume' => '/volume1', 'description' => 'Projects', 'variable' => 's' ]);
	    [ $rmShare, ] = $step('app::syno-storage', [ 'operation' => 'deleteShare', 'share' => 'projects' ]);
	    $shareinfo = json_decode((string)($of($sent, 'SYNO.Core.Share', 'create')['params']['shareinfo'] ?? ''), true);
	    $this->check('synology: Storage: the problems (a degraded volume and pool, a failing drive with its S.M.A.R.T.); volumes with their use; a shared folder made (shareinfo as JSON); '
		. 'deleting one refused without the confirmation', [ false, [ 'volume /volume1: degraded', 'pool reuse_1: degraded', 'drive Drive 2: failing (S.M.A.R.T. abnormal)' ],
		[ 95.0, false ], [ 'projects', '/volume1', 'Projects', true ], 'error' ], [
		$ov?->getVariable('o')['ok'] ?? null, $ov?->getVariable('o')['problems'] ?? null,
		[ $vols?->getVariable('v')['items'][0]['usedPercent'] ?? null, $vols?->getVariable('v')['items'][0]['ok'] ?? null ],
		[ $shareinfo['name'] ?? null, $shareinfo['vol_path'] ?? null, $shareinfo['desc'] ?? null, $shareinfo['enable_recycle_bin'] ?? null ], $rmShare?->getVariable('route') ]);
	    $put('storage', $storage());

	    //users, backup, API
	    [ $cu, $sent ] = $step('app::syno-users', [ 'operation' => 'create', 'user' => 'jane', 'password' => '{{ pw }}', 'email' => 'jane@example.com', 'joinGroups' => 'users, administrators',
		'variable' => 'u' ], [ 'pw' => 'S3cret!x' ]);
	    [ $dis, ] = $step('app::syno-users', [ 'operation' => 'disable', 'user' => 'jane' ]);
	    [ $mem, ] = $step('app::syno-users', [ 'operation' => 'members', 'group' => 'administrators', 'variable' => 'm' ]);
	    [ $self, ] = $step('app::syno-users', [ 'operation' => 'delete', 'user' => 'ADMIN' ]);
	    [ $ul, ] = $step('app::syno-users', [ 'operation' => 'list', 'variable' => 'l' ]);
	    $users = array_column(json_decode((string)@file_get_contents("{$dir}/users.json"), true) ?: [], 'expired', 'name');
	    $this->check('synology: Users: created with its password and groups; disabled (expired now); a group\'s members; the credential\'s own account not deleted; listed', [
		'S3cret!x', [ 'users', 'administrators' ], 'now', [ 'admin', 'jane' ], 'error', 2 ], [
		$of($sent, 'SYNO.Core.User', 'create')['password'] ?? null, $cu?->getVariable('u')['groups'] ?? null, $users['jane'] ?? null,
		array_column($mem?->getVariable('m')['items'] ?? [], 'name'), $self?->getVariable('route'), $ul?->getVariable('l')['count'] ?? null ]);
	    [ $bt, ] = $step('app::syno-backup', [ 'operation' => 'tasks', 'variable' => 't' ]);
	    [ $run, $sent ] = $step('app::syno-backup', [ 'operation' => 'run', 'task' => 'offsite', 'wait' => true, 'variable' => 'r' ]);
	    [ $noTask, ] = $step('app::syno-backup', [ 'operation' => 'run', 'task' => 'Cloud' ]);
	    $this->check('synology: Backup: the tasks; one run by name (any case) and waited for: finished, done at a new time; an unknown name: the error output', [
		[ 'Offsite', 'Local' ], [ true, 'done', '2026/11/20 12:00', true ], '1', 'error' ], [
		array_column($bt?->getVariable('t')['items'] ?? [], 'name'),
		[ $run?->getVariable('r')['finished'] ?? null, $run?->getVariable('r')['lastResult'] ?? null, $run?->getVariable('r')['lastTime'] ?? null, $run?->getVariable('r')['ok'] ?? null ],
		$of($sent, 'SYNO.Backup.Task', 'backup')['params']['task_id'] ?? null, $noTask?->getVariable('route') ]);
	    [ $call, $sent ] = $step('app::syno-api', [ 'operation' => 'request', 'api' => 'SYNO.Core.System', 'method' => 'info', 'callParams' => '{"type": "{{ t }}"}', 'variable' => 'a' ], [ 't' => 'storage' ]);
	    [ $apis, ] = $step('app::syno-api', [ 'operation' => 'apis', 'prefix' => 'syno.backup', 'variable' => 'a' ]);
	    $put('missing', [ 'SYNO.Backup.Task' ]);
	    [ $gone, $goneSent ] = $step('app::syno-backup', [ 'operation' => 'tasks' ]);
	    $put('missing', []);
	    $this->check('synology: API: a call with its parameters, at the newest version the NAS has; the NAS\'s APIs by prefix; an API the NAS lacks: refused before any call', [
		[ 'DS923+', 'storage', '3' ], [ [ 'name' => 'SYNO.Backup.Task', 'path' => 'entry.cgi', 'minVersion' => 1, 'maxVersion' => 1 ] ], true, [] ], [
		[ $call?->getVariable('a')['model'] ?? null, $of($sent, 'SYNO.Core.System', 'info')['params']['type'] ?? null, $of($sent, 'SYNO.Core.System', 'info')['version'] ?? null ],
		$apis?->getVariable('a')['items'] ?? null, str_contains((string)self::logLine($gone, 'Synology Backup tasks: '), 'this NAS has no SYNO.Backup.Task'),
		array_values(array_filter($goneSent, fn($r) => $r['api'] === 'SYNO.Backup.Task')) ]);

	    //the NAS trigger
	    $wf = $this->workflow('syno nas', [ $n('t', 'trigger::synology-nas', [ 'credential' => $id, 'events' => array_keys(\plugins\ITOps\models\node\NodeTriggerSynology::EVENTS),
		'spaceAt' => 90, 'tempAt' => 60, 'runs' => 'each', 'variable' => 'ev', 'mode' => 'interval', 'every' => 10, 'every_unit' => 'minutes' ]),
		$n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ev.event }}' ]), $e('e1', 't', 's') ]);
	    $this->check('synology: the NAS trigger\'s workflow is valid', true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $T = \plugins\ITOps\models\node\NodeTriggerSynology::class;
	    $node = $wf->nodes['t'];
	    $put('update', [ 'available' => false ]);
	    $put('temp', [ 45 ]);
	    [ $d0, $r0 ] = $T::check($wf, $node, []);
	    $put('storage', $storage('degraded', 'failing', 'abnormal', 950));
	    $put('tasks', [ '1' => [ 'task_id' => 1, 'name' => 'Offsite', 'status' => 'none', 'last_bkp_result' => 'failed', 'last_bkp_time' => '2026/11/20 02:00' ],
		'2' => [ 'task_id' => 2, 'name' => 'Local', 'status' => 'none', 'last_bkp_result' => 'done', 'last_bkp_time' => '2026/11/20 03:00' ] ]);
	    $put('update', [ 'available' => true, 'version' => 'DSM 7.3' ]);
	    $put('temp', [ 71 ]);
	    [ $d1, $r1 ] = $T::check($wf, $node, $d0);
	    [ $d2, $r2 ] = $T::check($wf, $node, $d1);
	    $put('storage', $storage());
	    [ $d3, $r3 ] = $T::check($wf, $node, $d2);
	    $put('missing', [ 'SYNO.API.Auth' ]);
	    [ $d4, $r4 ] = $T::check($wf, $node, $d3);
	    [ $d5, $r5 ] = $T::check($wf, $node, $d4);
	    $put('missing', []);
	    [ $d6, $r6 ] = $T::check($wf, $node, $d5);
	    $started = array_merge($started, $r0, $r1, $r2, $r3, $r4, $r5, $r6);
	    $show = fn($runs) => array_map(fn($x) => [ $x->getVariable('ev')['event'] ?? null, $x->getVariable('ev')['name'] ?? null ], $runs);
	    $this->check('synology: NAS trigger: the first check notes; then a degraded volume and pool, a failing drive, a volume filling up, a failed backup, an update, the heat '
		. '(one run each); nothing new: none; normal again; unreachable once; reachable again', [
		[], [ [ 'volume', '/volume1' ], [ 'volume', 'reuse_1' ], [ 'disk', 'Drive 2' ], [ 'space', '/volume1' ], [ 'backup', 'Offsite' ], [ 'update', '127.0.0.1' ],
		    [ 'temperature', '127.0.0.1' ] ], [], [ [ 'recovered', '/volume1' ], [ 'recovered', 'reuse_1' ], [ 'recovered', 'Drive 2' ] ], [ [ 'unreachable', '127.0.0.1' ] ], [],
		[ [ 'reachable', '127.0.0.1' ] ] ], [ $show($r0), $show($r1), $show($r2), $show($r3), $show($r4), $show($r5), $show($r6) ]);
	    $this->check('synology: NAS trigger: the runs queued, at the trigger; the event\'s detail, the NAS, the item', [ 'new', 'synology-nas', 'drive Drive 2: failing (S.M.A.R.T. abnormal)',
		'DS923+', 'S2' ], [ $r1[0]?->state, $r1[0]?->trigger, $r1[2]?->getVariable('ev')['detail'] ?? null, $r1[2]?->getVariable('ev')['nas']['model'] ?? null,
		$r1[2]?->getVariable('ev')['item']['serial'] ?? null ]);
	    $put('storage', $storage('normal', 'failing', 'normal'));
	    $now = $T::startNow($wf, $node);
	    $started = array_merge($started, $now['executions'] ?? []);
	    $this->check('synology: NAS trigger: Run in the designer: the NAS as it is now', [ 1, 'disk', 'Drive 2' ], [ count($now['executions'] ?? []),
		($now['executions'][0] ?? null)?->getVariable('ev')['event'] ?? null, ($now['executions'][0] ?? null)?->getVariable('ev')['name'] ?? null ]);

	    //the notification trigger
	    $init = $N::initConfig();
	    $host = (string)parse_url($init['url'], PHP_URL_HOST);
	    $moved = $N::forTransfer([ 'token' => $init['token'], 'secret' => $init['secret'], 'url' => 'https://old/x', 'setup' => 'old' ]);
	    $this->check('synology: notification: the address holds the token and the key; the setup shows it; an import makes both again from the token and the secret', [
		true, true, $init['url'], true ], [ str_contains($init['url'], '/synology/' . $init['token'] . '?key=' . $init['secret']), str_contains($init['setup'], $init['url']),
		$moved['url'], str_contains($moved['setup'], $init['url']) ]);
	    $wf = $this->workflow('syno notification', [ $n('t', 'trigger::synology-notification', $init + [ 'categories' => [ 'storage', 'disk' ], 'variable' => 'ev' ]),
		$n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ev.category }}' ]), $e('e1', 't', 's') ]);
	    $this->check('synology: notification: the workflow is valid', true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $version = $om->create('WfVersion', [ 'aid' => $wf->aid, 'project' => $wf->project, 'wf' => $wf->_id, 'number' => 1, 'name' => (string)$wf->name, 'nodes' => $wf->nodes ]);
	    $version->save();
	    $wf->version = $version->_id;
	    $wf->status = \models\Workflow::PUBLISHED;
	    $wf->save();
	    $post = function($url, $text) use ($host) {
		$ch = curl_init($url);
		curl_setopt_array($ch, [ CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode([ 'text' => $text ]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
		    CURLOPT_HTTPHEADER => [ 'Content-Type: application/json' ], CURLOPT_RESOLVE => [ "{$host}:443:127.0.0.1" ], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0 ]);
		$answer = json_decode((string)curl_exec($ch), true);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		return [ $status, $answer ];
	    };
	    $base = strtok($init['url'], '?');
	    [ $status ] = $post("{$base}?key=wrong", 'Volume 1 crashed');
	    if ($status === 0) {
		echo "  skipped: the notification trigger's address (this installation's web server cannot be reached from here)\n";
	    } else {
		[ $test, $testAnswer ] = $post($init['url'], 'This is a test message from NAS01');
		[ $ok, ] = $post($init['url'], "Volume 1 on NAS01 has entered degraded mode\nPlease repair it");
		$runs = iterator_to_array($om->find('WfExecution', [ 'wf' => $wf->_id, 'trigger' => 'synology-notification' ]), false);
		$started = array_merge($started, $runs);
		$this->check('synology: notification: a wrong key refused; DSM\'s test message answered, no run (not chosen); a storage one: one run with its title, category, problem', [
		    401, [ 200, 'ignored', 'test' ], 200, 1, [ 'Volume 1 on NAS01 has entered degraded mode', 'storage', true ] ], [
		    $status, [ $test, $testAnswer['state'] ?? null, $testAnswer['category'] ?? null ], $ok, count($runs),
		    [ $runs[0]?->getVariable('ev')['title'] ?? null, $runs[0]?->getVariable('ev')['category'] ?? null, $runs[0]?->getVariable('ev')['problem'] ?? null ] ]);
	    }
	} finally {
	    $S::$testing = false;
	    [ $S::$poll, \plugins\ITOps\models\node\NodeAppSynoBackup::$poll ] = $pollBefore;
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf) {
		\models\TriggerState::beforeWorkflowDelete($wf);
		foreach($om->find('WfVersion', [ 'wf' => $wf->_id ]) as $v)
		    $v->delete();
	    }
	    foreach($files as $x)
		$x->delete();
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $x)
		@unlink($x);
	    @rmdir($dir);
	}
    },

    //VMware vCenter against a fake vCenter 8.0 U3 (tests/VmwareTestServer.php; plugins\ITOps\util\VSphere::$testing: http, no address guard): the client (the address,
    //the ids' types, vCenter's messages, a new sign-in after a 401), the credential's test (both APIs), the steps (VMs by name, * patterns, filters by name; power already so; a guest
    //shutdown waited for, powered off when stuck; clone into a folder; snapshots: the tree, create, a failed task, remove, prune; datastores low on space; maintenance mode;
    //alarms named, acknowledged; the API), the alarm trigger (its first check notes the alarms; new ones and status changes start runs, once; acknowledged ones do not; a recovery)
    'vmware' => function() {
	$V = \plugins\ITOps\util\VSphere::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$this->check('vmware: the address without a pasted path; the types of vCenter\'s ids; lists repeated in the query; vCenter\'s messages (REST, VI/JSON); an alarm\'s key', [
	    'https://vc.example', [ 'VirtualMachine', 'Folder', 'StoragePod', 'ClusterComputeResource', 'VirtualApp', 'ResourcePool', null ], 'names=a&names=b&power_states=POWERED_ON',
	    'Virtual machine is already in the desired state. (ALREADY_IN_DESIRED_STATE)', 'Cannot complete login. (InvalidLogin)', [ 'alarm-7', 'vm-42', 'VirtualMachine' ] ], [
	    $V::baseUrl(' https://vc.example/ui/ '), array_map([ $V, 'typeOf' ], [ 'vm-42', 'group-v4', 'group-p3', 'domain-c8', 'resgroup-v12', 'resgroup-9', 'web01' ]),
	    $V::query([ 'names' => [ 'a', 'b' ], 'power_states' => 'POWERED_ON', 'empty' => '' ]),
	    $V::message([ 'error_type' => 'ALREADY_IN_DESIRED_STATE', 'messages' => [ [ 'default_message' => 'Virtual machine is already in the desired state.' ] ] ]),
	    $V::message([ '_typeName' => 'InvalidLogin', 'faultMessage' => [ [ 'message' => 'Cannot complete login.' ] ] ]), \plugins\ITOps\models\node\NodeAppVmwareAlarms::keyOf('alarm-7.vm-42') ]);

	$dir = sys_get_temp_dir() . '/qflows-check-vmware-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('vmware', '../../plugins/ITOps/tests/VmwareTestServer.php', $dir, [ 'VC_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$V::$testing = true;
	$V::forgetSessions();
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$alarms = fn(array $list) => file_put_contents("{$dir}/alarms.json", json_encode($list));
	$alarm = fn($id, $entity, $type, $status, $ack = false) => [ 'alarm' => $id, 'entity' => $entity, 'type' => $type, 'status' => $status, 'ack' => $ack ];
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $e) {
		return $e->getMessage();
	    }
	};
	$creds = [];
	$wfs = [];
	$started = [];
	try {
	    $make = function($password) use ($om, $port, &$creds) {
		$c = $om->create('CredentialVmware', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: VMware ' . uniqid(),
		    'serverUrl' => "https://127.0.0.1:{$port}", 'username' => 'automation@vsphere.local', 'password' => $password ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('vmware: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->serverUrl = "http://127.0.0.1:{$port}";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialVmware', (string)$c->_id);
	    };
	    $cred = $make('s3cret');
	    $wrong = $make('nope');
	    if (!$cred || !$wrong)
		return;
	    $bad = $om->create('CredentialVmware', [ 'name' => 'x', 'serverUrl' => 'https://vc/ui', 'username' => 'a', 'password' => 's' ]);
	    $bad->validates();
	    $requests();
	    $this->check('vmware: the form (the server without a path); Test: the product, the datacenters, VI/JSON there; the user as Basic; a wrong password: said so', [
		[ 'serverUrl' ], 'connected to 127.0.0.1, VMware vCenter Server 8.0.3 build-24322831, 1 datacenter; snapshots, alarms and maintenance mode available',
		'POST /api/session', true ], [
		array_values(array_intersect([ 'serverUrl' ], array_keys((array)$bad->errors()))), $cred->test(), ($requests()[0]['method'] ?? '') . ' /api/session',
		str_contains($thrown(fn() => $wrong->test()), 'sign-in refused: Authentication required. (UNAUTHENTICATED): check the user and password') ]);

	    $id = (string)$cred->_id;
	    $step = function($fq, $cfg, $vars = []) use ($n, $e, $id, $requests) {
		$requests();
		$exe = $this->runFlow("vmware {$fq}", [ $n('m', 'trigger::manual'), $n('c', $fq, $cfg + [ 'credential' => $id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'c'), $e('e2', 'c', 'err', 1) ], $vars);
		return [ $exe, $requests() ];
	    };
	    $names = fn($exe, $var) => array_column((array)($exe?->getVariable($var)['items'] ?? []), 'name');

	    //VMs
	    [ $exe, ] = $step('app::vmware-vms', [ 'operation' => 'list', 'names' => 'web*', 'variable' => 'v' ]);
	    [ $on, $sent ] = $step('app::vmware-vms', [ 'operation' => 'list', 'state' => 'POWERED_ON', 'cluster' => 'Prod', 'variable' => 'v' ]);
	    $this->check('vmware: VMs list: a * pattern matched here; a power state and a cluster by name (its id sent)', [ [ 'web01', 'web02' ], [ 'db01', 'web01' ], [ 'domain-c8' ] ],
		[ $names($exe, 'v'), $names($on, 'v'), end($sent)['query']['clusters'] ?? null ]);
	    [ $exe, ] = $step('app::vmware-vms', [ 'operation' => 'get', 'vm' => '{{ which }}', 'variable' => 'v' ], [ 'which' => 'web01' ]);
	    [ $none, ] = $step('app::vmware-vms', [ 'operation' => 'get', 'vm' => 'ghost' ]);
	    $this->check('vmware: VMs get: by name (an expression), its id added; a name no VM has: the error output', [ 'vm-42', 'POWERED_ON', 'error', true ], [
		$exe?->getVariable('v')['vm'] ?? null, $exe?->getVariable('v')['power_state'] ?? null, $none?->getVariable('route'),
		str_contains((string)self::logLine($none, 'VMware VMs get: '), 'VM: none named `ghost`') ]);
	    [ $a1, ] = $step('app::vmware-vms', [ 'operation' => 'start', 'vm' => 'web01', 'variable' => 'p' ]);
	    [ $a2, $sent ] = $step('app::vmware-vms', [ 'operation' => 'start', 'vm' => 'vm-43', 'variable' => 'p' ]);
	    $this->check('vmware: VMs power on: on already is done (already); an id used as it is', [ [ 'POWERED_ON', true ], [ 'POWERED_ON', false ], [ 'start' ] ], [
		[ $a1?->getVariable('p')['state'] ?? null, $a1?->getVariable('p')['already'] ?? null ], [ $a2?->getVariable('p')['state'] ?? null, $a2?->getVariable('p')['already'] ?? null ],
		$sent[0]['query']['action'] ?? null ]);
	    [ $sd, ] = $step('app::vmware-vms', [ 'operation' => 'shutdown', 'vm' => 'web01', 'variable' => 'p' ]);
	    [ $stuck, ] = $step('app::vmware-vms', [ 'operation' => 'shutdown', 'vm' => 'db01', 'force' => true, 'timeout' => 12, 'variable' => 'p' ]);
	    [ $gst, ] = $step('app::vmware-vms', [ 'operation' => 'guest', 'vm' => 'web02', 'variable' => 'g' ]);
	    [ $start, ] = $step('app::vmware-vms', [ 'operation' => 'start', 'vm' => 'web01' ]);
	    [ $gst2, ] = $step('app::vmware-vms', [ 'operation' => 'guest', 'vm' => 'web01', 'variable' => 'g' ]);
	    $this->check('vmware: VMs shut down: waited for until off; a guest that does not stop, powered off at the timeout; guest information: without VMware Tools the error output, '
		. 'with them the identity and interfaces', [ 'POWERED_OFF', [ 'POWERED_OFF', true ], [ 'error', true ], [ '10.0.0.42', 1 ] ], [ $sd?->getVariable('p')['state'] ?? null,
		[ $stuck?->getVariable('p')['state'] ?? null, $stuck?->getVariable('p')['forced'] ?? null ],
		[ $gst?->getVariable('route'), str_contains((string)self::logLine($gst, 'VMware VMs guest: '), 'VMware Tools is not running in the guest.') ],
		[ $gst2?->getVariable('g')['identity']['ip_address'] ?? null, count($gst2?->getVariable('g')['interfaces'] ?? []) ] ]);
	    [ $cl, $sent ] = $step('app::vmware-vms', [ 'operation' => 'clone', 'vm' => 'web01', 'newName' => 'web03', 'cloneFolder' => 'Databases', 'powerOn' => true, 'variable' => 'c' ]);
	    [ $del, ] = $step('app::vmware-vms', [ 'operation' => 'delete', 'vm' => 'web03' ]);
	    $this->check('vmware: VMs clone: the source, the new name, the folder by name, powered on; deleting a VM that is on: the error output with vCenter\'s message', [
		[ 'vm-42', 'web03', [ 'folder' => 'group-v5' ], true ], 'web03', 'error', true ], [
		[ end($sent)['body']['source'] ?? null, end($sent)['body']['name'] ?? null, end($sent)['body']['placement'] ?? null, end($sent)['body']['power_on'] ?? null ],
		$cl?->getVariable('c')['name'] ?? null, $del?->getVariable('route'), str_contains((string)self::logLine($del, 'VMware VMs delete: '), '(NOT_ALLOWED_IN_CURRENT_STATE)') ]);

	    //sessions dropped by vCenter: a new sign-in, once
	    $st = json_decode((string)file_get_contents("{$dir}/state.json"), true);
	    $st['sessions'] = [];
	    file_put_contents("{$dir}/state.json", json_encode($st));
	    [ $exe, $sent ] = $step('app::vmware-vms', [ 'operation' => 'power', 'vm' => 'vm-42', 'variable' => 'p' ]);
	    $this->check('vmware: a session vCenter dropped (401): signed in again, the request repeated', [ 'POWERED_ON', [ 'GET /api/vcenter/vm/vm-42/power', 'POST /api/session', 'GET /api/vcenter/vm/vm-42/power' ] ],
		[ $exe?->getVariable('p')['state'] ?? null, array_map(fn($r) => "{$r['method']} {$r['path']}", $sent) ]);

	    //snapshots
	    [ $ls, ] = $step('app::vmware-snapshots', [ 'operation' => 'list', 'vm' => 'web01', 'variable' => 's' ]);
	    $items = $ls?->getVariable('s')['items'] ?? [];
	    $this->check('vmware: Snapshots list: the tree flattened, parents first, with depth and the current one', [
		[ [ 'Base', 0, null, false ], [ 'Before patch', 1, 'snapshot-1', false ], [ 'broken', 2, 'snapshot-2', true ] ] ],
		[ array_map(fn($s) => [ $s['name'], $s['depth'], $s['parent'], $s['current'] ], $items) ]);
	    [ $cr, $sent ] = $step('app::vmware-snapshots', [ 'operation' => 'create', 'vm' => 'web01', 'snapName' => 'Pre {{ t }}', 'quiesce' => true, 'variable' => 's' ], [ 't' => 'T-7' ]);
	    [ $bad, ] = $step('app::vmware-snapshots', [ 'operation' => 'revert', 'vm' => 'web01', 'snapshot' => 'broken' ]);
	    [ $rv, ] = $step('app::vmware-snapshots', [ 'operation' => 'revert', 'vm' => 'web01', 'snapshot' => 'Before patch', 'variable' => 's' ]);
	    $this->check('vmware: Snapshots create: the name (an expression), quiesced; the task waited for: the snapshot\'s id; a revert whose task fails: the error output with the task\'s message; '
		. 'a revert by name', [ [ 'Pre T-7', true, false ], true, 'error', true, 'snapshot-2' ], [
		[ $sent[1]['body']['name'] ?? null, $sent[1]['body']['quiesce'] ?? null, $sent[1]['body']['memory'] ?? null ], str_starts_with((string)($cr?->getVariable('s')['snapshot'] ?? ''), 'snapshot-'),
		$bad?->getVariable('route'), str_contains((string)self::logLine($bad, 'VMware Snapshots revert: '), 'failed: The snapshot\'s disk is damaged.'), $rv?->getVariable('s')['snapshot'] ?? null ]);
	    [ $rm, $sent ] = $step('app::vmware-snapshots', [ 'operation' => 'remove', 'vm' => 'web01', 'snapshot' => 'Base', 'variable' => 's' ]);
	    [ $pr, ] = $step('app::vmware-snapshots', [ 'operation' => 'prune', 'vm' => 'web01', 'days' => '7', 'variable' => 's' ]);
	    [ $ls, ] = $step('app::vmware-snapshots', [ 'operation' => 'list', 'vm' => 'web01', 'variable' => 's' ]);
	    $this->check('vmware: Snapshots remove: consolidated, its children kept; prune: only those older than the days; what is left', [ [ false, true ], [ 'Before patch' ], [ 'broken', 'Pre T-7' ] ], [
		[ $sent[2]['body']['removeChildren'] ?? null, $sent[2]['body']['consolidate'] ?? null ], array_column($pr?->getVariable('s')['removed'] ?? [], 'name'), $names($ls, 's') ]);
	    [ $all, ] = $step('app::vmware-snapshots', [ 'operation' => 'removeAll', 'vm' => 'web01', 'variable' => 's' ]);
	    [ $ls, ] = $step('app::vmware-snapshots', [ 'operation' => 'list', 'vm' => 'web01', 'variable' => 's' ]);
	    $this->check('vmware: Snapshots remove all; a VM without snapshots: an empty list', [ 'end', 0 ], [ $all?->state, $ls?->getVariable('s')['count'] ?? null ]);

	    //inventory
	    [ $ds, ] = $step('app::vmware-inventory', [ 'operation' => 'datastores', 'lowPercent' => 10, 'variable' => 'i' ]);
	    [ $hs, $sent ] = $step('app::vmware-inventory', [ 'operation' => 'hosts', 'cluster' => 'Prod', 'variable' => 'i' ]);
	    $this->check('vmware: Inventory datastores: free percent, low on space; hosts of a cluster by name', [ [ [ 'ds-ssd', 5.0, true ], [ 'ds-nfs', 50.0, false ] ], [ 'esx01.lab', 'esx02.lab' ], [ 'domain-c8' ] ], [
		array_map(fn($d) => [ $d['name'], $d['freePercent'], $d['lowOnSpace'] ], $ds?->getVariable('i')['items'] ?? []), $names($hs, 'i'), end($sent)['query']['clusters'] ?? null ]);
	    [ $m1, $sent ] = $step('app::vmware-inventory', [ 'operation' => 'enterMaintenance', 'host' => 'esx01.lab', 'variable' => 'm' ]);
	    [ $m2, ] = $step('app::vmware-inventory', [ 'operation' => 'enterMaintenance', 'host' => 'host-10', 'variable' => 'm' ]);
	    [ $m3, ] = $step('app::vmware-inventory', [ 'operation' => 'exitMaintenance', 'host' => 'host-11', 'variable' => 'm' ]);
	    [ $ver, ] = $step('app::vmware-inventory', [ 'operation' => 'version', 'variable' => 'v' ]);
	    $this->check('vmware: Inventory maintenance mode: entered (the task waited for), in it already: done; exited; vCenter\'s version', [
		[ true, false, 0 ], [ true, true ], [ false, false ], '8.0.3.00400' ], [
		[ $m1?->getVariable('m')['inMaintenance'] ?? null, $m1?->getVariable('m')['already'] ?? null, $sent[2]['body']['timeout'] ?? null ],
		[ $m2?->getVariable('m')['inMaintenance'] ?? null, $m2?->getVariable('m')['already'] ?? null ], [ $m3?->getVariable('m')['inMaintenance'] ?? null, $m3?->getVariable('m')['already'] ?? null ],
		$ver?->getVariable('v')['version'] ?? null ]);

	    //alarms
	    $alarms([ $alarm('alarm-8', 'datastore-20', 'Datastore', 'yellow'), $alarm('alarm-7', 'vm-42', 'VirtualMachine', 'red'), $alarm('alarm-9', 'host-11', 'HostSystem', 'red', true) ]);
	    [ $al, ] = $step('app::vmware-alarms', [ 'operation' => 'list', 'status' => 'yellow', 'variable' => 'a' ]);
	    [ $ack, $sent ] = $step('app::vmware-alarms', [ 'operation' => 'acknowledge', 'alarm' => '{{ a.items[0] }}' ], [ 'a' => $al?->getVariable('a') ]);
	    [ $al2, ] = $step('app::vmware-alarms', [ 'operation' => 'list', 'status' => 'red', 'acknowledged' => true, 'entityType' => 'VirtualMachine', 'variable' => 'a' ]);
	    $this->check('vmware: Alarms list: red first, acknowledged ones left out, named (the alarm, the object); acknowledge an alarm of the list; acknowledged ones when chosen, of VMs', [
		[ [ 'alarm-7|vm-42', 'Virtual machine CPU usage', 'web01', 'red' ], [ 'alarm-8|datastore-20', 'Datastore usage on disk', 'ds-ssd', 'yellow' ] ],
		[ 'Alarm', 'VirtualMachine', 'vm-42' ], [ [ 'alarm-7|vm-42', true ] ] ], [
		array_map(fn($a) => [ $a['key'], $a['alarm']['name'], $a['entity']['name'], $a['status'] ], $al?->getVariable('a')['items'] ?? []),
		[ end($sent)['body']['alarm']['type'] ?? null, end($sent)['body']['entity']['type'] ?? null, end($sent)['body']['entity']['value'] ?? null ],
		array_map(fn($a) => [ $a['key'], $a['acknowledged'] ], $al2?->getVariable('a')['items'] ?? []) ]);

	    //API
	    [ $r1, ] = $step('app::vmware-api', [ 'operation' => 'rest', 'method' => 'GET', 'path' => '/api/vcenter/vm', 'query' => '{"names": "db01"}', 'variable' => 'r' ]);
	    [ $r2, ] = $step('app::vmware-api', [ 'operation' => 'vim', 'method' => 'GET', 'vimPath' => 'VirtualMachine/vm-42/name', 'variable' => 'r' ]);
	    [ $r3, ] = $step('app::vmware-api', [ 'operation' => 'vim', 'method' => 'POST', 'vimPath' => 'VirtualMachine/vm-42/CreateSnapshot_Task', 'body' => '{"name": "x", "memory": false, "quiesce": false}',
		'waitTask' => true, 'variable' => 'r' ]);
	    $this->check('vmware: API: a REST request with a query; a VI/JSON property; a VI/JSON method whose task is waited for', [ 'vm-44', 'web01', 'success' ],
		[ $r1?->getVariable('r')[0]['vm'] ?? null, $r2?->getVariable('r'), $r3?->getVariable('r')['state'] ?? null ]);

	    //the alarm trigger
	    $wf = $this->workflow('vmware alarm', [ $n('t', 'trigger::vmware', [ 'credential' => $id, 'status' => 'red', 'recoveries' => true, 'runs' => 'each', 'variable' => 'ev',
		'mode' => 'interval', 'every' => 2, 'every_unit' => 'minutes' ]), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ev.event }}' ]), $e('e1', 't', 's') ]);
	    $this->check('vmware: the alarm trigger\'s workflow is valid', true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $T = \plugins\ITOps\models\node\NodeTriggerVmware::class;
	    $node = $wf->nodes['t'];
	    $alarms([ $alarm('alarm-7', 'vm-42', 'VirtualMachine', 'red') ]);
	    [ $d0, $r0 ] = $T::check($wf, $node, []);
	    $alarms([ $alarm('alarm-7', 'vm-42', 'VirtualMachine', 'red'), $alarm('alarm-9', 'host-10', 'HostSystem', 'red'), $alarm('alarm-8', 'datastore-20', 'Datastore', 'yellow'),
		$alarm('alarm-7', 'vm-44', 'VirtualMachine', 'red', true) ]);
	    [ $d1, $r1 ] = $T::check($wf, $node, $d0);
	    [ $d2, $r2 ] = $T::check($wf, $node, $d1);
	    $alarms([ $alarm('alarm-7', 'vm-42', 'VirtualMachine', 'red'), $alarm('alarm-8', 'datastore-20', 'Datastore', 'red') ]);
	    [ $d3, $r3 ] = $T::check($wf, $node, $d2);
	    $started = array_merge($started, $r0, $r1, $r2, $r3);
	    $show = fn($runs) => array_map(fn($x) => [ $x->getVariable('ev')['event'] ?? null, $x->getVariable('ev')['alarm']['name'] ?? null, $x->getVariable('ev')['entity']['name'] ?? null,
		$x->getVariable('ev')['status'] ?? null ], $runs);
	    $this->check('vmware: alarm trigger: the first check notes what is triggered; then new red alarms (not yellow, not acknowledged) start runs, once; one that turns red, and a recovery', [
		[], [ [ 'alarm', 'Host connection and power state', 'esx01.lab', 'red' ] ], [], [ [ 'alarm', 'Datastore usage on disk', 'ds-ssd', 'red' ], [ 'recovery', 'Host connection and power state', 'esx01.lab', 'red' ] ] ],
		[ $show($r0), $show($r1), $show($r2), $show($r3) ]);
	    $this->check('vmware: alarm trigger: the runs queued, at the trigger', [ 'new', 'vmware' ], [ $r1[0]?->state, $r1[0]?->trigger ]);
	    //an alarm noted by the first check (alarm-7 on vm-42) that clears: no recovery
	    $alarms([ $alarm('alarm-8', 'datastore-20', 'Datastore', 'red') ]);
	    [ $d4, $r4 ] = $T::check($wf, $node, $d3);
	    $started = array_merge($started, $r4);
	    $this->check('vmware: alarm trigger: no recovery for an alarm the first check only noted', [], $show($r4));
	    $now = $T::startNow($wf, $node);
	    $started = array_merge($started, $now['executions'] ?? []);
	    $this->check('vmware: alarm trigger: Run in the designer: an alarm triggered now', [ 1, 'alarm', 'ds-ssd' ], [ count($now['executions'] ?? []),
		($now['executions'][0] ?? null)?->getVariable('ev')['event'] ?? null, ($now['executions'][0] ?? null)?->getVariable('ev')['entity']['name'] ?? null ]);
	} finally {
	    $V::$testing = false;
	    $V::forgetSessions();
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		\models\TriggerState::beforeWorkflowDelete($wf);
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //Proxmox VE against a fake cluster (tests/ProxmoxTestServer.php; plugins\ITOps\util\Proxmox::$testing: http, no address guard): the client (the address, parameters,
    //Proxmox's messages from the reason phrase, tasks' nodes), the credential's test, the steps (guests by name or VMID on their own node, filters; start when running is done; shutdown
    //with its timeout; clone to the next free VMID; delete refused while running; snapshots: list without `current`, a name Proxmox refuses, rollback, delete, prune; backups: vzdump to a
    //storage, a failed task with its log, the latest backup's age; storage low on space; the API), the task trigger (its first check notes the time; failed backups start runs, once)
    'proxmox' => function() {
	$P = \plugins\ITOps\util\Proxmox::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$upid = 'UPID:pve1:000A1B2C:00F00F00:6724A1B0:vzdump:100:root@pam:';
	$this->check('proxmox: the address without the web interface\'s #...; parameters (booleans 1 / 0, lists comma separated, empty ones left out); Proxmox\'s messages; a task\'s node', [
	    'https://pve1:8006', [ 'full' => 1, 'vmid' => '100,101', 'online' => 0 ], 'Parameter verification failed.; snapname: invalid format', 'pve1', null ], [
	    $P::baseUrl(' https://pve1:8006/#v1:0:=qemu%2F100 '), $P::params([ 'full' => true, 'vmid' => [ 100, 101 ], 'online' => false, 'storage' => '', 'name' => null ]),
	    $P::message('Parameter verification failed.', [ 'data' => null, 'errors' => [ 'snapname' => 'invalid format' ] ]), $P::taskNode($upid), $P::taskNode('UPID:bad') ]);

	$dir = sys_get_temp_dir() . '/qflows-check-pve-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('proxmox', '../../plugins/ITOps/tests/ProxmoxTestServer.php', $dir, [ 'PVE_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$P::$testing = true;
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$tasks = fn(array $list) => file_put_contents("{$dir}/tasks.json", json_encode($list));
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $e) {
		return $e->getMessage();
	    }
	};
	$creds = [];
	$wfs = [];
	$started = [];
	try {
	    $make = function($secret) use ($om, $port, &$creds) {
		$c = $om->create('CredentialProxmox', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Proxmox ' . uniqid(),
		    'serverUrl' => "https://127.0.0.1:{$port}", 'tokenId' => 'automation@pve!qflows', 'secret' => $secret ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('proxmox: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->serverUrl = "http://127.0.0.1:{$port}";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialProxmox', (string)$c->_id);
	    };
	    $cred = $make('s3cret');
	    $wrong = $make('nope');
	    if (!$cred || !$wrong)
		return;
	    $bad = $om->create('CredentialProxmox', [ 'name' => 'x', 'serverUrl' => 'https://pve1:8006/api2/json', 'tokenId' => 'automation@pve', 'secret' => 's' ]);
	    $bad->validates();
	    $requests();
	    $this->check('proxmox: the form (the server without a path, user@realm!token); Test: the version, the cluster, its nodes; the token in the header; a wrong secret: said so', [
		[ 'serverUrl', 'tokenId' ], 'connected: Proxmox VE 8.2.4, cluster lab, 2 nodes: pve1, pve2 (offline)', 'PVEAPIToken=automation@pve!qflows=s3cret', true ], [
		array_values(array_intersect([ 'serverUrl', 'tokenId' ], array_keys((array)$bad->errors()))), $cred->test(), $requests()[0]['auth'] ?? null,
		str_contains($thrown(fn() => $wrong->test()), 'HTTP status 401: authentication failure: check the API token and its secret') ]);

	    $id = (string)$cred->_id;
	    $step = function($fq, $cfg, $vars = []) use ($n, $e, $id, $requests) {
		$requests();
		$exe = $this->runFlow("proxmox {$fq}", [ $n('m', 'trigger::manual'), $n('c', $fq, $cfg + [ 'credential' => $id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'c'), $e('e2', 'c', 'err', 1) ], $vars);
		return [ $exe, $requests() ];
	    };
	    $names = fn($exe, $var) => array_column((array)($exe?->getVariable($var)['items'] ?? []), 'name');
	    $posted = fn($sent) => array_values(array_filter($sent, fn($r) => $r['method'] !== 'GET'));
	    $sentTo = fn($sent) => array_values(array_map(fn($r) => "{$r['method']} " . substr($r['path'], 10), array_filter($sent, fn($r) => $r['method'] !== 'GET')));

	    //guests
	    [ $l1, ] = $step('app::proxmox-guests', [ 'operation' => 'list', 'names' => 'web*', 'variable' => 'g' ]);
	    [ $l2, ] = $step('app::proxmox-guests', [ 'operation' => 'list', 'kind' => 'lxc', 'variable' => 'g' ]);
	    [ $l3, ] = $step('app::proxmox-guests', [ 'operation' => 'list', 'tag' => 'prod', 'variable' => 'g' ]);
	    [ $l4, ] = $step('app::proxmox-guests', [ 'operation' => 'list', 'status' => 'stopped', 'templates' => true, 'variable' => 'g' ]);
	    $this->check('proxmox: Guests list: a * pattern, containers, a tag; templates only when chosen', [ [ 'web01', 'web02' ], [ 'dns01' ], [ 'web01' ], [ 'web02', 'tpl-debian' ] ],
		[ $names($l1, 'g'), $names($l2, 'g'), $names($l3, 'g'), $names($l4, 'g') ]);
	    [ $a1, $s1 ] = $step('app::proxmox-guests', [ 'operation' => 'start', 'guest' => 'web01', 'variable' => 'p' ]);
	    [ $a2, $s2 ] = $step('app::proxmox-guests', [ 'operation' => 'start', 'guest' => '{{ which }}', 'variable' => 'p' ], [ 'which' => 'web02' ]);
	    [ $a3, $s3 ] = $step('app::proxmox-guests', [ 'operation' => 'shutdown', 'guest' => 200, 'shutdownTimeout' => 30, 'forceStop' => true, 'variable' => 'p' ]);
	    $this->check('proxmox: Guests start: running already is done; by name (an expression) on its own node, the task waited for; shutdown of a container with its timeout and forceStop', [
		[ true, [] ], [ 'running', false, [ 'POST /nodes/pve2/qemu/101/status/start' ], true ], [ 'stopped', [ 'timeout' => '30', 'forceStop' => '1' ], [ 'POST /nodes/pve1/lxc/200/status/shutdown' ] ] ], [
		[ $a1?->getVariable('p')['already'] ?? null, $sentTo($s1) ],
		[ $a2?->getVariable('p')['status'] ?? null, $a2?->getVariable('p')['already'] ?? null, $sentTo($s2), str_starts_with((string)($a2?->getVariable('p')['task'] ?? ''), 'UPID:pve2:') ],
		[ $a3?->getVariable('p')['status'] ?? null, array_intersect_key(end($s3)['params'] ?? [], [ 'timeout' => 1, 'forceStop' => 1 ]) ?: ($s3[count($s3) - 2]['params'] ?? null), $sentTo($s3) ] ]);
	    [ $if, ] = $step('app::proxmox-guests', [ 'operation' => 'interfaces', 'guest' => 'web01', 'variable' => 'i' ]);
	    [ $cl, $sc ] = $step('app::proxmox-guests', [ 'operation' => 'clone', 'guest' => 'tpl-debian', 'newName' => 'web03', 'full' => false, 'target' => 'pve1', 'variable' => 'c' ]);
	    [ $del, ] = $step('app::proxmox-guests', [ 'operation' => 'delete', 'guest' => 'web01' ]);
	    [ $mg, $sm ] = $step('app::proxmox-guests', [ 'operation' => 'migrate', 'guest' => 'web02', 'target' => 'pve1', 'variable' => 'm' ]);
	    $this->check('proxmox: Guests interfaces (the guest agent); clone a template to the next free VMID, linked, on another node; delete while running: the error output with Proxmox\'s message; '
		. 'migrate a running VM online', [ [ '10.0.1.100' ], [ 102, 'pve1', [ 'newid' => '102', 'full' => '0', 'name' => 'web03', 'target' => 'pve1' ] ], [ 'error', true ], [ 'pve1', '1' ] ], [
		[ $if?->getVariable('i')[1]['ip-addresses'][0]['ip-address'] ?? null ],
		[ $cl?->getVariable('c')['vmid'] ?? null, $cl?->getVariable('c')['node'] ?? null, array_intersect_key(array_values(array_filter($sc, fn($r) => $r['method'] === 'POST'))[0]['params'] ?? [], [ 'newid' => 1, 'full' => 1, 'name' => 1, 'target' => 1 ]) ],
		[ $del?->getVariable('route'), str_contains((string)self::logLine($del, 'Proxmox Guests delete: '), 'HTTP status 500: VM 100 is running - destroy failed') ],
		[ $mg?->getVariable('m')['node'] ?? null, array_values(array_filter($sm, fn($r) => $r['method'] === 'POST'))[0]['params']['online'] ?? null ] ]);

	    //snapshots
	    [ $ls, ] = $step('app::proxmox-snapshots', [ 'operation' => 'list', 'guest' => 'web01', 'variable' => 's' ]);
	    [ $badName, ] = $step('app::proxmox-snapshots', [ 'operation' => 'create', 'guest' => 'web01', 'snapName' => 'pre {{ t }}' ], [ 't' => 'T7' ]);
	    [ $cr, $sc ] = $step('app::proxmox-snapshots', [ 'operation' => 'create', 'guest' => 'web01', 'snapName' => 'pre_{{ t }}', 'vmstate' => true, 'variable' => 's' ], [ 't' => 'T7' ]);
	    $this->check('proxmox: Snapshots list: without `current`, oldest first, the one the guest runs from marked; a name Proxmox would refuse: the error output; create with the RAM', [
		[ [ 'base', false ], [ 'before_patch', false ], [ 'recent', true ] ], 'error', [ 'pre_T7', '1' ], 'pre_T7' ], [
		array_map(fn($s) => [ $s['name'], $s['current'] ], $ls?->getVariable('s')['items'] ?? []), $badName?->getVariable('route'),
		[ $posted($sc)[0]['params']['snapname'] ?? null, $posted($sc)[0]['params']['vmstate'] ?? null ], $cr?->getVariable('s')['snapshot'] ?? null ]);
	    [ $rb, $sr ] = $step('app::proxmox-snapshots', [ 'operation' => 'rollback', 'guest' => 'web01', 'snapshot' => 'before_patch', 'variable' => 's' ]);
	    [ $dl, ] = $step('app::proxmox-snapshots', [ 'operation' => 'delete', 'guest' => 100, 'snapshot' => 'base' ]);
	    [ $pr, ] = $step('app::proxmox-snapshots', [ 'operation' => 'prune', 'guest' => 100, 'days' => '7', 'variable' => 's' ]);
	    [ $ls, ] = $step('app::proxmox-snapshots', [ 'operation' => 'list', 'guest' => 'web01', 'variable' => 's' ]);
	    $this->check('proxmox: Snapshots rollback, delete; prune: only those older than the days; what is left', [ [ 'POST /nodes/pve1/qemu/100/snapshot/before_patch/rollback' ], 'end', [ 'before_patch' ], [ 'recent', 'pre_T7' ] ], [
		$sentTo($sr), $dl?->state, array_column($pr?->getVariable('s')['removed'] ?? [], 'name'), $names($ls, 's') ]);

	    //backups
	    [ $bk, $sb ] = $step('app::proxmox-backups', [ 'operation' => 'backup', 'guest' => 'web01', 'storage' => 'pbs', 'notes' => 'Before {{ why }}', 'variable' => 'b' ], [ 'why' => 'the upgrade' ]);
	    [ $bf, ] = $step('app::proxmox-backups', [ 'operation' => 'backup', 'guest' => 'web02', 'storage' => 'pbs' ]);
	    [ $lt, ] = $step('app::proxmox-backups', [ 'operation' => 'latest', 'guest' => 'dns01', 'storage' => 'pbs', 'variable' => 'b' ]);
	    [ $bl, ] = $step('app::proxmox-backups', [ 'operation' => 'list', 'storage' => 'pbs', 'node' => 'pve1', 'variable' => 'b' ]);
	    $this->check('proxmox: Backups: vzdump on the guest\'s node to a storage with notes, the backup made; a failed task: the error output with its log; the latest backup and its age; '
		. 'a storage\'s backups newest first', [ [ 'POST /nodes/pve1/vzdump', 'Before the upgrade', 'snapshot', 'zstd' ], true, [ 'error', true ], [ 200, 5.0 ], [ 4, 100 ] ], [
		[ $sentTo($sb)[0] ?? null, $posted($sb)[0]['params']['notes-template'] ?? null,
		    array_values(array_filter($sb, fn($r) => $r['method'] === 'POST'))[0]['params']['mode'] ?? null, array_values(array_filter($sb, fn($r) => $r['method'] === 'POST'))[0]['params']['compress'] ?? null ],
		str_starts_with((string)($bk?->getVariable('b')['backup']['volid'] ?? ''), 'pbs:backup/vm/100/'),
		[ $bf?->getVariable('route'), str_contains((string)self::logLine($bf, 'Proxmox Backups backup: '), 'failed: job errors (INFO: starting new backup job: vzdump 101 | ERROR: Backup of VM 101 failed - no space left on device)') ],
		[ $lt?->getVariable('b')['vmid'] ?? null, $lt?->getVariable('b')['ageHours'] ?? null ], [ $bl?->getVariable('b')['count'] ?? null, $bl?->getVariable('b')['items'][0]['vmid'] ?? null ] ]);
	    [ $bd, $sd ] = $step('app::proxmox-backups', [ 'operation' => 'delete', 'volid' => 'pbs:backup/vm/100/2026-10-30T01:00:00Z', 'node' => 'pve1', 'variable' => 'b' ]);
	    [ $jb, ] = $step('app::proxmox-backups', [ 'operation' => 'jobs', 'variable' => 'b' ]);
	    $this->check('proxmox: Backups delete (the volume ID encoded in the path); the backup jobs', [ [ 'DELETE /nodes/pve1/storage/pbs/content/pbs%3Abackup%2Fvm%2F100%2F2026-10-30T01%3A00%3A00Z' ], true, 1 ],
		[ $sentTo($sd), $bd?->getVariable('b')['deleted'] ?? null, $jb?->getVariable('b')['count'] ?? null ]);

	    //cluster
	    $now = time();
	    $tasks([ [ 'upid' => 'UPID:pve1:00000001:00000001:00000001:vzdump:100:root@pam:', 'node' => 'pve1', 'type' => 'vzdump', 'id' => '100', 'user' => 'root@pam',
		'starttime' => $now - 600, 'endtime' => $now - 500, 'status' => 'job errors' ],
		[ 'upid' => 'UPID:pve1:00000002:00000002:00000002:qmstart:100:root@pam:', 'node' => 'pve1', 'type' => 'qmstart', 'id' => '100', 'user' => 'root@pam', 'starttime' => $now - 400, 'endtime' => $now - 399, 'status' => 'OK' ],
		[ 'upid' => 'UPID:pve1:00000003:00000003:00000003:vzdump::root@pam:', 'node' => 'pve1', 'type' => 'vzdump', 'id' => '', 'user' => 'root@pam', 'starttime' => $now - 10 ] ]);
	    [ $cn, ] = $step('app::proxmox-cluster', [ 'operation' => 'nodes', 'variable' => 'c' ]);
	    [ $cs, ] = $step('app::proxmox-cluster', [ 'operation' => 'storage', 'node' => 'pve1', 'variable' => 'c' ]);
	    [ $st, ] = $step('app::proxmox-cluster', [ 'operation' => 'status', 'variable' => 'c' ]);
	    [ $tk, ] = $step('app::proxmox-cluster', [ 'operation' => 'tasks', 'failed' => true, 'variable' => 'c' ]);
	    $this->check('proxmox: Cluster nodes with memory in percent; storage of a node with free percent, low on space; the status (an offline node); failed tasks only (not a running one)', [
		25.0, [ [ 'local', 5.0, true ], [ 'pbs', 60.0, false ] ], [ 'lab', true, 1 ], [ 'vzdump' ] ], [
		$cn?->getVariable('c')['items'][0]['memPercent'] ?? null, array_map(fn($s) => [ $s['storage'], $s['freePercent'], $s['lowOnSpace'] ], $cs?->getVariable('c')['items'] ?? []),
		[ $st?->getVariable('c')['cluster'] ?? null, $st?->getVariable('c')['quorate'] ?? null, $st?->getVariable('c')['offline'] ?? null ], array_column($tk?->getVariable('c')['items'] ?? [], 'type') ]);

	    //API
	    [ $r1, ] = $step('app::proxmox-api', [ 'method' => 'GET', 'path' => '/nodes/pve1/qemu/100/config', 'variable' => 'r' ]);
	    [ $r2, ] = $step('app::proxmox-api', [ 'method' => 'POST', 'path' => '/api2/json/nodes/pve1/qemu/100/snapshot', 'parameters' => '{"snapname": "api_snap"}', 'wait' => true, 'variable' => 'r' ]);
	    $this->check('proxmox: API: a GET; a POST whose task is waited for (a path with /api2/json too)', [ '4096', 'OK' ], [ $r1?->getVariable('r')['memory'] ?? null, $r2?->getVariable('r')['exitstatus'] ?? null ]);

	    //the task trigger
	    $wf = $this->workflow('proxmox task', [ $n('t', 'trigger::proxmox', [ 'credential' => $id, 'results' => 'failed', 'types' => 'vzdump', 'runs' => 'each', 'variable' => 'ev',
		'mode' => 'interval', 'every' => 5, 'every_unit' => 'minutes' ]), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ev.type }}' ]), $e('e1', 't', 's') ]);
	    $this->check('proxmox: the task trigger\'s workflow is valid', true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $T = \plugins\ITOps\models\node\NodeTriggerProxmox::class;
	    $node = $wf->nodes['t'];
	    [ $d0, $r0 ] = $T::check($wf, $node, []);
	    $since = (int)$d0['since'];
	    $base = [ 'node' => 'pve1', 'user' => 'root@pam', 'starttime' => $since - 60 ];
	    $tasks([ [ 'upid' => 'UPID:pve1:00000001:00000001:00000001:vzdump:100:root@pam:', 'type' => 'vzdump', 'id' => '100', 'endtime' => $since - 500, 'status' => 'job errors' ] + $base,
		[ 'upid' => 'UPID:pve1:00000004:00000004:00000004:vzdump:101:root@pam:', 'type' => 'vzdump', 'id' => '101', 'endtime' => $since + 5, 'status' => 'job errors' ] + $base,
		[ 'upid' => 'UPID:pve1:00000005:00000005:00000005:qmstart:101:root@pam:', 'type' => 'qmstart', 'id' => '101', 'endtime' => $since + 6, 'status' => 'start failed' ] + $base,
		[ 'upid' => 'UPID:pve1:00000006:00000006:00000006:vzdump:200:root@pam:', 'type' => 'vzdump', 'id' => '200', 'endtime' => $since + 7, 'status' => 'OK' ] + $base ]);
	    [ $d1, $r1 ] = $T::check($wf, $node, $d0);
	    [ $d2, $r2 ] = $T::check($wf, $node, $d1);
	    $tasks([ [ 'upid' => 'UPID:pve1:00000004:00000004:00000004:vzdump:101:root@pam:', 'type' => 'vzdump', 'id' => '101', 'endtime' => $since + 5, 'status' => 'job errors' ] + $base,
		[ 'upid' => 'UPID:pve2:00000007:00000007:00000007:vzdump:102:root@pam:', 'type' => 'vzdump', 'id' => '102', 'endtime' => $since + 5, 'status' => 'job errors', 'node' => 'pve2' ] + $base ]);
	    [ $d3, $r3 ] = $T::check($wf, $node, $d2);
	    $started = array_merge($started, $r0, $r1, $r2, $r3);
	    $show = fn($runs) => array_map(fn($x) => [ $x->getVariable('ev')['type'] ?? null, $x->getVariable('ev')['id'] ?? null, $x->getVariable('ev')['failed'] ?? null ], $runs);
	    $this->check('proxmox: task trigger: the first check notes the time; then a failed backup that ended since starts a run (not an older one, not another type, not an OK one), once; '
		. 'another that ended at the same second', [ [], [ [ 'vzdump', '101', true ] ], [], [ [ 'vzdump', '102', true ] ] ], [ $show($r0), $show($r1), $show($r2), $show($r3) ]);
	    $this->check('proxmox: task trigger: the runs queued, at the trigger', [ 'new', 'proxmox' ], [ $r1[0]?->state, $r1[0]?->trigger ]);
	    $now2 = $T::startNow($wf, $node);
	    $started = array_merge($started, $now2['executions'] ?? []);
	    $this->check('proxmox: task trigger: Run in the designer: the newest matching task', [ 1, '102' ], [ count($now2['executions'] ?? []), ($now2['executions'][0] ?? null)?->getVariable('ev')['id'] ?? null ]);
	} finally {
	    $P::$testing = false;
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		\models\TriggerState::beforeWorkflowDelete($wf);
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //Hyper-V against a fake host behind WinRM (tests/HyperVTestServer.php; util\WinRM::$testing: http, no address guard): the client (the WS-Management
    //address, PowerShell quoting, the script's wrapper with $P as data, PowerShell's errors from CLIXML; a shell made, the command run without cmd.exe, a Receive that timed out received
    //again, the output in parts, the shell deleted), the credential's test, the steps (VMs by name, * patterns; start when running is done; a shutdown that does not end: an error, or
    //turned off; delete refused while running; checkpoints: list, create, restore, remove, prune; volumes low on space; events; PowerShell with parameters, its error), the VM state
    //trigger (its first check notes the VMs; a VM that stops starts a run, once; passing states are no change; replication health; a recovery)
    'hyperV' => function() {
	$W = \util\WinRM::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$wrapped = $W::wrap('x', 'Get-VM', [ 'name' => "it's {{ x }}; Remove-VM" ]);
	$this->check('hyperv: the WS-Management address; a PowerShell string literal (quotes doubled, smart quotes too); the parameters as data in the wrapper; PowerShell\'s errors from CLIXML', [
	    'https://hv01:5986/wsman', 'https://hv01:5986/wsman', "'it''s ‘‘x’’'", true, "The term 'Get-Thing' is not recognized as the name of a cmdlet." ], [
	    $W::endpoint('https://hv01:5986/'), $W::endpoint('https://hv01:5986/wsman'), $W::quote("it's ‘x’"),
	    str_contains($wrapped, "\$P = ConvertFrom-Json '{\"name\":\"it''s {{ x }}; Remove-VM\"}'\n"),
	    $W::errorText("#< CLIXML\r\n<Objs><S S=\"Error\">The term 'Get-Thing' is not recognized_x000D__x000A_</S><S S=\"Error\">as the name of a cmdlet.</S></Objs>") ]);

	$dir = sys_get_temp_dir() . '/qflows-check-hv-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('hyperv', '../../plugins/ITOps/tests/HyperVTestServer.php', $dir, [ 'HV_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$W::$testing = true;
	$scripts = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/scripts.jsonl"))));
	    @unlink("{$dir}/scripts.jsonl");
	    return $list;
	};
	$setVm = function($name, array $set) use ($dir) {
	    $st = json_decode((string)file_get_contents("{$dir}/state.json"), true);
	    foreach($st['vms'] as &$v)
		if ($v['name'] === $name)
		    $v = $set + $v;
	    unset($v);
	    file_put_contents("{$dir}/state.json", json_encode($st));
	};
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $e) {
		return $e->getMessage();
	    }
	};
	$creds = [];
	$wfs = [];
	$started = [];
	try {
	    $make = function($password) use ($om, $port, &$creds) {
		$c = $om->create('CredentialHyperV', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Hyper-V ' . uniqid(),
		    'serverUrl' => "https://127.0.0.1:{$port}", 'username' => 'hv\\automation', 'password' => $password, 'authMethod' => 'basic' ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('hyperv: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->serverUrl = "http://127.0.0.1:{$port}";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialHyperV', (string)$c->_id);
	    };
	    $cred = $make('s3cret');
	    $wrong = $make('nope');
	    if (!$cred || !$wrong)
		return;
	    $bad = $om->create('CredentialHyperV', [ 'name' => 'x', 'serverUrl' => 'http://hv01:5985', 'username' => 'a', 'password' => 's' ]);
	    $bad->validates();
	    $scripts();
	    $test = $cred->test();
	    $sent = $scripts();
	    $state = json_decode((string)file_get_contents("{$dir}/state.json"), true);
	    $this->check('hyperv: the form (an https address); Test: the host, its Windows, its VMs; powershell.exe without cmd.exe, UTF-8; the shell deleted after; a wrong password: said so', [
		[ 'serverUrl' ], 'connected to HV01, Microsoft Windows Server 2022 Datacenter (10.0.20348), 3 VMs', [ 'test', 'powershell.exe', 'TRUE', '65001' ], [], true ], [
		array_values(array_intersect([ 'serverUrl' ], array_keys((array)$bad->errors()))), $test,
		[ $sent[0]['name'] ?? null, $sent[0]['command'] ?? null, $sent[0]['options']['WINRS_SKIP_CMD_SHELL'] ?? null, $sent[0]['shell']['WINRS_CODEPAGE'] ?? null ],
		array_keys((array)($state['shells'] ?? [])), str_contains($thrown(fn() => $wrong->test()), 'sign-in refused (HTTP status 401)') ]);

	    $id = (string)$cred->_id;
	    $step = function($fq, $cfg, $vars = []) use ($n, $e, $id, $scripts) {
		$scripts();
		$exe = $this->runFlow("hyperv {$fq}", [ $n('m', 'trigger::manual'), $n('c', $fq, $cfg + [ 'credential' => $id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'c'), $e('e2', 'c', 'err', 1) ], $vars);
		return [ $exe, $scripts() ];
	    };
	    $names = fn($exe, $var) => array_column((array)($exe?->getVariable($var)['items'] ?? []), 'name');

	    //VMs
	    [ $l1, $s1 ] = $step('app::hyperv-vms', [ 'operation' => 'list', 'names' => "web*\n", 'variable' => 'v' ]);
	    [ $l2, ] = $step('app::hyperv-vms', [ 'operation' => 'list', 'state' => 'Running', 'variable' => 'v' ]);
	    [ $g1, $sg ] = $step('app::hyperv-vms', [ 'operation' => 'get', 'vm' => '{{ which }}', 'variable' => 'v' ], [ 'which' => "web01'; Stop-Computer; '" ]);
	    $this->check('hyperv: VMs list: patterns and a state, the names given as $P; a name from an expression stays data: no VM of that name (the error output)', [
		[ 'web01', 'web02' ], [ 'db01', 'web01' ], [ 'web*' ], [ 'error', "web01'; Stop-Computer; '" ], true ], [
		$names($l1, 'v'), $names($l2, 'v'), $s1[0]['params']['names'] ?? null, [ $g1?->getVariable('route'), $sg[0]['params']['vm'] ?? null ], $sg[0]['helpers'] ?? null ]);
	    [ $a1, ] = $step('app::hyperv-vms', [ 'operation' => 'start', 'vm' => 'web01', 'variable' => 'p' ]);
	    [ $a2, ] = $step('app::hyperv-vms', [ 'operation' => 'start', 'vm' => '22222222-2222-2222-2222-222222222222', 'variable' => 'p' ]);
	    [ $sd1, ] = $step('app::hyperv-vms', [ 'operation' => 'shutdown', 'vm' => 'db01', 'wait' => 30 ]);
	    [ $sd2, $ss ] = $step('app::hyperv-vms', [ 'operation' => 'shutdown', 'vm' => 'db01', 'wait' => 30, 'turnOff' => true, 'variable' => 'p' ]);
	    $this->check('hyperv: VMs start: running already is done; by ID; a shutdown that does not end: the error output, or turned off when chosen', [
		[ true, 'Running' ], [ false, 'Running' ], [ 'error', true ], [ 'Off', true, 30, true ] ], [
		[ $a1?->getVariable('p')['already'] ?? null, $a1?->getVariable('p')['state'] ?? null ], [ $a2?->getVariable('p')['already'] ?? null, $a2?->getVariable('p')['state'] ?? null ],
		[ $sd1?->getVariable('route'), str_contains((string)self::logLine($sd1, 'Hyper-V VMs shutdown: '), 'the guest OS did not shut down within 30 s') ],
		[ $sd2?->getVariable('p')['state'] ?? null, $sd2?->getVariable('p')['forced'] ?? null, $ss[0]['params']['wait'] ?? null, $ss[0]['params']['turnOff'] ?? null ] ]);
	    [ $nw, ] = $step('app::hyperv-vms', [ 'operation' => 'network', 'vm' => 'web01', 'variable' => 'i' ]);
	    [ $d1, ] = $step('app::hyperv-vms', [ 'operation' => 'delete', 'vm' => 'web01' ]);
	    [ $d2, ] = $step('app::hyperv-vms', [ 'operation' => 'delete', 'vm' => 'db01', 'disks' => true, 'variable' => 'd' ]);
	    $this->check('hyperv: VMs network adapters with their addresses; delete a running VM: the error output; delete an off VM with its disks', [
		[ '10.0.2.10', 'fe80::1' ], [ 'error', true ], [ true, [ 'D:\\Hyper-V\\Disks\\db01.vhdx' ] ] ], [
		$nw?->getVariable('i')[0]['ipAddresses'] ?? null, [ $d1?->getVariable('route'), str_contains((string)self::logLine($d1, 'Hyper-V VMs delete: '), "VM 'web01' is Running: turn it off first") ],
		[ $d2?->getVariable('d')['deleted'] ?? null, $d2?->getVariable('d')['disksDeleted'] ?? null ] ]);

	    //checkpoints
	    [ $cl, ] = $step('app::hyperv-checkpoints', [ 'operation' => 'list', 'vm' => 'web01', 'variable' => 'c' ]);
	    [ $cc, $sc ] = $step('app::hyperv-checkpoints', [ 'operation' => 'create', 'vm' => 'web01', 'cpName' => 'Before {{ t }}', 'variable' => 'c' ], [ 't' => 'T-9' ]);
	    [ $cr, ] = $step('app::hyperv-checkpoints', [ 'operation' => 'restore', 'vm' => 'web01', 'checkpoint' => 'Before patch', 'variable' => 'c' ]);
	    [ $cm, ] = $step('app::hyperv-checkpoints', [ 'operation' => 'remove', 'vm' => 'web01', 'checkpoint' => 'Base', 'variable' => 'c' ]);
	    [ $cx, ] = $step('app::hyperv-checkpoints', [ 'operation' => 'remove', 'vm' => 'web01', 'checkpoint' => 'Nope' ]);
	    [ $cp, ] = $step('app::hyperv-checkpoints', [ 'operation' => 'prune', 'vm' => 'web01', 'days' => '7', 'pattern' => 'Before*', 'variable' => 'c' ]);
	    [ $cl2, ] = $step('app::hyperv-checkpoints', [ 'operation' => 'list', 'vm' => 'web01', 'variable' => 'c' ]);
	    $this->check('hyperv: Checkpoints list (the current one marked); create with an expression; restore; remove; one that does not exist: the error output; prune: old ones named so; what is left', [
		[ [ 'Base', false ], [ 'Before patch', false ], [ 'Recent', true ] ], [ 'Before T-9', true ], 'Before patch', 'Base', 'error', [ 'Before patch' ], [ 'Recent', 'Before T-9' ] ], [
		array_map(fn($c) => [ $c['name'], $c['current'] ], $cl?->getVariable('c')['items'] ?? []), [ $cc?->getVariable('c')['name'] ?? null, $cc?->getVariable('c')['current'] ?? null ],
		$cr?->getVariable('c')['name'] ?? null, $cm?->getVariable('c')['name'] ?? null, $cx?->getVariable('route'), array_column($cp?->getVariable('c')['removed'] ?? [], 'name'),
		$names($cl2, 'c') ]);

	    //host
	    [ $h1, ] = $step('app::hyperv-host', [ 'operation' => 'host', 'variable' => 'h' ]);
	    [ $h2, ] = $step('app::hyperv-host', [ 'operation' => 'volumes', 'lowPercent' => 10, 'variable' => 'h' ]);
	    [ $h3, $sh ] = $step('app::hyperv-host', [ 'operation' => 'events', 'hours' => 12, 'level' => 'errors', 'maxItems' => 1, 'variable' => 'h' ]);
	    $this->check('hyperv: Host: memory free in percent; volumes low on space; events of the last hours, at most N (truncated)', [
		25.0, [ [ 'C:', 30.0, false ], [ 'D:', 5.0, true ] ], [ 1, true, 12, 'errors', 2 ] ], [
		$h1?->getVariable('h')['memoryFreePercent'] ?? null, array_map(fn($d) => [ $d['drive'], $d['freePercent'], $d['lowOnSpace'] ], $h2?->getVariable('h')['items'] ?? []),
		[ $h3?->getVariable('h')['count'] ?? null, $h3?->getVariable('h')['truncated'] ?? null, $sh[0]['params']['hours'] ?? null, $sh[0]['params']['level'] ?? null, $sh[0]['params']['max'] ?? null ] ]);

	    //PowerShell
	    [ $p1, ] = $step('app::hyperv-powershell', [ 'script' => 'Get-VM', 'parameters' => '{"name": "{{ who }}"}', 'variable' => 'r' ], [ 'who' => 'web01' ]);
	    [ $p2, ] = $step('app::hyperv-powershell', [ 'script' => 'Get-Thing', 'parameters' => '{"fail": true}' ]);
	    $this->check('hyperv: PowerShell: the parameters (an expression) as $P; a script whose output is not JSON: the error output with PowerShell\'s message', [ [ 'name' => 'web01' ], 'error', true ], [
		$p1?->getVariable('r')['echo'] ?? null, $p2?->getVariable('route'), str_contains((string)self::logLine($p2, 'Hyper-V PowerShell run: '), "PowerShell: The term 'Get-Thing' is not recognized") ]);

	    //the VM state trigger
	    $wf = $this->workflow('hyperv state', [ $n('t', 'trigger::hyperv', [ 'credential' => $id, 'when' => 'stops', 'replication' => true, 'recoveries' => true, 'runs' => 'each', 'variable' => 'ev',
		'mode' => 'interval', 'every' => 2, 'every_unit' => 'minutes' ]), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ev.event }}' ]), $e('e1', 't', 's') ]);
	    $this->check('hyperv: the VM state trigger\'s workflow is valid', true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $T = \plugins\ITOps\models\node\NodeTriggerHyperV::class;
	    $node = $wf->nodes['t'];
	    //now: web01 Running, web02 Running (started above); db01 deleted
	    [ $d0, $r0 ] = $T::check($wf, $node, []);
	    $setVm('web01', [ 'state' => 'Stopping' ]);
	    $setVm('web02', [ 'state' => 'Off' ]);
	    [ $d1, $r1 ] = $T::check($wf, $node, $d0);
	    $setVm('web01', [ 'state' => 'Off', 'health' => 'Critical' ]);
	    [ $d2, $r2 ] = $T::check($wf, $node, $d1);
	    [ $d3, $r3 ] = $T::check($wf, $node, $d2);
	    $setVm('web01', [ 'state' => 'Running', 'health' => 'Normal' ]);
	    [ $d4, $r4 ] = $T::check($wf, $node, $d3);
	    $started = array_merge($started, $r0, $r1, $r2, $r3, $r4);
	    $show = fn($runs) => array_map(fn($x) => [ $x->getVariable('ev')['event'] ?? null, $x->getVariable('ev')['vm']['name'] ?? null, $x->getVariable('ev')['from'] ?? null, $x->getVariable('ev')['to'] ?? null ], $runs);
	    $this->check('hyperv: VM state trigger: the first check notes the VMs; a VM that stops starts a run (Stopping is no change yet); then off with replication Critical; once; '
		. 'running again and Normal: recoveries', [ [], [ [ 'stopped', 'web02', 'Running', 'Off' ] ], [ [ 'stopped', 'web01', 'Running', 'Off' ], [ 'replication', 'web01', 'Normal', 'Critical' ] ], [],
		[ [ 'recovery', 'web01', 'Off', 'Running' ], [ 'recovery', 'web01', 'Critical', 'Normal' ] ] ], [ $show($r0), $show($r1), $show($r2), $show($r3), $show($r4) ]);
	    $this->check('hyperv: VM state trigger: the runs queued, at the trigger', [ 'new', 'hyperv' ], [ $r1[0]?->state, $r1[0]?->trigger ]);
	    $now = $T::startNow($wf, $node);
	    $started = array_merge($started, $now['executions'] ?? []);
	    $this->check('hyperv: VM state trigger: Run in the designer: a VM that is not running', [ 1, 'web02', 'stopped' ], [ count($now['executions'] ?? []),
		($now['executions'][0] ?? null)?->getVariable('ev')['vm']['name'] ?? null, ($now['executions'][0] ?? null)?->getVariable('ev')['event'] ?? null ]);
	} finally {
	    $W::$testing = false;
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		\models\TriggerState::beforeWorkflowDelete($wf);
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //Zabbix against a fake server (tests/ZabbixTestServer.php; plugins\ITOps\util\Zabbix::$testing: http, no address guard): the client (the API's address,
    //apiinfo.version without the token, the token as Bearer from 6.4 and as `auth` before, Zabbix's errors), the credential's test, the steps (current problems without the
    //acknowledged / suppressed ones, by host / tags, with their hosts; an event with its acknowledgements; acknowledgements with a message and a severity change; a maintenance's
    //window (6.0: hostids, 7.0: hosts); hosts created, disabled, deleted; latest values; any method), the problem trigger (its first check notes the problems; new ones and
    //severity changes start runs, once; acknowledged ones do not; a recovery when chosen)
    'zabbix' => function() {
	$Z = \plugins\ITOps\util\Zabbix::class;
	$N = \plugins\ITOps\models\node\NodeAppZabbix::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$this->check('zabbix: the API address (a pasted page dropped); a refused token said so; names, ids and tags of settings', [
	    'https://zbx.example/zabbix/api_jsonrpc.php', 'https://zbx.example/api_jsonrpc.php', true, [ 'web01', 'db 01' ], [ '101', '102' ],
	    [ [ 'tag' => 'service', 'value' => 'nginx' ], [ 'tag' => 'env', 'value' => '' ] ] ], [
	    $Z::apiUrl('https://zbx.example/zabbix/zabbix.php?action=dashboard.view'), $Z::apiUrl('https://zbx.example/'),
	    str_starts_with($Z::errorText([ 'message' => 'Invalid params.', 'data' => 'Not authorized.' ]), 'the API token is refused'),
	    $N::names("web01, db 01\nweb01"), $N::ids([ [ 'eventid' => '101' ], '102' ], 'Events'), $N::tags('service=nginx, env') ]);

	$dir = sys_get_temp_dir() . '/qflows-check-zbx-' . uniqid();
	$dirOld = $dir . '-60';
	@mkdir($dir, 0700, true);
	@mkdir($dirOld, 0700, true);
	$server = $this->startServer('zabbix', '../../plugins/ITOps/tests/ZabbixTestServer.php', $dir, [ 'ZBX_STATE' => $dir, 'ZBX_VERSION' => '7.0.5' ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$old = $this->startServer('zabbix 6.0', '../../plugins/ITOps/tests/ZabbixTestServer.php', $dirOld, [ 'ZBX_STATE' => $dirOld, 'ZBX_VERSION' => '6.0.30' ]);
	if (!$old) {
	    proc_terminate($proc);
	    proc_close($proc);
	    return;
	}
	[ $portOld, $procOld ] = $old;
	$Z::$testing = true;
	$requests = function($d = null) use ($dir) {
	    $d ??= $dir;
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$d}/requests.jsonl"))));
	    @unlink("{$d}/requests.jsonl");
	    return $list;
	};
	$byMethod = fn(array $sent, $m) => array_values(array_filter($sent, fn($r) => $r['method'] === $m));
	$problems = fn(array $list) => file_put_contents("{$dir}/problems.json", json_encode($list));
	$pr = fn($id, $host, $sev, $ack = 0, $sup = 0, $tags = []) => [ 'eventid' => (string)$id, 'objectid' => (string)(5000 + $id), 'name' => "Problem {$id} on {$host}", 'severity' => (string)$sev,
	    'clock' => '1793500000', 'acknowledged' => (string)$ack, 'suppressed' => (string)$sup, 'hostid' => $host === 'web01' ? '10084' : '10085', 'tags' => $tags ];
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $e) {
		return $e->getMessage();
	    }
	};
	$creds = [];
	$wfs = [];
	$started = [];
	try {
	    $make = function($token, $p) use ($om, &$creds) {
		$c = $om->create('CredentialZabbix', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Zabbix ' . uniqid(),
		    'serverUrl' => "https://127.0.0.1:{$p}/zabbix", 'apiToken' => $token ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('zabbix: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->serverUrl = "http://127.0.0.1:{$p}/zabbix";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialZabbix', (string)$c->_id);
	    };
	    $cred = $make('tok3n', $port);
	    $wrong = $make('nope', $port);
	    $cred60 = $make('tok3n', $portOld);
	    if (!$cred || !$wrong || !$cred60)
		return;
	    $bad = $om->create('CredentialZabbix', [ 'name' => 'x', 'serverUrl' => 'http://zbx.example', 'apiToken' => 't' ]);
	    $bad->validates();
	    $requests();
	    $test = $cred->test();
	    $sent = $requests();
	    $this->check('zabbix: the form (https only); Test: the version and the hosts; apiinfo.version without the token, then the token as Bearer (7.0), no auth member; a wrong token: said so', [
		[ 'serverUrl' ], 'connected to Zabbix 7.0.5, 2 hosts visible to the token', [ null, null ], [ 'Bearer tok3n', null ], '/zabbix/api_jsonrpc.php', true ], [
		array_values(array_intersect([ 'serverUrl' ], array_keys((array)$bad->errors()))), $test, [ $sent[0]['authorization'] ?? null, $sent[0]['auth'] ?? null ],
		[ $sent[1]['authorization'] ?? null, $sent[1]['auth'] ?? null ], $sent[1]['path'] ?? null, str_contains($thrown(fn() => $wrong->test()), 'the API token is refused') ]);
	    $requests($dirOld);
	    $test60 = $cred60->test();
	    $sent = $requests($dirOld);
	    $this->check('zabbix: 6.0: the token as the auth member, no header', [ 'connected to Zabbix 6.0.30, 2 hosts visible to the token', [ null, 'tok3n' ] ],
		[ $test60, [ $sent[1]['authorization'] ?? null, $sent[1]['auth'] ?? null ] ]);

	    $step = function($fq, $cfg, $vars = [], $c = null) use ($n, $e, $cred, $requests) {
		$requests();
		$exe = $this->runFlow("zbx {$fq}", [ $n('m', 'trigger::manual'), $n('c', $fq, $cfg + [ 'credential' => (string)($c ?? $cred)->_id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'c'), $e('e2', 'c', 'err', 1) ], $vars);
		return [ $exe, $requests() ];
	    };
	    $problems([ $pr(101, 'web01', 4, 0, 0, [ [ 'tag' => 'service', 'value' => 'nginx' ] ]), $pr(102, 'db01', 2, 1), $pr(103, 'db01', 5, 0, 1), $pr(104, 'web01', 1) ]);
	    [ $exe, $sent ] = $step('app::zbx-problems', [ 'operation' => 'problems', 'variable' => 'p' ]);
	    $p = $exe?->getVariable('p');
	    $pg = $byMethod($sent, 'problem.get')[0]['params'] ?? [];
	    $this->check('zabbix: Problems: Warning and worse, without acknowledged and suppressed ones; each with its host (event.get), severity as text, tags, since', [
		[ [ '101', 'web01', 'Web server 1', 'High' ] ], [ 'service' => 'nginx' ], '2026-11-01T', [ [ 2, 3, 4, 5 ], false, false, 1001 ] ], [
		array_map(fn($x) => [ $x['eventid'], $x['host'], $x['hostName'], $x['severityText'] ], $p['items'] ?? []), (array)($p['items'][0]['tags'] ?? []),
		substr((string)($p['items'][0]['since'] ?? ''), 0, 11), [ $pg['severities'] ?? null, $pg['acknowledged'] ?? null, $pg['suppressed'] ?? null, $pg['limit'] ?? null ] ]);
	    [ $exe, $sent ] = $step('app::zbx-problems', [ 'operation' => 'problems', 'hosts' => 'db01', 'acknowledged' => true, 'suppressed' => true, 'minSeverity' => 'any', 'variable' => 'p' ]);
	    [ $tagged, ] = $step('app::zbx-problems', [ 'operation' => 'problems', 'tags' => 'service=ngi', 'variable' => 'p' ]);
	    [ $ghost, ] = $step('app::zbx-problems', [ 'operation' => 'problems', 'hosts' => 'ghost' ]);
	    $this->check('zabbix: Problems of a host (its id looked up), with acknowledged and suppressed ones, newest first; by a tag; an unknown host: the error output', [
		[ '103', '102' ], [ '10085' ], [ '101' ], 'error', true ], [ array_column($exe?->getVariable('p')['items'] ?? [], 'eventid'),
		$byMethod($sent, 'problem.get')[0]['params']['hostids'] ?? null, array_column($tagged?->getVariable('p')['items'] ?? [], 'eventid'), $ghost?->getVariable('route'),
		str_contains((string)self::logLine($ghost, 'Zabbix Problems problems: '), 'Zabbix has no host `ghost`') ]);
	    [ $exe, $sent ] = $step('app::zbx-ack', [ 'operation' => 'acknowledge', 'eventIds' => '{{ items }}', 'message' => 'Ticket {{ t }}', 'severity' => 'disaster' ],
		[ 'items' => [ [ 'eventid' => '101' ], [ 'eventid' => '104' ] ], 't' => 'T-42' ]);
	    $ack = $byMethod($sent, 'event.acknowledge')[0]['params'] ?? [];
	    [ $empty, ] = $step('app::zbx-ack', [ 'operation' => 'message', 'eventIds' => '101', 'message' => '{{ blank }}' ], [ 'blank' => '' ]);
	    [ $ev, ] = $step('app::zbx-problems', [ 'operation' => 'event', 'eventId' => '101', 'variable' => 'ev' ]);
	    $this->check('zabbix: Acknowledge: the events of a list, acknowledge + message + severity (action 14), the message with an expression; a message step without one: the error output; '
		. 'Get an event: with its acknowledgement', [ [ '101', '104' ], 14, 'Ticket T-42', 5, 'error', [ 'Problem 101 on web01', 'Ticket T-42', 'Zabbix Administrator' ] ], [
		$ack['eventids'] ?? null, $ack['action'] ?? null, $ack['message'] ?? null, $ack['severity'] ?? null, $empty?->getVariable('route'),
		[ $ev?->getVariable('ev')['name'] ?? null, $ev?->getVariable('ev')['acknowledges'][0]['message'] ?? null, $ev?->getVariable('ev')['acknowledges'][0]['user'] ?? null ] ]);
	    $win = [ 'operation' => 'maintenanceCreate', 'name' => 'Patching', 'hosts' => 'web01', 'groups' => 'Databases', 'start' => '2026-11-02T22:00:00Z', 'minutes' => 90, 'collectData' => false, 'variable' => 'm' ];
	    [ $exe, $sent ] = $step('app::zbx-ack', $win);
	    $mc = $byMethod($sent, 'maintenance.create')[0]['params'] ?? [];
	    [ $old60, $sent60 ] = [ null, null ];
	    $requests($dirOld);
	    [ $old60, ] = $step('app::zbx-ack', $win, [], $cred60);
	    $mc60 = $byMethod($requests($dirOld), 'maintenance.create')[0]['params'] ?? [];
	    [ $list, ] = $step('app::zbx-ack', [ 'operation' => 'maintenances', 'variable' => 'l' ]);
	    [ $del, $sentDel ] = $step('app::zbx-ack', [ 'operation' => 'maintenanceDelete', 'maintenanceIds' => '{{ m.maintenanceids }}' ], [ 'm' => $exe?->getVariable('m') ]);
	    $start = strtotime('2026-11-02T22:00:00Z');
	    $this->check('zabbix: a maintenance: from the start for the minutes, without data collection; hosts and groups by id (7.0: objects, 6.0: id lists); listed with its hosts and groups; removed', [
		[ $start, $start + 5400, 1, [ [ 'hostid' => '10084' ] ], [ [ 'groupid' => '4' ] ], [ [ 'timeperiod_type' => 0, 'start_date' => $start, 'period' => 5400 ] ] ],
		[ [ '10084' ], [ '4' ], false ], [ 'Patching', [ 'web01' ], [ 'Databases' ] ], 'end', 1 ], [
		[ $mc['active_since'] ?? null, $mc['active_till'] ?? null, $mc['maintenance_type'] ?? null, $mc['hosts'] ?? null, $mc['groups'] ?? null, $mc['timeperiods'] ?? null ],
		[ $mc60['hostids'] ?? null, $mc60['groupids'] ?? null, isset($mc60['hosts']) ], [ $list?->getVariable('l')['items'][0]['name'] ?? null,
		array_column($list?->getVariable('l')['items'][0]['hosts'] ?? [], 'host'), array_column($list?->getVariable('l')['items'][0]['groups'] ?? [], 'name') ],
		$del?->state, count($byMethod($sentDel, 'maintenance.delete')) ]);

	    [ $exe, $sent ] = $step('app::zbx-hosts', [ 'operation' => 'create', 'host' => 'app02', 'visibleName' => 'App server 2', 'groups' => 'Linux servers', 'templates' => 'Linux by Zabbix agent',
		'address' => '10.0.0.9', 'tags' => 'env=prod', 'variable' => 'h' ]);
	    $hc = $byMethod($sent, 'host.create')[0]['params'] ?? [];
	    [ $dup, ] = $step('app::zbx-hosts', [ 'operation' => 'create', 'host' => 'app02', 'groups' => 'Linux servers', 'interface' => 'none' ]);
	    [ $dis, $sentDis ] = $step('app::zbx-hosts', [ 'operation' => 'disable', 'host' => 'app02' ]);
	    [ $lst, $sentList ] = $step('app::zbx-hosts', [ 'operation' => 'list', 'status' => 'enabled', 'variable' => 'l' ]);
	    $this->check('zabbix: Hosts create: groups and templates by id, the agent interface, tags; again: the error output with Zabbix\'s message; disable; list the enabled ones with their groups (7.0: selectHostGroups)', [
		[ 'app02', 'App server 2', [ [ 'groupid' => '2' ] ], [ [ 'templateid' => '10001' ] ], [ [ 'type' => 1, 'main' => 1, 'useip' => 1, 'ip' => '10.0.0.9', 'dns' => '', 'port' => '10050' ] ], [ [ 'tag' => 'env', 'value' => 'prod' ] ] ],
		'error', true, 1, [ 'Web server 1', 'Database 1' ], [ 'Databases' ], true ], [
		[ $hc['host'] ?? null, $hc['name'] ?? null, $hc['groups'] ?? null, $hc['templates'] ?? null, $hc['interfaces'] ?? null, $hc['tags'] ?? null ],
		$dup?->getVariable('route'), str_contains((string)self::logLine($dup, 'Zabbix Hosts create: '), 'Host with the same name "app02" already exists.'),
		$byMethod($sentDis, 'host.update')[0]['params']['status'] ?? null, array_column($lst?->getVariable('l')['items'] ?? [], 'name'),
		array_column($lst?->getVariable('l')['items'][1]['groups'] ?? [], 'name'), isset($byMethod($sentList, 'host.get')[0]['params']['selectHostGroups']) ]);
	    [ $get, ] = $step('app::zbx-hosts', [ 'operation' => 'get', 'host' => 'web01', 'variable' => 'g' ]);
	    [ $items, ] = $step('app::zbx-hosts', [ 'operation' => 'items', 'host' => 'web01', 'search' => 'cpu', 'variable' => 'i' ]);
	    [ $groups, ] = $step('app::zbx-hosts', [ 'operation' => 'groups', 'search' => 'data', 'variable' => 'gr' ]);
	    [ $gone, ] = $step('app::zbx-hosts', [ 'operation' => 'delete', 'host' => 'app02' ]);
	    [ $lst60, ] = [ null ];
	    $requests($dirOld);
	    $step('app::zbx-hosts', [ 'operation' => 'list' ], [], $cred60);
	    $hg60 = $byMethod($requests($dirOld), 'host.get')[0]['params'] ?? [];
	    $this->check('zabbix: Hosts get: groups, problems; latest values: a key or name containing a text; host groups; delete; 6.0: selectGroups', [
		[ 'enabled', [ 'Linux servers' ], 2 ], [ [ 'system.cpu.util', '12.5', '%' ] ], [ 'Databases' ], 'end', [ true, false ] ], [
		[ $get?->getVariable('g')['status'] ?? null, array_column($get?->getVariable('g')['groups'] ?? [], 'name'), $get?->getVariable('g')['problems'] ?? null ],
		array_map(fn($x) => [ $x['key'], $x['value'], $x['units'] ], $items?->getVariable('i')['items'] ?? []), array_column($groups?->getVariable('gr')['items'] ?? [], 'name'),
		$gone?->state, [ isset($hg60['selectGroups']), isset($hg60['selectHostGroups']) ] ]);
	    [ $api, ] = $step('app::zbx-api', [ 'method' => 'host.get', 'apiParams' => '{"output": ["host"]}', 'variable' => 'a' ]);
	    [ $nope, ] = $step('app::zbx-api', [ 'method' => 'trigger.get' ]);
	    [ $ver, ] = $step('app::zbx-api', [ 'method' => 'apiinfo.version', 'variable' => 'v' ]);
	    $this->check('zabbix: API: any method with its parameters; a method the server does not have: the error output with its message; apiinfo.version', [
		[ 'web01', 'db01' ], 'error', true, '7.0.5' ], [ array_column((array)$api?->getVariable('a'), 'host'), $nope?->getVariable('route'),
		str_contains((string)self::logLine($nope, 'Zabbix API call: '), 'Method not found.: Incorrect API "trigger.get".'), $ver?->getVariable('v') ]);

	    //the problem trigger
	    $wf = $this->workflow('zbx problem', [ $n('t', 'trigger::zabbix', [ 'credential' => (string)$cred->_id, 'minSeverity' => '3', 'recoveries' => true, 'runs' => 'each', 'variable' => 'ev',
		'mode' => 'interval', 'every' => 2, 'every_unit' => 'minutes' ]), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ev.event }}' ]), $e('e1', 't', 's') ]);
	    $this->check('zabbix: the problem trigger\'s workflow is valid', true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $T = \plugins\ITOps\models\node\NodeTriggerZabbix::class;
	    $node = $wf->nodes['t'];
	    $problems([ $pr(101, 'web01', 4) ]);
	    [ $d0, $r0 ] = $T::check($wf, $node, []);
	    $problems([ $pr(101, 'web01', 4), $pr(105, 'db01', 4), $pr(106, 'db01', 2), $pr(107, 'web01', 5, 1) ]);
	    [ $d1, $r1 ] = $T::check($wf, $node, $d0);
	    [ $d2, $r2 ] = $T::check($wf, $node, $d1);
	    $problems([ $pr(105, 'db01', 5), $pr(107, 'web01', 5, 1) ]);
	    [ $d3, $r3 ] = $T::check($wf, $node, $d2);
	    $problems([ $pr(107, 'web01', 5, 1) ]);
	    [ $d4, $r4 ] = $T::check($wf, $node, $d3);
	    $started = array_merge($started, $r0, $r1, $r2, $r3, $r4);
	    $show = fn($runs) => array_map(fn($x) => [ $x->getVariable('ev')['event'] ?? null, $x->getVariable('ev')['eventid'] ?? null, $x->getVariable('ev')['host'] ?? null,
		$x->getVariable('ev')['severityText'] ?? null ], $runs);
	    $this->check('zabbix: problem trigger: the first check notes what is wrong; then a new problem (Average and worse, not acknowledged) starts a run, once; a severity change '
		. 'starts another; no recovery for a problem the first check only noted; one for a problem that started a run', [
		[], [ [ 'problem', '105', 'db01', 'High' ] ], [], [ [ 'problem', '105', 'db01', 'Disaster' ] ], [ [ 'recovery', '105', 'db01', 'Disaster' ] ] ],
		[ $show($r0), $show($r1), $show($r2), $show($r3), $show($r4) ]);
	    $this->check('zabbix: problem trigger: the runs queued, at the trigger', [ 'new', 'zabbix' ], [ $r1[0]?->state, $r1[0]?->trigger ]);
	    $problems([ $pr(108, 'web01', 3) ]);
	    $now = $T::startNow($wf, $node);
	    $started = array_merge($started, $now['executions'] ?? []);
	    $this->check('zabbix: problem trigger: Run in the designer: a current problem', [ 1, '108' ], [ count($now['executions'] ?? []), ($now['executions'][0] ?? null)?->getVariable('ev')['eventid'] ?? null ]);
	} finally {
	    $Z::$testing = false;
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		\models\TriggerState::beforeWorkflowDelete($wf);
	    foreach($creds as $c)
		$c->delete();
	    foreach([ $proc, $procOld ] as $pp) {
		proc_terminate($pp);
		proc_close($pp);
	    }
	    foreach([ $dir, $dirOld ] as $d) {
		foreach(glob("{$d}/*") as $f)
		    @unlink($f);
		@rmdir($d);
	    }
	}
    },

    //Wazuh against a fake one (tests/WazuhTestServer.php: the Server API refuses a parameter its spec does not have; the indexer; plugins\ITOps\util\Wazuh::$testing:
    //http, no address guard): the client (sign-in, the token kept, signed in again after a 401, Wazuh's errors, pages), the credential's test, every step (an empty agents_list is never
    //sent: to Wazuh it means every agent; Wazuh's 200 with everything failed is an error), the alert trigger (the first check only notes the time; late alerts; seen; MAX_RUNS)
    'wazuh' => function() {
	$W = \plugins\ITOps\util\Wazuh::class;
	$N = \plugins\ITOps\models\node\NodeAppWazuh::class;
	$A = \plugins\ITOps\models\node\NodeAppWzAlerts::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $x) {
		return $x->getMessage();
	    }
	};
	$this->check('wazuh: values: agent ids padded, from agents and alerts; `all` and empty agents; a group\'s name', [
	    '001', '012', '005', [ '001', '002' ], [ '003', '007' ], null, 'Agents: empty (`all` for every agent)', 'Agents: name the agents, `all` cannot be used here', 'Group: not a group\'s name: `a/b`' ], [
	    $N::agentId('1'), $N::agentId(12), $N::agentId([ 'agent' => [ 'id' => '005' ] ]), $N::agentList('1, 002'), $N::agentList([ [ 'id' => '003' ], [ 'agent' => [ 'id' => '007' ] ] ]), $N::agentList('all'),
	    $thrown(fn() => $N::agentList(' ')), $thrown(fn() => $N::agentList('all', false)), $thrown(fn() => $N::group('a/b')) ]);
	$this->check('wazuh: alert filters: level, ids and names of agents, rules, groups, techniques, a query', [
	    [ 'range' => [ 'rule.level' => [ 'gte' => 7 ] ] ],
	    [ 'bool' => [ 'should' => [ [ 'terms' => [ 'agent.id' => [ '001' ] ] ], [ 'terms' => [ 'agent.name' => [ 'web01' ] ] ] ], 'minimum_should_match' => 1 ] ],
	    [ 'terms' => [ 'rule.id' => [ '5712' ] ] ], [ 'terms' => [ 'rule.groups' => [ 'sshd', 'pam' ] ] ], [ 'terms' => [ 'rule.mitre.id' => [ 'T1110' ] ] ], [ 'query_string' => [ 'query' => 'data.srcip:1.2.3.4', 'analyze_wildcard' => true ] ] ],
	    $A::filters([ 'minLevel' => 7, 'agents' => '1, web01', 'ruleIds' => '5712', 'groups' => 'sshd,pam', 'mitre' => 'T1110', 'query' => 'data.srcip:1.2.3.4' ]));
	$this->check('wazuh: level 0 is a filter, empty is not', [ [ [ 'range' => [ 'rule.level' => [ 'gte' => 0 ] ] ] ], [] ], [ $A::filters([ 'minLevel' => 0 ]), $A::filters([ 'minLevel' => '' ]) ]);

	$dir = sys_get_temp_dir() . '/qflows-check-wz-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('wazuh', '../../plugins/ITOps/tests/WazuhTestServer.php', $dir, [ 'WZ_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$W::$testing = true;
	$W::forgetTokens();
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$alert = fn($id, $at, $level = 10, $agent = 'web01', $rule = '5712') => [ '_id' => $id, 'timestamp' => $at, 'rule' => [ 'id' => $rule, 'level' => $level, 'description' => "Rule {$rule}", 'groups' => [ 'sshd' ], 'mitre' => [ 'id' => [ 'T1110' ] ] ],
	    'agent' => [ 'id' => $agent === 'web01' ? '001' : '002', 'name' => $agent ], 'data' => [ 'srcip' => '203.0.113.5' ], 'full_log' => "log of {$id}" ];
	$alerts = fn(array $list) => file_put_contents("{$dir}/alerts.json", json_encode($list));
	$creds = [];
	$wfs = [];
	$started = [];
	try {
	    $make = function(array $over = []) use ($om, &$creds, $port) {
		$c = $om->create('CredentialWazuh', $over + [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Wazuh ' . uniqid(), 'serverUrl' => "https://127.0.0.1:{$port}",
		    'username' => 'wazuh-wui', 'password' => 's3cret', 'indexerUrl' => "https://127.0.0.1:{$port}", 'indexerUsername' => 'admin', 'indexerPassword' => '1ndexer' ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('wazuh: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->serverUrl = "http://127.0.0.1:{$port}";
		if ($c->indexerUrl)
		    $c->indexerUrl = "http://127.0.0.1:{$port}";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialWazuh', (string)$c->_id);
	    };
	    $cred = $make();
	    $noIndexer = $make([ 'indexerUrl' => '', 'indexerUsername' => '', 'indexerPassword' => '' ]);
	    $wrong = $make([ 'password' => 'nope' ]);
	    $wrongIndexer = $make([ 'indexerPassword' => 'nope' ]);
	    if (!$cred || !$noIndexer || !$wrong || !$wrongIndexer)
		return;
	    $bad = $om->create('CredentialWazuh', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'x', 'serverUrl' => 'http://wazuh.example:55000', 'username' => 'u', 'password' => 'p', 'indexerUrl' => 'https://wazuh.example:9200/path' ]);
	    $bad->validates();
	    $badKeys = array_keys((array)$bad->errors());
	    sort($badKeys);
	    $alerts([ $alert('a1', '2026-11-03T08:00:00.000+0000', 3), $alert('a2', '2026-11-03T09:00:00.000+0000', 12, 'db01', '5501') ]);
	    $requests();
	    $this->check('wazuh: the form: https only, no path; Test: version, host, the alerts; signed in with Basic, then Bearer; the indexer with Basic; no indexer said; refused users said', [
		[ 'indexerUrl', 'serverUrl' ], 'connected to Wazuh 4.14.0 on wazuh-manager; indexer: 2 alerts', 'connected to Wazuh 4.14.0 on wazuh-manager; no indexer: the alerts and the vulnerabilities cannot be read',
		'sign-in refused: Invalid credentials', true ], [
		$badKeys, $cred->test(), $noIndexer->test(), $thrown(fn() => $wrong->test()), str_contains($thrown(fn() => $wrongIndexer->test()), 'security_exception') ]);
	    $W::forgetTokens();
	    $requests();
	    $client = $cred->client($this->account->_id, 20);
	    $client->request('GET', '/');
	    $client->request('GET', '/');
	    file_put_contents("{$dir}/expired", '1');
	    $client->request('GET', '/');
	    $sent = $requests();
	    $this->check('wazuh: the token kept across requests; a 401: a new sign-in and the request again', [ '/security/user/authenticate', '/', '/', '/', '/security/user/authenticate', '/' ], array_column($sent, 'path'));
	    $this->check('wazuh: not an API path; Wazuh\'s error with its code; a page of rows, then the next', [ 'not an API path: `/a/../b`', 'HTTP status 404: The requested URL was not found: GET /nothing', 1200, 1200, 1200, false, [ 500, 500, 500 ] ], (function() use ($client, $thrown, $requests) {
		$p = $thrown(fn() => $client->request('GET', '/a/../b'));
		$m = $thrown(fn() => $client->request('GET', '/nothing'));
		$requests();
		[ $rows, $total, $cut ] = $client->rows('/syscollector/001/packages', [], 5000);
		return [ $p, $m, count($rows), count(array_unique(array_column($rows, 'name'))), $total, $cut, array_map(fn($r) => (int)$r['query']['limit'], $requests()) ];
	    })());

	    $step = function($fq, $cfg, $vars = [], $c = null) use ($n, $e, $cred, $requests) {
		$requests();
		$exe = $this->runFlow("wz {$fq}", [ $n('m', 'trigger::manual'), $n('c', $fq, $cfg + [ 'credential' => (string)($c ?? $cred)->_id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'c'), $e('e2', 'c', 'err', 1) ], $vars);
		return [ $exe, array_values(array_filter($requests(), fn($r) => $r['path'] !== '/security/user/authenticate')) ];
	    };

	    //alerts
	    $recent = gmdate('Y-m-d\TH:i:s.000+0000', time() - 3600);
	    $alerts([ $alert('a1', $recent, 3), $alert('a2', gmdate('Y-m-d\TH:i:s.000+0000', time() - 1800), 12, 'db01', '5501'), $alert('a3', gmdate('Y-m-d\TH:i:s.000+0000', time() - 600), 10), $alert('old', gmdate('Y-m-d\TH:i:s.000+0000', time() - 3 * 86400), 15) ]);
	    [ $exe, $sent ] = $step('app::wazuh-alerts', [ 'operation' => 'search', 'minLevel' => 7, 'variable' => 'r' ]);
	    $r = $exe?->getVariable('r');
	    $this->check('wazuh: Alerts search: level and worse, of the last 24 hours, newest first, with _id; the index', [ [ 'a3', 'a2' ], 2, 2, false, 'wazuh-alerts-4.x-2026.11.03', '/wazuh-alerts-*/_search' ],
		[ array_column($r['items'] ?? [], '_id'), $r['count'] ?? null, $r['total'] ?? null, $r['truncated'] ?? null, $r['items'][0]['_index'] ?? null, $sent[0]['path'] ?? null ]);
	    [ $exe, ] = $step('app::wazuh-alerts', [ 'operation' => 'search', 'agents' => '{{ who }}', 'since' => '-2 days', 'maxItems' => 1, 'variable' => 'r' ], [ 'who' => [ 'web01' ] ]);
	    $r = $exe?->getVariable('r');
	    $this->check('wazuh: Alerts search: an agent from a list; truncated at At most', [ [ 'a3' ], 2, true ], [ array_column($r['items'] ?? [], '_id'), $r['total'] ?? null, $r['truncated'] ?? null ]);
	    [ $exe, ] = $step('app::wazuh-alerts', [ 'operation' => 'get', 'alertId' => 'a2', 'variable' => 'g' ]);
	    [ $none, ] = $step('app::wazuh-alerts', [ 'operation' => 'get', 'alertId' => 'ghost', 'variable' => 'g' ]);
	    [ $cnt, ] = $step('app::wazuh-alerts', [ 'operation' => 'count', 'minLevel' => 10, 'variable' => 'c' ]);
	    [ $top, ] = $step('app::wazuh-alerts', [ 'operation' => 'top', 'field' => 'agent.name', 'variable' => 't' ]);
	    [ $noIdx, ] = $step('app::wazuh-alerts', [ 'operation' => 'count', 'variable' => 'c' ], [], $noIndexer);
	    file_put_contents("{$dir}/indexer-error", '1');
	    [ $badQuery, ] = $step('app::wazuh-alerts', [ 'operation' => 'count', 'query' => 'a:(', 'variable' => 'c' ]);
	    @unlink("{$dir}/indexer-error");
	    $this->check('wazuh: Alerts get, not found: the error output; count; top values; no indexer said; the indexer\'s reason', [ 'db01', 'error', [ 'count' => 2 ], [ [ 'value' => 'web01', 'count' => 2 ], [ 'value' => 'db01', 'count' => 1 ] ], true, true ], [
		$exe?->getVariable('g')['agent']['name'] ?? null, $none?->getVariable('route'), $cnt?->getVariable('c'), $top?->getVariable('t')['items'] ?? null,
		str_contains((string)self::logLine($noIdx, 'Wazuh Alerts count: '), 'add its address and user to the credential'), str_contains((string)self::logLine($badQuery, 'Wazuh Alerts count: '), 'query_shard_exception: Failed to parse query') ]);

	    //vulnerabilities
	    file_put_contents("{$dir}/vulnerabilities.json", json_encode([
		[ '_id' => 'v1', 'timestamp' => '2026-11-03T08:00:00Z', 'agent' => [ 'id' => '001', 'name' => 'web01' ], 'package' => [ 'name' => 'xz' ], 'vulnerability' => [ 'id' => 'CVE-2024-3094', 'severity' => 'Critical', 'score' => [ 'base' => 10 ] ] ],
		[ '_id' => 'v2', 'timestamp' => '2026-11-03T08:00:00Z', 'agent' => [ 'id' => '002', 'name' => 'db01' ], 'package' => [ 'name' => 'openssl' ], 'vulnerability' => [ 'id' => 'CVE-2023-1', 'severity' => 'High', 'score' => [ 'base' => 7.5 ] ] ],
		[ '_id' => 'v3', 'timestamp' => '2026-11-03T08:00:00Z', 'agent' => [ 'id' => '001', 'name' => 'web01' ], 'package' => [ 'name' => 'bash' ], 'vulnerability' => [ 'id' => 'CVE-2022-2', 'severity' => 'Low', 'score' => [ 'base' => 2 ] ] ] ]));
	    [ $vl, $sent ] = $step('app::wazuh-vulnerabilities', [ 'operation' => 'list', 'severities' => [ 'Critical', 'High' ], 'variable' => 'v' ]);
	    [ $vc, ] = $step('app::wazuh-vulnerabilities', [ 'operation' => 'list', 'cve' => 'CVE-2022-2', 'package' => 'bash', 'agents' => '001', 'severities' => [], 'variable' => 'v' ]);
	    [ $vs, ] = $step('app::wazuh-vulnerabilities', [ 'operation' => 'summary', 'severities' => [], 'variable' => 's' ]);
	    $this->check('wazuh: Vulnerabilities: of severities, the highest score first; a CVE, a package, an agent; per severity', [ [ 'v1', 'v2' ], '/wazuh-states-vulnerabilities-*/_search', [ 'v3' ], [ 'total' => 3, 'Critical' => 1, 'High' => 1, 'Medium' => 0, 'Low' => 1 ] ], [
		array_column($vl?->getVariable('v')['items'] ?? [], '_id'), $sent[0]['path'] ?? null, array_column($vc?->getVariable('v')['items'] ?? [], '_id'), $vs?->getVariable('s') ]);

	    //agents
	    [ $exe, $sent ] = $step('app::wazuh-agents', [ 'operation' => 'list', 'status' => [ 'active' ], 'group' => 'web', 'search' => 'web', 'variable' => 'a' ]);
	    $this->check('wazuh: Agents list: status, group, text; wait_for_complete; first page', [ [ '001' ], 'active', 'web', 'true', '0' ], [ array_column($exe?->getVariable('a')['items'] ?? [], 'id'), $sent[0]['query']['status'] ?? null, $sent[0]['query']['group'] ?? null, $sent[0]['query']['wait_for_complete'] ?? null, $sent[0]['query']['offset'] ?? null ]);
	    [ $get, ] = $step('app::wazuh-agents', [ 'operation' => 'get', 'agent' => '{{ alert }}', 'variable' => 'g' ], [ 'alert' => [ 'agent' => [ 'id' => '002' ] ] ]);
	    [ $ghost, ] = $step('app::wazuh-agents', [ 'operation' => 'get', 'agent' => '77', 'variable' => 'g' ]);
	    [ $sum, ] = $step('app::wazuh-agents', [ 'operation' => 'summary', 'variable' => 's' ]);
	    [ $out, ] = $step('app::wazuh-agents', [ 'operation' => 'outdated', 'variable' => 'o' ]);
	    [ $cr, $sentCr ] = $step('app::wazuh-agents', [ 'operation' => 'create', 'name' => 'app02', 'ip' => '10.0.0.9', 'variable' => 'c' ]);
	    [ $dup, ] = $step('app::wazuh-agents', [ 'operation' => 'create', 'name' => 'web01', 'variable' => 'c' ]);
	    [ $key, ] = $step('app::wazuh-agents', [ 'operation' => 'key', 'agent' => '1', 'variable' => 'k' ]);
	    $this->check('wazuh: Agents get from an alert; not there: the error output; summary; outdated; create: id and key, name and ip sent; a name that exists: Wazuh\'s error; key', [
		'db01', 'error', 3, [ '002' ], [ 'id' => '003', 'key' => 'c2VjcmV0a2V5' ], [ 'name' => 'app02', 'ip' => '10.0.0.9' ], true, 'c2VjcmV0a2V5' ], [
		$get?->getVariable('g')['name'] ?? null, $ghost?->getVariable('route'), $sum?->getVariable('s')['connection']['total'] ?? null, array_column($out?->getVariable('o')['items'] ?? [], 'id'), $cr?->getVariable('c'), $sentCr[0]['body'] ?? null,
		str_contains((string)self::logLine($dup, 'Wazuh Agents create: '), 'An agent with the same name already exists (error 1708)'), $key?->getVariable('k')['key'] ?? null ]);
	    [ $rs, $sent ] = $step('app::wazuh-agents', [ 'operation' => 'restart', 'agents' => '1, 2, 99', 'variable' => 'r' ]);
	    $r = $rs?->getVariable('r');
	    [ $emptyR, $sentEmpty ] = $step('app::wazuh-agents', [ 'operation' => 'restart', 'agents' => '{{ none }}', 'variable' => 'r' ], [ 'none' => '' ]);
	    [ $allR, $sentAll ] = $step('app::wazuh-agents', [ 'operation' => 'restart', 'agents' => 'all', 'variable' => 'r' ]);
	    [ $allFail, ] = $step('app::wazuh-agents', [ 'operation' => 'restart', 'agents' => '98,99', 'variable' => 'r' ]);
	    $this->check('wazuh: restart: some failed: a result that says so; empty agents: nothing sent; `all`: no list; all failed: the error output with Wazuh\'s message', [
		'001,002,099', [ '001', '002' ], 1, 'Agent does not exist', 'error', 0, [ 'PUT', false ], 'error', true ], [
		$sent[0]['query']['agents_list'] ?? null, $r['affected'] ?? null, $r['failedCount'] ?? null, $r['failed'][0]['message'] ?? null, $emptyR?->getVariable('route'), count($sentEmpty), [ $sentAll[0]['method'] ?? null, isset($sentAll[0]['query']['agents_list']) ],
		$allFail?->getVariable('route'), str_contains((string)self::logLine($allFail, 'Wazuh Agents restart: '), '098: Agent does not exist') ]);
	    [ $del, $sent ] = $step('app::wazuh-agents', [ 'operation' => 'delete', 'agents' => '2', 'purge' => true, 'variable' => 'd' ]);
	    [ $delAll, $sentDelAll ] = $step('app::wazuh-agents', [ 'operation' => 'delete', 'agents' => 'all', 'variable' => 'd' ]);
	    $this->check('wazuh: delete: of any status and age, purged; never `all`', [ [ '002', 'all', '0s', 'true' ], [ '002' ], 'error', 0 ], [ [ $sent[0]['query']['agents_list'] ?? null, $sent[0]['query']['status'] ?? null, $sent[0]['query']['older_than'] ?? null, $sent[0]['query']['purge'] ?? null ],
		$del?->getVariable('d')['affected'] ?? null, $delAll?->getVariable('route'), count($sentDelAll) ]);
	    [ $gl, ] = $step('app::wazuh-agents', [ 'operation' => 'groups', 'variable' => 'g' ]);
	    [ $ga, ] = $step('app::wazuh-agents', [ 'operation' => 'groupAgents', 'group' => 'web', 'variable' => 'g' ]);
	    [ $gadd, $sentAdd ] = $step('app::wazuh-agents', [ 'operation' => 'assign', 'agents' => '1', 'group' => 'web', 'variable' => 'g' ]);
	    [ $gdel, $sentDel ] = $step('app::wazuh-agents', [ 'operation' => 'unassign', 'agents' => 'all', 'group' => 'web', 'variable' => 'g' ]);
	    [ $gnew, $sentNew ] = $step('app::wazuh-agents', [ 'operation' => 'groupCreate', 'group' => 'linux', 'variable' => 'g' ]);
	    [ $grm, $sentRm ] = $step('app::wazuh-agents', [ 'operation' => 'groupDelete', 'group' => 'linux', 'variable' => 'g' ]);
	    [ $gbad, $sentBad ] = $step('app::wazuh-agents', [ 'operation' => 'groupDelete', 'group' => '../x', 'variable' => 'g' ]);
	    $this->check('wazuh: groups: list, agents, assign, unassign (its list is required: all), create, delete; a bad name is not sent', [ [ 'default', 'web' ], [ '001' ], [ 'PUT', '001', 'web' ], [ 'DELETE', 'all' ], [ 'group_id' => 'linux' ], 'linux', 'error', 0 ], [
		array_column($gl?->getVariable('g')['items'] ?? [], 'name'), array_column($ga?->getVariable('g')['items'] ?? [], 'id'), [ $sentAdd[0]['method'] ?? null, $sentAdd[0]['query']['agents_list'] ?? null, $sentAdd[0]['query']['group_id'] ?? null ],
		[ $sentDel[0]['method'] ?? null, $sentDel[0]['query']['agents_list'] ?? null ], $sentNew[0]['body'] ?? null, $sentRm[0]['query']['groups_list'] ?? null, $gbad?->getVariable('route'), count($sentBad) ]);

	    //active response
	    [ $bk, $sent ] = $step('app::wazuh-response', [ 'operation' => 'block', 'agents' => '{{ alert }}', 'ip' => '{{ alert.data.srcip }}', 'variable' => 'r' ], [ 'alert' => $alert('x', $recent) ]);
	    [ $bIp, $sentIp ] = $step('app::wazuh-response', [ 'operation' => 'block', 'agents' => '1', 'ip' => '1.2.3; rm -rf', 'variable' => 'r' ]);
	    [ $bNo, $sentNo ] = $step('app::wazuh-response', [ 'operation' => 'block', 'agents' => '{{ none }}', 'ip' => '203.0.113.5', 'variable' => 'r' ], [ 'none' => '' ]);
	    [ $run, $sentRun ] = $step('app::wazuh-response', [ 'operation' => 'run', 'agents' => 'all', 'command' => '!custom-script', 'arguments' => "a\nb", 'alert' => '{"user": "bob"}', 'variable' => 'r' ]);
	    [ $rbad, $sentRbad ] = $step('app::wazuh-response', [ 'operation' => 'run', 'agents' => '1', 'command' => '../evil', 'variable' => 'r' ]);
	    $this->check('wazuh: Active Response: block: the script with the alert\'s address, on the alert\'s agent; a bad address, no agents: not sent; run: on `all`, arguments, alert data; a bad command: not sent', [
		[ '001', [ 'command' => '!firewall-drop', 'arguments' => [], 'alert' => [ 'data' => [ 'srcip' => '203.0.113.5' ] ] ] ], [ '001' ], 0, 0, [ false, [ 'command' => '!custom-script', 'arguments' => [ 'a', 'b' ], 'alert' => [ 'data' => [ 'user' => 'bob' ] ] ] ], 0 ], [
		[ $sent[0]['query']['agents_list'] ?? null, $sent[0]['body'] ?? null ], $bk?->getVariable('r')['affected'] ?? null, count($sentIp), count($sentNo), [ isset($sentRun[0]['query']['agents_list']), $sentRun[0]['body'] ?? null ], count($sentRbad) ]);

	    //scans, inventory, manager
	    [ $sp, ] = $step('app::wazuh-scans', [ 'operation' => 'scaPolicies', 'agent' => '1', 'variable' => 's' ]);
	    [ $sc, ] = $step('app::wazuh-scans', [ 'operation' => 'scaChecks', 'agent' => '1', 'policy' => 'cis_ubuntu24', 'result' => 'failed', 'variable' => 's' ]);
	    [ $fi, $sentFi ] = $step('app::wazuh-scans', [ 'operation' => 'fim', 'agent' => '1', 'file' => '/etc/passwd', 'variable' => 's' ]);
	    [ $fl, ] = $step('app::wazuh-scans', [ 'operation' => 'fimLast', 'agent' => '1', 'variable' => 's' ]);
	    [ $fr, $sentFr ] = $step('app::wazuh-scans', [ 'operation' => 'fimRun', 'agents' => '1', 'variable' => 's' ]);
	    [ $ro, ] = $step('app::wazuh-scans', [ 'operation' => 'rootcheck', 'agent' => '1', 'variable' => 's' ]);
	    [ $rr, $sentRr ] = $step('app::wazuh-scans', [ 'operation' => 'rootcheckRun', 'agents' => '{{ none }}', 'variable' => 's' ], [ 'none' => '' ]);
	    $this->check('wazuh: Scans: SCA policies, failed checks, FIM files of a path, last scan, FIM started, rootcheck; started without agents: not sent', [ 75, [ 1 ], '/etc/passwd', '2026-11-03T09:05:00Z', [ '/syscheck', '001' ], 'solved', 0 ], [
		$sp?->getVariable('s')['items'][0]['score'] ?? null, array_column($sc?->getVariable('s')['items'] ?? [], 'id'), $sentFi[0]['query']['file'] ?? null, $fl?->getVariable('s')['end'] ?? null, [ $sentFr[0]['path'] ?? null, $sentFr[0]['query']['agents_list'] ?? null ],
		$ro?->getVariable('s')['items'][0]['status'] ?? null, count($sentRr) ]);
	    [ $io, ] = $step('app::wazuh-inventory', [ 'operation' => 'os', 'agent' => '1', 'variable' => 'i' ]);
	    [ $ip, ] = $step('app::wazuh-inventory', [ 'operation' => 'packages', 'agent' => '1', 'maxItems' => 700, 'variable' => 'i' ]);
	    $this->check('wazuh: Inventory: one item; a list read in pages up to At most', [ 'os', 700, 1200, true ], [ $io?->getVariable('i')['kind'] ?? null, $ip?->getVariable('i')['count'] ?? null, $ip?->getVariable('i')['total'] ?? null, $ip?->getVariable('i')['truncated'] ?? null ]);
	    [ $ms, ] = $step('app::wazuh-manager', [ 'operation' => 'status', 'variable' => 'm' ]);
	    [ $mi, ] = $step('app::wazuh-manager', [ 'operation' => 'info', 'variable' => 'm' ]);
	    [ $mc, ] = $step('app::wazuh-manager', [ 'operation' => 'cluster', 'variable' => 'm' ]);
	    [ $ml, $sentMl ] = $step('app::wazuh-manager', [ 'operation' => 'logs', 'logLevel' => 'error', 'tag' => 'wazuh-analysisd', 'variable' => 'm' ]);
	    [ $mr, $sentMr ] = $step('app::wazuh-manager', [ 'operation' => 'rules', 'ruleIds' => '5712,5501', 'level' => '7-10', 'group' => 'sshd', 'variable' => 'm' ]);
	    [ $md, ] = $step('app::wazuh-manager', [ 'operation' => 'decoders', 'variable' => 'm' ]);
	    [ $mx, $sentMx ] = $step('app::wazuh-manager', [ 'operation' => 'restart', 'variable' => 'm' ]);
	    $this->check('wazuh: Manager: status (a failed daemon said); info; cluster; log of a level; rules: ids, level, group; decoders; restart (takes no wait_for_complete)', [ true, 'v4.14.0', 'no', 'error', [ '5712,5501', '7-10', 'sshd' ], 'sshd', [ 'wazuh-manager' ], [] ], [
		str_contains((string)self::logLine($ms, 'Wazuh Manager status: '), '2 daemons running, wazuh-db in another state'), $mi?->getVariable('m')['version'] ?? null, $mc?->getVariable('m')['enabled'] ?? null, $sentMl[0]['query']['level'] ?? null,
		[ $sentMr[0]['query']['rule_ids'] ?? null, $sentMr[0]['query']['level'] ?? null, $sentMr[0]['query']['group'] ?? null ], $md?->getVariable('m')['items'][0]['name'] ?? null, $mx?->getVariable('m')['affected'] ?? null, $sentMx[0]['query'] ?? null ]);

	    //API, Other parameters
	    [ $as, ] = $step('app::wazuh-api', [ 'operation' => 'server', 'method' => 'GET', 'path' => '/groups', 'query' => '{"limit": 1}', 'variable' => 'a' ]);
	    [ $ai, $sentAi ] = $step('app::wazuh-api', [ 'operation' => 'indexer', 'method' => 'POST', 'path' => '/wazuh-alerts-*/_search', 'body' => '{"size": 0}', 'variable' => 'a' ]);
	    [ $an, ] = $step('app::wazuh-api', [ 'operation' => 'server', 'method' => 'GET', 'path' => '/nothing', 'variable' => 'a' ]);
	    [ $op, $sentOp ] = $step('app::wazuh-agents', [ 'operation' => 'list', 'params' => '{"q": "os.platform=ubuntu"}', 'variable' => 'a' ]);
	    [ $oa, $sentOa ] = $step('app::wazuh-alerts', [ 'operation' => 'search', 'params' => '{"_source": ["rule"]}', 'variable' => 'a' ]);
	    [ $ob, ] = $step('app::wazuh-agents', [ 'operation' => 'list', 'params' => '[1]', 'variable' => 'a' ]);
	    $this->check('wazuh: API: to the server and to the indexer; a path it has not: the error output; Other parameters: in the query, in the search; not an object: the error output', [ 1, [ 'size' => 0 ], 'error', 'os.platform=ubuntu', [ 'rule' ], 'error' ], [
		count($as?->getVariable('a')['data']['affected_items'] ?? []), $sentAi[0]['body'] ?? null, $an?->getVariable('route'), $sentOp[0]['query']['q'] ?? null, $sentOa[0]['body']['_source'] ?? null, $ob?->getVariable('route') ]);

	    //the trigger
	    $wf = $this->workflow('wz alert', [ $n('t', 'trigger::wazuh', [ 'credential' => (string)$cred->_id, 'minLevel' => 7, 'runs' => 'each', 'variable' => 'ev', 'mode' => 'interval', 'every' => 2, 'every_unit' => 'minutes' ]),
		$n('s', 'app::set', [ 'variable' => 'seen', 'value' => '{{ ev.rule.id }}' ]), $e('e1', 't', 's') ]);
	    $wfs[] = $wf;
	    $this->check('wazuh: the alert trigger\'s workflow is valid', true, $wf->validates());
	    $noIdxWf = $this->workflow('wz alert none', [ $n('t', 'trigger::wazuh', [ 'credential' => (string)$noIndexer->_id, 'variable' => 'ev', 'mode' => 'interval', 'every' => 2, 'every_unit' => 'minutes' ]) ]);
	    $wfs[] = $noIdxWf;
	    $this->check('wazuh: the alert trigger needs a credential with the indexer', [ false, true ], [ $noIdxWf->validates(), str_contains(json_encode($noIdxWf->errors()), 'the credential has no indexer') ]);
	    $T = \plugins\ITOps\models\node\NodeTriggerWazuh::class;
	    $node = $wf->nodes['t'];
	    $at = fn($offset) => gmdate('Y-m-d\TH:i:s', time() + $offset) . '.000+0000';
	    $show = fn($runs) => array_map(fn($x) => $x->getVariable('ev')['_id'] ?? null, $runs);
	    //the newest alert of any level sets the floor: the clock of Wazuh, not ours
	    $alerts([ $alert('b0', $at(-500), 12), $alert('b1', $at(-300), 2) ]);
	    [ $d0, $r0 ] = $T::check($wf, $node, []);
	    $floorOk = $d0['since'] === (int)(new \DateTimeImmutable($at(-300)))->format('Uv');
	    $alerts([ $alert('b0', $at(-500), 12), $alert('b1', $at(-300), 2), $alert('b2', $at(-290), 12), $alert('b3', $at(-280), 3), $alert('b4', $at(-270), 9, 'db01') ]);
	    [ $d1, $r1 ] = $T::check($wf, $node, $d0);
	    [ $d2, $r2 ] = $T::check($wf, $node, $d1);
	    //late: older than the newest started, inside the lag
	    $alerts([ $alert('b0', $at(-500), 12), $alert('b2', $at(-290), 12), $alert('b4', $at(-270), 9, 'db01'), $alert('b5', $at(-275), 11) ]);
	    [ $d3, $r3 ] = $T::check($wf, $node, $d2);
	    //older than the first check: never
	    $alerts([ $alert('b6', $at(-400), 15) ]);
	    [ $d4, $r4 ] = $T::check($wf, $node, $d3);
	    //a flood: MAX_RUNS per check, the others in the next one
	    $flood = array_map(fn($i) => $alert("f{$i}", $at(-200 + (int)floor($i / 10)), 12), range(1, 250));
	    $alerts($flood);
	    [ $d5, $r5 ] = $T::check($wf, $node, $d4);
	    foreach($r5 as $x)
		$started[] = $x;
	    [ $d6, $r6 ] = $T::check($wf, $node, $d5);
	    $started = array_merge($started, $r0, $r1, $r2, $r3, $r4, $r6);
	    $this->check('wazuh: alert trigger: the first check notes the time of Wazuh\'s newest alert; then new ones of the level start a run, oldest first, once; a late one too; one older than the first check never', [
		true, [], [ 'b2', 'b4' ], [], [ 'b5' ], [] ], [ $floorOk, $show($r0), $show($r1), $show($r2), $show($r3), $show($r4) ]);
	    $this->check('wazuh: alert trigger: a flood: 200 runs in a check, the rest in the next, none twice', [ 200, 50, 250 ], [ count($r5), count($r6), count(array_unique(array_merge($show($r5), $show($r6)))) ]);
	    $this->check('wazuh: alert trigger: the runs queued, at the trigger, with the alert', [ 'new', 'wazuh', 'Rule 5712' ], [ $r1[0]?->state, $r1[0]?->trigger, $r1[0]?->getVariable('ev')['rule']['description'] ?? null ]);
	    //what is kept does not grow: nothing older than the lag
	    $alerts([ $alert('c1', $at(3600), 12) ]);
	    [ $d7, $r7 ] = $T::check($wf, $node, $d6);
	    $started = array_merge($started, $r7);
	    $this->check('wazuh: alert trigger: what it has seen is forgotten after the lag', [ [ 'c1' ], [ 'c1' ] ], [ $show($r7), array_map(fn($s) => $s[0], $d7['seen']) ]);
	    //batch
	    $batch = $node;
	    $batch['config'] = [ 'runs' => 'batch' ] + (array)$node['config'];
	    $alerts([ $alert('d1', $at(3700), 12), $alert('d2', $at(3701), 12) ]);
	    [ , $r8 ] = $T::check($wf, $batch, $d7);
	    $started = array_merge($started, $r8);
	    $this->check('wazuh: alert trigger: one run per check', [ 1, 2, [ 'd1', 'd2' ] ], [ count($r8), $r8[0]?->getVariable('ev')['count'] ?? null, array_column($r8[0]?->getVariable('ev')['alerts'] ?? [], '_id') ]);
	    $alerts([ $alert('e1', $at(-100), 12), $alert('e2', $at(-50), 12), $alert('e3', $at(-10), 2) ]);
	    $now = $T::startNow($wf, $node);
	    $started = array_merge($started, $now['executions'] ?? []);
	    $this->check('wazuh: alert trigger: Run in the designer: the newest match', [ 1, 'e2' ], [ count($now['executions'] ?? []), ($now['executions'][0] ?? null)?->getVariable('ev')['_id'] ?? null ]);
	} finally {
	    $W::$testing = false;
	    $W::forgetTokens();
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		\models\TriggerState::beforeWorkflowDelete($wf);
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //Namecheap against a fake one (tests/NamecheapTestServer.php; plugins\ITOps\util\Namecheap::$testUrl: http, no address guard): the values (domains, hosts,
    //records, the email type), the client (POST with the caller's fields, Namecheap's errors with what they mean), the credential's form and test, every step; the DNS changes keep
    //the records they do not name (setHosts replaces them all) and send EmailType MX with MX records; unchanged records are not written
    'namecheap' => function() {
	$NC = \plugins\ITOps\util\Namecheap::class;
	$D = \plugins\ITOps\models\node\NodeAppNcDns::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $x) {
		return $x->getMessage();
	    }
	};
	$this->check('namecheap: values: a domain\'s name (sld, tld; of a list item), a host, a record, the email type', [
	    [ 'example.co.uk', 'example', 'co.uk' ], [ 'example.com', 'example', 'com' ], 'Domain: not a domain\'s name: `localhost`', 'Domain: empty', '@', '@', 'www', 'a.b', '*', 'Host: not a host\'s name: `a b`',
	    [ 'Name' => 'mail', 'Type' => 'MX', 'Address' => 'mx.example.net', 'TTL' => 300, 'MXPref' => 5 ], 'Record: type: not a record type Namecheap has: `SRV`',
	    'MX', 'MXE', 'NONE', 'FWD', null, true, false, true ], [
	    $NC::domain(' Example.CO.uk. '), $NC::domain([ 'Name' => 'example.com', 'Expires' => '01/01/2030' ]), $thrown(fn() => $NC::domain('localhost')), $thrown(fn() => $NC::domain('')),
	    $D::host('', 'example.com'), $D::host('example.com', 'example.com'), $D::host('WWW.example.com', 'example.com'), $D::host('a.b', 'example.com'), $D::host('*', 'example.com'), $thrown(fn() => $D::host('a b', 'example.com')),
	    $D::record([ 'host' => 'mail.example.com', 'type' => 'mx', 'value' => 'mx.example.net', 'ttl' => '300', 'mxPref' => 5 ], 'example.com'), $thrown(fn() => $D::record([ 'type' => 'SRV', 'value' => 'x' ], 'example.com')),
	    $D::emailType([ [ 'Type' => 'A' ], [ 'Type' => 'MX' ] ], 'FWD'), $D::emailType([ [ 'Type' => 'MXE' ] ], ''), $D::emailType([ [ 'Type' => 'A' ] ], 'MX'), $D::emailType([], 'fwd'), $D::emailType([], ''),
	    $D::sameRecord([ 'Name' => 'WWW', 'Type' => 'CNAME', 'Address' => 'Host.example.net.' ], [ 'Name' => 'www', 'Type' => 'CNAME', 'Address' => 'host.example.net' ]),
	    $D::sameRecord([ 'Name' => '@', 'Type' => 'TXT', 'Address' => 'A' ], [ 'Name' => '@', 'Type' => 'TXT', 'Address' => 'a' ]),
	    $D::sameRecord([ 'Name' => '@', 'Type' => 'A', 'Address' => '1.2.3.4' ], [ 'Name' => '@', 'Type' => 'A', 'Address' => ' 1.2.3.4 ' ]) ]);
	$xml = $NC::parse('<?xml version="1.0"?><ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><CommandResponse><R A="true" B="x"><C>1</C><C>2</C><D E="false">t</D></R></CommandResponse></ApiResponse>');
	$this->check('namecheap: the answer read: attributes (booleans), repeated children a list, text; a DOCTYPE refused', [ [ 'A' => true, 'B' => 'x', 'C' => [ '1', '2' ], 'D' => [ 'E' => false, 'value' => 't' ] ], null ],
	    [ $NC::toArray($xml->CommandResponse->R), $NC::parse('<!DOCTYPE x [<!ENTITY a "b">]><ApiResponse Status="OK"/>') ]);

	$dir = sys_get_temp_dir() . '/qflows-check-nc-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('namecheap', '../../plugins/ITOps/tests/NamecheapTestServer.php', $dir, [ 'NC_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$NC::$testUrl = "http://127.0.0.1:{$port}/xml.response";
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$put = fn($name, $v) => file_put_contents("{$dir}/{$name}.json", json_encode($v));
	$get = fn($name) => json_decode((string)@file_get_contents("{$dir}/{$name}.json"), true);
	$creds = [];
	try {
	    $make = function(array $over = []) use ($om, &$creds) {
		$c = $om->create('CredentialNamecheap', $over + [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Namecheap ' . uniqid(), 'apiUser' => 'nfuser', 'apiKey' => 'k3y', 'clientIp' => '203.0.113.10' ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('namecheap: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		return $om->findById('CredentialNamecheap', (string)$c->_id);
	    };
	    $cred = $make();
	    $wrongKey = $make([ 'apiKey' => 'nope' ]);
	    $wrongIp = $make([ 'clientIp' => '198.51.100.1' ]);
	    if (!$cred || !$wrongKey || !$wrongIp)
		return;
	    $bad = $om->create('CredentialNamecheap', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'x', 'apiUser' => 'a b', 'apiKey' => 'k', 'clientIp' => '300.1.1.1' ]);
	    $bad->validates();
	    $badKeys = array_keys((array)$bad->errors());
	    sort($badKeys);
	    $requests();
	    $this->check('namecheap: the form: user names, an IPv4 address; Test: the balance; the caller\'s fields by POST, the key not in the address; a wrong key, an IP not whitelisted said', [
		[ 'apiUser', 'clientIp' ], 'connected to Namecheap as nfuser; balance 42.50 USD', [ 'POST', '/xml.response', 'nfuser', 'nfuser', '203.0.113.10', 'namecheap.users.getBalances' ],
		'Parameter APIKey is invalid (error 1011102): the API key is wrong, or API access is not enabled for the user',
		'Parameter RequestIP is invalid (error 1011150): the credential\'s client IP is not whitelisted for the API user (Namecheap: Profile > Tools > API Access)' ], [
		$badKeys, $cred->test(), (function() use ($requests) {
		    $r = $requests()[0] ?? [];
		    return [ $r['method'] ?? null, $r['path'] ?? null, $r['form']['ApiUser'] ?? null, $r['form']['UserName'] ?? null, $r['form']['ClientIp'] ?? null, $r['form']['Command'] ?? null ];
		})(), $thrown(fn() => $wrongKey->test()), $thrown(fn() => $wrongIp->test()) ]);
	    $client = $cred->client($this->account->_id, 20);
	    $this->check('namecheap: not a command; an unknown one: Namecheap\'s error; the caller\'s fields not changed by the parameters', [ 'not a Namecheap command: `users.getBalances`',
		'Command namecheap.nothing.here not found (error 1010000)', 'k3y' ], [ $thrown(fn() => $client->call('users.getBalances')), $thrown(fn() => $client->call('namecheap.nothing.here')),
		(function() use ($client, $requests) {
		    $requests();
		    $client->call('namecheap.users.getBalances', [ 'ApiKey' => 'other' ]);
		    return $requests()[0]['form']['ApiKey'] ?? null;
		})() ]);

	    $step = function($fq, $cfg, $vars = []) use ($n, $e, $cred, $requests) {
		$requests();
		$exe = $this->runFlow("nc {$fq}", [ $n('m', 'trigger::manual'), $n('c', $fq, $cfg + [ 'credential' => (string)$cred->_id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'c'), $e('e2', 'c', 'err', 1) ], $vars);
		return [ $exe, $requests() ];
	    };
	    $commands = fn($sent) => array_map(fn($r) => $r['form']['Command'] ?? null, $sent);

	    //domains
	    $day = 86400;
	    $domains = [];
	    for ($i = 0; $i < 130; $i++)
		$domains[] = [ 'Name' => sprintf('d%03d.com', $i), 'Expires' => gmdate('m/d/Y', time() + (200 + $i) * $day) ];
	    $domains[] = [ 'Name' => 'example.com', 'Expires' => gmdate('m/d/Y', time() + 10 * $day) ];
	    $domains[] = [ 'Name' => 'soon.ro', 'Expires' => gmdate('m/d/Y', time() + 25 * $day + 3600) ];
	    $domains[] = [ 'Name' => 'gone.net', 'Expires' => gmdate('m/d/Y', time() - 5 * $day) ];
	    $put('domains', $domains);
	    [ $l, $sent ] = $step('app::namecheap-domains', [ 'operation' => 'list', 'variable' => 'r' ]);
	    $r = $l?->getVariable('r');
	    [ $l2, ] = $step('app::namecheap-domains', [ 'operation' => 'list', 'search' => 'd12', 'maxItems' => 5, 'variable' => 'r' ]);
	    $r2 = $l2?->getVariable('r');
	    $this->check('namecheap: Domains list: pages of 100, all of them; the attributes, booleans; containing a text; truncated at At most', [ 133, 133, false, [ 1, 2 ], 'd000.com', false, [ 10, 5, true ] ], [
		$r['count'] ?? null, $r['total'] ?? null, $r['truncated'] ?? null, array_map(fn($s) => (int)$s['form']['Page'], $sent), $r['items'][0]['Name'] ?? null, $r['items'][0]['IsLocked'] ?? null,
		[ $r2['total'] ?? null, $r2['count'] ?? null, $r2['truncated'] ?? null ] ]);
	    [ $x, $sent ] = $step('app::namecheap-domains', [ 'operation' => 'expiring', 'days' => 30, 'variable' => 'r' ]);
	    [ $x2, ] = $step('app::namecheap-domains', [ 'operation' => 'expiring', 'days' => 30, 'includeExpired' => false, 'variable' => 'r' ]);
	    $this->check('namecheap: Domains expiring: within the days, soonest first, with daysLeft; expired ones too or not; only the first page read (sorted by expiry)', [
		[ 'gone.net', 'example.com', 'soon.ro' ], [ -5, 10, 25 ], [ 'example.com', 'soon.ro' ], [ 'EXPIREDATE' ] ], [
		array_column($x?->getVariable('r')['items'] ?? [], 'Name'), array_column($x?->getVariable('r')['items'] ?? [], 'daysLeft'), array_column($x2?->getVariable('r')['items'] ?? [], 'Name'),
		array_map(fn($s) => $s['form']['SortBy'] ?? null, $sent) ]);
	    [ $g, ] = $step('app::namecheap-domains', [ 'operation' => 'get', 'domain' => '{{ d }}', 'variable' => 'g' ], [ 'd' => [ 'Name' => 'example.com' ] ]);
	    [ $ghost, ] = $step('app::namecheap-domains', [ 'operation' => 'get', 'domain' => 'ghost.com', 'variable' => 'g' ]);
	    [ $chk, $sent ] = $step('app::namecheap-domains', [ 'operation' => 'check', 'domains' => 'new-name.com, taken.com', 'variable' => 'c' ]);
	    [ $tooMany, ] = $step('app::namecheap-domains', [ 'operation' => 'check', 'domains' => implode(',', array_map(fn($i) => "n{$i}.com", range(1, 51))), 'variable' => 'c' ]);
	    $this->check('namecheap: Domains get (of a list item), not found: the error output; check: which are available, at most 50', [ [ 'example.com', true, 'dns1.registrar-servers.com' ], 'error',
		'Domain not found (error 2019166)', [ 'new-name.com' ], 'new-name.com,taken.com', 'Names: at most 50 in one check, not 51' ], [
		[ $g?->getVariable('g')['DomainName'] ?? null, $g?->getVariable('g')['IsOwner'] ?? null, $g?->getVariable('g')['DnsDetails']['Nameserver'][0] ?? null ], $ghost?->getVariable('route'),
		str_replace('Namecheap Domains get: ', '', (string)self::logLine($ghost, 'Namecheap Domains get: ')), $chk?->getVariable('c')['available'] ?? null, $sent[0]['form']['DomainList'] ?? null,
		str_replace('Namecheap Domains check: ', '', (string)self::logLine($tooMany, 'Namecheap Domains check: ')) ]);
	    [ $lk, ] = $step('app::namecheap-domains', [ 'operation' => 'lock', 'domain' => 'example.com', 'variable' => 'l' ]);
	    [ $ls, ] = $step('app::namecheap-domains', [ 'operation' => 'lockStatus', 'domain' => 'example.com', 'variable' => 'l' ]);
	    [ $ul, $sent ] = $step('app::namecheap-domains', [ 'operation' => 'unlock', 'domain' => 'example.com', 'variable' => 'l' ]);
	    [ $ls2, ] = $step('app::namecheap-domains', [ 'operation' => 'lockStatus', 'domain' => 'example.com', 'variable' => 'l' ]);
	    [ $rn, $rsent ] = $step('app::namecheap-domains', [ 'operation' => 'renew', 'domain' => 'example.com', 'years' => 2, 'variable' => 'n' ]);
	    [ $ct, ] = $step('app::namecheap-domains', [ 'operation' => 'contacts', 'domain' => 'example.com', 'variable' => 'c' ]);
	    [ $tl, ] = $step('app::namecheap-domains', [ 'operation' => 'tlds', 'maxItems' => 2, 'variable' => 't' ]);
	    $this->check('namecheap: Domains lock, its state, unlock; renew: the years, what was charged; contacts; TLDs, truncated', [ true, 'UNLOCK', false, '2', '21.96', 'Ana', [ 'com', 'ro' ], [ 3, true ] ], [
		$ls?->getVariable('l')['locked'] ?? null, $sent[0]['form']['LockAction'] ?? null, $ls2?->getVariable('l')['locked'] ?? null, $rsent[0]['form']['Years'] ?? null, $rn?->getVariable('n')['ChargedAmount'] ?? null,
		$ct?->getVariable('c')['Registrant']['FirstName'] ?? null, array_column($tl?->getVariable('t')['items'] ?? [], 'Name'), [ $tl?->getVariable('t')['total'] ?? null, $tl?->getVariable('t')['truncated'] ?? null ] ]);

	    //DNS
	    $put('hosts', [ 'example.com' => [ 'emailType' => 'FWD', 'records' => [ [ 'Name' => '@', 'Type' => 'A', 'Address' => '192.0.2.1', 'TTL' => 1800 ], [ 'Name' => 'www', 'Type' => 'CNAME', 'Address' => 'example.com.', 'TTL' => 1800 ],
		[ 'Name' => '@', 'Type' => 'TXT', 'Address' => 'v=spf1 -all', 'TTL' => 3600 ] ] ] ]);
	    $records = fn() => array_map(fn($r) => "{$r['Name']} {$r['Type']} {$r['Address']} {$r['TTL']}" . (isset($r['MXPref']) ? " {$r['MXPref']}" : ''), $get('hosts')['example.com']['records'] ?? []);
	    [ $ls, ] = $step('app::namecheap-dns', [ 'operation' => 'records', 'domain' => 'example.com', 'variable' => 'd' ]);
	    [ $lsA, $sent ] = $step('app::namecheap-dns', [ 'operation' => 'records', 'domain' => 'example.com', 'host' => 'example.com', 'type' => 'A', 'variable' => 'd' ]);
	    $this->check('namecheap: DNS records: all; of a host (its full name) and type; the email type; SLD / TLD sent', [ 3, [ '192.0.2.1' ], 'FWD', [ 'example', 'com' ] ], [
		$ls?->getVariable('d')['count'] ?? null, array_column($lsA?->getVariable('d')['records'] ?? [], 'Address'), $ls?->getVariable('d')['emailType'] ?? null, [ $sent[0]['form']['SLD'] ?? null, $sent[0]['form']['TLD'] ?? null ] ]);
	    [ $add, $sent ] = $step('app::namecheap-dns', [ 'operation' => 'add', 'domain' => 'example.com', 'host' => 'api', 'type' => 'A', 'address' => '192.0.2.9', 'ttl' => 300, 'variable' => 'd' ]);
	    $after = $records();
	    [ $again, $sent2 ] = $step('app::namecheap-dns', [ 'operation' => 'add', 'domain' => 'example.com', 'host' => 'api', 'type' => 'A', 'address' => '192.0.2.9', 'ttl' => 300, 'variable' => 'd' ]);
	    $this->check('namecheap: DNS add: the others kept, the email type kept; the same record again: not written', [
		[ '@ A 192.0.2.1 1800', 'www CNAME example.com. 1800', '@ TXT v=spf1 -all 3600', 'api A 192.0.2.9 300' ], [ 'namecheap.domains.dns.getHosts', 'namecheap.domains.dns.setHosts' ], 'FWD', true,
		[ 'namecheap.domains.dns.getHosts' ], false ], [ $after, $commands($sent), $sent[1]['form']['EmailType'] ?? null, $add?->getVariable('d')['changed'] ?? null, $commands($sent2), $again?->getVariable('d')['changed'] ?? null ]);
	    [ $mx, $sent ] = $step('app::namecheap-dns', [ 'operation' => 'add', 'domain' => 'example.com', 'host' => '@', 'type' => 'MX', 'address' => 'mx1.example.net', 'mxPref' => 5, 'variable' => 'd' ]);
	    $this->check('namecheap: DNS add an MX record: EmailType MX, its priority', [ 'MX', '5', 'MX', '@ MX mx1.example.net 1800 5' ], [ $sent[1]['form']['EmailType'] ?? null, $sent[1]['form']['MXPref5'] ?? null, $get('hosts')['example.com']['emailType'] ?? null, $records()[4] ?? null ]);
	    $step('app::namecheap-dns', [ 'operation' => 'add', 'domain' => 'example.com', 'host' => 'api', 'type' => 'A', 'address' => '192.0.2.10', 'variable' => 'd' ]);
	    [ $set, ] = $step('app::namecheap-dns', [ 'operation' => 'set', 'domain' => 'example.com', 'host' => 'api', 'type' => 'A', 'address' => '{{ ip }}', 'ttl' => 60, 'variable' => 'd' ], [ 'ip' => '198.51.100.20' ]);
	    $apiA = array_values(array_filter($records(), fn($r) => str_starts_with($r, 'api ')));
	    [ $same, $sent ] = $step('app::namecheap-dns', [ 'operation' => 'set', 'domain' => 'example.com', 'host' => 'api', 'type' => 'A', 'address' => '198.51.100.20', 'ttl' => 60, 'variable' => 'd' ]);
	    $this->check('namecheap: DNS set: the host\'s records of the type replaced by one; the same again: not written', [ [ 'api A 198.51.100.20 60' ], 2, [ 'namecheap.domains.dns.getHosts' ], 6 - 2 + 1 ], [
		$apiA, count($set?->getVariable('d')['removed'] ?? []), $commands($sent), count($records()) ]);
	    [ $rm, ] = $step('app::namecheap-dns', [ 'operation' => 'remove', 'domain' => 'example.com', 'host' => '@', 'type' => 'MX', 'variable' => 'd' ]);
	    [ $rm0, $sent ] = $step('app::namecheap-dns', [ 'operation' => 'remove', 'domain' => 'example.com', 'host' => 'nothing', 'type' => 'A', 'variable' => 'd' ]);
	    $this->check('namecheap: DNS remove: the MX records gone, EmailType back to NONE; none matching: nothing written', [ 1, false, 'NONE', 0, [ 'namecheap.domains.dns.getHosts' ] ], [
		count($rm?->getVariable('d')['removed'] ?? []), in_array('@ MX mx1.example.net 1800 5', $records(), true), $get('hosts')['example.com']['emailType'] ?? null, count($rm0?->getVariable('d')['removed'] ?? []), $commands($sent) ]);
	    [ $rp, ] = $step('app::namecheap-dns', [ 'operation' => 'replace', 'domain' => 'example.com', 'records' => '{{ list }}', 'variable' => 'd' ], [ 'list' => [ [ 'host' => '@', 'type' => 'A', 'value' => '203.0.113.1' ],
		[ 'Name' => 'www', 'Type' => 'CNAME', 'Address' => 'example.com.', 'TTL' => 600, 'HostId' => 101 ] ] ]);
	    [ $rpBad, ] = $step('app::namecheap-dns', [ 'operation' => 'replace', 'domain' => 'example.com', 'records' => '[{"type": "A"}]', 'variable' => 'd' ]);
	    $this->check('namecheap: DNS replace: exactly the list (records of List records work too); a record without a value: the error output, nothing written', [
		[ '@ A 203.0.113.1 1800', 'www CNAME example.com. 600' ], 'error', 'Records: item 1: no value' ], [ $records(), $rpBad?->getVariable('route'), str_replace('Namecheap DNS replace: ', '', (string)self::logLine($rpBad, 'Namecheap DNS replace: ')) ]);
	    [ $ns0, ] = $step('app::namecheap-dns', [ 'operation' => 'nameservers', 'domain' => 'example.com', 'variable' => 'n' ]);
	    [ , $sent ] = $step('app::namecheap-dns', [ 'operation' => 'setNameservers', 'domain' => 'example.com', 'nameservers' => 'NS1.example.net, ns2.example.net', 'variable' => 'n' ]);
	    [ $ns1, ] = $step('app::namecheap-dns', [ 'operation' => 'nameservers', 'domain' => 'example.com', 'variable' => 'n' ]);
	    [ $one, ] = $step('app::namecheap-dns', [ 'operation' => 'setNameservers', 'domain' => 'example.com', 'nameservers' => 'ns1.example.net', 'variable' => 'n' ]);
	    $step('app::namecheap-dns', [ 'operation' => 'resetNameservers', 'domain' => 'example.com', 'variable' => 'n' ]);
	    [ $ns2, ] = $step('app::namecheap-dns', [ 'operation' => 'nameservers', 'domain' => 'example.com', 'variable' => 'n' ]);
	    $this->check('namecheap: DNS name servers: Namecheap\'s; custom ones (at least 2); Namecheap\'s again', [ true, 'ns1.example.net,ns2.example.net', [ 'ns1.example.net', 'ns2.example.net' ], false, 'error', true ], [
		$ns0?->getVariable('n')['usingNamecheapDns'] ?? null, $sent[0]['form']['Nameservers'] ?? null, $ns1?->getVariable('n')['nameservers'] ?? null, $ns1?->getVariable('n')['usingNamecheapDns'] ?? null,
		$one?->getVariable('route'), $ns2?->getVariable('n')['usingNamecheapDns'] ?? null ]);
	    [ $sf, $sent ] = $step('app::namecheap-dns', [ 'operation' => 'setForwarding', 'domain' => 'example.com', 'forwards' => '[{"mailbox": "Info", "forwardTo": "ana@example.org"}, {"mailbox": "sales", "forwardTo": "bob@example.org"}]', 'variable' => 'f' ]);
	    [ $gf, ] = $step('app::namecheap-dns', [ 'operation' => 'forwarding', 'domain' => 'example.com', 'variable' => 'f' ]);
	    [ $bf, ] = $step('app::namecheap-dns', [ 'operation' => 'setForwarding', 'domain' => 'example.com', 'forwards' => '[{"mailbox": "x", "forwardTo": "not an address"}]', 'variable' => 'f' ]);
	    $this->check('namecheap: DNS email forwarding: set, read back; a wrong address refused', [ [ 'info', 'ana@example.org' ], [ [ 'mailbox' => 'info', 'forwardTo' => 'ana@example.org' ], [ 'mailbox' => 'sales', 'forwardTo' => 'bob@example.org' ] ], 'error' ], [
		[ $sent[0]['form']['MailBox1'] ?? null, $sent[0]['form']['ForwardTo1'] ?? null ], $gf?->getVariable('f')['forwards'] ?? null, $bf?->getVariable('route') ]);

	    //account, API, Other parameters
	    [ $b, ] = $step('app::namecheap-account', [ 'operation' => 'balances', 'variable' => 'a' ]);
	    [ $p, $sent ] = $step('app::namecheap-account', [ 'operation' => 'pricing', 'tld' => '.COM', 'action' => 'RENEW', 'variable' => 'a' ]);
	    [ $p0, ] = $step('app::namecheap-account', [ 'operation' => 'pricing', 'tld' => 'nope', 'variable' => 'a' ]);
	    [ $ssl, ] = $step('app::namecheap-account', [ 'operation' => 'ssl', 'variable' => 'a' ]);
	    $this->check('namecheap: Account balances; prices of a TLD, per years; none: an error; SSL certificates', [ '42.50', [ 'DOMAIN', 'RENEW', 'com' ], [ [ 'com', 'renew', '1', '10.98' ], [ 'com', 'renew', '2', '21.96' ] ], 'error', [ 'www.example.com' ] ], [
		$b?->getVariable('a')['AvailableBalance'] ?? null, [ $sent[0]['form']['ProductType'] ?? null, $sent[0]['form']['ActionName'] ?? null, $sent[0]['form']['ProductName'] ?? null ],
		array_map(fn($i) => [ $i['tld'], $i['action'], $i['Duration'], $i['Price'] ], $p?->getVariable('a')['items'] ?? []), $p0?->getVariable('route'), array_column($ssl?->getVariable('a')['items'] ?? [], 'HostName') ]);
	    [ $api, $sent ] = $step('app::namecheap-api', [ 'operation' => 'command', 'command' => 'namecheap.domains.ns.getInfo', 'parameters' => '{"SLD": "example", "TLD": "com", "Nameserver": "ns1.example.com"}', 'variable' => 'x' ]);
	    [ $apiBad, ] = $step('app::namecheap-api', [ 'operation' => 'command', 'command' => 'namecheap.domains.ns.getInfo', 'parameters' => '[1]', 'variable' => 'x' ]);
	    [ , $osent ] = $step('app::namecheap-domains', [ 'operation' => 'list', 'search' => 'example', 'params' => '{"SortBy": "NAME_DESC"}', 'variable' => 'r' ]);
	    $this->check('namecheap: API: a command, its parameters, the answer as an array; parameters not an object refused; Other parameters added to a step\'s', [ [ '198.51.100.7', [ 'OK', 'Linked' ] ], 'ns1.example.com', 'error', 'NAME_DESC' ], [
		[ $api?->getVariable('x')['DomainNSInfoResult']['IP'] ?? null, $api?->getVariable('x')['DomainNSInfoResult']['NameserverStatuses']['Status'] ?? null ], $sent[0]['form']['Nameserver'] ?? null,
		$apiBad?->getVariable('route'), $osent[0]['form']['SortBy'] ?? null ]);
	} finally {
	    $NC::$testUrl = null;
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //GLPI against a fake server (tests/GlpiTestServer.php; plugins\ITOps\util\Glpi::$testing: http, no address guard): ids, HTML both ways, the
    //credential (API token or login + password, App-Token, profile and entity), a session opened again when expired and closed after each step, the Tickets operations
    //(search paged, create with a category by complete name, a requester by email, assigned by login and group, update, status with a follow-up, actors replaced, follow-ups,
    //tasks, solution, attachment, delete), Assets, Setup, the API step, the ticket trigger (created by id, updated by date_mod with floor and seen)
    'glpi' => function() {
	$G = \plugins\ITOps\util\Glpi::class;
	$T = \plugins\ITOps\models\node\NodeAppGlpiTickets::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$thrown = function(\Closure $f) {
	    try {
		$f();
		return 'no error';
	    } catch (\Throwable $x) {
		return $x->getMessage();
	    }
	};
	$this->check('glpi: values: ids (an address, an item, #12), HTML from text (paragraphs, line breaks, escaped) and kept, GLPI\'s encoded HTML as text, the criteria (status, title, AND), '
	    . 'a time moved', [
	    12, 7, 3, 'Ticket: not an id (a number, e.g. 12): `x`', "<p>a &lt; b<br>c</p>\n<p>d</p>", '<p>kept</p>', "Line\n\n- one\n- two", [ 'all', 'AND', 'AND' ], '2026-01-01 00:59:00' ], [
	    $G::itemId('https://glpi.example.com/front/ticket.form.php?id=12'), $G::itemId([ 'id' => 7 ]), $G::itemId('#3'), $thrown(fn() => $G::itemId('x')), $G::html("a < b\nc\n\nd"),
	    $G::html('<p>kept</p>'), $G::plain('&lt;p&gt;Line&lt;/p&gt;&lt;ul&gt;&lt;li&gt;one&lt;/li&gt;&lt;li&gt;two&lt;/li&gt;&lt;/ul&gt;'),
	    (fn($c) => [ $c[0]['value'], $c[1]['link'], $c[2]['link'] ])($T::criteria('', 'disk', [ [ 'field' => 7, 'searchtype' => 'equals', 'value' => 2 ] ])),
	    \plugins\ITOps\models\node\NodeTriggerGlpi::shifted('2026-01-01 01:00:00', -60) ]);

	$dir = sys_get_temp_dir() . '/qflows-check-glpi-' . uniqid();
	@mkdir($dir, 0700, true);
	$clock = fn($t) => file_put_contents("{$dir}/now.txt", $t);
	$clock('2026-11-12 10:00:00');
	$server = $this->startServer('glpi', '../../plugins/ITOps/tests/GlpiTestServer.php', $dir, [ 'GLPI_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$G::$testing = true;
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$db = fn() => json_decode((string)@file_get_contents("{$dir}/items.json"), true);
	$sessions = fn() => count(json_decode((string)@file_get_contents("{$dir}/sessions.json"), true) ?: []);
	$creds = [];
	$files = [];
	$wfs = [];
	$started = [];
	try {
	    $make = function(array $over) use ($om, $port, &$creds) {
		$c = $om->create('CredentialGlpi', $over + [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: GLPI ' . uniqid(), 'url' => 'https://glpi.example.com' ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('glpi: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		$c->url = "http://127.0.0.1:{$port}/apirest.php";
		$c->save([ 'validate' => false ]);
		return $om->findById('CredentialGlpi', (string)$c->_id);
	    };
	    $tok = $make([ 'auth' => 'token', 'userToken' => 'tok-glpi', 'appToken' => 'app-1', 'profileId' => '6', 'entityId' => '0', 'entityRecursive' => true ]);
	    $pwd = $make([ 'auth' => 'password', 'login' => 'glpi', 'password' => 'secret', 'appToken' => 'app-1' ]);
	    $noApp = $make([ 'auth' => 'token', 'userToken' => 'tok-glpi' ]);
	    if (!$tok || !$pwd || !$noApp)
		return;
	    $bad = $om->create('CredentialGlpi', [ 'name' => 'x', 'auth' => 'token', 'url' => 'http://glpi.example.com' ]);
	    $bad->validates();
	    $badKeys = array_values(array_intersect([ 'url', 'userToken' ], array_keys((array)$bad->errors())));
	    $requests();
	    $tt = $tok->test();
	    $sent = $requests();
	    $this->check('glpi: the form (https, the API token when signing in with it); Test: user_token and App-Token, the profile and the entity changed, the session closed; '
		. 'login and password by Basic; a wrong App-Token said', [
		[ 'url', 'userToken' ], 'connected to GLPI as Workflow Bot, profile Technician, entity Root entity',
		[ '/apirest.php/initSession', 'user_token tok-glpi', 'app-1', '/apirest.php/changeActiveProfile', 6, '/apirest.php/changeActiveEntities', true, '/apirest.php/killSession' ], 0,
		'connected to GLPI as Workflow Bot, profile Technician, entity Root entity', 'HTTP status 400: parameter app_token seems wrong (ERROR_WRONG_APP_TOKEN_PARAMETER)' ], [
		$badKeys, $tt, [ $sent[0]['path'] ?? null, $sent[0]['headers']['auth'] ?? null, $sent[0]['headers']['app'] ?? null, $sent[1]['path'] ?? null, $sent[1]['body']['profiles_id'] ?? null,
		    $sent[2]['path'] ?? null, $sent[2]['body']['is_recursive'] ?? null, end($sent)['path'] ?? null ], $sessions(), $pwd->test(), $thrown(fn() => $noApp->test()) ]);

	    $step = function($fq, $cfg, $vars = [], $cred = null) use ($n, $e, $tok, $requests) {
		$requests();
		$exe = $this->runFlow("glpi {$fq}", [ $n('m', 'trigger::manual'), $n('s', $fq, $cfg + [ 'credential' => (string)($cred ?? $tok)->_id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 's'), $e('e2', 's', 'err', 1) ], $vars);
		return [ $exe, $requests() ];
	    };
	    $logOf = fn($exe, $prefix) => preg_replace('/ \(\d+ ms\)$/', '', str_replace($prefix, '', (string)self::logLine($exe, $prefix)));
	    $TS = 'app::glpi-tickets';
	    $posted = fn($sent, $path) => array_values(array_filter($sent, fn($r) => $r['method'] !== 'GET' && $r['path'] === "/apirest.php{$path}"))[0] ?? [];

	    //create, get, update, status, actors
	    [ $c1, $sent ] = $step($TS, [ 'operation' => 'create', 'name' => 'Printer down on {{ floor }}', 'content' => "Paper jam\nFloor {{ floor }}", 'type' => '1', 'urgency' => '4',
		'category' => 'Hardware > Printers', 'requester' => 'guest@example.org', 'user' => 'ana', 'group' => 'Helpdesk', 'fields' => '{"locations_id": 1}', 'variable' => 't' ], [ 'floor' => 2 ]);
	    $in = $posted($sent, '/Ticket')['body']['input'] ?? [];
	    $t1 = $c1?->getVariable('t');
	    [ $c2, ] = $step($TS, [ 'operation' => 'create', 'name' => 'X', 'content' => 'Y', 'category' => 'Printers', 'variable' => 't' ]);
	    [ $c3, ] = $step($TS, [ 'operation' => 'create', 'name' => 'X', 'content' => '', 'variable' => 't' ]);
	    $this->check('glpi: Tickets create: the description as HTML, the category by its complete name, a requester\'s email of no user notified, assigned by login, a group by name, '
		. 'Other fields; the ticket read back (plain text, names); an ambiguous category; no description; the sessions closed', [
		[ "<p>Paper jam<br>Floor 2</p>", 2, 0, 'guest@example.org', 5, 1, 1, 4, 1 ], [ 1, "Paper jam\nFloor 2", 'Processing (assigned)', 'High', 'Hardware > Printers', 'Incident', true ],
		'Category: 2 match `Printers`: give its complete name (A > B) or its id', 'Description: required to create a ticket', 0 ], [
		[ $in['content'] ?? null, $in['itilcategories_id'] ?? null, $in['_users_id_requester'] ?? null, $in['_users_id_requester_notif']['alternative_email'][0] ?? null,
		    $in['_users_id_assign'] ?? null, $in['_groups_id_assign'] ?? null, $in['locations_id'] ?? null, $in['urgency'] ?? null, $in['type'] ?? null ],
		[ $t1['id'] ?? null, $t1['content'] ?? null, $t1['status'] ?? null, $t1['urgency'] ?? null, $t1['category'] ?? null, $t1['type'] ?? null,
		    str_ends_with((string)($t1['url'] ?? ''), '/front/ticket.form.php?id=1') ],
		$logOf($c2, 'GLPI Tickets create: '), $logOf($c3, 'GLPI Tickets create: '), $sessions() ]);
	    [ $g1, ] = $step($TS, [ 'operation' => 'get', 'ticket' => '{{ t }}', 'variable' => 'g' ], [ 't' => $t1 ]);
	    $g = $g1?->getVariable('g');
	    file_put_contents("{$dir}/expire.json", '1');
	    [ $u1, $sent ] = $step($TS, [ 'operation' => 'update', 'ticket' => '1', 'name' => 'Printer down', 'impact' => '2', 'variable' => 'u' ]);
	    [ $s1, $sent2 ] = $step($TS, [ 'operation' => 'status', 'ticket' => '1', 'newStatus' => 'pending', 'message' => 'Waiting for the part', 'private' => true, 'variable' => 's' ]);
	    [ $a1, $sent3 ] = $step($TS, [ 'operation' => 'assign', 'ticket' => '1', 'role' => 'assigned', 'user' => 'dan@example.com', 'replace' => true, 'variable' => 'a' ]);
	    [ $a2, ] = $step($TS, [ 'operation' => 'assign', 'ticket' => '1', 'role' => 'assigned', 'user' => '6', 'variable' => 'a' ]);
	    $links = array_values(array_filter($db()['Ticket_User'] ?? [], fn($l) => $l['tickets_id'] === 1 && $l['type'] === 2));
	    $this->check('glpi: Tickets get: the actors (an email, a user, a group); update (an expired session opened again); status with a private follow-up; an actor by email replacing '
		. 'the others; already there: nothing done', [
		[ [ [ 'kind' => 'email', 'email' => 'guest@example.org' ] ], [ 'user', 'Pop Ana' ], [ 'group', 'Helpdesk' ] ], [ 'Printer down', 'Low', 2 ],
		[ 'Pending', 1, 4 ], [ 'user 5 removed', 'user 6 added' ], [ 6 ], '#1 assigned: already there' ], [
		[ $g['requesters'] ?? null, [ $g['assigned'][0]['kind'] ?? null, $g['assigned'][0]['name'] ?? null ], [ $g['assigned'][1]['kind'] ?? null, $g['assigned'][1]['name'] ?? null ] ],
		[ $u1?->getVariable('u')['name'] ?? null, $u1?->getVariable('u')['impact'] ?? null, count(array_filter($sent, fn($r) => $r['path'] === '/apirest.php/initSession')) ],
		[ $s1?->getVariable('s')['status'] ?? null, $posted($sent2, '/ITILFollowup')['body']['input']['is_private'] ?? null, $posted($sent2, '/Ticket/1')['body']['input']['status'] ?? null ],
		$a1?->getVariable('a')['changes'] ?? null, array_column($links, 'users_id'), $logOf($a2, 'GLPI Tickets assign: ') ]);

	    //follow-ups, tasks, solution, attachment, search, delete
	    $clock('2026-11-12 10:05:00');
	    $step($TS, [ 'operation' => 'followup', 'ticket' => '1', 'message' => 'Part ordered', 'variable' => 'f' ]);
	    [ $fl, ] = $step($TS, [ 'operation' => 'followups', 'ticket' => '1', 'variable' => 'f' ]);
	    [ $tk, $sent ] = $step($TS, [ 'operation' => 'task', 'ticket' => '1', 'message' => 'Replaced the roller', 'duration' => 30, 'taskState' => 'done', 'technician' => 'dan', 'variable' => 'k' ]);
	    [ $tks, ] = $step($TS, [ 'operation' => 'tasks', 'ticket' => '1', 'variable' => 'k' ]);
	    [ $sv, $sent2 ] = $step($TS, [ 'operation' => 'solve', 'ticket' => '1', 'message' => 'Fixed', 'solutionType' => 'Fixed', 'variable' => 'v' ]);
	    $this->check('glpi: Tickets follow-ups (the newest first, as text); a task (seconds, state, technician by login); tasks; a solution (its type by name): Solved', [
		[ 'Part ordered', 'Waiting for the part' ], [ 1800, 2, 6 ], [ 30, 'done', 'Ionescu Dan' ], [ 1, 'Solved' ] ], [
		array_column($fl?->getVariable('f')['items'] ?? [], 'content'),
		[ $posted($sent, '/TicketTask')['body']['input']['actiontime'] ?? null, $posted($sent, '/TicketTask')['body']['input']['state'] ?? null, $posted($sent, '/TicketTask')['body']['input']['users_id_tech'] ?? null ],
		[ $tks?->getVariable('k')['items'][0]['duration'] ?? null, $tks?->getVariable('k')['items'][0]['state'] ?? null, $tks?->getVariable('k')['items'][0]['technician'] ?? null ],
		[ $posted($sent2, '/ITILSolution')['body']['input']['solutiontypes_id'] ?? null, $sv?->getVariable('v')['status'] ?? null ] ]);
	    for ($k = 2; $k <= 4; $k++)
		$step($TS, [ 'operation' => 'create', 'name' => "Disk {$k}", 'content' => 'full', 'variable' => 't' ]);
	    [ $sr, $sent ] = $step($TS, [ 'operation' => 'search', 'statusFilter' => 'notold', 'text' => 'disk', 'order' => 'oldest', 'maxItems' => 2, 'variable' => 'r' ]);
	    $r = $sr?->getVariable('r');
	    [ $sa, ] = $step($TS, [ 'operation' => 'search', 'statusFilter' => '', 'criteria' => '[{"field": 7, "searchtype": "equals", "value": 2}]', 'variable' => 'r' ]);
	    $file = \models\File::createFromString('log.txt', "line\n", [ 'metadata' => [ 'project' => $this->project->_id, 'aid' => $this->account->_id, 'owner' => null, 'source' => 'check' ] ]);
	    $files[] = $file;
	    [ $at, ] = $step($TS, [ 'operation' => 'attach', 'ticket' => '2', 'sourceFile' => (string)$file, 'variable' => 'd' ]);
	    [ $dl, $sent2 ] = $step($TS, [ 'operation' => 'delete', 'ticket' => '4', 'variable' => 'x' ]);
	    $doc = array_values($db()['Document'] ?? [])[0] ?? [];
	    $this->check('glpi: Tickets search (not solved, a part of the title, the oldest first, At most: truncated; a category by GLPI\'s criteria, any status); attach (multipart, linked to '
		. 'the ticket); delete to the trash', [
		[ 2, true, [ 'Disk 2', 'Disk 3' ] ], [ 1 ], [ [ 'log.txt' ], 'Ticket', 2 ], [ 'false', 1 ] ], [
		[ $r['count'] ?? null, $r['truncated'] ?? null, array_column($r['items'] ?? [], 'name') ], array_column($sa?->getVariable('r')['items'] ?? [], 'id'),
		[ $doc['filename'] ?? null, $doc['itemtype'] ?? null, $doc['items_id'] ?? null ], [ $posted($sent2, '/Ticket/4')['query']['force_purge'] ?? null, $db()['Ticket']['4']['is_deleted'] ?? null ] ]);

	    //assets, setup, API, tools
	    $AS = 'app::glpi-assets';
	    [ $ac, $sent ] = $step($AS, [ 'operation' => 'create', 'itemtype' => 'Computer', 'name' => 'srv-01', 'serial' => 'SN123', 'state' => 'In use', 'location' => 'HQ', 'variable' => 'c' ]);
	    [ $al, ] = $step($AS, [ 'operation' => 'list', 'itemtype' => 'Computer', 'serial' => 'sn1', 'variable' => 'c' ]);
	    [ $ag, $sent2 ] = $step($AS, [ 'operation' => 'get', 'itemtype' => 'Computer', 'item' => '1', 'with' => 'networkports, softwares', 'variable' => 'c' ]);
	    $getReq = array_values(array_filter($sent2, fn($x) => $x['path'] === '/apirest.php/Computer/1'))[0] ?? [];
	    [ $aw, ] = $step($AS, [ 'operation' => 'get', 'itemtype' => 'Computer', 'item' => '1', 'with' => 'gpus', 'variable' => 'c' ]);
	    [ $ap, ] = $step($AS, [ 'operation' => 'delete', 'itemtype' => 'Computer', 'item' => '1', 'purge' => true, 'variable' => 'c' ]);
	    $this->check('glpi: Assets create (the status and location by name); list by a part of the serial; get with sub-items; a wrong With; delete purged', [
		[ 1, 1, 'In use', 'HQ' ], [ 1, 'srv-01' ], [ 'true', 'true' ], 'error', false ], [
		[ $posted($sent, '/Computer')['body']['input']['states_id'] ?? null, $posted($sent, '/Computer')['body']['input']['locations_id'] ?? null, $ac?->getVariable('c')['states_id'] ?? null,
		    $ac?->getVariable('c')['locations_id'] ?? null ],
		[ $al?->getVariable('c')['count'] ?? null, $al?->getVariable('c')['items'][0]['name'] ?? null ],
		[ $getReq['query']['with_networkports'] ?? null, $getReq['query']['with_softwares'] ?? null ],
		$aw?->getVariable('route'), isset($db()['Computer']['1']) ]);
	    $SU = 'app::glpi-setup';
	    [ $su, ] = $step($SU, [ 'operation' => 'user', 'user' => 'ana@example.com', 'variable' => 'u' ]);
	    [ $sc, ] = $step($SU, [ 'operation' => 'categories', 'search' => 'print', 'variable' => 'u' ]);
	    [ $so, ] = $step($SU, [ 'operation' => 'searchOptions', 'itemtype' => 'Ticket', 'search' => 'stat', 'variable' => 'u' ]);
	    [ $api, ] = $step('app::glpi-api', [ 'operation' => 'request', 'method' => 'GET', 'path' => '/Ticket/{{ k }}', 'query' => '{"expand_dropdowns": true}', 'variable' => 'x' ], [ 'k' => 1 ]);
	    [ $apiBad, ] = $step('app::glpi-api', [ 'operation' => 'request', 'method' => 'GET', 'path' => '/initSession', 'variable' => 'x' ]);
	    $ops = \util\agent\Tools::operations('glpi');
	    $this->check('glpi: Setup: a user by email (emails, groups, profiles; no secrets); categories (complete names); search options; API: any path (GLPI\'s answer as it is); the session\'s own refused; as AI tools', [
		[ 5, [ 'ana@example.com' ], [ 'Helpdesk' ], 'Technician', false ], [ 'Hardware > Printers', 'Software > Printers' ], [ [ 12, 'Status' ] ], 'Hardware &#62; Printers', 'error',
		[ false, true, false, true ] ], [
		[ $su?->getVariable('u')['id'] ?? null, array_column($su?->getVariable('u')['emails'] ?? [], 'email'), $su?->getVariable('u')['groups'] ?? null,
		    $su?->getVariable('u')['profiles'][0]['profile'] ?? null, array_key_exists('api_token', (array)($su?->getVariable('u')['fields'] ?? [])) ],
		array_column($sc?->getVariable('u')['items'] ?? [], 'completename'), array_map(fn($o) => [ $o['field'], $o['name'] ], $so?->getVariable('u')['items'] ?? []),
		$api?->getVariable('x')['itilcategories_id'] ?? null, $apiBad?->getVariable('route'),
		[ $ops['glpi_tickets_search']['writes'] ?? null, $ops['glpi_tickets_create']['writes'] ?? null, $ops['glpi_setup_users']['writes'] ?? null, $ops['glpi_assets_delete']['writes'] ?? null ] ]);

	    //the ticket trigger
	    $wf = $this->workflow('glpi ticket', [ $n('t', 'trigger::glpi', [ 'credential' => (string)$tok->_id, 'statusFilter' => 'notold', 'event' => 'created', 'runs' => 'each',
		'variable' => 'ticket', 'mode' => 'interval', 'every' => 2, 'every_unit' => 'minutes' ]), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ ticket.id }}' ]), $e('e1', 't', 's') ]);
	    $this->check('glpi: ticket: the workflow is valid', true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $TG = \plugins\ITOps\models\node\NodeTriggerGlpi::class;
	    $node = $wf->nodes['t'];
	    [ $d0, $none ] = $TG::check($wf, $node, []);
	    $clock('2026-11-12 10:10:00');
	    $step($TS, [ 'operation' => 'create', 'name' => 'New A', 'content' => 'a', 'variable' => 't' ]);
	    $step($TS, [ 'operation' => 'create', 'name' => 'New B', 'content' => 'b', 'variable' => 't' ]);
	    [ $d1, $first ] = $TG::check($wf, $node, $d0);
	    [ $d2, $second ] = $TG::check($wf, $node, $d1);
	    $started = array_merge($started, $first, $second);
	    $nodeU = array_replace_recursive($node, [ 'config' => [ 'event' => 'updated', 'statusFilter' => '' ] ]);
	    [ $u0, ] = $TG::check($wf, $nodeU, $d2);
	    $clock('2026-11-12 10:12:00');
	    $step($TS, [ 'operation' => 'followup', 'ticket' => '2', 'message' => 'x', 'variable' => 'c' ]);
	    $step($TS, [ 'operation' => 'create', 'name' => 'New C', 'content' => 'c', 'variable' => 't' ]);
	    [ $u1, $upd ] = $TG::check($wf, $nodeU, $u0);
	    [ , $upd2 ] = $TG::check($wf, $nodeU, $u1);
	    $started = array_merge($started, $upd, $upd2);
	    $this->check('glpi: ticket: the first check only notes the newest id; then the tickets created since, the oldest first; checked again: none; Created or updated: a follow-up '
		. 'and a new ticket, their events, once',
		[ [], 4, [ 'New A', 'New B' ], [ 'created', 'created' ], [], [ 'updated', '2026-11-12 10:10:00' ], [ [ 2, 'updated' ], [ 7, 'created' ] ], [], [ 'new', 'glpi' ] ], [
		$none, $d0['last'] ?? null, array_map(fn($x) => $x->getVariable('ticket')['name'] ?? null, $first), array_map(fn($x) => $x->getVariable('ticket')['event'] ?? null, $first), $second,
		[ $u0['mode'] ?? null, $u0['floor'] ?? null ], array_map(fn($x) => [ $x->getVariable('ticket')['id'] ?? null, $x->getVariable('ticket')['event'] ?? null ], $upd), $upd2,
		[ $first[0]?->state ?? null, $first[0]?->trigger ?? null ] ]);
	    $now = $TG::startNow($wf, $node);
	    $started = array_merge($started, $now['executions'] ?? []);
	    $this->check('glpi: ticket: Run in the designer: the newest ticket; every session closed', [ 'Run with the newest ticket: #7 New C', 0 ], [ $now['message'] ?? null, $sessions() ]);
	} finally {
	    $G::$testing = false;
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		\models\TriggerState::beforeWorkflowDelete($wf);
	    foreach($files as $f)
		$om->findOne('File', [ '_id' => $f ])?->delete();
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },

    //Microsoft Intune against a fake sign-in and Graph (tests/IntuneTestServer.php; plugins\ITOps\util\Intune::$testing: its address for both): values
    //(OData quoting, filters, Intune's JSON error messages), the credential (client credentials, a wrong secret said), a token kept and asked again after a 401, a 429 waited for,
    //Devices (filters, a device by name or serial number, apps, compliance states, remote actions and their bodies, delete), Policies, Apps (assignments added once and removed),
    //Autopilot (import and its state, update, delete), the API step, the device trigger (enrolled: floor, seen; noncompliant: the set compared)
    'intune' => function() {
	$IN = \plugins\ITOps\util\Intune::class;
	$D = \plugins\ITOps\models\node\NodeAppIntuneDevices::class;
	$TI = \plugins\ITOps\models\node\NodeTriggerIntune::class;
	$om = \V::app()->om;
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$this->check('intune: values: an OData literal, filters joined, an id of an item, Intune\'s error message (its JSON\'s Message), a path below /v1.0, the trigger\'s filter', [
	    "'O''Brien'", "(a eq 'x') and (b)", 'x-1', 'Defender scan is only supported (BadRequest)', 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices?$top=5',
	    "(operatingSystem eq 'Windows') and (managedDeviceOwnerType eq 'company') and (complianceState eq 'noncompliant')" ], [
	    $IN::quote("O'Brien"), \plugins\ITOps\models\node\NodeAppIntune::filter([ "a eq 'x'", '', 'b' ]), $IN::id([ 'id' => 'x-1' ]),
	    $IN::message([ 'error' => [ 'code' => 'BadRequest', 'message' => json_encode([ '_version' => 3, 'Message' => 'Defender scan is only supported - Operation ID (for customer support): 1' ]) ] ]),
	    $IN::url('/deviceManagement/managedDevices', [ '$top' => 5, '$filter' => '' ]),
	    $TI::filter([ 'os' => 'Windows', 'ownership' => 'company' ], "complianceState eq 'noncompliant'") ]);

	$dir = sys_get_temp_dir() . '/qflows-check-intune-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('intune', '../../plugins/ITOps/tests/IntuneTestServer.php', $dir, [ 'INTUNE_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$IN::$testing = "http://127.0.0.1:{$port}";
	$IN::forgetTokens();
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$db = fn() => json_decode((string)@file_get_contents("{$dir}/db.json"), true);
	$creds = [];
	$wfs = [];
	$started = [];
	try {
	    $make = function(array $over) use ($om, &$creds) {
		$c = $om->create('CredentialIntune', $over + [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Intune ' . uniqid(), 'tenant' => 'contoso.onmicrosoft.com',
		    'clientId' => '11111111-2222-3333-4444-555555555555' ]);
		if (!$c->validates() || !$c->save()) {
		    $this->fail('intune: the credential was not saved: ' . json_encode($c->errors()));
		    return null;
		}
		$creds[] = $c;
		return $om->findById('CredentialIntune', (string)$c->_id);
	    };
	    $ok = $make([ 'clientSecret' => 'sec-ok' ]);
	    $wrong = $make([ 'clientSecret' => 'nope' ]);
	    if (!$ok || !$wrong)
		return;
	    $bad = $om->create('CredentialIntune', [ 'name' => 'x', 'tenant' => 'contoso.onmicrosoft.com', 'clientId' => 'not-a-guid' ]);
	    $bad->validates();
	    $badKeys = array_values(array_intersect([ 'clientId', 'clientSecret' ], array_keys((array)$bad->errors())));
	    $requests();
	    $tt = $ok->test();
	    $sent = $requests();
	    $thrown = function(\Closure $f) {
		try {
		    $f();
		    return 'no error';
		} catch (\Throwable $x) {
		    return $x->getMessage();
		}
	    };
	    $this->check('intune: the form (a client ID is a GUID, the secret required); Test: client credentials for Graph\'s .default scope, the tenant\'s devices; a wrong secret said', [
		[ 'clientId', 'clientSecret' ], 'connected to Intune: 4 enrolled devices (2 Windows, 1 macOS, 1 iOS / iPadOS)', [ '/contoso.onmicrosoft.com/oauth2/v2.0/token', 'client_credentials', 'Bearer tok-1' ],
		'Microsoft refused the sign-in: AADSTS7000215: Invalid client secret provided.' ], [
		$badKeys, $tt, [ $sent[0]['path'] ?? null, $sent[0]['body']['grant_type'] ?? null, $sent[1]['auth'] ?? null ], $thrown(fn() => $wrong->test()) ]);

	    $step = function($fq, $cfg, $vars = []) use ($n, $e, $ok, $requests) {
		$requests();
		$exe = $this->runFlow("intune {$fq}", [ $n('m', 'trigger::manual'), $n('s', $fq, $cfg + [ 'credential' => (string)$ok->_id, 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 's'), $e('e2', 's', 'err', 1) ], $vars);
		return [ $exe, $requests() ];
	    };
	    $logOf = fn($exe, $prefix) => preg_replace('/ \(\d+ ms\)$/', '', str_replace($prefix, '', (string)self::logLine($exe, $prefix)));
	    $DV = 'app::intune-devices';

	    //devices
	    [ $l1, $sent ] = $step($DV, [ 'operation' => 'list', 'os' => 'Windows', 'compliance' => 'noncompliant', 'variable' => 'd' ]);
	    [ $l2, ] = $step($DV, [ 'operation' => 'list', 'user' => 'ana@contoso.com', 'variable' => 'd' ]);
	    [ $l3, ] = $step($DV, [ 'operation' => 'list', 'name' => 'laptop', 'maxItems' => 2, 'variable' => 'd' ]);
	    [ $g1, $sent2 ] = $step($DV, [ 'operation' => 'get', 'device' => 'SN-C3', 'variable' => 'd' ]);
	    [ $g2, ] = $step($DV, [ 'operation' => 'get', 'device' => 'LAPTOP-02', 'variable' => 'd' ]);
	    [ $ap, ] = $step($DV, [ 'operation' => 'apps', 'device' => 'LAPTOP-01', 'variable' => 'd' ]);
	    [ $cp, ] = $step($DV, [ 'operation' => 'compliance', 'device' => '{{ dev }}', 'variable' => 'd' ], [ 'dev' => [ 'id' => 'aaaaaaaa-0000-0000-0000-000000000002' ] ]);
	    $this->check('intune: Devices list (an operating system and compliance as $filter; a user; a part of the name, At most: truncated); get by serial number (the device simplified, '
		. 'its admin center address); a name of two devices said; its apps; its compliance states (a device of a variable)', [
		[ [ 'LAPTOP-02' ], "(operatingSystem eq 'Windows') and (complianceState eq 'noncompliant')" ], [ 'LAPTOP-01', 'IPHONE-ANA' ], [ 2, true ],
		[ 'IPHONE-ANA', 'iOS', 'personal', true ], 'Device: 2 devices are named `LAPTOP-02`: give the id', [ 'Google Chrome' ], [ 'noncompliant', 'nonCompliant' ] ], [
		[ array_column($l1?->getVariable('d')['items'] ?? [], 'name'), $sent[1]['query']['$filter'] ?? ($sent[0]['query']['$filter'] ?? null) ],
		array_column($l2?->getVariable('d')['items'] ?? [], 'name'), [ $l3?->getVariable('d')['count'] ?? null, $l3?->getVariable('d')['truncated'] ?? null ],
		[ $g1?->getVariable('d')['name'] ?? null, $g1?->getVariable('d')['os'] ?? null, $g1?->getVariable('d')['ownership'] ?? null,
		    str_ends_with((string)($g1?->getVariable('d')['url'] ?? ''), '/mdmDeviceId/aaaaaaaa-0000-0000-0000-000000000003') ],
		$logOf($g2, 'Intune Devices get: '), array_column($ap?->getVariable('d')['items'] ?? [], 'name'),
		[ $cp?->getVariable('d')['items'][0]['state'] ?? null, $cp?->getVariable('d')['items'][0]['settings'][0]['state'] ?? null ] ]);
	    $step($DV, [ 'operation' => 'sync', 'device' => 'LAPTOP-01', 'variable' => 'a' ]);
	    $step($DV, [ 'operation' => 'defenderScan', 'device' => 'SN-A1', 'fullScan' => true, 'variable' => 'a' ]);
	    $step($DV, [ 'operation' => 'wipe', 'device' => 'SN-B2', 'keepUserData' => true, 'variable' => 'a' ]);
	    [ $ds, ] = $step($DV, [ 'operation' => 'defenderScan', 'device' => 'SN-C3', 'variable' => 'a' ]);
	    [ $dl, ] = $step($DV, [ 'operation' => 'delete', 'device' => 'aaaaaaaa-0000-0000-0000-000000000004', 'variable' => 'a' ]);
	    $actions = array_map(fn($a) => [ substr($a[0], -1), $a[1], $a[2] ], $db()['actions'] ?? []);
	    $this->check('intune: Devices remote actions (sync, a full Defender scan, a wipe keeping the user\'s data); Intune\'s refusal said; delete', [
		[ [ '1', 'syncDevice', null ], [ '1', 'windowsDefenderScan', [ 'quickScan' => false ] ], [ '2', 'wipe', [ 'keepEnrollmentData' => false, 'keepUserData' => true ] ] ],
		'HTTP status 400: Defender scan is only supported on Windows devices (BadRequest)', [ true, 3 ] ], [
		$actions, $logOf($ds, 'Intune Devices defenderScan: '), [ $dl?->getVariable('a')['deleted'] ?? null, count($db()['devices'] ?? []) ] ]);

	    //policies, apps
	    $PO = 'app::intune-policies';
	    [ $p1, ] = $step($PO, [ 'operation' => 'compliancePolicy', 'policy' => 'windows baseline', 'variable' => 'p' ]);
	    [ $p2, ] = $step($PO, [ 'operation' => 'complianceStatuses', 'policy' => '{{ p }}', 'status' => 'nonCompliant', 'variable' => 'p' ], [ 'p' => $p1?->getVariable('p') ]);
	    [ $p3, ] = $step($PO, [ 'operation' => 'configurationSummary', 'policy' => 'Windows restrictions', 'variable' => 'p' ]);
	    [ $p4, ] = $step($PO, [ 'operation' => 'configurations', 'variable' => 'p' ]);
	    $AP = 'app::intune-apps';
	    [ $a1, ] = $step($AP, [ 'operation' => 'apps', 'assignedOnly' => true, 'variable' => 'x' ]);
	    [ $a2, $sent ] = $step($AP, [ 'operation' => 'assign', 'app' => 'Notepad++', 'intent' => 'available', 'target' => 'group', 'group' => 'AAAAAAAA-0000-0000-0000-000000000301', 'variable' => 'x' ]);
	    [ $a3, ] = $step($AP, [ 'operation' => 'assign', 'app' => 'Notepad++', 'intent' => 'available', 'target' => 'group', 'group' => 'aaaaaaaa-0000-0000-0000-000000000301', 'variable' => 'x' ]);
	    [ $a4, ] = $step($AP, [ 'operation' => 'app', 'app' => 'Notepad++', 'variable' => 'x' ]);
	    [ $a5, ] = $step($AP, [ 'operation' => 'unassign', 'app' => 'Notepad++', 'target' => 'group', 'group' => 'aaaaaaaa-0000-0000-0000-000000000301', 'allIntents' => true, 'variable' => 'x' ]);
	    [ $a6, ] = $step($AP, [ 'operation' => 'discoveredDevices', 'app' => 'Google Chrome', 'variable' => 'x' ]);
	    $posted = array_values(array_filter($sent, fn($r) => $r['method'] === 'POST' && str_ends_with($r['path'], '/assignments')))[0]['body'] ?? [];
	    $this->check('intune: Policies: a compliance policy by name with its assignments; its device statuses of a status; a profile\'s summary; profiles listed (paged); Apps: assigned only; '
		. 'an assignment to a group (Graph\'s types); the same again: nothing done; the app\'s assignments; removed; the devices of a discovered app', [
		[ 'windows10CompliancePolicy', [ [ 'id' => 'as1', 'target' => 'groupAssignmentTarget', 'groupId' => 'aaaaaaaa-0000-0000-0000-000000000301' ] ] ], [ 'LAPTOP-02' ], [ 2, 1, 1 ], 1,
		[ 'Company Portal' ], [ '#microsoft.graph.mobileAppAssignment', 'available', '#microsoft.graph.groupAssignmentTarget', 'aaaaaaaa-0000-0000-0000-000000000301', true ], false,
		[ [ 'available', 'group' ] ], 1, [ 'LAPTOP-01', 'LAPTOP-02' ] ], [
		[ $p1?->getVariable('p')['type'] ?? null, $p1?->getVariable('p')['assignments'] ?? null ], array_column($p2?->getVariable('p')['items'] ?? [], 'device'),
		[ $p3?->getVariable('p')['success'] ?? null, $p3?->getVariable('p')['error'] ?? null, $p3?->getVariable('p')['pending'] ?? null ], $p4?->getVariable('p')['count'] ?? null,
		array_column($a1?->getVariable('x')['items'] ?? [], 'name'),
		[ $posted['@odata.type'] ?? null, $posted['intent'] ?? null, $posted['target']['@odata.type'] ?? null, $posted['target']['groupId'] ?? null, $a2?->getVariable('x')['changed'] ?? null ],
		$a3?->getVariable('x')['changed'] ?? null, array_map(fn($x) => [ $x['intent'], $x['target'] ], $a4?->getVariable('x')['assignments'] ?? []), $a5?->getVariable('x')['count'] ?? null,
		array_column($a6?->getVariable('x')['items'] ?? [], 'name') ]);

	    //autopilot, API, tools
	    $AU = 'app::intune-autopilot';
	    [ $u1, ] = $step($AU, [ 'operation' => 'list', 'groupTag' => 'sales', 'variable' => 'y' ]);
	    [ $u2, ] = $step($AU, [ 'operation' => 'import', 'serial' => 'AP-2000', 'hash' => "aGFy\nZHdhcmU=", 'groupTag' => 'IT', 'user' => 'ana@contoso.com', 'variable' => 'y' ]);
	    [ $u3, ] = $step($AU, [ 'operation' => 'importStatus', 'import' => '{{ imp }}', 'variable' => 'y' ], [ 'imp' => $u2?->getVariable('y') ]);
	    [ $u4, $sent ] = $step($AU, [ 'operation' => 'update', 'device' => 'AP-1001', 'groupTag' => 'Marketing', 'user' => 'dan@contoso.com', 'variable' => 'y' ]);
	    [ $u5, ] = $step($AU, [ 'operation' => 'import', 'serial' => 'AP-2001', 'hash' => 'not base64!', 'variable' => 'y' ]);
	    [ $u6, ] = $step($AU, [ 'operation' => 'delete', 'device' => 'AP-1002', 'variable' => 'y' ]);
	    $upd = array_values(array_filter($sent, fn($r) => str_ends_with($r['path'], '/updateDeviceProperties')))[0]['body'] ?? [];
	    [ $api, ] = $step('app::intune-api', [ 'operation' => 'request', 'method' => 'GET', 'path' => '/beta/deviceManagement/managedDevices', 'query' => '{"$select": "id,deviceName", "$top": 10}', 'variable' => 'z' ]);
	    [ $apiBad, ] = $step('app::intune-api', [ 'operation' => 'request', 'method' => 'GET', 'path' => 'https://example.com/v1.0/me', 'variable' => 'z' ]);
	    $ops = \util\agent\Tools::operations('intune');
	    $this->check('intune: Autopilot: by group tag (no managed device); import (the hash without spaces) pending, then complete; update by serial number (the user addressable too); a wrong '
		. 'hash; delete; API: Graph beta; another address refused; as AI tools', [
		[ [ 'AP-1001' ], null ], [ 'pending', 'IT' ], [ 'complete', 'aaaaaaaa-0000-0000-0000-000000000777' ],
		[ 'groupTag' => 'Marketing', 'userPrincipalName' => 'dan@contoso.com', 'addressableUserName' => 'dan@contoso.com' ], 'Hardware hash: not Base64', 1, 3, 'error',
		[ false, true, false, true, true ] ], [
		[ array_column($u1?->getVariable('y')['items'] ?? [], 'serialNumber'), array_key_exists('managedDeviceId', $u1?->getVariable('y')['items'][0] ?? []) ? $u1->getVariable('y')['items'][0]['managedDeviceId'] : 'missing' ],
		[ $u2?->getVariable('y')['status'] ?? null, $u2?->getVariable('y')['groupTag'] ?? null ], [ $u3?->getVariable('y')['status'] ?? null, $u3?->getVariable('y')['deviceId'] ?? null ],
		$upd, $logOf($u5, 'Intune Autopilot import: '), count($db()['autopilot'] ?? []), count($api?->getVariable('z')['value'] ?? []), $apiBad?->getVariable('route'),
		[ $ops['intune_devices_list']['writes'] ?? null, $ops['intune_devices_wipe']['writes'] ?? null, $ops['intune_policies_compliance_statuses']['writes'] ?? null,
		    $ops['intune_apps_assign']['writes'] ?? null, $ops['intune_autopilot_import']['writes'] ?? null ] ]);

	    //the device trigger
	    $wf = $this->workflow('intune device', [ $n('t', 'trigger::intune', [ 'credential' => (string)$ok->_id, 'event' => 'enrolled', 'runs' => 'each', 'os' => 'Windows', 'ownership' => '',
		'variable' => 'device', 'mode' => 'interval', 'every' => 5, 'every_unit' => 'minutes' ]), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ device.name }}' ]), $e('e1', 't', 's') ]);
	    $this->check('intune: device: the workflow is valid', true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $node = $wf->nodes['t'];
	    $t0 = (int)floor(microtime(true) * 1000) - 3600000;
	    $edit = function(\Closure $f) use ($dir, $db) {
		$d = $db();
		$f($d);
		file_put_contents("{$dir}/db.json", json_encode($d, JSON_UNESCAPED_SLASHES));
	    };
	    $device = fn($n, $name, $os, $ms) => [ 'id' => sprintf('aaaaaaaa-0000-0000-0000-%012d', $n), 'deviceName' => $name, 'operatingSystem' => $os, 'complianceState' => 'compliant',
		'managedDeviceOwnerType' => 'company', 'userPrincipalName' => 'new@contoso.com', 'serialNumber' => "SN-{$n}", 'enrolledDateTime' => $TI::iso($ms) ];
	    [ $d0, $none ] = $TI::check($wf, $node, [], $t0);
	    $edit(function(&$d) use ($device, $t0) {
		$d['devices'][] = $device(11, 'NEW-WIN', 'Windows', $t0 + 60000);
		$d['devices'][] = $device(12, 'NEW-MAC', 'macOS', $t0 + 61000);
		$d['devices'][] = $device(13, 'OLD-WIN', 'Windows', $t0 - 60000);
	    });
	    $requests();
	    [ $d1, $first ] = $TI::check($wf, $node, $d0, $t0 + 120000);
	    $filter = array_values(array_filter($requests(), fn($r) => str_ends_with($r['path'], '/managedDevices')))[0]['query']['$filter'] ?? null;
	    [ , $second ] = $TI::check($wf, $node, $d1, $t0 + 180000);
	    $started = array_merge($started, $first, $second);
	    $nodeN = array_replace_recursive($node, [ 'config' => [ 'event' => 'noncompliant', 'os' => '' ] ]);
	    [ $n0, ] = $TI::check($wf, $nodeN, $d1);
	    $set = fn($id, $state) => $edit(function(&$d) use ($id, $state) {
		foreach($d['devices'] as &$x)
		    if ($x['id'] === $id)
			$x['complianceState'] = $state;
	    });
	    $set('aaaaaaaa-0000-0000-0000-000000000001', 'noncompliant');
	    [ $n1, $nc ] = $TI::check($wf, $nodeN, $n0);
	    [ $n2, $nc2 ] = $TI::check($wf, $nodeN, $n1);
	    $set('aaaaaaaa-0000-0000-0000-000000000001', 'compliant');
	    [ $n3, ] = $TI::check($wf, $nodeN, $n2);
	    $set('aaaaaaaa-0000-0000-0000-000000000001', 'noncompliant');
	    [ , $nc3 ] = $TI::check($wf, $nodeN, $n3);
	    $started = array_merge($started, $nc, $nc2, $nc3);
	    $this->check('intune: device: the first check only notes the time; then the Windows devices enrolled since (not before, not another system), their filter; checked again: none; '
		. 'Becoming noncompliant: the first check notes those that are; one becoming noncompliant starts a run, once; compliant and noncompliant again: again', [
		[ [], [ 'NEW-WIN' ], 'enrolled', "(operatingSystem eq 'Windows') and (enrolledDateTime ge " . $TI::iso($t0) . ')', [] ],
		[ [ 'aaaaaaaa-0000-0000-0000-000000000002' ], [ [ 'LAPTOP-01', 'noncompliant' ] ], [], 1, [ 'new', 'intune' ] ] ], [
		[ $none, array_map(fn($x) => $x->getVariable('device')['name'] ?? null, $first), $first[0]?->getVariable('device')['event'] ?? null, $filter, $second ],
		[ $n0['ids'] ?? null, array_map(fn($x) => [ $x->getVariable('device')['name'] ?? null, $x->getVariable('device')['event'] ?? null ], $nc), $nc2, count($nc3),
		    [ $nc[0]?->state ?? null, $nc[0]?->trigger ?? null ] ] ]);
	    $now = $TI::startNow($wf, $nodeN);
	    $started = array_merge($started, $now['executions'] ?? []);
	    $this->check('intune: device: Run in the designer: a noncompliant device', 1, count($now['executions'] ?? []));
	} finally {
	    $IN::$testing = null;
	    $IN::forgetTokens();
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		\models\TriggerState::beforeWorkflowDelete($wf);
	    foreach($creds as $c)
		$c->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },
];
