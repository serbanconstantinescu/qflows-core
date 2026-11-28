<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: Synology Storage - the NAS's volumes, storage pools, drives and shared folders (DSM's own web interface's APIs: an administrator; not
    documented for integrations). Operations:
	overview      volumes, pools and drives at once, with the problems (SYNO.Storage.CGI.Storage load_info): { volumes, pools, disks, problems, ok }
	volumes       the volumes: { id, path, status, fsType, total, used, usedPercent, pool, ok }
	pools         the storage pools: { id, status, raid, total, used, usedPercent, disks, ok }
	disks         the drives: { id, name, model, serial, status, smart, temperature, size, ok }
	shares        the shared folders (SYNO.Core.Share list): name, volume, description, encryption, recycle bin
	createShare   a shared folder on a volume (SYNO.Core.Share create), deleted (delete: its files too)
	deleteShare
    ok: DSM's status is normal (a volume or pool: not degraded, crashed or read-only; a drive: its status and S.M.A.R.T. normal); problem(): what is wrong, the NAS trigger's
    test too
*/

namespace plugins\ITOps\models\node;

class NodeAppSynoStorage extends NodeAppSynology {
    public static $name = 'syno-storage';
    public static $label = 'Synology Storage';
    public static $icon = 'bi-device-hdd';
    public static $defaultOperation = 'overview';
    public static $defaultVariable = 'storage';

    const OPERATIONS = [
	'overview' => 'Get the storage overview',
	'volumes' => 'List volumes',
	'pools' => 'List storage pools',
	'disks' => 'List drives',
	'shares' => 'List shared folders',
	'createShare' => 'Create a shared folder',
	'deleteShare' => 'Delete a shared folder',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\agent\Tools)
    const WRITES = [ 'createShare', 'deleteShare' ];
    const LISTS = [ 'volumes', 'pools', 'disks', 'shares' ];
    //a status that is a problem (DSM's words: degraded, crashed, failing, warning, abnormal, read-only, ...); rebuilding / repairing: busy, not a problem
    const PROBLEM = '/degrad|crash|fail|warn|attention|abnormal|error|read_?only|missing|bad|damage/i';

