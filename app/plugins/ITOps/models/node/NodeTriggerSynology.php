<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: Synology NAS - starts the workflow when the NAS changes, for the events chosen:
	volume       a volume or storage pool is no longer normal (degraded, crashed, read-only, ...)
	disk         a drive's status or S.M.A.R.T. is no longer normal (warning, failing, crashed, ...)
	recovered    a volume, pool or drive is normal again
	space        a volume's used space reaches the percent given
	backup       a Hyper Backup task fails (its last result failed or partial, a new one)
	update       a DSM update is available (a new version)
	temperature  the system's temperature reaches the degrees given
	unreachable  the NAS cannot be reached or signed in to
	reachable    it can again
    It checks the NAS on its schedule (models\wf\WfSchedule, every 10 minutes by default; util\Scheduler runs runScheduled), signed in as the credential's account (an
    administrator): SYNO.Core.System info, SYNO.Storage.CGI.Storage load_info, SYNO.Backup.Task list (backup), SYNO.Core.Upgrade.Server check (update); only what the events
    chosen need. Compared with the last check's state (models\TriggerState: { started, reachable, volumes, pools, disks: { id: ok }, over: { volume: bool }, tasks: { id:
    [ result, time ] }, update, hot }); the first check only notes it; a part that cannot be read (e.g. no Hyper Backup) keeps its previous state. The variable: { event, kind
    (volume, pool, disk, task, system), name, detail, item, nas: { model, serial, version, address }, previous } or { events, count } per check; at most MAX_RUNS per check
*/

namespace plugins\ITOps\models\node;

