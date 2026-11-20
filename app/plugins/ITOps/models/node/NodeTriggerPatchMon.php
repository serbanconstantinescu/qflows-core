<?php

/*
    PatchMon host - starts the workflow when a host PatchMon watches changes, for the events chosen:
	security   its security updates go up (from none, or more than at the last check)
	updates    it had no updates and now has some
	patched    it had updates and is up to date again
	reboot     it needs a reboot (it did not)
	stale      it stops reporting: silent for over twice the update interval (PatchMon's reporting_state stale; hosts not enrolled yet left out)
	reporting  it was stale and reports again
	new        a host appears
    of every host or of the host groups given. It checks the server on its schedule (models\wf\WfSchedule, every 10 minutes by default; util\Scheduler runs runScheduled):
    the hosts with their state (integration API: GET hosts?include=stats) compared with the last check's (models\TriggerState: hosts { id: { update, security, reboot,
    reporting, status } }). The first check only notes the hosts. The variable: the host as PatchMon lists it, with event and previous (its state at the last check), or
    { events, count } per check; at most MAX_RUNS per check
*/

namespace plugins\ITOps\models\node;

class NodeTriggerPatchMon extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'patchmon';
    public static $label = 'PatchMon host';
    public static $icon = 'bi-bandaid';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const EVENTS = [
	'security' => 'Security updates go up',
	'updates' => 'Updates appear',
	'patched' => 'Up to date again',
	'reboot' => 'Reboot needed',
	'stale' => 'Stops reporting',
	'reporting' => 'Reports again',
	'new' => 'New host',
    ];
    const RUNS = [ 'each' => 'One run per event', 'batch' => 'One run per check with events' ];
    const PENDING = [ 'updates_pending', 'security_required' ];
    const MAX_RUNS = 200;
    const TIMEOUT = 60;

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('patchmon', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'PatchMon credential',
		'choices' => $choices,
		'help' => 'The server whose hosts start the workflow',
		'required' => true,
	    ],
	    [
		'key' => 'events',
		'type' => 'select',
		'multiple' => true,
		'label' => 'When a host',
		'required' => true,
		'default' => [ 'security', 'reboot', 'stale' ],
		'choices' => self::EVENTS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'hostgroup',
		'type' => 'text',
		'label' => 'Host groups',
		'default' => '',
		'help' => 'Names or ids, separated by commas; empty: every host',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'runs',
		'type' => 'select',
		'label' => 'Runs',
		'required' => true,
		'default' => 'each',
		'choices' => self::RUNS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Host variable',
		'required' => true,
		'default' => 'host',
		'help' => 'e.g. {{ host.event }}, {{ host.friendly_name }}, {{ host.security_updates_count }}',
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
	    $this->errors('config', 'PatchMon host: choose at least one event');
	    $valid = false;
	}
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'patchmon')))) {
	    $this->errors('config', 'PatchMon host: choose a PatchMon credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'host') : 'host';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ events, count }', 'fields' => [
		'events' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a host, with event and previous' ] ], 'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the host, as PatchMon lists it', 'fields' => [
	    'event' => [ 'type' => 'string', 'info' => implode(', ', array_keys(self::EVENTS)) ], 'id' => [ 'type' => 'string' ], 'friendly_name' => [ 'type' => 'string' ],
	    'hostname' => [ 'type' => 'string' ], 'ip' => [ 'type' => 'string' ], 'host_groups' => [ 'type' => 'array' ], 'os_type' => [ 'type' => 'string' ],
	    'os_version' => [ 'type' => 'string' ], 'last_update' => [ 'type' => 'string', 'info' => 'ISO 8601: its last report' ],
	    'update_state' => [ 'type' => 'string', 'info' => 'up_to_date, updates_pending or security_required' ], 'updates_count' => [ 'type' => 'number' ],
	    'security_updates_count' => [ 'type' => 'number' ], 'needs_reboot' => [ 'type' => 'boolean' ],
	    'reporting_state' => [ 'type' => 'string', 'info' => 'reporting, overdue or stale' ], 'effective_status' => [ 'type' => 'string', 'info' => 'pending, active or inactive' ],
	    'previous' => [ 'type' => 'object', 'info' => 'its state at the last check (null for a new host)' ] ] ] ];
    }

    //the events chosen (known ones)
    protected static function chosen(array $cfg) {
	return array_values(array_intersect(array_keys(self::EVENTS), (array)($cfg['events'] ?? [])));
    }

    //what is compared of a host
    protected static function stateOf(array $h) {
	return [ 'update' => (string)($h['update_state'] ?? ''), 'security' => (int)($h['security_updates_count'] ?? 0), 'reboot' => !empty($h['needs_reboot']),
	    'reporting' => (string)($h['reporting_state'] ?? ''), 'status' => (string)($h['status'] ?? '') ];
    }

    //the events of a host between its previous state ($prev, null: new) and now ($now), in EVENTS' order
    public static function eventsOf($prev, array $now) {
	if ($prev === null)
	    return [ 'new' ];
	$events = [];
	if ($now['security'] > (int)$prev['security'])
	    $events[] = 'security';
	if ($prev['update'] === 'up_to_date' && in_array($now['update'], self::PENDING, true))
	    $events[] = 'updates';
	if (in_array($prev['update'], self::PENDING, true) && $now['update'] === 'up_to_date')
	    $events[] = 'patched';
	if ($now['reboot'] && empty($prev['reboot']))
	    $events[] = 'reboot';
	if ($now['reporting'] === 'stale' && $prev['reporting'] !== 'stale' && $now['status'] !== 'pending')
	    $events[] = 'stale';
	if ($prev['reporting'] === 'stale' && $now['reporting'] === 'reporting')
	    $events[] = 'reporting';
	return $events;
    }

    //the hosts of the step's server, with their state
    protected static function current($wf, array $cfg, $timeout) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'patchmon');
	if (!$cred)
	    throw new \Exception('PatchMon host: the credential is no longer available');
	$groups = NodeAppPatchMon::names((string)($cfg['hostgroup'] ?? ''));
	try {
	    $r = $cred->client($wf->aid, $timeout)->api('GET', '/hosts', [ 'include' => 'stats', 'hostgroup' => $groups ? implode(',', $groups) : null ]);
	} catch (\RuntimeException $e) {
	    throw new \Exception('PatchMon host: ' . $e->getMessage());
	}
	return array_values(array_filter((array)($r['hosts'] ?? []), fn($h) => is_array($h) && !empty($h['id'])));
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("PatchMon host: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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

    //one check: [ the new state data { started: true, hosts: { id: state } }, the executions started ]; a host whose event could not start a run (over MAX_RUNS) keeps its
    //previous state, so the next check finds the event again
    public static function check($wf, array $node, array $data) {
	$cfg = (array)($node['config'] ?? []);
	$old = (array)($data['hosts'] ?? []);
	$first = empty($data['started']);
	if (!$first && array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$chosen = self::chosen($cfg);

	$events = [];
	$now = [];
	foreach(self::current($wf, $cfg, self::TIMEOUT) as $h) {
	    $id = (string)$h['id'];
	    $state = self::stateOf($h);
	    $prev = isset($old[$id]) ? (array)$old[$id] : null;
	    $mine = $first ? [] : array_values(array_intersect(self::eventsOf($prev, $state), $chosen));
	    if ($mine && count($events) + count($mine) > self::MAX_RUNS) {
		//not started: the next check finds them again (a new host stays out of the state)
		if ($prev !== null)
		    $now[$id] = $prev;
		continue;
	    }
	    foreach($mine as $ev)
		$events[] = [ 'event' => $ev ] + $h + [ 'previous' => $prev === null ? null : [ 'update_state' => $prev['update'], 'security_updates_count' => (int)$prev['security'],
		    'needs_reboot' => (bool)$prev['reboot'], 'reporting_state' => $prev['reporting'] ] ];
	    $now[$id] = $state;
	}

	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'host';
	$values = !$events ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'events' => $events, 'count' => count($events) ] ] : $events);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'patchmon', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['events']) ? 'PatchMon: ' . \util\Format::plural($v['count'], 'event')
		: "PatchMon {$v['event']}: " . ($v['friendly_name'] ?? $v['hostname'] ?? $v['id']));
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'started' => true, 'hosts' => (object)$now ], $started ];
    }

    //whether host $h is now as event $ev would leave it (Run in the designer)
    protected static function showsNow(array $h, $ev) {
	$s = self::stateOf($h);
	return match($ev) {
	    'security' => $s['security'] > 0,
	    'updates' => in_array($s['update'], self::PENDING, true),
	    'patched' => $s['update'] === 'up_to_date',
	    'reboot' => $s['reboot'],
	    'stale' => $s['reporting'] === 'stale' && $s['status'] !== 'pending',
	    'reporting' => $s['reporting'] === 'reporting',
	    default => true,
	};
    }

    //Run in the designer: one run with the first host now in the state of a chosen event; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    $hosts = self::current($wf, $cfg, 30);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => $e->getMessage() ];
	}
	$pick = null;
	foreach(self::chosen($cfg) as $ev) {
	    foreach($hosts as $h)
		if (self::showsNow($h, $ev)) {
		    $pick = [ 'event' => $ev ] + $h + [ 'previous' => null ];
		    break 2;
		}
	}
	if (!$pick)
	    return [ 'executions' => [], 'message' => 'PatchMon host: no host is now in the state of the events chosen' ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'host';
	$exe = \models\wf\WfExecution::startFor($wf, 'patchmon', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => ($cfg['runs'] ?? 'each') === 'batch'
	    ? [ 'events' => [ $pick ], 'count' => 1 ] : $pick ] ], (string)$node['wfid']);
	$exe->save();
	$label = $pick['friendly_name'] ?? $pick['hostname'] ?? $pick['id'];
	return [ 'executions' => [ $exe ], 'message' => "Run with a host now in that state: {$label} ({$pick['event']})" ];
    }
}
