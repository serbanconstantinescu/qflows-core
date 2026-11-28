<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: Synology API - any call of DSM's Web API (what the other Synology steps do not have: Download Station, Surveillance Station, Synology
    Drive, Photos, logs, quotas, ...), signed in as the credential's account. Operations:
	request  an API, its method, a version (empty: the newest the NAS has), the parameters (a JSON object: arrays and objects sent as JSON, booleans as true / false):
	         the call's data
	apis     the APIs the NAS has (SYNO.API.Info): { name, path, minVersion, maxVersion }, optionally those beginning with a prefix
    Other parameters is not used here
*/

namespace plugins\ITOps\models\node;

class NodeAppSynoApi extends NodeAppSynology {
    public static $name = 'syno-api';
    public static $label = 'Synology API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'nas';

    const OPERATIONS = [ 'request' => 'Call an API', 'apis' => 'List the NAS\'s APIs' ];
    //the operations that change something (any call); the others only read (an AI step's tools: the read ones ticked by default, util\agent\Tools)
    const WRITES = [ 'request' ];
    const LISTS = [ 'apis' ];

    protected static function operationFields() {
	return [
	    self::field('api', 'API', [ 'request' ], [ 'required' => true, 'help' => 'e.g. SYNO.DownloadStation2.Task, SYNO.Core.Quota ({{ }} allowed)',
		'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => 'SYNO.Core.System' ] ]),
	    self::field('method', 'Method', [ 'request' ], [ 'required' => true, 'help' => 'e.g. list, get, info', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('version', 'Version', [ 'request' ], [ 'help' => 'Empty: the newest the NAS has', 'ui' => [ 'width' => 3 ] ]),
	    self::field('callParams', 'Parameters', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"offset": 0, "limit": 50}',
		'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	    self::field('prefix', 'Beginning with', [ 'apis' ], [ 'help' => 'e.g. SYNO.Backup; empty: all', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    public static function configSchema() {
	return array_values(array_filter(parent::configSchema(), fn($f) => $f['key'] !== 'params'));
    }

    protected static function itemInfo($op) {
	return '{ name, path, minVersion, maxVersion }';
    }

    protected function extraParams() {
	return [];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'apis') {
	    $prefix = $text('prefix');
	    $rows = [];
	    foreach($this->nas->apis() as $name => $a)
		if (is_array($a) && ($prefix === '' || stripos((string)$name, $prefix) === 0))
		    $rows[] = [ 'name' => (string)$name, 'path' => (string)($a['path'] ?? ''), 'minVersion' => (int)($a['minVersion'] ?? 1), 'maxVersion' => (int)($a['maxVersion'] ?? 1) ];
	    usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
	    return $this->listResult($rows, 'API');
	}
	$api = $text('api');
	$method = $text('method');
	$version = $text('version');
	if ($version !== '' && !preg_match('/^\d{1,3}$/', $version))
	    throw new \RuntimeException('Version: a number, or empty');
	$params = $value('callParams');
	if (is_string($params)) {
	    $params = trim($params) === '' ? [] : json_decode($params, true);
	    if (!is_array($params))
		throw new \RuntimeException('Parameters: not a JSON object');
	}
	if (!is_array($params) || ($params && array_is_list($params)))
	    throw new \RuntimeException('Parameters: not a JSON object');
	return [ $this->nas->call($api, $method, $params, $version === '' ? null : (int)$version), "{$api} {$method}" ];
    }
}
