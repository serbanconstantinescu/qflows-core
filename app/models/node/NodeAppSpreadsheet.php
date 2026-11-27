<?php

/*
    Spreadsheet file - CLD: 2026-11-17T01:00:00+02:00: a list of rows written by util\TableExport as an Excel (.xlsx: one sheet, the header frozen, with filters) or a CSV file
    (UTF-8, comma-separated, a header line), saved in the project's Files (models\File, GridFS; metadata: project, aid, source `spreadsheet`, workflow). No credential: made here.
	Rows: an expression giving a list, of objects (e.g. {{ table.rows }}) or of lists (each a row's cells, in the columns' order).
	Columns: [ { key, label } ] in their order; empty: the keys of the objects as they come (the first row's first, then new ones), each its own header. Rows of lists: the
	columns name the cells by position (key not used), else the headers are Column 1, 2, ...
	Cells: numbers and true / false as such, a date of a data table (an ISO 8601 date or date and time) as a date (in the server's time zone; CSV: YYYY-MM-DD or
	YYYY-MM-DD HH:MM:SS), null empty, a list / object as JSON text; never a formula (Excel: text cells; CSV: text starting with = + - @ gets a ' before it).
    The variable (default `file`) gets { id, filename, size, contentType, rows } (Send email: Attachments {{ file.id }}). At most MAX_ROWS rows.
*/

namespace models\node;

class NodeAppSpreadsheet extends \models\wf\WfNode {
    public static $type = 'app';
    public static $name = 'spreadsheet';
    public static $label = 'Spreadsheet file';
    public static $icon = 'bi-file-earmark-spreadsheet';
    //the step picker: a basic step, in Web & files
    public static $audience = 'basic';
    public static $category = 'web';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const FORMATS = [ 'xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)' ];
    const MAX_ROWS = 100000;
    const MAX_COLUMNS = 200;
    //a date (YYYY-MM-DD) or a date and time (ISO 8601) of a data table's row
    const DATE = '/^\d{4}-\d{2}-\d{2}$/';
    const DATETIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/';

