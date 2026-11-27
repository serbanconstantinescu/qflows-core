<?php

/*
    PDF document - CLD: 2026-11-22T12:00:00+02:00: a document designed in the step (the PDF designer: models\PdfDesign, app.PdfDesigner) filled with the variables
    ({{ }}, conditions, repeating rows) and made a PDF by util\PdfDocument (tc-lib-pdf, installed by scripts/tc-lib-pdf.sh), saved in the project's Files (models\File,
    GridFS; metadata: project, aid, source `pdf`, workflow). No credential.
    The variable (default `file`) gets { id, filename, size, contentType, pages } (Send email: Attachments {{ file.id }}).
*/

namespace models\node;

class NodeAppPdf extends \models\wf\WfNode {
    public static $type = 'app';
    public static $name = 'pdf';
    public static $label = 'PDF document';
    public static $icon = 'bi-file-earmark-pdf';
    //the step picker: a basic step, in Web & files
    public static $audience = 'basic';
    public static $category = 'web';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static function configSchema() {
	return [
	    \models\PdfDesign::schema(),
	    [
		'key' => 'filename',
		'type' => 'text',
		'label' => 'File name',
		'help' => '.pdf is added when missing',
		'default' => 'document',
		'ui' => [ 'expression' => true, 'placeholder' => 'invoice-{{ invoice.number }}' ],
	    ],
	    //CLD: 2026-11-27T12:00:00+02:00: the folder of Files it is saved in
	    self::filesFolderField(),
	    [
		'key' => 'title',
		'type' => 'text',
		'label' => 'Document title',
		'help' => 'In the PDF\'s properties; empty: the file name',
		'ui' => [ 'expression' => true, 'advanced' => true, 'width' => 6 ],
	    ],
	    [
		'key' => 'mode',
		'type' => 'select',
		'label' => 'Standard',
		'default' => '',
		'choices' => \util\PdfDocument::MODES,
		'help' => 'PDF/A: for archiving (fonts embedded)',
		'ui' => [ 'advanced' => true, 'width' => 6 ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'File variable',
		'required' => true,
		'default' => 'file',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write' ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => NodeAppHttp::ON_ERROR,
		'help' => 'A failing expression, a list that is not one, a missing image',
	    ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'file') : 'file';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the saved file: { id, filename, size, contentType, pages }', 'fields' => [
	    'id' => [ 'type' => 'string', 'info' => 'Send email: Attachments' ], 'filename' => [ 'type' => 'string' ], 'size' => [ 'type' => 'number', 'info' => 'bytes' ],
	    'contentType' => [ 'type' => 'string' ], 'pages' => [ 'type' => 'number' ] ] ] ] : [];
    }

    //the design stored normalized; its expressions, conditions, rows and images checked
    public function validates($options = []) {
	$valid = parent::validates($options);
	$config = is_array($this->config) ? $this->config : [];
	$config['design'] = \models\PdfDesign::normalize($config['design'] ?? []);
	$this->config = $config;
	foreach(\models\PdfDesign::problems($config['design'], $this->workflow) as $m) {
	    $this->errors('config', "Document: {$m}");
	    $valid = false;
	}
	return $valid;
    }

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	$name = (string)$this->cfg('variable', 'file');
	try {
	    $design = \models\PdfDesign::normalize($this->cfg('design', []));
	    $filename = \util\EmailHtml::fillText((string)$this->cfg('filename', ''), $vars);
	    $filename = NodeAppSpreadsheet::filenameOf($filename !== '' ? $filename : 'document', 'pdf');
	    $title = \util\EmailHtml::fillText((string)$this->cfg('title', ''), $vars);
	    $mode = (string)$this->cfg('mode', '');
	    $r = \util\PdfDocument::render($design, $vars, [ 'aid' => $this->exec->aid, 'project' => $this->exec->project, 'title' => $title !== '' ? $title : $filename,
		'mode' => isset(\util\PdfDocument::MODES[$mode]) ? $mode : '' ]);
	//tc-lib-pdf's exceptions are \Exception (a font, an image it cannot read)
	} catch (\Exception $e) {
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, null);
	    $this->exec->log($this->wfid, 'pdf: ' . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException('PDF document: ' . $e->getMessage());
	}

	$id = \models\File::createFromString($filename, $r['pdf'], [ 'folder' => $this->filesFolder(), 'metadata' => [
	    'project' => $this->exec->project,
	    'aid' => $this->exec->aid,
	    'owner' => null,
	    'source' => 'pdf',
	    'wf' => $this->exec->wf,
	    'created_at' => new \MongoDB\BSON\UTCDateTime(),
	] ]);
	$size = strlen($r['pdf']);
	$file = [ 'id' => (string)$id, 'filename' => $filename, 'size' => $size, 'contentType' => \models\File::CONTENT_TYPES['pdf'], 'pages' => $r['pages'] ];
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $file);
	$this->exec->log($this->wfid, "pdf: saved `{$filename}` (" . \util\Format::plural($r['pages'], 'page') . ", {$size} bytes)");
	return [ 0 ];
    }
}
