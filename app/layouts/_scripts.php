<?php

//register some js before hook-call
$js_assets = [
    'app.formatter.js',
    'app.pjax.js',
    'app.fieldhelper.js',
    'app.exprinput.js',
    'app.codeeditor.js',
    'app.formdesigner.js',
    'app.emaildesigner.js',
    //CLD: 2026-11-22T12:00:00+02:00: the PDF designer (the PDF document step)
    'app.pdfdesigner.js',
    'app.viewer.js',
    'app.overview.js',
    'app.smartform.js',
    //CLD: 2026-11-19T12:00:00+02:00: a new credential from a step's credential field (app.smartform `createTypes`)
    'app.credentialdialog.js',
    'app.workflow.js',
    'app.wfexecutions.js',
    'app.wfdebug.js',
    //the designer's Tests panel
    'app.wftests.js',
    'app.wfassistant.js',
    'app.dataview.js',
    //the chat pages of the agents
    'app.agentchat.js',
    //last
    'app.js',
];

foreach($js_assets as $asset) {
    $mtime = @filemtime(BASE . '/public_html/js/' . $asset);
    \V::app()->cs->addPageScriptFile('/js/' . $asset . '?' . $mtime, []);
}
