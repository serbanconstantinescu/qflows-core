<?php

/*
    CLD: 2026-11-22T12:00:00+02:00: Log patterns - log lines grouped into patterns (util\LogPatterns): each line parsed (a format, or found out), its secrets masked,
    its variable parts masked (<num> <ip> <uuid> <hex> <time>), the similar ones grouped (Drain) per service. Known patterns (a catalog the workflow keeps, e.g. a data
    table's rows) are matched first and keep their signature, so a workflow can tell new patterns and spikes from the usual ones: the SRE agent's "learn what is normal".
	Lines: a list of texts or of { file, text } (Read a log file, Log watch: {{ log.lines }}), or of objects with message
	The variable (default `patterns`): { patterns: [ { signature, template, service, level, count, sample, known } ], count, lines, newPatterns }
    No credential, nothing leaves the server
*/

namespace models\node;

class NodeAppLogPatterns extends \models\wf\WfNode {
    public static $type = 'app';
    public static $name = 'logpatterns';
    public static $label = 'Log patterns';
    public static $icon = 'bi-diagram-3';
    public static $audience = 'basic';
    public static $category = 'monitoring';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static function configSchema() {
	return [
	    [
		'key' => 'lines',
		'type' => 'text',
		'label' => 'Lines',
		'required' => true,
		'default' => '{{ log.lines }}',
		'help' => 'A list of lines: texts, or { file, text } as Log watch and Read a log file give them',
		'ui' => [ 'expression' => true, 'placeholder' => '{{ log.lines }}' ],
	    ],
	    [
		'key' => 'format',
		'type' => 'select',
		'label' => 'Format',
		'required' => true,
		'default' => 'auto',
		'choices' => \util\LogPatterns::FORMATS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'service',
		'type' => 'text',
		'label' => 'Service',
		'default' => '',
		'help' => 'Empty: the line\'s own (syslog program, JSON service), else its file\'s name',
		'ui' => [ 'width' => 6, 'expression' => 'text' ],
	    ],
	    [
		'key' => 'regex',
		'type' => 'text',
		'label' => 'Regular expression',
		'default' => '',
		'help' => 'Named groups message, level, service, e.g. ^(?<level>\w+) \[(?<service>[^\]]+)\] (?<message>.*)$; a line it does not match: plain text',
		'requiredIf' => [ 'if' => 'format', 'in' => [ 'regex' ] ],
		'ui' => [ 'code' => true, 'showIf' => [ 'key' => 'format', 'operator' => 'eq', 'value' => 'regex' ] ],
	    ],
	    [
		'key' => 'known',
		'type' => 'text',
		'label' => 'Known patterns',
		'default' => '',
		'help' => 'The catalog: a list of templates, or of { template, signature, service } (e.g. {{ catalog.rows }}); a line like one of them joins it',
		'ui' => [ 'expression' => true, 'placeholder' => '{{ catalog.rows }}' ],
	    ],
	    [
		'key' => 'similarity',
		'type' => 'number',
		'label' => 'Similarity',
		'default' => 0.5,
		'help' => 'The share of a line\'s words that must be the same to join a pattern (0.1 to 1)',
		'validate' => [ [ 'inRange', 'lower' => 0.1, 'upper' => 1, 'skipEmpty' => true, 'message' => 'from 0.1 to 1' ] ],
		'ui' => [ 'width' => 4, 'step' => 0.05, 'advanced' => true ],
	    ],
	    [
		'key' => 'redact',
		'type' => 'switch',
		'label' => 'Mask secrets',
		'default' => true,
		'help' => 'Passwords, tokens, keys, email addresses',
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'redactIps',
		'type' => 'switch',
		'label' => 'Mask IP addresses',
		'default' => false,
		'help' => 'In the samples too',
		'ui' => [ 'width' => 4, 'advanced' => true ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'patterns',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'Lines or known patterns that are not lists, a bad regular expression',
		'ui' => [ 'width' => 6 ],
	    ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'patterns') : 'patterns';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$pattern = [ 'signature' => [ 'type' => 'string', 'info' => '16 hex, the same from run to run for a known pattern' ], 'template' => [ 'type' => 'string' ],
	    'service' => [ 'type' => 'string' ], 'level' => [ 'type' => 'string', 'info' => 'critical, error, warning, notice, info, debug or empty' ],
	    'count' => [ 'type' => 'number' ], 'sample' => [ 'type' => 'string', 'info' => 'the first line, secrets masked' ], 'known' => [ 'type' => 'boolean' ] ];
	$vars = [ $name => [ 'type' => 'object', 'info' => '{ patterns, count, lines, newPatterns }', 'fields' => [
	    'patterns' => [ 'type' => 'array', 'info' => 'the most frequent first', 'items' => [ 'type' => 'object', 'fields' => $pattern ] ],
	    'count' => [ 'type' => 'number', 'info' => 'patterns' ], 'lines' => [ 'type' => 'number', 'info' => 'lines read' ],
	    'newPatterns' => [ 'type' => 'number', 'info' => 'patterns not among the known ones' ] ] ] ];
	if (is_array($config) && ($config['onError'] ?? 'fail') === 'output')
	    $vars['error'] = [ 'type' => 'string', 'info' => 'the error message (error output)' ];
	return $vars;
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	if ($this->cfg('format', 'auto') === 'regex' && !$this->cfgEmpty('regex') && self::regex($this->cfg('regex', '')) === null)
	    $valid = $this->configError('Log patterns: Regular expression: not a regular expression');
	return $valid;
    }

    //a regular expression setting (without delimiters) as a pattern, or null
    protected static function regex($text) {
	$re = '~' . str_replace('~', '\~', trim((string)$text)) . '~u';
	return @preg_match($re, '') === false ? null : $re;
    }

    //a setting giving a list: the list ([] when empty), or a \RuntimeException
    protected function listOf($key, $label) {
	$v = \util\Expression::render((string)$this->cfg($key, ''), $this->exec->expressionVariables());
	if (is_string($v) && trim($v) !== '') {
	    $decoded = json_decode($v, true);
	    $v = is_array($decoded) ? $decoded : $v;
	}
	if ($v === null || $v === '')
	    return [];
	if (!is_array($v) || !array_is_list($v))
	    throw new \RuntimeException("{$label}: not a list (" . get_debug_type($v) . ')');
	return $v;
    }

    public function execute($edgeId = null) {
	$name = (string)$this->cfg('variable', 'patterns');
	try {
	    $lines = $this->listOf('lines', 'Lines');
	    if (count($lines) > \util\LogPatterns::MAX_LINES)
		$this->exec->log($this->wfid, 'Log patterns: the first ' . \util\LogPatterns::MAX_LINES . ' lines of ' . count($lines));
	    $regex = null;
	    if ($this->cfg('format', 'auto') === 'regex') {
		$regex = self::regex($this->cfg('regex', ''));
		if ($regex === null)
		    throw new \RuntimeException('Regular expression: not a regular expression');
	    }
	    $start = microtime(true);
	    $r = \util\LogPatterns::mine($lines, [ 'format' => (string)$this->cfg('format', 'auto'), 'regex' => $regex,
		'service' => \util\Expression::toText(\util\Expression::render((string)$this->cfg('service', ''), $this->exec->expressionVariables())),
		'known' => $this->listOf('known', 'Known patterns'), 'similarity' => (float)($this->cfg('similarity', 0.5) ?: 0.5),
		'redact' => (bool)$this->cfg('redact', true), 'ips' => (bool)$this->cfg('redactIps', false) ]);
	} catch (\RuntimeException | \ErrorException $e) {
	    $message = 'Log patterns: ' . $e->getMessage();
	    if ($this->cfg('onError', 'fail') === 'output') {
		$this->exec->setVariable('error', $message);
		$this->exec->log($this->wfid, $message);
		return [ 1 ];
	    }
	    throw new \ErrorException($message);
	}
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $r);
	$this->exec->log($this->wfid, 'Log patterns: ' . \util\Format::plural($r['lines'], 'line') . ', ' . \util\Format::plural($r['count'], 'pattern') . ", {$r['newPatterns']} new ("
	    . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }
}
