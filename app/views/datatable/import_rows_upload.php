<?php
//CLD: 2026-11-21T13:00:00+02:00: Import rows, step 1 (DatatableController::import_rows): a CSV or Excel file, or rows pasted from a spreadsheet (tab-separated, as
//Excel, Google Sheets or LibreOffice copy them); $error: why the last one was refused
$actionUrl = \V::app()->createUrl('/datatable/import_rows', [ 'id' => $dt->_id ]);
$formId = 'dt-import-rows-' . uniqid();

$bodyTpl = '
{:error}
<form id="{:formId}" method="post" action="{:action}" enctype="multipart/form-data">
    <label class="form-label" for="{:formId}-file">A file</label>
    <input type="file" class="form-control" id="{:formId}-file" name="file" accept=".csv,.txt,.tsv,.xlsx">
    <div class="form-text mb-3">CSV or Excel (.xlsx), up to {:maxBytes} and {:maxRows}.</div>
    <label class="form-label" for="{:formId}-paste">Or rows pasted from a spreadsheet</label>
    <textarea class="form-control font-monospace small" id="{:formId}-paste" name="paste" rows="6" placeholder="Copy the cells in Excel, Google Sheets or LibreOffice (with their header row, if you like) and paste them here"></textarea>
    <div class="form-text">The next step pairs the columns found with the table&#039;s columns, to check before importing.</div>
</form>
';
$body = \util\VString::insert($bodyTpl, [
    'error' => $error ? \util\VString::insert('<div class="alert alert-danger py-2">{:error}</div>', [ 'error' => \V::app()->html->escape($error) ]) : '',
    'formId' => $formId,
    'action' => $actionUrl,
    'maxBytes' => \util\Format::bytes(\util\TableImport::MAX_BYTES),
    'maxRows' => \util\Format::plural(\util\TableImport::MAX_ROWS, 'row'),
]);

$footer = "
<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
<button type='submit' class='btn btn-primary' form='{$formId}'>Next</button>
";

$modal = new \sys\html\Modal([
    'title' => \util\VString::insert('Import rows into {:name}', [ 'name' => \V::app()->html->escape((string)$dt->name) ]),
    'body' => $body,
    'footer' => $footer,
]);
echo $modal->render();