    protected static function operationFields() {
	return [
	    self::field('share', 'Shared folder', [ 'createShare', 'deleteShare' ], [ 'required' => true, 'help' => 'Its name, e.g. projects', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('volume', 'Volume', [ 'createShare' ], [ 'required' => true, 'default' => '/volume1', 'help' => 'e.g. /volume1', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('description', 'Description', [ 'createShare' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('recycleBin', 'Recycle bin', [ 'createShare' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 3 ] ]),
	    self::field('confirm', 'Delete its files too', [ 'deleteShare' ], [ 'type' => 'switch', 'default' => false,
		'help' => 'Required: deleting a shared folder deletes everything in it', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return match($op) {
	    'volumes' => '{ id, path, status, fsType, total, used, usedPercent, pool, ok }',
	    'pools' => '{ id, status, raid, total, used, usedPercent, disks, ok }',
	    'disks' => '{ id, name, model, serial, status, smart, temperature, size, ok }',
	    default => '{ name, path, description, encrypted, recycleBin }',
	};
    }

    public static function problem($status) {
	return (bool)preg_match(self::PROBLEM, (string)$status);
    }

    protected static function percent($used, $total) {
	return $total > 0 ? round($used * 100 / $total, 1) : 0;
    }

    public static function volume(array $v) {
	$total = self::bytes($v['size']['total'] ?? 0);
	$used = self::bytes($v['size']['used'] ?? 0);
	$status = (string)($v['status'] ?? '');
	return [ 'id' => (string)($v['id'] ?? $v['vol_path'] ?? ''), 'path' => (string)($v['vol_path'] ?? ''), 'name' => (string)($v['display_name'] ?? $v['vol_path'] ?? ''),
	    'status' => $status, 'fsType' => (string)($v['fs_type'] ?? ''), 'total' => $total, 'used' => $used, 'usedPercent' => self::percent($used, $total),
	    'pool' => (string)($v['pool_path'] ?? ''), 'ok' => $status !== '' && !self::problem($status) ];
    }

    public static function pool(array $p) {
	$total = self::bytes($p['size']['total'] ?? 0);
	$used = self::bytes($p['size']['used'] ?? 0);
	$status = (string)($p['status'] ?? '');
	return [ 'id' => (string)($p['id'] ?? ''), 'name' => (string)($p['display_name'] ?? $p['id'] ?? ''), 'status' => $status,
	    'raid' => (string)($p['device_type'] ?? $p['raidType'] ?? ''), 'total' => $total, 'used' => $used, 'usedPercent' => self::percent($used, $total),
	    'disks' => array_values(array_map('strval', (array)($p['disks'] ?? []))), 'ok' => $status !== '' && !self::problem($status) ];
    }

    public static function disk(array $d) {
	$status = (string)($d['status'] ?? '');
	$smart = (string)($d['smart_status'] ?? '');
	return [ 'id' => (string)($d['id'] ?? ''), 'name' => (string)($d['longName'] ?? $d['name'] ?? $d['id'] ?? ''), 'model' => trim((string)($d['vendor'] ?? '') . ' ' . (string)($d['model'] ?? '')),
	    'serial' => (string)($d['serial'] ?? ''), 'status' => $status, 'smart' => $smart, 'temperature' => isset($d['temp']) ? (int)$d['temp'] : null,
	    'size' => self::bytes($d['size_total'] ?? 0), 'ok' => !self::problem($status) && !self::problem($smart) ];
    }

    //load_info, flattened: { volumes, pools, disks, problems: [ "<kind> <name>: <status>" ], ok }
    public static function overview(array $d) {
	$volumes = array_map([ self::class, 'volume' ], array_values(array_filter((array)($d['volumes'] ?? []), 'is_array')));
	$pools = array_map([ self::class, 'pool' ], array_values(array_filter((array)($d['storagePools'] ?? []), 'is_array')));
	$disks = array_map([ self::class, 'disk' ], array_values(array_filter((array)($d['disks'] ?? []), 'is_array')));
	$problems = [];
	foreach([ [ 'volume', $volumes ], [ 'pool', $pools ], [ 'drive', $disks ] ] as [ $kind, $list ])
	    foreach($list as $x)
		if (!$x['ok'])
		    $problems[] = "{$kind} {$x['name']}: " . ($x['status'] !== '' ? $x['status'] : 'unknown') . (!empty($x['smart']) && self::problem($x['smart']) ? " (S.M.A.R.T. {$x['smart']})" : '');
	return [ 'volumes' => $volumes, 'pools' => $pools, 'disks' => $disks, 'problems' => $problems, 'ok' => !$problems ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if (in_array($operation, [ 'overview', 'volumes', 'pools', 'disks' ], true)) {
	    $o = self::overview($this->call('SYNO.Storage.CGI.Storage', 'load_info', [], 1));
	    if ($operation === 'overview')
		return [ $o, \util\Format::plural(count($o['volumes']), 'volume') . ', ' . \util\Format::plural(count($o['disks']), 'drive') . ': '
		    . ($o['ok'] ? 'all normal' : implode('; ', array_slice($o['problems'], 0, 5))) ];
	    [ $r, $summary ] = $this->listResult($o[$operation], match($operation) { 'volumes' => 'volume', 'pools' => 'storage pool', default => 'drive' });
	    $bad = count(array_filter($o[$operation], fn($x) => !$x['ok']));
	    return [ $r, $summary . ($bad ? ", {$bad} not normal" : '') ];
	}
	switch($operation) {
	    case 'shares':
		[ $rows, $total ] = $this->pages('SYNO.Core.Share', 'list', [ 'additional' => [ 'encryption', 'recyclebin', 'share_quota' ] ], 'shares', 1);
		$rows = array_map(fn($s) => [ 'name' => (string)($s['name'] ?? ''), 'path' => (string)($s['vol_path'] ?? ''), 'description' => (string)($s['desc'] ?? ''),
		    'encrypted' => !empty($s['additional']['encryption']) || !empty($s['encryption']), 'recycleBin' => !empty($s['additional']['recyclebin']['enable_recycle_bin'] ?? $s['enable_recycle_bin'] ?? false) ]
		    + $s, $rows);
		return $this->listResult($rows, 'shared folder', $total);
	    case 'createShare':
		$name = self::shareName($text('share'));
		$volume = $text('volume');
		if (!preg_match('~^/volume(USB)?\d+$~', $volume))
		    throw new \RuntimeException('Volume: e.g. /volume1, not `' . mb_substr($volume, 0, 40) . '`');
		$info = [ 'name' => $name, 'vol_path' => $volume, 'desc' => $text('description'), 'enable_recycle_bin' => (bool)$this->cfg('recycleBin', true),
		    'recycle_bin_admin_only' => false, 'hidden' => false, 'hide_unreadable' => false, 'enable_share_cow' => false, 'enable_share_compress' => false, 'name_org' => '' ];
		$d = $this->call('SYNO.Core.Share', 'create', [ 'name' => $name, 'shareinfo' => $info ], 1);
		return [ [ 'name' => $name, 'path' => $volume ] + $d, "shared folder {$name} created on {$volume}" ];
	    case 'deleteShare':
		$name = self::shareName($text('share'));
		if (!$this->cfg('confirm', false))
		    throw new \RuntimeException('turn on "Delete its files too" to delete the shared folder ' . $name);
		$d = $this->call('SYNO.Core.Share', 'delete', [ 'name' => [ $name ] ], 1);
		return [ [ 'name' => $name, 'deleted' => true ] + $d, "shared folder {$name} deleted" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    public static function shareName($name) {
	$name = trim((string)$name);
	if (!preg_match('/^[^\/\\\\:*?"<>|\x00-\x1f]{1,32}$/u', $name) || trim($name, ' .') === '')
		throw new \RuntimeException('Shared folder: not a shared folder name (up to 32 characters, without / \\ : * ? " < > |): `' . mb_substr($name, 0, 40) . '`');
	return $name;
    }
}
