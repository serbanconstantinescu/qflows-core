<?php
/*
    CLD: 2026-11-21T21:00:00+02:00: SSH - commands on a Linux / Unix server through an SSH credential of the project (models\credential\CredentialSsh; util\Ssh). Operations:
	run       a shell script (sh) sent on stdin; the parameters (a JSON object, or an expression giving one) are shell variables P_<name> in it: values of the workflow go
	          there, never into the script's text. { stdout, stderr, exitCode, ok }; a non-zero exit code is an error (On error) unless "Any exit code" is on
	disks     the file systems (df -PTk, pseudo file systems left out): { items: [ { filesystem, type, sizeKb, usedKb, availableKb, usePercent, mount } ], count }
	status    a systemd unit's state (systemctl show): { unit, load, active, sub, enabled, pid, since }
	restart   a systemd unit restarted (systemctl restart; with sudo -n when "Use sudo" is on: the user needs a NOPASSWD rule for it), then its state: { unit, active, sub }
	journal   a unit's journal lines (journalctl -u, the newest N, since a time): { lines, count }
	log       CLD: 2026-11-22T12:00:00+02:00: log files (paths, shell patterns) read from a position, only under the credential's log folders (util\LogFiles: rotation,
	          truncation, whole lines, a filter): { lines: [ { file, text } ], count, more, position, notes }; the position goes back into Position for the next read
    See NodeAppRemote for the shape, the settings and the errors. As an AI step's tools (family `ssh`): run and restart change things and ask for approval by default
*/

namespace models\node;

class NodeAppSsh extends NodeAppRemote {
    public static $name = 'ssh';
    public static $label = 'SSH';
    public static $icon = 'bi-terminal';
    public static $capability = 'ssh';
    public static $credentialLabel = 'SSH credential';

    const OPERATIONS = [ 'run' => 'Run a script', 'disks' => 'Disk usage', 'status' => 'A service\'s state', 'restart' => 'Restart a service', 'journal' => 'A service\'s log', 'log' => 'Read a log file' ];
    const WRITES = [ 'run', 'restart' ];
    const UNIT_RE = '/^[A-Za-z0-9@._:\\-]{1,200}$/';

    protected $ssh = null;
    protected $cred = null;
    protected $timeout = 120;

