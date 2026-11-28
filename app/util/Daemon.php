<?php

/*
    the workflow daemon's command port (commands\DaemonCommand::job_command): tcp://<app.daemon_host>:<app.daemon_port> (DAEMON_PORT of app.env), one command per line;
    nothing happens when the daemon does not run (false)
    notify()       NEWJOB: dispatch the queued jobs now (it also checks the queue every 10 s)
    kill($jid)     KILLJOB:<jid>: stop the worker running the job
    push($type, $scope, $data)   PUSH:<json>: an event for the open pages it concerns (the UI refreshes)
    CLD: 2026-11-28T10:00:00Z: PUSH for the realtime UI events (the daemon does not handle it yet)
*/

namespace util;

class Daemon {

    //where the daemon takes its commands; $listen: the daemon's own address (app.daemon_listen), else where others reach it (app.daemon_host); both 127.0.0.1 unless set (Docker)
    public static function address($listen = false) {
	$host = (string)\V::app()->config($listen ? 'app.daemon_listen' : 'app.daemon_host');
	return 'tcp://' . (str_contains($host, ':') ? "[{$host}]" : $host) . ':' . (int)\V::app()->config('app.daemon_port');
    }

    //send one command line; false when the daemon does not answer within $timeout seconds
    public static function command($line, $timeout = 1) {
	$conn = @stream_socket_client(self::address(), $errno, $errstr, $timeout);
	if (!$conn)
	    return false;
	fwrite($conn, $line . "\n");
	fclose($conn);
	return true;
    }

    public static function notify() {
	return self::command('NEWJOB');
    }

    public static function kill($jid) {
	return self::command("KILLJOB:{$jid}");
    }

    //CLD: 2026-11-28T10:00:00Z: an event for the UI, e.g. push('tasks::changed', [ 'aid' => $aid, 'project' => $project ]): it only says what changed, the pages load the data
    //themselves. $scope: who it is for (aid, project, user, execution). A short timeout: a missed event is not lost work (the pages reload on reconnect)
    public static function push($type, $scope, $data = []) {
	$scope = array_map(fn($v) => (string)$v, array_filter((array)$scope, fn($v) => $v !== null && $v !== ''));
	return self::command('PUSH:' . \util\JSON::encode([ 'type' => (string)$type, 'scope' => $scope, 'data' => (object)$data ]), 0.2);
    }

    //CLD: 2026-11-28T10:00:00Z: where the browsers' live events are served (commands\DaemonCommand::setup_push_listener; nginx /push proxies to it)
    public static function pushAddress() {
	$host = (string)\V::app()->config('app.daemon_listen');
	return 'tcp://' . (str_contains($host, ':') ? "[{$host}]" : $host) . ':' . (int)\V::app()->config('app.daemon_push_port');
    }

    //CLD: 2026-11-28T10:00:00Z: the page's ticket for /push?t=<token>: its claims (aid, project, user; other keys a page may follow, e.g. execution; a value
    //may be a list) and when it ends (exp), signed with a key of its own (sys\Security::deriveKey). A push goes to the pages whose claims hold each key of its scope.
    //Only who is told of a change is decided by it: the pages load the data themselves (their access checks)
    public static function pushToken(array $claims, $ttl = 43200) {
	$claims = array_map(fn($v) => is_array($v) ? array_values(array_map('strval', $v)) : (string)$v, array_filter($claims, fn($v) => $v !== null && $v !== ''));
	$claims['exp'] = time() + (int)$ttl;
	$body = \util\Jwt::b64(\util\JSON::encode($claims));
	return $body . '.' . \util\Jwt::b64(hash_hmac('sha256', $body, \V::app()->security->deriveKey('push-token'), true));
    }

    //the claims of a valid, unexpired token, else null
    public static function pushClaims($token) {
	$parts = explode('.', (string)$token);
	if (count($parts) !== 2 || $parts[0] === '')
	    return null;
	$sig = \util\Jwt::b64(hash_hmac('sha256', $parts[0], \V::app()->security->deriveKey('push-token'), true));
	if (!hash_equals($sig, $parts[1]))
	    return null;
	$claims = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/')), true);
	if (!is_array($claims) || (int)($claims['exp'] ?? 0) <= time())
	    return null;
	return $claims;
    }
}
