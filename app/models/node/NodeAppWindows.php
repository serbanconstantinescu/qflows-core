<?php
/*
    CLD: 2026-11-21T21:00:00+02:00: Windows - PowerShell on a Windows server through a Windows credential of the project (models\credential\CredentialWindows; util\WinRM:
    Windows PowerShell 5.1 over WinRM HTTPS). Operations:
	run       a PowerShell script; the parameters (a JSON object, or an expression giving one) are $P in it (values of the workflow go there, never into the script's text);
	          its value (ConvertTo-Json) is the result; an error (throw, a terminating error) is an error of the step (On error)
	disks     the fixed disks: { items: [ { drive, label, fileSystem, sizeGb, freeGb, usePercent } ], count }
	services  the services whose name or display name matches (wildcards; empty: all), optionally only the automatic ones that are stopped:
	          { items: [ { name, displayName, status, startType } ], count }
	start / stop / restart   a service (by name): { name, displayName, status }
	events    the newest events of a log (System, Application, ...) of some levels in the last hours: { items: [ { time, id, source, level, message } ], count }
    See NodeAppRemote for the shape, the settings and the errors. As an AI step's tools (family `windows`): run, start, stop and restart change things and ask for approval by default
*/

namespace models\node;

class NodeAppWindows extends NodeAppRemote {
    public static $name = 'windows';
    public static $label = 'Windows';
    public static $icon = 'bi-windows';
    public static $capability = 'windows';
    public static $credentialLabel = 'Windows credential';

    const OPERATIONS = [ 'run' => 'Run a PowerShell script', 'disks' => 'Disk usage', 'services' => 'List services', 'start' => 'Start a service', 'stop' => 'Stop a service',
	'restart' => 'Restart a service', 'events' => 'Recent events' ];
    const WRITES = [ 'run', 'start', 'stop', 'restart' ];
    const LEVELS = [ '1' => 'Critical', '2' => 'Error', '3' => 'Warning', '4' => 'Information' ];

    protected $win = null;

