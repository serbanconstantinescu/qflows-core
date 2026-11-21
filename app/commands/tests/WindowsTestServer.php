<?php

/*
    CLD: 2026-11-21T21:30:00+02:00: a fake Windows server APP01 behind WinRM (WS-Management, the cmd shell) for the check suite (CheckCommand::suiteWindows: the Windows credential
    and step), run by PHP's built-in server; the protocol is the Hyper-V fake's (plugins/ITOps/tests/HyperVTestServer.php):
    WIN_STATE=<a folder> php -S 127.0.0.1:<port> WindowsTestServer.php
	POST /wsman only, Basic app01\ops : s3cret; Create, Command (the script decoded, kept), Receive (first: the TimedOut fault, then the output in two parts), Signal, Delete.
	The script is not run: its first line (# r22:<name>) and its parameters ($P) choose the answer, from <state>/state.json (services, disks, events), as ps()'s JSON
	{ ok, data } or { ok: false, error }; windows:run with {"fail": true}: PowerShell's error on stderr (CLIXML). Each command appended to <state>/scripts.jsonl
*/

$dir = getenv('WIN_STATE') ?: sys_get_temp_dir();
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$raw = (string)file_get_contents('php://input');
$soap = function($body, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/soap+xml;charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:a="http://schemas.xmlsoap.org/ws/2004/08/addressing"'
	. ' xmlns:w="http://schemas.dmtf.org/wbem/wsman/1/wsman.xsd" xmlns:rsp="http://schemas.microsoft.com/wbem/wsman/1/windows/shell"'
	. ' xmlns:f="http://schemas.microsoft.com/wbem/wsman/1/wsmanfault"><s:Header/><s:Body>' . $body . '</s:Body></s:Envelope>';
    exit;
};
$fault = fn($code, $text, $status = 500) => $soap('<s:Fault><s:Code><s:Value>s:Receiver</s:Value></s:Code><s:Reason><s:Text xml:lang="en-US">' . htmlspecialchars($text) . '</s:Text></s:Reason>'
    . '<s:Detail><f:WSManFault Code="' . $code . '" Machine="app01"><f:Message>' . htmlspecialchars($text) . '</f:Message></f:WSManFault></s:Detail></s:Fault>', $status);
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/wsman' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit;
}
if (($headers['authorization'] ?? '') !== 'Basic ' . base64_encode('app01\ops:s3cret')) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="WSMAN"');
    exit;
}
$doc = new DOMDocument();
if (!@$doc->loadXML($raw))
    $fault('2150858817', 'The WS-Management service cannot process the request because the XML is invalid.', 400);
$xp = new DOMXPath($doc);
$action = (string)$xp->evaluate('string(//*[local-name()="Action"])');
$shell = (string)$xp->evaluate('string(//*[local-name()="Selector"][@Name="ShellId"])');
$options = [];
foreach($xp->query('//*[local-name()="Option"]') as $o)
    $options[$o->getAttribute('Name')] = $o->textContent;

$stateFile = "{$dir}/state.json";
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [
    'services' => [
	[ 'name' => 'Spooler', 'displayName' => 'Print Spooler', 'status' => 'Running', 'startType' => 'Automatic' ],
	[ 'name' => 'W3SVC', 'displayName' => 'World Wide Web Publishing Service', 'status' => 'Stopped', 'startType' => 'Automatic' ],
	[ 'name' => 'MSSQLSERVER', 'displayName' => 'SQL Server (MSSQLSERVER)', 'status' => 'Running', 'startType' => 'Automatic' ],
	[ 'name' => 'wuauserv', 'displayName' => 'Windows Update', 'status' => 'Stopped', 'startType' => 'Manual' ],
    ],
    'disks' => [ [ 'drive' => 'C:', 'label' => 'System', 'fileSystem' => 'NTFS', 'sizeGb' => 99.4, 'freeGb' => 6.2, 'usePercent' => 94 ],
	[ 'drive' => 'D:', 'label' => 'Data', 'fileSystem' => 'NTFS', 'sizeGb' => 499.9, 'freeGb' => 310.0, 'usePercent' => 38 ] ],
    'commands' => [], 'n' => 0 ];
