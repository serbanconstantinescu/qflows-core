<?php
//CLD: 2026-11-21T13:00:00+02:00: Import rows, step 2 (DatatableController::import_rows): the sheet (Excel), whether the first row is a header, each file column paired with
//a table column or skipped, add or update by a column, the first rows; changing the sheet or the header choice posts step=preview (the pairs are guessed again); Import
//posts step=import. $errors: texts
$actionUrl = \V::app()->createUrl('/datatable/import_rows', [ 'id' => $dt->_id ]);
$formId = 'dt-import-rows-' . uniqid();
$refresh = "this.form.elements.step.value='preview'; this.form.requestSubmit()";
$optionTpl = '<option value="{:value}"{:selected}>{:label}</option>';

$sheetSelect = '';
if (count($sheets) > 1) {
    $options = '';
    foreach($sheets as $s)
	$options .= \util\VString::insert($optionTpl, [ 'value' => \V::app()->html->escape($s), 'selected' => $s === $sheet ? ' selected' : '', 'label' => \V::app()->html->escape($s) ]);
    $sheetSelect = \util\VString::insert('<div class="col-md-4"><label class="form-label">Sheet</label><select name="sheet" class="form-select form-select-sm" onchange="{:refresh}">{:options}</select></div>', [
	'refresh' => $refresh,
	'options' => $options,
    ]);
} elseif ($sheet !== null) {
    $sheetSelect = \util\VString::insert('<input type="hidden" name="sheet" value="{:value}">', [ 'value' => \V::app()->html->escape($sheet) ]);
}

//a table column as an option: its label and key
$columnLabel = fn($key) => $columns[$key]->label . ' (' . $key . ')';

//each file column: its name (header) or number, its first values, the table column it fills
$pairTpl = '
    <tr>
	<td class="text-nowrap">{:name}</td>
	<td class="small text-muted text-truncate" style="max-width: 16rem" title="{:samples}">{:samples}</td>
	<td><select class="form-select form-select-sm" name="map[{:c}]" aria-label="Table column for {:name}">{:options}</select></td>
    </tr>';
$pairRows = '';
foreach($names as $c => $name) {
    $options = \util\VString::insert($optionTpl, [ 'value' => '', 'selected' => $map[$c] === '' ? ' selected' : '', 'label' => '&mdash; skip &mdash;' ]);
    foreach($columns as $key => $f)
	$options .= \util\VString::insert($optionTpl, [ 'value' => \V::app()->html->escape($key), 'selected' => $map[$c] === $key ? ' selected' : '', 'label' => \V::app()->html->escape($columnLabel($key)) ]);
    $samples = [];
    foreach(array_slice($dataRows, 0, 5) as $row) {
	if (!\util\TableImport::isBlank($row[$c] ?? null))
	    $samples[] = mb_substr(\util\TableImport::text($row[$c]), 0, 40);
    }
    $pairRows .= \util\VString::insert($pairTpl, [
	'name' => \V::app()->html->escape($name !== '' ? $name : 'Column ' . ($c + 1)),
	'samples' => \V::app()->html->escape(implode(', ', $samples)),
	'c' => $c,
	'options' => $options,
    ]);
}

//update: the column rows are matched by
$matchOptions = '';
foreach($columns as $key => $f)
    $matchOptions .= \util\VString::insert($optionTpl, [ 'value' => \V::app()->html->escape($key), 'selected' => $match === $key ? ' selected' : '', 'label' => \V::app()->html->escape($columnLabel($key)) ]);

$contentTpl = '
<form id="{:formId}" method="post" action="{:action}">
    <input type="hidden" name="token" value="{:token}">
    <input type="hidden" name="step" value="import">
    <input type="hidden" name="header_set" value="1">
    <input type="hidden" name="header_was" value="{:headerWas}">
    <input type="hidden" name="sheet_shown" value="{:sheet}">
    {:errors}
    <p class="small text-muted mb-3">{:file}: {:rows}. Pair each column found with a column of the table, or skip it.</p>
    <div class="row g-3 mb-3 align-items-end">
	{:sheetSelect}
	<div class="col-md-4">
	    <div class="form-check form-switch mb-1">
		<input class="form-check-input" type="checkbox" role="switch" id="{:formId}-header" name="header" value="1"{:headerChecked} onchange="{:refresh}">
		<label class="form-check-label" for="{:formId}-header">First row is a header</label>
	    </div>
	</div>
    </div>
    <div class="table-responsive mb-3">
	<table class="table table-sm align-middle mb-0">
	    <thead><tr><th>Column found</th><th>Values</th><th style="width: 18rem">Fills the table column</th></tr></thead>
	    <tbody>{:pairRows}</tbody>
	</table>
    </div>
    <div class="form-check mb-1">
	<input class="form-check-input" type="radio" name="mode" value="add" id="{:formId}-add"{:addChecked}>
	<label class="form-check-label" for="{:formId}-add">Add all as new rows</label>
    </div>
    <div class="form-check d-flex flex-wrap align-items-center gap-2">
	<input class="form-check-input" type="radio" name="mode" value="update" id="{:formId}-update"{:updateChecked}>
	<label class="form-check-label" for="{:formId}-update">Update the row with the same value in</label>
	<select name="match" class="form-select form-select-sm w-auto" aria-label="The column rows are matched by" onchange="document.getElementById(\'{:formId}-update\').checked = true">{:matchOptions}</select>
	<span class="small">and add the others</span>
    </div>
    <div class="form-text">Values a column cannot read are left empty; in an update they leave the row&#039;s value as it is. Rows the column rules refuse are skipped. Object columns cannot be filled from a file.</div>
</form>
';
$content = \util\VString::insert($contentTpl, [
    'formId' => $formId,
    'action' => $actionUrl,
    'token' => $token,
    'headerWas' => $header ? '1' : '0',
    'sheet' => \V::app()->html->escape($sheet),
    'errors' => $errors ? \util\VString::insert('<div class="alert alert-danger py-2">{:list}</div>', [ 'list' => implode('<br>', \V::app()->html->escape($errors)) ]) : '',
    'file' => \V::app()->html->escape(!empty($meta['pasted']) ? 'The pasted rows' : $meta['name']),
    'rows' => \util\Format::plural(count($dataRows), 'row'),
    'sheetSelect' => $sheetSelect,
    'headerChecked' => $header ? ' checked' : '',
    'refresh' => $refresh,
    'pairRows' => $pairRows,
    'addChecked' => $mode === 'add' ? ' checked' : '',
    'updateChecked' => $mode === 'update' ? ' checked' : '',
    'matchOptions' => $matchOptions,
]);

$footer = "
<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
<button type='submit' class='btn btn-primary' form='{$formId}'><i class='bi bi-upload me-1'></i>Import</button>
";

$modal = new \sys\html\Modal([
    'title' => \util\VString::insert('Import rows into {:name}', [ 'name' => \V::app()->html->escape((string)$dt->name) ]),
    'body' => $content,
    'footer' => $footer,
    'size' => 'lg',
]);
echo $modal->render();