    protected static function operationFields() {
	return [
	    self::field('script', 'Script', [ 'run' ], [ 'type' => 'textarea', 'required' => true, 'default' => "Get-Service | Where-Object Status -eq 'Running' | Select-Object -First 5 Name, DisplayName",
		'help' => 'Its value is the result; the parameters are $P (e.g. $P.service)', 'ui' => [ 'rows' => 8, 'code' => true ] ]),
	    self::field('parameters', 'Parameters', [ 'run' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"service": "{{ alert.service }}"}',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true ] ]),
	    self::field('name', 'Name', [ 'services' ], [ 'help' => 'Wildcards, e.g. MSSQL* or *Backup*; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('stoppedAuto', 'Only automatic services that are stopped', [ 'services' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('service', 'Service', [ 'start', 'stop', 'restart' ], [ 'required' => true, 'help' => 'Its name (not the display name), e.g. Spooler',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('log', 'Log', [ 'events' ], [ 'default' => 'System', 'help' => 'System, Application, or another log\'s name', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('levels', 'Levels', [ 'events' ], [ 'type' => 'select', 'multiple' => true, 'default' => [ '1', '2' ], 'choices' => self::LEVELS, 'ui' => [ 'width' => 4 ] ]),
	    self::field('hours', 'In the last (hours)', [ 'events' ], [ 'type' => 'number', 'default' => 24, 'ui' => [ 'width' => 2, 'step' => 1 ] ]),
	    self::field('max', 'At most', [ 'events' ], [ 'type' => 'number', 'default' => 50, 'ui' => [ 'width' => 2, 'step' => 1 ] ]),
	];
    }

    protected static function resultInfo($op) {
	return match($op) {
	    'run' => [ 'type' => 'any', 'info' => 'the script\'s value' ],
	    'disks' => [ 'type' => 'object', 'info' => '{ items: [ { drive, label, fileSystem, sizeGb, freeGb, usePercent } ], count }' ],
	    'services' => [ 'type' => 'object', 'info' => '{ items: [ { name, displayName, status, startType } ], count }' ],
	    'start', 'stop', 'restart' => [ 'type' => 'object', 'info' => '{ name, displayName, status }' ],
	    'events' => [ 'type' => 'object', 'info' => '{ items: [ { time, id, source, level, message } ], count }' ],
	    default => parent::resultInfo($op),
	};
    }

    protected function connect($cred, $timeout) {
	$this->win = $cred->client($this->exec->aid, $timeout);
    }

    protected function ps($operation, $script, array $params = []) {
	return $this->win->ps('windows:' . $operation, $script, $params);
    }

    //a list's result: [ { items, count }, summary ]
    protected static function listOf($value, $what) {
	$items = $value === null ? [] : (is_array($value) && array_is_list($value) ? $value : [ $value ]);
	return [ [ 'items' => $items, 'count' => count($items) ], \util\Format::plural(count($items), $what) ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'run':
		$script = trim((string)$this->cfg('script', ''));
		if ($script === '')
		    throw new \RuntimeException('Script: empty');
		$r = $this->ps('run', $script, self::params($this->cfg('parameters', ''), $value));
		return [ $r, 'script run' . (is_array($r) && array_is_list($r) ? ': ' . \util\Format::plural(count($r), 'item') : '') ];

	    case 'disks':
		$r = $this->ps('disks', <<<'PS'
@(Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' | ForEach-Object {
  [ordered]@{ drive = $_.DeviceID; label = $_.VolumeName; fileSystem = $_.FileSystem; sizeGb = [math]::Round($_.Size / 1GB, 1); freeGb = [math]::Round($_.FreeSpace / 1GB, 1);
    usePercent = $(if ($_.Size) { [int](100 - 100 * $_.FreeSpace / $_.Size) } else { 0 }) }
})
PS);
		return self::listOf($r, 'disk');

	    case 'services':
		$r = $this->ps('services', <<<'PS'
$n = "$($P.name)".Trim()
$s = @(Get-Service | Where-Object { $n -eq '' -or $_.Name -like $n -or $_.DisplayName -like $n })
if ($P.stoppedAuto) { $s = @($s | Where-Object { "$($_.StartType)" -eq 'Automatic' -and "$($_.Status)" -ne 'Running' }) }
@($s | ForEach-Object { [ordered]@{ name = $_.Name; displayName = $_.DisplayName; status = "$($_.Status)"; startType = "$($_.StartType)" } })
PS, [ 'name' => (string)$text('name'), 'stoppedAuto' => (bool)$this->cfg('stoppedAuto', false) ]);
		return self::listOf($r, 'service');

	    case 'start':
	    case 'stop':
	    case 'restart':
		$service = trim((string)$text('service'));
		if ($service === '')
		    throw new \RuntimeException('Service: empty');
		$r = (array)$this->ps($operation, <<<'PS'
$s = Get-Service -Name $P.service
switch ($P.op) { 'start' { Start-Service -InputObject $s } 'stop' { Stop-Service -InputObject $s -Force } 'restart' { Restart-Service -InputObject $s -Force } }
$s.WaitForStatus($(if ($P.op -eq 'stop') { 'Stopped' } else { 'Running' }), [TimeSpan]::FromSeconds(60))
$s = Get-Service -Name $P.service
[ordered]@{ name = $s.Name; displayName = $s.DisplayName; status = "$($s.Status)" }
PS, [ 'service' => $service, 'op' => $operation ]);
		return [ $r, ($r['name'] ?? $service) . ': ' . ($r['status'] ?? '?') ];

	    case 'events':
		$levels = array_values(array_intersect(array_map('strval', (array)$this->cfg('levels', [ '1', '2' ])), array_keys(self::LEVELS)));
		$r = $this->ps('events', <<<'PS'
$f = @{ LogName = $P.log; StartTime = (Get-Date).AddHours(-[double]$P.hours) }
if ($P.levels.Count) { $f.Level = [int[]]$P.levels }
@(Get-WinEvent -FilterHashtable $f -MaxEvents ([int]$P.max) -ErrorAction SilentlyContinue | ForEach-Object {
  [ordered]@{ time = $_.TimeCreated.ToUniversalTime().ToString('o'); id = $_.Id; source = $_.ProviderName; level = $_.LevelDisplayName;
    message = $(if ($_.Message) { $_.Message.Substring(0, [Math]::Min(2000, $_.Message.Length)) } else { '' }) }
})
PS, [ 'log' => trim((string)$text('log')) ?: 'System', 'levels' => $levels ?: [ '1', '2' ], 'hours' => max(1, min(24 * 90, (int)$this->cfg('hours', 24))),
		    'max' => max(1, min(1000, (int)$this->cfg('max', 50))) ]);
		return self::listOf($r, 'event');
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
