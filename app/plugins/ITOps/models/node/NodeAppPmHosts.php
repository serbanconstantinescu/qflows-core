<?php

/*
    PatchMon Hosts - the hosts PatchMon watches (the integration API). Operations (setting `operation`):
	list          the hosts (GET hosts, ?hostgroup, ?include=stats): { items: [ { id, friendly_name, hostname, ip, host_groups, and with stats: os_type, os_version,
	              last_update, status, effective_status, reporting_state, update_state, needs_reboot, updates_count, security_updates_count, total_packages } ] };
	              State keeps the hosts in that state (read with the stats)
	get           a host (GET hosts/<id>/info): machine id, names, IP, OS, agent version, host groups
	stats         its package counts (GET hosts/<id>/stats)
	system        its system (GET hosts/<id>/system): kernel, uptime, CPU, memory, disks, needs_reboot and why
	network       its network (GET hosts/<id>/network)
	notes         its notes (GET hosts/<id>/notes)
	integrations  its integrations, e.g. Docker (GET hosts/<id>/integrations)
	delete        the host and all its data (DELETE hosts/<id>; the token needs host: delete)
    Host: its id, or its host name / friendly name (looked up in the list)
*/

namespace plugins\ITOps\models\node;

class NodeAppPmHosts extends NodeAppPatchMon {
    public static $name = 'pm-hosts';
    public static $label = 'PatchMon Hosts';
    public static $icon = 'bi-hdd-stack';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'hosts';

    const OPERATIONS = [
	'list' => 'List hosts',
	'get' => 'Get a host',
	'stats' => 'Get its package counts',
	'system' => 'Get its system',
	'network' => 'Get its network',
	'notes' => 'Get its notes',
	'integrations' => 'Get its integrations',
	'delete' => 'Delete a host',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'delete' ];
    const LISTS = [ 'list' ];
    const STATES = [
	'' => 'Any',
	'updates' => 'Updates pending',
	'security' => 'Security updates pending',
	'reboot' => 'Reboot needed',
	'up_to_date' => 'Up to date',
	'not_reporting' => 'Not reporting (overdue or stale)',
	'stale' => 'Stale (silent for over twice the update interval)',
	'pending' => 'Not enrolled yet',
    ];
    const DETAILS = [ 'get' => 'info', 'stats' => 'stats', 'system' => 'system', 'network' => 'network', 'notes' => 'notes', 'integrations' => 'integrations' ];

    protected static function operationFields() {
	return [
	    self::field('hostgroup', 'Host groups', [ 'list' ], [ 'help' => 'Names or ids, separated by commas; empty: every host', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('state', 'State', [ 'list' ], [ 'type' => 'select', 'choices' => self::STATES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('stats', 'With package counts and state', [ 'list' ], [ 'type' => 'switch', 'default' => true, 'help' => 'OS, last report, updates, reboot (always read with a State)',
		'ui' => [ 'width' => 6 ] ]),
	    self::hostField(array_keys(self::DETAILS + [ 'delete' => '' ])),
	];
    }

    //whether host $h (read with stats) is in state $state
    public static function inState(array $h, $state) {
	$update = (string)($h['update_state'] ?? '');
	$reporting = (string)($h['reporting_state'] ?? '');
	return match($state) {
	    'updates' => in_array($update, [ 'updates_pending', 'security_required' ], true) || (int)($h['updates_count'] ?? 0) > 0,
	    'security' => $update === 'security_required' || (int)($h['security_updates_count'] ?? 0) > 0,
	    'reboot' => !empty($h['needs_reboot']),
	    'up_to_date' => $update === 'up_to_date',
	    'not_reporting' => in_array($reporting, [ 'overdue', 'stale' ], true) && ($h['status'] ?? '') !== 'pending',
	    'stale' => $reporting === 'stale' && ($h['status'] ?? '') !== 'pending',
	    'pending' => ($h['status'] ?? '') === 'pending',
	    default => true,
	};
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list') {
	    $state = isset(self::STATES[(string)$this->cfg('state', '')]) ? (string)$this->cfg('state', '') : '';
	    $groups = self::names($value('hostgroup'));
	    $query = [ 'hostgroup' => $groups ? implode(',', $groups) : null, 'include' => ($state !== '' || $this->cfg('stats', true)) ? 'stats' : null ];
	    $hosts = array_values(array_filter((array)($this->get('/hosts', $query)['hosts'] ?? []), fn($h) => is_array($h) && self::inState($h, $state)));
	    [ $result, $summary ] = $this->listResult($hosts, 'host');
	    return [ $result, $summary . ($state !== '' ? ' (' . mb_strtolower(self::STATES[$state]) . ')' : '') ];
	}
	$id = $this->pm->hostId($value('host'));
	if ($operation === 'delete') {
	    $r = $this->pm->api('DELETE', '/hosts/' . $id, [], $this->extraParams() ?: null);
	    $deleted = (array)($r['deleted'] ?? []);
	    return [ $r, 'host ' . ($deleted['friendly_name'] ?? $deleted['hostname'] ?? $id) . ' deleted' ];
	}
	if (!isset(self::DETAILS[$operation]))
	    throw new \RuntimeException("unknown operation `{$operation}`");
	return [ $this->get('/hosts/' . $id . '/' . self::DETAILS[$operation]), "host {$id}" ];
    }
}
