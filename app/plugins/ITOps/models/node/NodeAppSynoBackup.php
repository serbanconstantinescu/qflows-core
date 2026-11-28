<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: Synology Backup - Hyper Backup's tasks (SYNO.Backup.Task: DSM's own web interface's API, an administrator; not documented for
    integrations, it may change between Hyper Backup versions). Operations:
	tasks    the tasks: { id, name, state, status, lastResult, lastTime, nextTime, ok, failed, running }
	task     a task, with its status now (get + status)
	run      a backup started now (backup); Wait until it finishes reads its status every POLL seconds until it is no longer running, or the step's timeout nears
	cancel   a running backup cancelled (cancel)
    Task: its id (a number) or its name (looked up in the list). lastResult as Hyper Backup gives it: done, failed, partial, cancel, ...; ok: done; failed: failed or partial
*/

namespace plugins\ITOps\models\node;

class NodeAppSynoBackup extends NodeAppSynology {
    public static $name = 'syno-backup';
    public static $label = 'Synology Backup';
    public static $icon = 'bi-cloud-arrow-up';
    public static $defaultOperation = 'tasks';
    public static $defaultVariable = 'backup';

    const OPERATIONS = [
	'tasks' => 'List Hyper Backup tasks',
	'task' => 'Get a task',
	'run' => 'Back up now',
	'cancel' => 'Cancel a backup',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\agent\Tools)
    const WRITES = [ 'run', 'cancel' ];
    const LISTS = [ 'tasks' ];
    const ADDITIONAL = [ 'last_bkp_time', 'next_bkp_time', 'last_bkp_result', 'is_modified', 'last_bkp_progress', 'last_bkp_success_time' ];
    const FAILED = [ 'failed', 'partial', 'error', 'backup_failed', 'partial_success' ];
    const OK = [ 'done', 'success', 'backup_success' ];
    //a task's status while it works
    const RUNNING = '/backup|running|preparing|waiting|detect|resum|uploading|version_delet|relink/i';

    //seconds between two reads of a backup being waited for (the check suite shortens it)
    public static $poll = 10;

    protected static function operationFields() {
	return [
	    self::field('task', 'Task', [ 'task', 'run', 'cancel' ], [ 'required' => true, 'help' => 'Its id or name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('wait', 'Wait until it finishes', [ 'run' ], [ 'type' => 'switch', 'default' => false,
		'help' => 'The result is then the task with its last result; give the step a timeout long enough', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return '{ id, name, state, status, lastResult, lastTime, nextTime, ok, failed, running }';
    }

    public static function running($status) {
	$s = (string)$status;
	return $s !== '' && $s !== 'none' && (bool)preg_match(self::RUNNING, $s) && !preg_match('/fail|done|success/i', $s);
    }

    //a task of Hyper Backup, flattened (its fields kept)
    public static function task(array $t) {
	$result = (string)($t['last_bkp_result'] ?? $t['additional']['last_bkp_result'] ?? '');
	$status = (string)($t['status'] ?? '');
	return [ 'id' => (int)($t['task_id'] ?? $t['id'] ?? 0), 'name' => (string)($t['name'] ?? ''), 'state' => (string)($t['state'] ?? ''), 'status' => $status,
	    'lastResult' => $result, 'lastTime' => (string)($t['last_bkp_time'] ?? $t['additional']['last_bkp_time'] ?? ''),
	    'nextTime' => (string)($t['next_bkp_time'] ?? $t['additional']['next_bkp_time'] ?? ''),
	    'ok' => in_array($result, self::OK, true), 'failed' => in_array($result, self::FAILED, true), 'running' => self::running($status) ] + $t;
    }

    //the tasks (one call: Hyper Backup has few)
    protected function tasks() {
	$d = $this->call('SYNO.Backup.Task', 'list', [ 'additional' => self::ADDITIONAL, 'offset' => 0, 'limit' => self::MAX_ITEMS ], 1);
	return array_map([ self::class, 'task' ], array_values(array_filter((array)($d['task_list'] ?? $d['tasks'] ?? []), 'is_array')));
    }

    //a task's id: a number, a task, or its name (looked up)
    protected function taskId($value) {
	if (is_array($value))
	    $value = $value['id'] ?? $value['task_id'] ?? $value['name'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if ($v === '')
	    throw new \RuntimeException('Task: empty');
	if (preg_match('/^\d{1,9}$/', $v))
	    return (int)$v;
	$found = array_values(array_filter($this->tasks(), fn($t) => strcasecmp($t['name'], $v) === 0));
	if (!$found)
	    throw new \RuntimeException("Task: Hyper Backup has no task named `" . mb_substr($v, 0, 80) . '`');
	if (count($found) > 1)
	    throw new \RuntimeException("Task: several tasks are named `{$v}`: use its id");
	return $found[0]['id'];
    }

    //a task with its status now
    protected function taskNow($id) {
	$s = $this->nas->call('SYNO.Backup.Task', 'status', [ 'task_id' => $id, 'blOnline' => false, 'additional' => self::ADDITIONAL ], 1);
	$d = $this->nas->call('SYNO.Backup.Task', 'get', [ 'task_id' => $id, 'additional' => self::ADDITIONAL ], 1);
	$t = (array)($d['task'] ?? $d);
	return self::task(array_replace($t, (array)($s['task'] ?? $s), [ 'task_id' => $id ]));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'tasks':
		$rows = $this->tasks();
		[ $r, $summary ] = $this->listResult($rows, 'task');
		$failed = count(array_filter($rows, fn($t) => $t['failed']));
		return [ $r, $summary . ($failed ? ", {$failed} failed last time" : '') ];
	    case 'task':
		$t = $this->taskNow($this->taskId($value('task')));
		return [ $t, "task {$t['name']}: " . ($t['running'] ? 'running' : ($t['lastResult'] !== '' ? "last {$t['lastResult']}" : $t['status'])) ];
	    case 'run':
		$id = $this->taskId($value('task'));
		$wait = (bool)$this->cfg('wait', false);
		//waited for: its last backup's time before, so that one ended before the first read is seen too
		$before = $wait ? $this->taskNow($id)['lastTime'] : '';
		$d = $this->call('SYNO.Backup.Task', 'backup', [ 'task_id' => $id ], 1);
		if (!$wait)
		    return [ [ 'id' => $id, 'started' => true ] + $d, "task {$id}: backup started" ];
		$deadline = microtime(true) + $this->nas->left() - 5;
		$seen = false;
		while(true) {
		    $t = $this->taskNow($id);
		    $seen = $seen || $t['running'];
		    $finished = !$t['running'] && ($seen || $t['lastTime'] !== $before);
		    if ($finished || microtime(true) + self::$poll > $deadline)
			break;
		    sleep(self::$poll);
		}
		$t += [ 'finished' => $finished ];
		return [ $t, "task {$t['name']}: " . ($finished ? ($t['lastResult'] ?: 'finished') : 'not finished when the wait ended (the NAS goes on)') ];
	    case 'cancel':
		$id = $this->taskId($value('task'));
		$d = $this->call('SYNO.Backup.Task', 'cancel', [ 'task_id' => [ $id ] ], 1);
		return [ [ 'id' => $id, 'cancelled' => true ] + $d, "task {$id}: cancelled" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