$save = function() use (&$state, $stateFile) {
    file_put_contents($stateFile, json_encode($state));
};

if (str_ends_with($action, '/transfer/Create')) {
    $id = sprintf('%08X-0000-4000-8000-%012X', random_int(0, 0xFFFFFFF), ++$state['n']);
    $state['shells'][$id] = $options;
    $save();
    $soap('<x:ResourceCreated xmlns:x="http://schemas.xmlsoap.org/ws/2004/09/transfer"><a:Address>http://127.0.0.1/wsman</a:Address><a:ReferenceParameters>'
	. '<w:ResourceURI>http://schemas.microsoft.com/wbem/wsman/1/windows/shell/cmd</w:ResourceURI><w:SelectorSet><w:Selector Name="ShellId">' . $id . '</w:Selector></w:SelectorSet>'
	. '</a:ReferenceParameters></x:ResourceCreated><rsp:Shell><rsp:ShellId>' . $id . '</rsp:ShellId></rsp:Shell>');
}
if (!isset($state['shells'][$shell]))
    $fault('2150858843', 'The request for the Windows Remote Shell with ShellId ' . $shell . ' failed because the shell was not found on the server.');
if (str_ends_with($action, '/transfer/Delete')) {
    unset($state['shells'][$shell]);
    $save();
    $soap('');
}
if (str_ends_with($action, '/shell/Signal'))
    $soap('<rsp:SignalResponse/>');