    protected static function operationFields() {
	return [
	    self::field('script', 'Script', [ 'run' ], [ 'type' => 'textarea', 'required' => true, 'default' => "df -h /\nuptime",
		'help' => 'sh; the parameters are shell variables $P_<name> (e.g. "$P_service": quote them)', 'ui' => [ 'rows' => 8, 'code' => true ] ]),
	    self::field('parameters', 'Parameters', [ 'run' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"service": "{{ cmk.service }}"}',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true ] ]),
	    self::field('anyExit', 'Any exit code', [ 'run' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Off: a non-zero exit code is an error (On error)' ]),
	    self::field('unit', 'Service', [ 'status', 'restart', 'journal' ], [ 'required' => true, 'help' => 'A systemd unit, e.g. nginx or nginx.service',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('sudo', 'Use sudo', [ 'restart', 'journal' ], [ 'type' => 'switch', 'default' => true, 'help' => 'sudo -n: the user needs a NOPASSWD rule for the command',
		'ui' => [ 'width' => 6 ] ]),
	    self::field('lines', 'Lines', [ 'journal' ], [ 'type' => 'number', 'default' => 100, 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	    self::field('paths', 'Log files', [ 'log' ], [ 'type' => 'textarea', 'required' => true, 'default' => '/var/log/messages',
		'help' => 'One path per line, shell patterns allowed (/var/log/nginx/*.log); only under the credential\'s log folders; compressed files are left out',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('filter', 'Only lines matching', [ 'log' ], [ 'help' => 'A regular expression, e.g. (?i)error|warn (empty: every line)', 'ui' => [ 'width' => 6, 'expression' => 'text' ] ]),
	    self::field('from', 'Read', [ 'log' ], [ 'type' => 'select', 'default' => 'position', 'choices' => \util\LogFiles::FROM, 'ui' => [ 'width' => 6 ] ]),
	    self::field('position', 'Position', [ 'log' ], [ 'help' => 'The position of the last read (its result\'s position, e.g. {{ logs.position }}); empty: the last lines',
		'ui' => [ 'expression' => true, 'hideIf' => [ 'key' => 'from', 'operator' => 'eq', 'value' => 'end' ] ] ]),
	    self::field('maxLines', 'Lines at most', [ 'log' ], [ 'type' => 'number', 'default' => 1000, 'help' => 'More: the next read goes on (more is true)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => \util\LogFiles::MAX_LINES, 'skipEmpty' => true, 'message' => 'from 1 to ' . \util\LogFiles::MAX_LINES ] ],
		'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	    self::field('logSudo', 'Read with sudo', [ 'log' ], [ 'type' => 'switch', 'default' => false,
		'help' => 'Off: the user reads them (on most systems: in group adm); on: sudo -n readlink, stat, find, tail need a NOPASSWD rule', 'ui' => [ 'width' => 6 ] ]),
	    self::field('since', 'Since', [ 'journal' ], [ 'help' => 'e.g. -1h, today, 2026-11-21 08:00 (empty: no limit)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected static function resultInfo($op) {
	return match($op) {
	    'run' => [ 'type' => 'object', 'info' => '{ stdout, stderr, exitCode, ok }', 'fields' => [ 'stdout' => [ 'type' => 'string' ], 'stderr' => [ 'type' => 'string' ],
		'exitCode' => [ 'type' => 'number' ], 'ok' => [ 'type' => 'boolean', 'info' => 'exit code 0' ] ] ],
	    'disks' => [ 'type' => 'object', 'info' => '{ items: [ { filesystem, type, sizeKb, usedKb, availableKb, usePercent, mount } ], count }' ],
	    'status' => [ 'type' => 'object', 'info' => '{ unit, load, active, sub, enabled, pid, since }' ],
	    'restart' => [ 'type' => 'object', 'info' => '{ unit, active, sub }' ],
	    'journal' => [ 'type' => 'object', 'info' => '{ lines, count }' ],
	    'log' => self::logInfo(),
	    default => parent::resultInfo($op),
	};
    }

    //what a read of log files gives (the step's variable, the Log watch trigger's)
    public static function logInfo() {
	return [ 'type' => 'object', 'info' => '{ lines: [ { file, text } ], count, more, position, notes }', 'fields' => [
	    'lines' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'file' => [ 'type' => 'string' ], 'text' => [ 'type' => 'string' ] ] ] ],
	    'count' => [ 'type' => 'number' ], 'more' => [ 'type' => 'boolean', 'info' => 'more lines are waiting: the next read goes on' ],
	    'position' => [ 'type' => 'array', 'info' => 'where this read stopped: give it to the next one' ],
	    'notes' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'info' => 'rotated, truncated, lost, not in the log folders, no file' ] ] ];
    }

    protected function connect($cred, $timeout) {
	$this->cred = $cred;
	$this->ssh = $cred->client($this->exec->aid);
	$this->timeout = $timeout;
    }

    //runs $script: [ stdout, stderr, exit code ]
    protected function sh($script, array $params = []) {
	return $this->ssh->run($script, $params, $this->timeout);
    }

    protected static function unit($text) {
	$unit = trim((string)$text);
	if (!preg_match(self::UNIT_RE, $unit))
	    throw new \RuntimeException("Service: not a unit name: {$unit}");
	return $unit;
    }

    //stderr's last lines, for a message
    protected static function tail($text) {
	$lines = array_slice(array_filter(array_map('trim', preg_split('/\R/', (string)$text))), -3);
	return mb_substr(implode(' | ', $lines), 0, 500);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'run':
		$script = (string)$this->cfg('script', '');
		if (trim($script) === '')
		    throw new \RuntimeException('Script: empty');
		[ $out, $err, $code ] = $this->sh($script, self::params($this->cfg('parameters', ''), $value));
		if ($code !== 0 && !$this->cfg('anyExit', false))
		    throw new \RuntimeException("exit code {$code}" . (trim($err) !== '' ? ': ' . self::tail($err) : ''));
		return [ [ 'stdout' => $out, 'stderr' => $err, 'exitCode' => $code, 'ok' => $code === 0 ], "exit code {$code}, " . strlen($out) . ' bytes of output' ];

	    case 'disks':
		[ $out, $err, $code ] = $this->sh('df -PTk -x tmpfs -x devtmpfs -x squashfs -x overlay 2>/dev/null || df -Pk');
		if ($code !== 0 && trim($out) === '')
		    throw new \RuntimeException('df failed: ' . self::tail($err));
		$items = [];
		foreach(array_slice(preg_split('/\R/', trim($out)), 1) as $line) {
		    $c = preg_split('/\s+/', trim($line));
		    if (count($c) >= 7)
			$items[] = [ 'filesystem' => $c[0], 'type' => $c[1], 'sizeKb' => (int)$c[2], 'usedKb' => (int)$c[3], 'availableKb' => (int)$c[4],
			    'usePercent' => (int)rtrim($c[5], '%'), 'mount' => implode(' ', array_slice($c, 6)) ];
		    elseif (count($c) === 6)
			$items[] = [ 'filesystem' => $c[0], 'type' => '', 'sizeKb' => (int)$c[1], 'usedKb' => (int)$c[2], 'availableKb' => (int)$c[3],
			    'usePercent' => (int)rtrim($c[4], '%'), 'mount' => $c[5] ];
		}
		$full = array_reduce($items, fn($m, $i) => max($m, $i['usePercent']), 0);
		return [ [ 'items' => $items, 'count' => count($items) ], \util\Format::plural(count($items), 'file system') . ", the fullest {$full}%" ];

	    case 'status':
		$unit = self::unit($text('unit'));
		[ $out, $err, $code ] = $this->sh('systemctl show "$P_unit" -p Id -p LoadState -p ActiveState -p SubState -p UnitFileState -p MainPID -p ActiveEnterTimestamp --no-pager',
		    [ 'unit' => $unit ]);
		if ($code !== 0)
		    throw new \RuntimeException('systemctl failed: ' . self::tail($err));
		$p = [];
		foreach(preg_split('/\R/', trim($out)) as $line) {
		    $kv = explode('=', $line, 2);
		    if (count($kv) === 2)
			$p[$kv[0]] = $kv[1];
		}
		if (($p['LoadState'] ?? '') === 'not-found')
		    throw new \RuntimeException("Service: no unit {$unit}");
		$r = [ 'unit' => $p['Id'] ?? $unit, 'load' => $p['LoadState'] ?? '', 'active' => $p['ActiveState'] ?? '', 'sub' => $p['SubState'] ?? '',
		    'enabled' => $p['UnitFileState'] ?? '', 'pid' => (int)($p['MainPID'] ?? 0), 'since' => $p['ActiveEnterTimestamp'] ?? '' ];
		return [ $r, "{$r['unit']} {$r['active']} ({$r['sub']})" ];

	    case 'restart':
		$unit = self::unit($text('unit'));
		$sudo = $this->cfg('sudo', true) ? 'sudo -n ' : '';
		//the restart's own exit code decides (a unit that stays active after a refused restart is not a success)
		[ $out, $err, $code ] = $this->sh($sudo . 'systemctl restart "$P_unit"; rc=$?; [ $rc -eq 0 ] && sleep 2; echo "RC=$rc"; systemctl show "$P_unit" -p ActiveState -p SubState --no-pager',
		    [ 'unit' => $unit ]);
		$rc = preg_match('/^RC=(\d+)$/m', $out, $m) ? (int)$m[1] : $code;
		if ($rc !== 0)
		    throw new \RuntimeException('the restart failed (exit code ' . $rc . ')' . (trim($err) !== '' ? ': ' . self::tail($err) : ''));
		preg_match('/ActiveState=(\S+)/', $out, $a);
		preg_match('/SubState=(\S+)/', $out, $s);
		return [ [ 'unit' => $unit, 'active' => $a[1] ?? '', 'sub' => $s[1] ?? '' ], "{$unit} restarted: " . ($a[1] ?? '?') ];

	    case 'journal':
		$unit = self::unit($text('unit'));
		$lines = max(1, min(10000, (int)$this->cfg('lines', 100)));
		$since = trim((string)$text('since'));
		$sudo = $this->cfg('sudo', true) ? 'sudo -n ' : '';
		[ $out, $err, $code ] = $this->sh($sudo . 'journalctl -u "$P_unit" -n "$P_lines" ${P_since:+--since "$P_since"} --no-pager -o short-iso',
		    [ 'unit' => $unit, 'lines' => (string)$lines, 'since' => $since ]);
		if ($code !== 0)
		    throw new \RuntimeException('journalctl failed: ' . self::tail($err));
		$list = array_values(array_filter(preg_split('/\R/', rtrim($out)), fn($l) => $l !== '' && !str_starts_with($l, '-- ')));
		return [ [ 'lines' => $list, 'count' => count($list) ], \util\Format::plural(count($list), 'line') ];

	    case 'log':
		$r = \util\LogFiles::read($this->ssh, \util\LogFiles::paths($text('paths')), [ 'folders' => \util\LogFiles::folders($this->cred->logFolders ?? ''),
		    'filter' => \util\LogFiles::filter($text('filter')), 'from' => $this->cfg('from', 'position') === 'end' ? 'end' : 'position',
		    'position' => $this->cfg('from', 'position') === 'end' ? [] : \util\LogFiles::position($value('position')),
		    'lines' => (int)$this->cfg('maxLines', 1000), 'sudo' => (bool)$this->cfg('logSudo', false), 'timeout' => $this->timeout ]);
		return [ $r, \util\Format::plural($r['count'], 'line') . ($r['more'] ? ', more waiting' : '') . ($r['notes'] ? '; ' . implode('; ', $r['notes']) : '') ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
