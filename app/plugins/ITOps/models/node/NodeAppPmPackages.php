<?php

/*
    PatchMon Packages - a host's packages and what its agent reported (the integration API). Operations (setting `operation`):
	packages   its packages (GET hosts/<id>/packages, ?updates_only=true for those with an update): { items: [ { name, description, category, current_version,
	           available_version, needs_update, is_security_update, last_checked } ] } (security updates first); Packages: all, with an update, security updates only (of
	           those), and Name keeps the packages whose name holds it
	reports    its package reports, newest first (GET hosts/<id>/package_reports?limit): { items: [ { status, date, total_packages, outdated_packages, security_updates,
	           execution_time_seconds, error_message } ] }
	queue      its agent's queue (GET hosts/<id>/agent_queue?limit): { queue_status: { waiting, active, delayed, failed }, job_history: [...] }
    Host: its id, or its host name / friendly name (looked up in the list)
*/

namespace plugins\ITOps\models\node;

class NodeAppPmPackages extends NodeAppPatchMon {
    public static $name = 'pm-packages';
    public static $label = 'PatchMon Packages';
    public static $icon = 'bi-box-seam';
    public static $defaultOperation = 'packages';
    public static $defaultVariable = 'packages';

    const OPERATIONS = [
	'packages' => 'List its packages',
	'reports' => 'List its package reports',
	'queue' => 'Get its agent queue',
    ];
    //the operations that change something: none (an AI step's tools: all ticked by default, util\agent\Tools)
    const WRITES = [];
    const LISTS = [ 'packages', 'reports' ];
    const WHICH = [ 'updates' => 'With an update', 'security' => 'Security updates', 'all' => 'All installed' ];
    const MAX_HISTORY = 100;

    protected static function operationFields() {
	return [
	    self::hostField(array_keys(self::OPERATIONS)),
	    self::field('which', 'Packages', [ 'packages' ], [ 'type' => 'select', 'default' => 'updates', 'choices' => self::WHICH, 'ui' => [ 'width' => 6 ] ]),
	    self::field('name', 'Name contains', [ 'packages' ], [ 'help' => 'Empty: any package', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('limit', 'Last', [ 'reports', 'queue' ], [ 'type' => 'number', 'default' => 10, 'help' => 'Reports or jobs, newest first',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_HISTORY, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_HISTORY ] ], 'ui' => [ 'step' => 1, 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$id = $this->pm->hostId($value('host'));
	$limit = max(1, min(self::MAX_HISTORY, (int)$this->cfg('limit', 10)));
	switch($operation) {
	    case 'packages':
		$which = isset(self::WHICH[(string)$this->cfg('which', 'updates')]) ? (string)$this->cfg('which', 'updates') : 'updates';
		$r = $this->get("/hosts/{$id}/packages", [ 'updates_only' => $which !== 'all' ? 'true' : null ]);
		$name = mb_strtolower($text('name'));
		$rows = array_filter((array)($r['packages'] ?? []), fn($p) => is_array($p) && ($which !== 'security' || !empty($p['is_security_update']))
		    && ($name === '' || str_contains(mb_strtolower((string)($p['name'] ?? '')), $name)));
		$host = (array)($r['host'] ?? []);
		[ $result, $summary ] = $this->listResult($rows, $which === 'all' ? 'package' : ($which === 'security' ? 'security update' : 'package with an update'));
		return [ $result + [ 'host' => $host ], $summary . ' on ' . ($host['friendly_name'] ?? $host['hostname'] ?? $id) ];
	    case 'reports':
		$r = $this->get("/hosts/{$id}/package_reports", [ 'limit' => $limit ]);
		return $this->listResult((array)($r['reports'] ?? []), 'report', $r['total'] ?? null);
	    case 'queue':
		$r = $this->get("/hosts/{$id}/agent_queue", [ 'limit' => $limit ]);
		$q = (array)($r['queue_status'] ?? []);
		return [ $r, sprintf('%d waiting, %d active, %d failed', (int)($q['waiting'] ?? 0), (int)($q['active'] ?? 0), (int)($q['failed'] ?? 0)) ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
