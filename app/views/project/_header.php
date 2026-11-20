<?php
//CLD: 2026-11-20T16:00:00+02:00: the tabs: General, Members (with their number), Export / import; the project's name is in the breadcrumb
$navCfg = [
    'items' => [
	'general' => [
	    'label' => 'General',
	    'href' => \V::app()->createUrl('/project/settings', [ 'id' => $project->_id, '__tab' => 'general' ]),
	],
	'members' => [
	    'label' => \util\VString::insert('Members <span class="badge rounded-pill text-bg-secondary ms-1">{:n}</span>', [ 'n' => count((array)$project->members) ]),
	    'href' => \V::app()->createUrl('/project/settings', [ 'id' => $project->_id, '__tab' => 'members' ]),
	],
    ],
    'active' => $tab,
];
//Export / import (Project::transfer)
if (\V::app()->user->can('Project::transfer', [ 'object' => $project ]))
    $navCfg['items']['transfer'] = [
	'label' => 'Export / import',
	'href' => \V::app()->createUrl('/project/settings', [ 'id' => $project->_id, '__tab' => 'transfer' ]),
    ];

echo (new \sys\html\Nav($navCfg))->render();