class NodeTriggerSynology extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'synology-nas';
    public static $label = 'Synology NAS';
    public static $icon = 'bi-hdd-network';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const EVENTS = [
	'volume' => 'A volume or pool is not normal',
	'disk' => 'A drive is not normal',
	'recovered' => 'Normal again',
	'space' => 'A volume is filling up',
	'backup' => 'A Hyper Backup task fails',
	'update' => 'A DSM update is available',
	'temperature' => 'The temperature is high',
	'unreachable' => 'The NAS cannot be reached',
	'reachable' => 'It can be reached again',
    ];
    const RUNS = [ 'each' => 'One run per event', 'batch' => 'One run per check with events' ];
    const MAX_RUNS = 200;
    const TIMEOUT = 60;

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('synology', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Synology credential',
		'choices' => $choices,
		'help' => 'The NAS that starts the workflow (an administrator account)',
		'required' => true,
	    ],
	    [
		'key' => 'events',
		'type' => 'select',
		'multiple' => true,
		'label' => 'When',
		'required' => true,
		'default' => [ 'volume', 'disk', 'backup', 'unreachable' ],
		'choices' => self::EVENTS,
	    ],
	    [
		'key' => 'spaceAt',
		'type' => 'number',
		'label' => 'Filling up at (% used)',
		'default' => 90,
		'help' => 'For "A volume is filling up"',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 100, 'skipEmpty' => true, 'message' => 'from 1 to 100' ] ],
		'ui' => [ 'width' => 3, 'step' => 1 ],
	    ],
	    [
		'key' => 'tempAt',
		'type' => 'number',
		'label' => 'High at (°C)',
		'default' => 60,
		'help' => 'For "The temperature is high"',
		'validate' => [ [ 'inRange', 'lower' => 20, 'upper' => 120, 'skipEmpty' => true, 'message' => 'from 20 to 120' ] ],
		'ui' => [ 'width' => 3, 'step' => 1 ],
	    ],
	    [
		'key' => 'runs',
		'type' => 'select',
		'label' => 'Runs',
		'required' => true,
		'default' => 'each',
		'choices' => self::RUNS,
		'ui' => [ 'width' => 6, 'newRow' => true ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Event variable',
		'required' => true,
		'default' => 'nas',
		'help' => 'e.g. {{ nas.event }}, {{ nas.name }}, {{ nas.detail }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	], \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 10, 'every_unit' => 'minutes' ]));
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return \models\wf\WfSchedule::matches($cfg, $t);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$error = \models\wf\WfSchedule::validate(is_array($this->config) ? $this->config : []);
	if ($error !== null) {
	    $this->errors('config', $error);
	    $valid = false;
	}
	if (!self::chosen(is_array($this->config) ? $this->config : [])) {
	    $this->errors('config', 'Synology NAS: choose at least one event');
	    $valid = false;
	}
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'synology')))) {
	    $this->errors('config', 'Synology NAS: choose a Synology credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'nas') : 'nas';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ events, count }', 'fields' => [
		'events' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an event: { event, kind, name, detail, item, nas, previous }' ] ], 'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'what changed on the NAS', 'fields' => [
	    'event' => [ 'type' => 'string', 'info' => implode(', ', array_keys(self::EVENTS)) ],
	    'kind' => [ 'type' => 'string', 'info' => 'volume, pool, disk, task or system' ],
	    'name' => [ 'type' => 'string', 'info' => 'the volume, pool, drive or task (the NAS for system events)' ],
	    'detail' => [ 'type' => 'string', 'info' => 'what happened, in words' ],
	    'item' => [ 'type' => 'object', 'info' => 'the volume, pool, drive or task as Synology Storage / Backup give it' ],
	    'nas' => [ 'type' => 'object', 'info' => '{ model, serial, version, address, temperature }' ],
	    'previous' => [ 'type' => 'any', 'info' => 'its state at the last check' ] ] ] ];
    }

    //the events chosen (known ones)
    protected static function chosen(array $cfg) {
	return array_values(array_intersect(array_keys(self::EVENTS), (array)($cfg['events'] ?? [])));
    }

    //the NAS now: { reachable, error, nas, storage (null: not read), tasks (null), update (null) }
    protected static function current($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'synology');
	if (!$cred)
	    throw new \Exception('Synology NAS: the credential is no longer available');
	$chosen = self::chosen($cfg);
	$s = $cred->client($wf->aid, $timeout);
	$now = [ 'reachable' => true, 'error' => null, 'nas' => [ 'address' => (string)parse_url((string)$cred->serverUrl, PHP_URL_HOST) ], 'storage' => null, 'tasks' => null, 'update' => null ];
	try {
	    try {
		$s->signIn();
		$info = $s->call('SYNO.Core.System', 'info', [], 1);
	    } catch (\RuntimeException $e) {
		return [ 'reachable' => false, 'error' => $e->getMessage() ] + $now;
	    }
	    $now['nas'] += [ 'model' => (string)($info['model'] ?? ''), 'serial' => (string)($info['serial'] ?? ''), 'version' => (string)($info['firmware_ver'] ?? ''),
		'temperature' => isset($info['sys_temp']) ? (int)$info['sys_temp'] : null ];
	    //the parts: one that cannot be read is left out (its state kept)
	    $part = function(\Closure $f) {
		try {
		    return $f();
		} catch (\RuntimeException $e) {
		    return null;
		}
	    };
	    if (array_intersect($chosen, [ 'volume', 'disk', 'recovered', 'space' ]))
		$now['storage'] = $part(fn() => NodeAppSynoStorage::overview($s->call('SYNO.Storage.CGI.Storage', 'load_info', [], 1)));
	    if (in_array('backup', $chosen, true))
		$now['tasks'] = $part(fn() => array_map([ NodeAppSynoBackup::class, 'task' ], array_values(array_filter((array)(($d = $s->call('SYNO.Backup.Task', 'list',
		    [ 'additional' => NodeAppSynoBackup::ADDITIONAL, 'offset' => 0, 'limit' => 1000 ], 1))['task_list'] ?? $d['tasks'] ?? []), 'is_array'))));
	    if (in_array('update', $chosen, true))
		$now['update'] = $part(function() use ($s) {
		    $d = $s->call('SYNO.Core.Upgrade.Server', 'check', [], 2);
		    $u = (array)($d['update'] ?? $d);
		    return !empty($u['available']) ? (string)($u['version'] ?? '?') : '';
		});
	} finally {
	    $s->signOut();
	}
	return $now;
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Synology NAS: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
	    return;
	}
	try {
	    [ $data, $started ] = self::check($wf, $node, $state->dataArray());
	} catch (\Throwable $e) {
	    $state->release();
	    throw $e;
	}
	$state->finish($data);
	return $started;
    }

    //the events between the previous state ($old) and now ($now): [ events, the new state ]
    public static function eventsOf(array $old, array $now, array $cfg) {
	$first = empty($old['started']);
	$spaceAt = max(1, min(100, (int)($cfg['spaceAt'] ?? 90)));
	$tempAt = max(20, min(120, (int)($cfg['tempAt'] ?? 60)));
	$new = [ 'started' => true ] + $old;
	$events = [];
	$add = function($event, $kind, $name, $detail, $item, $previous) use (&$events, $now) {
	    $events[] = [ 'event' => $event, 'kind' => $kind, 'name' => (string)$name, 'detail' => $detail, 'item' => $item, 'nas' => $now['nas'], 'previous' => $previous ];
	};
	$nasName = $now['nas']['address'] ?: 'the NAS';

	$wasReachable = !array_key_exists('reachable', $old) || $old['reachable'];
	$new['reachable'] = $now['reachable'];
	if (!$now['reachable']) {
	    if (!$first && $wasReachable)
		$add('unreachable', 'system', $nasName, (string)$now['error'], null, true);
	    return [ $events, $new ];
	}
	if (!$first && !$wasReachable)
	    $add('reachable', 'system', $nasName, 'reachable again', null, false);

	if ($now['storage'] !== null) {
	    foreach([ 'volumes' => 'volume', 'pools' => 'pool', 'disks' => 'disk' ] as $key => $kind) {
		$prev = (array)($old[$key] ?? []);
		$next = [];
		foreach($now['storage'][$key] as $x) {
		    $id = (string)$x['id'];
		    $next[$id] = $x['ok'];
		    if ($first)
			continue;
		    $was = array_key_exists($id, $prev) ? (bool)$prev[$id] : null;
		    $word = $kind === 'disk' ? 'drive' : $kind;
		    if (!$x['ok'] && $was !== false)
			$add($kind === 'disk' ? 'disk' : 'volume', $kind, $x['name'], "{$word} {$x['name']}: {$x['status']}" . (!empty($x['smart']) && NodeAppSynoStorage::problem($x['smart'])
			    ? " (S.M.A.R.T. {$x['smart']})" : ''), $x, $was === null ? null : 'normal');
		    elseif ($x['ok'] && $was === false)
			$add('recovered', $kind, $x['name'], "{$word} {$x['name']}: normal again", $x, 'not normal');
		}
		$new[$key] = (object)$next;
	    }
	    $over = (array)($old['over'] ?? []);
	    $nextOver = [];
	    foreach($now['storage']['volumes'] as $v) {
		$id = (string)$v['id'];
		$nextOver[$id] = $v['usedPercent'] >= $spaceAt;
		if (!$first && $nextOver[$id] && empty($over[$id]))
		    $add('space', 'volume', $v['name'], "volume {$v['name']}: {$v['usedPercent']} % used", $v, isset($over[$id]) ? false : null);
	    }
	    $new['over'] = (object)$nextOver;
	}

	if ($now['tasks'] !== null) {
	    $prev = (array)($old['tasks'] ?? []);
	    $next = [];
	    foreach($now['tasks'] as $t) {
		$id = (string)$t['id'];
		$next[$id] = [ $t['lastResult'], $t['lastTime'] ];
		$was = isset($prev[$id]) ? (array)$prev[$id] : null;
		if (!$first && $t['failed'] && ($was === null || $was[1] !== $t['lastTime'] || !in_array($was[0], NodeAppSynoBackup::FAILED, true)))
		    $add('backup', 'task', $t['name'], "Hyper Backup task {$t['name']}: {$t['lastResult']}" . ($t['lastTime'] !== '' ? " ({$t['lastTime']})" : ''), $t,
			$was === null ? null : [ 'lastResult' => $was[0], 'lastTime' => $was[1] ]);
	    }
	    $new['tasks'] = (object)$next;
	}

	if ($now['update'] !== null) {
	    if (!$first && $now['update'] !== '' && $now['update'] !== (string)($old['update'] ?? ''))
		$add('update', 'system', $nasName, "DSM update available: {$now['update']}", [ 'version' => $now['update'] ], (string)($old['update'] ?? '') ?: null);
	    $new['update'] = $now['update'];
	}

	$temp = $now['nas']['temperature'] ?? null;
	if ($temp !== null) {
	    $hot = $temp >= $tempAt;
	    if (!$first && $hot && empty($old['hot']))
		$add('temperature', 'system', $nasName, "temperature {$temp} °C", [ 'temperature' => $temp ], false);
	    $new['hot'] = $hot;
	}
	return [ $events, $new ];
    }

    //one check: [ the new state data, the executions started ]; over MAX_RUNS: the state kept as it was (the next check finds the events again)
    public static function check($wf, array $node, array $data) {
	$cfg = (array)($node['config'] ?? []);
	if (!empty($data['started']) && array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	[ $all, $new ] = self::eventsOf($data, self::current($wf, $cfg, self::TIMEOUT), $cfg);
	$chosen = self::chosen($cfg);
	$events = array_values(array_filter($all, fn($ev) => in_array($ev['event'], $chosen, true)));
	if (count($events) > self::MAX_RUNS) {
	    $events = array_slice($events, 0, self::MAX_RUNS);
	    $new = $data;
	}

	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'nas';
	$values = !$events ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'events' => $events, 'count' => count($events) ] ] : $events);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'synology-nas', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['events']) ? 'Synology: ' . \util\Format::plural($v['count'], 'event') : "Synology {$v['event']}: {$v['detail']}");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ $new, $started ];
    }

    //Run in the designer: one run with the first event the NAS is now in the state of (as if the last check had been all normal); the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    $now = self::current($wf, $cfg, 30);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => $e->getMessage() ];
	}
	//a normal NAS at the last check: every item ok, below the thresholds, reachable
	$normal = [ 'started' => true, 'reachable' => true, 'hot' => false, 'update' => '' ];
	foreach([ 'volumes', 'pools', 'disks' ] as $k)
	    $normal[$k] = array_fill_keys(array_map(fn($x) => (string)$x['id'], $now['storage'][$k] ?? []), true);
	$normal['over'] = array_fill_keys(array_map(fn($x) => (string)$x['id'], $now['storage']['volumes'] ?? []), false);
	$normal['tasks'] = [];
	foreach($now['tasks'] ?? [] as $t)
	    $normal['tasks'][(string)$t['id']] = [ 'done', '' ];
	[ $events, ] = self::eventsOf($normal, $now, $cfg);
	$pick = null;
	foreach(self::chosen($cfg) as $ev)
	    foreach($events as $x)
		if ($x['event'] === $ev) {
		    $pick = $x;
		    break 2;
		}
	if (!$pick)
	    return [ 'executions' => [], 'message' => 'Synology NAS: the NAS is not now in the state of the events chosen' ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'nas';
	$exe = \models\wf\WfExecution::startFor($wf, 'synology-nas', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => ($cfg['runs'] ?? 'each') === 'batch'
	    ? [ 'events' => [ $pick ], 'count' => 1 ] : $pick ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with the NAS as it is now: {$pick['detail']} ({$pick['event']})" ];
    }
}
