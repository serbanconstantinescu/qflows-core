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
}
