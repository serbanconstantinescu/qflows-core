<?php
//CLD: 2026-11-27T10:00:00+02:00: a folder's name, in a dialog: a new folder (FileController::folder) or a rename (rename); $error: why the last one was refused

$body = \util\VString::insert('
{:error}
<label for="{:id}" class="form-label">Folder name</label>
<input id="{:id}" type="text" name="name" value="{:name}" maxlength="{:max}" class="form-control" required autocomplete="off">
', [
    'id' => $id = 'f-' . uniqid(),
    'name' => \V::app()->html->escape($name),
    'max' => \models\Folder::MAX_NAME,
    'error' => $error !== null ? \util\VString::insert('<div class="alert alert-danger py-2">{:error}</div>', [ 'error' => \V::app()->html->escape($error) ]) : '',
]);

$footer = \util\VString::insert('
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">{:button}</button>
', [ 'button' => \V::app()->html->escape($button) ]);

$modal = new \sys\html\Modal([
    'title' => $title,
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => \V::app()->form->create(null, [ 'action' => $action ]),
    'afterContent' => \V::app()->form->end(),
    'size' => 'small',
]);
echo $modal->render();

\V::app()->cs->addPageScript("setTimeout(() => { const i = document.getElementById('{$id}'); i?.focus(); i?.select(); }, 200)");
