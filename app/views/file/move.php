<?php
//CLD: 2026-11-27T10:00:00+02:00: a file or a folder moved (FileController::move): the folder it goes to, from the project's folders by path ($paths: [ id => path ];
//for a folder not itself or the ones inside it); $current: the folder it is in ('' the top level); $error: why the last move was refused

$options = \util\VString::insert('<option value=""{:sel}>Top level</option>', [ 'sel' => $current === '' ? ' selected' : '' ]);
foreach($paths as $id => $path) {
    $options .= \util\VString::insert('<option value="{:id}"{:sel}>{:path}</option>', [
	'id' => $id,
	'sel' => $id === $current ? ' selected' : '',
	'path' => \V::app()->html->escape($path),
    ]);
}

$body = \util\VString::insert('
{:error}
<p class="text-truncate mb-2"><i class="bi {:icon} me-1"></i>{:name}</p>
<label for="{:id}" class="form-label">Move to</label>
<select id="{:id}" name="target" class="form-select">{:options}</select>
', [
    'id' => 'f-' . uniqid(),
    'icon' => $isFolder ? 'bi-folder' : 'bi-file-earmark',
    'name' => \V::app()->html->escape((string)$entry->filename),
    'options' => $options,
    'error' => $error !== null ? \util\VString::insert('<div class="alert alert-danger py-2">{:error}</div>', [ 'error' => \V::app()->html->escape($error) ]) : '',
]);

$footer = '
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Move</button>
';

$modal = new \sys\html\Modal([
    'title' => $isFolder ? 'Move folder' : 'Move file',
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => \V::app()->form->create(null, [ 'action' => \V::app()->createUrl('/file/move', [ 'id' => $entry->_id ]) ]),
    'afterContent' => \V::app()->form->end(),
    'size' => 'small',
]);
echo $modal->render();
