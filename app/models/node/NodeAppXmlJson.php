<?php

/*
    XML to JSON - CLD: 2026-11-28T16:00:00+02:00: an XML document (a project file, or a text such as {{ http.body }}) as data the next steps use ({{ xml.data.order.id }}),
    converted by util\Xml::toArray (its rules: attributes @name, the text of an element with attributes / children #text, repeated elements lists); optionally saved
    as a .json file in the project's Files (models\File, GridFS; metadata: project, aid, source `xml-json`, workflow). No credential.
    The variable (default `xml`) gets { root, data, file } (file: { id, filename, size, contentType } or null).
*/

namespace models\node;

class NodeAppXmlJson extends \models\wf\WfNode {
    public static $type = 'app';
    public static $name = 'xml-json';
    public static $label = 'XML to JSON';
    public static $icon = 'bi-filetype-json';
    //the step picker: a basic step, in Data
    public static $audience = 'basic';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const SOURCES = [ 'file' => 'A project file', 'text' => 'A text' ];

    public static function configSchema() {
	return [
	    [
		'key' => 'source',
		'type' => 'select',
		'label' => 'XML from',
		'required' => true,
		'default' => 'file',
		'choices' => self::SOURCES,
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'file',
		'type' => 'text',
		'label' => 'Project file',
		'help' => 'Its id, or a file object, e.g. {{ file }} of Download File',
		'ui' => [ 'width' => 8, 'placeholder' => '{{ file.id }}', 'expression' => true, 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'file' ] ],
	    ],
	    [
		'key' => 'xml',
		'type' => 'textarea',
		'label' => 'XML',
		'default' => '',
		'help' => 'e.g. {{ http.body }}',
		'ui' => [ 'rows' => 5, 'expression' => true, 'code' => true, 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'text' ] ],
	    ],
	    [
		'key' => 'lists',
		'type' => 'text',
		'label' => 'Always lists',
		'default' => '',
		'help' => 'Elements always given as a list, even when there is one, comma separated, e.g. item, line',
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => 'item, line' ],
	    ],
	    [
		'key' => 'attributePrefix',
		'type' => 'text',
		'label' => 'Attribute prefix',
		'required' => true,
		'default' => '@',
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		'key' => 'textKey',
		'type' => 'text',
		'label' => 'Text key',
		'required' => true,
		'default' => '#text',
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		'key' => 'types',
		'type' => 'switch',
		'label' => 'Numbers and true / false as such',
		'default' => false,
		'help' => 'Off: every value is a text (007 always stays a text)',
		'ui' => [ 'width' => 6, 'newRow' => true ],
	    ],
	    [
		'key' => 'stripNamespaces',
		'type' => 'switch',
		'label' => 'Names without namespace prefixes',
		'default' => false,
		'help' => 'cbc:ID as ID',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'saveFile',
		'type' => 'switch',
		'label' => 'Also save a JSON file in Files',
		'default' => false,
		'ui' => [ 'width' => 12, 'newRow' => true ],
	    ],
	    [
		'key' => 'filename',
		'type' => 'text',
		'label' => 'File name',
		'default' => '',
		'help' => 'Empty: the XML file\'s name (or data), .json added',
		'ui' => [ 'width' => 6, 'expression' => true, 'placeholder' => 'order-{{ order_id }}', 'showIf' => [ 'key' => 'saveFile', 'operator' => 'eq', 'value' => true ] ],
	    ],
	    self::filesFolderField([ 'ui' => [ 'showIf' => [ 'key' => 'saveFile', 'operator' => 'eq', 'value' => true ] ] ]),
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Variable',
		'required' => true,
		'default' => 'xml',
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
		'help' => 'No file, XML that is not valid, larger than 20 MB',
		'ui' => [ 'width' => 6 ],
	    ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'xml') : 'xml';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the XML as data: { root, data, file }', 'fields' => [
	    'root' => [ 'type' => 'string', 'info' => 'the name of the root element' ],
	    'data' => [ 'type' => 'object', 'info' => '{ <root>: ... }, e.g. data.order.id' ],
	    'file' => [ 'type' => 'object', 'info' => 'the saved JSON file, or null', 'fields' => [ 'id' => [ 'type' => 'string' ], 'filename' => [ 'type' => 'string' ],
		'size' => [ 'type' => 'number', 'info' => 'bytes' ], 'contentType' => [ 'type' => 'string' ] ] ] ] ] ] : [];
    }

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	$name = (string)$this->cfg('variable', 'xml');
	try {
	    [ $xml, $sourceName ] = $this->source($vars);
	    $data = \util\Xml::toArray($xml, [
		'attributePrefix' => (string)$this->cfg('attributePrefix', '@'),
		'textKey' => (string)$this->cfg('textKey', '#text'),
		'lists' => array_values(array_filter(array_map('trim', explode(',', (string)$this->cfg('lists', ''))), 'strlen')),
		'types' => (bool)$this->cfg('types', false),
		'stripNamespaces' => (bool)$this->cfg('stripNamespaces', false),
	    ]);
	} catch (\RuntimeException $e) {
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, null);
	    $this->exec->log($this->wfid, 'xml-json: ' . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException('XML to JSON: ' . $e->getMessage());
	}

	$root = (string)array_key_first($data);
	$file = null;
	if ($this->cfg('saveFile', false)) {
	    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
	    $given = trim(\util\EmailHtml::fillText((string)$this->cfg('filename', ''), $vars));
	    $filename = NodeAppSpreadsheet::filenameOf($given !== '' ? $given : ($sourceName !== '' ? pathinfo($sourceName, PATHINFO_FILENAME) : 'data'), 'json');
	    $id = \models\File::createFromString($filename, $json, [ 'folder' => $this->filesFolder(), 'metadata' => [
		'project' => $this->exec->project,
		'aid' => $this->exec->aid,
		'owner' => null,
		'source' => 'xml-json',
		'wf' => $this->exec->wf,
		'created_at' => new \MongoDB\BSON\UTCDateTime(),
	    ] ]);
	    $file = [ 'id' => (string)$id, 'filename' => $filename, 'size' => strlen($json), 'contentType' => \models\File::CONTENT_TYPES['json'] ];
	}
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, [ 'root' => $root, 'data' => $data, 'file' => $file ]);
	$this->exec->log($this->wfid, "xml-json: converted `<{$root}>`" . ($file ? ", saved `{$file['filename']}` ({$file['size']} bytes)" : ''));
	return [ 0 ];
    }

    //[ the XML text, the file's name ('' for a text) ]
    private function source(array $vars) {
	if ($this->cfg('source', 'file') === 'text')
	    return [ \util\Expression::toText(\util\Expression::render((string)$this->cfg('xml', ''), $vars)), '' ];
	$ids = \models\File::idsOf(\util\Expression::render((string)$this->cfg('file', ''), $vars));
	if (!$ids)
	    throw new \RuntimeException('Project file: no file given');
	if (count($ids) > 1)
	    throw new \RuntimeException('Project file: ' . count($ids) . ' files given, one expected');
	$file = \util\Validator::rule('oid', $ids[0]) ? \V::app()->om->findOne('File', [ '_id' => $ids[0], 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	if (empty($file))
	    throw new \RuntimeException('no project file `' . mb_substr($ids[0], 0, 60) . '`');
	if ((int)$file->length > \util\Xml::MAX_BYTES)
	    throw new \RuntimeException("`{$file->filename}` is larger than " . (\util\Xml::MAX_BYTES / 1048576) . ' MB');
	return [ (string)$file->getContents(), (string)$file->filename ];
    }
}