    public static function configSchema() {
	return [
	    [
		'key' => 'rows',
		'type' => 'text',
		'label' => 'Rows',
		'required' => true,
		'help' => 'A list of objects (e.g. {{ table.rows }}) or of lists of cells',
		'ui' => [ 'expression' => true, 'placeholder' => '{{ table.rows }}' ],
	    ],
	    [
		'key' => 'format',
		'type' => 'select',
		'label' => 'Format',
		'required' => true,
		'default' => 'xlsx',
		'choices' => self::FORMATS,
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'filename',
		'type' => 'text',
		'label' => 'File name',
		'help' => 'The extension is added when missing',
		'default' => 'report',
		'ui' => [ 'width' => 4, 'expression' => true, 'placeholder' => 'report-{{ schedule_time }}' ],
	    ],
	    [
		'key' => 'sheet',
		'type' => 'text',
		'label' => 'Sheet name',
		'default' => 'Sheet1',
		'help' => 'Excel only, at most 31 characters',
		'ui' => [ 'width' => 4, 'expression' => true, 'showIf' => [ 'key' => 'format', 'operator' => 'eq', 'value' => 'xlsx' ] ],
	    ],
	    //CLD: 2026-11-27T12:00:00+02:00: the folder of Files it is saved in
	    self::filesFolderField([ 'ui' => [ 'newRow' => true ] ]),
	    [
		'key' => 'columns',
		'type' => 'nested',
		'multiple' => true,
		'fields' => [
		    [ 'key' => 'key', 'type' => 'text', 'label' => 'Field', 'transform' => 'trim', 'help' => false, 'ui' => [ 'width' => 5 ] ],
		    [ 'key' => 'label', 'type' => 'text', 'label' => 'Header', 'help' => false, 'ui' => [ 'width' => 7 ] ],
		],
		'label' => 'Columns',
		'itemLabel' => 'Column',
		'default' => [],
		'help' => 'Empty: every field of the rows, its name as the header',
		'ui' => [ 'addLabel' => 'Add column', 'compact' => true, 'maxRows' => self::MAX_COLUMNS ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'File variable',
		'required' => true,
		'default' => 'file',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write', 'width' => 6, 'newRow' => true ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => NodeAppHttp::ON_ERROR,
		'help' => 'Rows that are not a list, too many rows or columns',
		'ui' => [ 'width' => 6 ],
	    ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'file') : 'file';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the saved file: { id, filename, size, contentType, rows }', 'fields' => [
	    'id' => [ 'type' => 'string', 'info' => 'Send email: Attachments' ], 'filename' => [ 'type' => 'string' ], 'size' => [ 'type' => 'number', 'info' => 'bytes' ],
	    'contentType' => [ 'type' => 'string' ], 'rows' => [ 'type' => 'number', 'info' => 'the rows written, without the header' ] ] ] ] : [];
    }

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	try {
	    $rows = \util\Expression::render((string)$this->cfg('rows', ''), $vars);
	    if ($rows === null || $rows === '')
		$rows = [];
	    if (!is_array($rows) || !array_is_list($rows))
		throw new \RuntimeException('Rows: not a list (' . get_debug_type($rows) . ')');
	    if (count($rows) > self::MAX_ROWS)
		throw new \RuntimeException('Rows: more than ' . self::MAX_ROWS);
	    $format = (string)$this->cfg('format', 'xlsx');
	    if (!isset(self::FORMATS[$format]))
		throw new \RuntimeException("Format: `{$format}` is not xlsx or csv");
	    [ $keys, $labels ] = $this->columnsOf($rows);
	    $filename = self::filenameOf(\util\EmailHtml::fillText((string)$this->cfg('filename', ''), $vars), $format);
	    $path = $this->write($format, $rows, $keys, $labels, \util\EmailHtml::fillText((string)$this->cfg('sheet', ''), $vars));
	} catch (\RuntimeException $e) {
	    $name = (string)$this->cfg('variable', 'file');
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, null);
	    $this->exec->log($this->wfid, 'spreadsheet: ' . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException('Spreadsheet file: ' . $e->getMessage());
	}

	$size = (int)filesize($path);
	$stream = fopen($path, 'rb');
	$id = \models\File::createFromStream($filename, $stream, [ 'folder' => $this->filesFolder(), 'metadata' => [
	    'project' => $this->exec->project,
	    'aid' => $this->exec->aid,
	    'owner' => null,
	    'source' => 'spreadsheet',
	    'wf' => $this->exec->wf,
	    'created_at' => new \MongoDB\BSON\UTCDateTime(),
	] ]);
	fclose($stream);
	@unlink($path);
	$file = [ 'id' => (string)$id, 'filename' => $filename, 'size' => $size, 'contentType' => \models\File::CONTENT_TYPES[$format], 'rows' => count($rows) ];
	$name = (string)$this->cfg('variable', 'file');
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $file);
	$this->exec->log($this->wfid, "spreadsheet: saved `{$filename}` (" . \util\Format::plural(count($rows), 'row') . ", {$size} bytes)");
	return [ 0 ];
    }

    //[ keys (null for rows of lists: by position), headers ] from the Columns setting, else from the rows
    private function columnsOf(array $rows) {
	$set = array_values(array_filter((array)$this->cfg('columns', []), fn($c) => is_array($c) && (trim((string)($c['key'] ?? '')) !== '' || trim((string)($c['label'] ?? '')) !== '')));
	$lists = $rows && array_is_list((array)$rows[0]) && !is_object($rows[0]);
	if ($set) {
	    $keys = $lists ? null : array_map(fn($c) => trim((string)($c['key'] ?? '')), $set);
	    $labels = array_map(fn($c) => trim((string)($c['label'] ?? '')) !== '' ? trim((string)$c['label']) : trim((string)($c['key'] ?? '')), $set);
	} elseif ($lists) {
	    $n = max(array_map(fn($r) => is_array($r) ? count($r) : 1, $rows));
	    $keys = null;
	    $labels = array_map(fn($i) => 'Column ' . ($i + 1), range(0, max(0, $n - 1)));
	} else {
	    $keys = [];
	    foreach($rows as $r) {
		foreach(is_array($r) ? array_keys($r) : [] as $k)
		    $keys[(string)$k] = true;
	    }
	    $keys = array_keys($keys);
	    $labels = $keys;
	}
	if (count($labels) > self::MAX_COLUMNS)
	    throw new \RuntimeException('Columns: more than ' . self::MAX_COLUMNS);
	return [ $keys, $labels ];
    }

    //the cells of row $r in the columns' order
    private static function cellsOf($r, $keys, $n) {
	if ($keys === null) {
	    $list = is_array($r) ? array_values($r) : [ $r ];
	    return array_pad(array_slice($list, 0, $n), $n, null);
	}
	$r = is_array($r) ? $r : [];
	return array_map(fn($k) => $r[$k] ?? null, $keys);
    }

    //a cell's value for util\TableExport: dates of a data table as dates (it writes lists / objects as JSON)
    private static function cellValue($v) {
	if (is_string($v) && (preg_match(self::DATE, $v) || preg_match(self::DATETIME, $v))) {
	    try {
		//shown in the server's time zone, as the app shows them
		return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
	    } catch (\Exception $e) {
		return $v;
	    }
	}
	return $v;
    }

    //the file (a temporary one) of the rows in $format (util\TableExport)
    private function write($format, array $rows, $keys, array $labels, $sheet) {
	//a column of dates only (as the first row has them) shown as a date, or a date and time
	$first = $rows ? self::cellsOf($rows[0], $keys, count($labels)) : [];
	$columns = [];
	foreach($labels as $i => $l) {
	    $v = $first[$i] ?? null;
	    $columns[] = [ 'label' => $l ] + (is_string($v) && preg_match(self::DATE, $v) ? [ 'format' => 'date' ] : (is_string($v) && preg_match(self::DATETIME, $v) ? [ 'format' => 'datetime' ] : []));
	}
	$x = new \util\TableExport(trim($sheet) !== '' ? $sheet : 'Sheet1', $columns, $format);
	foreach($rows as $r)
	    $x->addRow(array_map([ self::class, 'cellValue' ], self::cellsOf($r, $keys, count($labels))));
	return $x->finish();
    }

    //a file name for the Files: no path, safe characters kept, the format's extension when it has another one or none
    public static function filenameOf($name, $format) {
	$name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/', '-', basename(str_replace('\\', '/', (string)$name))), ' .-');
	if ($name === '')
	    $name = 'report';
	if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $format)
	    $name .= '.' . $format;
	return mb_substr($name, 0, 200);
    }
}