//the script's answer, as a Windows server would give it: [ stdout, stderr ]
$answer = function($name, $P) use (&$state, $save) {
    $ok = fn($data) => [ json_encode([ 'ok' => true, 'data' => $data ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '' ];
    $err = fn($text) => [ json_encode([ 'ok' => false, 'error' => $text ]), '' ];
    switch($name) {
	case 'windows:test':
	    return $ok([ 'name' => 'APP01', 'windows' => 'Microsoft Windows Server 2022 Standard', 'version' => '10.0.20348', 'uptimeHours' => 75 ]);
	case 'windows:run':
	    if (!empty($P['fail']))
		return [ '', "#< CLIXML\r\n<Objs Version=\"1.1.0.1\" xmlns=\"http://schemas.microsoft.com/powershell/2004/04\"><S S=\"Error\">Access is denied._x000D__x000A_</S></Objs>" ];
	    return $ok([ 'echo' => $P ]);
	case 'windows:disks':
	    return $ok($state['disks']);
	case 'windows:services':
	    $n = trim((string)($P['name'] ?? ''));
	    return $ok(array_values(array_filter($state['services'], fn($s) => ($n === '' || fnmatch($n, $s['name'], FNM_CASEFOLD) || fnmatch($n, $s['displayName'], FNM_CASEFOLD))
		&& (empty($P['stoppedAuto']) || ($s['startType'] === 'Automatic' && $s['status'] !== 'Running')))));
	case 'windows:start':
	case 'windows:stop':
	case 'windows:restart':
	    foreach($state['services'] as &$s) {
		if (strcasecmp($s['name'], (string)($P['service'] ?? '')) === 0) {
		    $s['status'] = $P['op'] === 'stop' ? 'Stopped' : 'Running';
		    $save();
		    return $ok([ 'name' => $s['name'], 'displayName' => $s['displayName'], 'status' => $s['status'] ]);
		}
	    }
	    return $err("Cannot find any service with service name '" . ($P['service'] ?? '') . "'.");
	case 'windows:events':
	    $all = [ [ 'time' => gmdate('c', time() - 600), 'id' => 7034, 'source' => 'Service Control Manager', 'level' => 'Error', 'message' => 'The World Wide Web Publishing Service service terminated unexpectedly.' ],
		[ 'time' => gmdate('c', time() - 7200), 'id' => 2013, 'source' => 'srv', 'level' => 'Warning', 'message' => 'The C: disk is at or near capacity.' ],
		[ 'time' => gmdate('c', time() - 86400 * 3), 'id' => 41, 'source' => 'Kernel-Power', 'level' => 'Critical', 'message' => 'The system has rebooted without cleanly shutting down first.' ] ];
	    $levels = array_map(fn($l) => [ '1' => 'Critical', '2' => 'Error', '3' => 'Warning', '4' => 'Information' ][(string)$l] ?? '', (array)($P['levels'] ?? []));
	    $since = time() - 3600 * (float)($P['hours'] ?? 24);
	    return $ok(array_slice(array_values(array_filter($all, fn($e) => in_array($e['level'], $levels, true) && strtotime($e['time']) >= $since)), 0, (int)($P['max'] ?? 50)));
    }
    return $err("The term '{$name}' is not recognized");
};

if (str_ends_with($action, '/shell/Command')) {
    $args = (string)$xp->evaluate('string(//*[local-name()="Arguments"])');
    $command = (string)$xp->evaluate('string(//*[local-name()="Command"])');
    if (!preg_match('/-EncodedCommand (\S+)/', $args, $m))
	$fault('2147942402', 'The system cannot find the file specified.');
    $script = mb_convert_encoding(base64_decode($m[1]), 'UTF-8', 'UTF-16LE');
    $name = preg_match('/^# r22:(\S+)/', $script, $n) ? $n[1] : '';
    $params = preg_match("/^\\\$P = ConvertFrom-Json '((?:[^']|'')*)'\$/m", $script, $p) ? json_decode(str_replace("''", "'", $p[1]), true) : null;
    [ $out, $err ] = $answer($name, (array)$params);
    $id = sprintf('%08X-1111-4000-8000-%012X', random_int(0, 0xFFFFFFF), ++$state['n']);
    $state['commands'][$id] = [ 'out' => $out, 'err' => $err, 'receives' => 0 ];
    $save();
    file_put_contents("{$dir}/scripts.jsonl", json_encode([ 'name' => $name, 'params' => $params, 'command' => $command, 'length' => mb_strlen($script), 'shell' => $state['shells'][$shell],
	'options' => $options, 'helpers' => str_contains($script, 'function r22find') ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    $soap('<rsp:CommandResponse><rsp:CommandId>' . $id . '</rsp:CommandId></rsp:CommandResponse>');
}
if (str_ends_with($action, '/shell/Receive')) {
    $id = (string)$xp->evaluate('string(//*[local-name()="DesiredStream"]/@CommandId)');
    $c = &$state['commands'][$id];
    if (!$c)
	$fault('2150858843', 'The command was not found.');
    $c['receives']++;
    $save();
    if ($c['receives'] === 1)
	$fault('2150858793', 'The WS-Management service cannot complete the operation within the time specified in OperationTimeout.');
    $half = intdiv(strlen($c['out']), 2);
    $part = $c['receives'] === 2 ? substr($c['out'], 0, $half) : substr($c['out'], $half);
    $streams = '<rsp:Stream Name="stdout" CommandId="' . $id . '">' . base64_encode($part) . '</rsp:Stream>';
    if ($c['receives'] === 2) {
	$soap('<rsp:ReceiveResponse>' . $streams . '<rsp:CommandState CommandId="' . $id . '" State="http://schemas.microsoft.com/wbem/wsman/1/windows/shell/CommandState/Running"/></rsp:ReceiveResponse>');
    }
    if ($c['err'] !== '')
	$streams .= '<rsp:Stream Name="stderr" CommandId="' . $id . '">' . base64_encode($c['err']) . '</rsp:Stream>';
    $soap('<rsp:ReceiveResponse>' . $streams . '<rsp:Stream Name="stdout" CommandId="' . $id . '" End="true"></rsp:Stream><rsp:CommandState CommandId="' . $id
	. '" State="http://schemas.microsoft.com/wbem/wsman/1/windows/shell/CommandState/Done"><rsp:ExitCode>' . ($c['err'] !== '' ? 1 : 0) . '</rsp:ExitCode></rsp:CommandState></rsp:ReceiveResponse>');
}
$fault('2150858817', "Unknown action {$action}");
