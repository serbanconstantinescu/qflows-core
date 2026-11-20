<?php
$retUrl = \V::app()->request->query['ret'] ?? '/datatable/list';
//CLD: 2026-11-21T11:00:00+02:00: rename a table (Rename in the list, the pencil on its page) or name a new one (Create > Empty table); the name's rule said under it
if ($dt->exists()) {
    $t_ok = 'Rename';
    $t_title = 'Rename data table';
    $actionUrl = \V::app()->createUrl('', [ 'ret' => $retUrl, 'id' => $dt->_id ]);
} else {
    $t_ok = 'Create';
    $t_title = 'New data table';
    $actionUrl = \V::app()->createUrl('', [ 'ret' => $retUrl ]);
}
$form_begin = \V::app()->form->create($dt, [ 'action' => $actionUrl ]);

$body = '';
foreach(['name' ] as $f)
    $body .= $dt->schema[$f]->input();
$body .= '<div class="form-text">Letters, digits and _, not starting with a digit.</div>';

$form_end = \V::app()->form->end();

$footer = "
<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
<button type='submit' class='btn btn-primary'>{$t_ok}</button>
";

$modal = new \sys\html\Modal([
    'title' => $t_title,
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => $form_begin,
    'afterContent' => $form_end,
]);
echo $modal->render();
