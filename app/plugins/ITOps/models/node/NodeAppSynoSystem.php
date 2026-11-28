<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: Synology System - the NAS itself (DSM's own web interface's APIs: an administrator; not documented for integrations). Operations:
	info          the model, serial, DSM version, uptime, temperature, memory (SYNO.Core.System info)
	utilization   CPU, memory, network and disk load now (SYNO.Core.System.Utilization get)
	update        whether a DSM update is available (SYNO.Core.Upgrade.Server check): { available, version, ... }
	packages      the packages installed, with their state (SYNO.Core.Package list)
	startPackage  a package started / stopped (SYNO.Core.Package.Control start / stop; its id, e.g. HyperBackup)
	stopPackage
	connections   who is connected now: DSM, File Station, SMB, AFP, ... (SYNO.Core.CurrentConnection list)
	reboot        the NAS restarted / shut down (SYNO.Core.System reboot / shutdown): the connection ends with it
	shutdown
*/

namespace plugins\ITOps\models\node;

class NodeAppSynoSystem extends NodeAppSynology {
    public static $name = 'syno-system';
    public static $label = 'Synology System';
    public static $icon = 'bi-cpu';
    public static $defaultOperation = 'info';
    public static $defaultVariable = 'nas';

    const OPERATIONS = [
	'info' => 'Get the NAS\'s information',
	'utilization' => 'Get its load now',
	'update' => 'Check for a DSM update',
	'packages' => 'List packages',
	'startPackage' => 'Start a package',
	'stopPackage' => 'Stop a package',
	'connections' => 'List current connections',
	'reboot' => 'Restart the NAS',
	'shutdown' => 'Shut the NAS down',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\agent\Tools)
    const WRITES = [ 'startPackage', 'stopPackage', 'reboot', 'shutdown' ];
    const LISTS = [ 'packages', 'connections' ];

    protected static function operationFields() {
	return [
	    self::field('package', 'Package', [ 'startPackage', 'stopPackage' ], [ 'required' => true, 'help' => 'Its id, as List packages gives it (e.g. HyperBackup, SynologyDrive)',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('confirm', 'I understand the NAS goes offline', [ 'reboot', 'shutdown' ], [ 'type' => 'switch', 'default' => false,
		'help' => 'Required: the NAS\'s services stop for every user (a shut-down NAS needs someone, or Wake on LAN, to start again)', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return $op === 'packages' ? '{ id, name, version, status, running }' : 'a connection: { who, type, from, descr, time }';
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'info':
		$d = $this->call('SYNO.Core.System', 'info', [], 1);
		return [ $d, trim(($d['model'] ?? 'NAS') . ', ' . ($d['firmware_ver'] ?? '')) . (isset($d['sys_temp']) ? ", {$d['sys_temp']} °C" : '') ];
	    case 'utilization':
		$d = $this->call('SYNO.Core.System.Utilization', 'get', [], 1);
		$cpu = (int)($d['cpu']['user_load'] ?? 0) + (int)($d['cpu']['system_load'] ?? 0) + (int)($d['cpu']['other_load'] ?? 0);
		return [ $d, "CPU {$cpu} %, memory " . (int)($d['memory']['real_usage'] ?? 0) . ' %' ];
	    case 'update':
		$d = $this->call('SYNO.Core.Upgrade.Server', 'check', [], 2);
		$u = (array)($d['update'] ?? $d);
		$available = !empty($u['available']);
		return [ [ 'available' => $available, 'version' => (string)($u['version'] ?? '') ] + $u, $available ? 'update available: ' . ($u['version'] ?? '?') : 'DSM is up to date' ];
	    case 'packages':
		$d = $this->call('SYNO.Core.Package', 'list', [ 'additional' => [ 'status', 'dsm_apps' ] ], 2);
		$rows = [];
		foreach((array)($d['packages'] ?? []) as $p)
		    if (is_array($p)) {
			$status = (string)($p['additional']['status'] ?? '');
			$rows[] = [ 'id' => (string)($p['id'] ?? ''), 'name' => (string)($p['name'] ?? ''), 'version' => (string)($p['version'] ?? ''), 'status' => $status,
			    'running' => $status === 'running' ];
		    }
		return $this->listResult($rows, 'package');
	    case 'startPackage':
	    case 'stopPackage':
		$id = $text('package');
		if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $id))
		    throw new \RuntimeException('Package: not a package id: `' . mb_substr($id, 0, 80) . '`');
		$d = $this->call('SYNO.Core.Package.Control', $operation === 'startPackage' ? 'start' : 'stop', [ 'id' => $id ], 1);
		return [ [ 'id' => $id ] + $d, "{$id} " . ($operation === 'startPackage' ? 'started' : 'stopped') ];
	    case 'connections':
		$d = $this->call('SYNO.Core.CurrentConnection', 'list', [ 'start' => 0, 'limit' => $this->maxItems() + 1 ], 1);
		$rows = array_values(array_filter((array)($d['items'] ?? $d['connections'] ?? []), 'is_array'));
		return $this->listResult($rows, 'connection', (int)($d['total'] ?? count($rows)));
	    case 'reboot':
	    case 'shutdown':
		if (!$this->cfg('confirm', false))
		    throw new \RuntimeException('turn on "I understand the NAS goes offline" to ' . ($operation === 'reboot' ? 'restart' : 'shut down') . ' the NAS');
		$d = $this->call('SYNO.Core.System', $operation, [ 'force' => false, 'local' => true ], 1);
		return [ [ $operation => true ] + $d, $operation === 'reboot' ? 'the NAS restarts' : 'the NAS shuts down' ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
