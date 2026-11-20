<?php
$this->pageTitle = 'Project: ' . $project->name;
?>


<div class="main-header py-2 px-3">
    <?= (new \sys\html\BtnExpandSidebar())->render() ?>
    <?= (new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	//CLD: 2026-11-20T16:00:00+02:00: Home > the project (icon, name: escaped by display()) > Settings; the tabs' header no longer repeats the name
	[ 'label' => $project->schema['icon']->display() . ' ' . $project->schema['name']->display(), 'href' => \V::app()->createUrl('/project/settings', [ 'id' => $project->_id ]) ],
	[ 'label' => 'Settings' ],
    ]]))->render() ?>
</div>

<div class="main-body p-4">

    <?php
    //only known tabs (the value is used to build a require() path)
    $tab = \V::app()->request->query['__tab'] ?? 'general';
    //transfer (Export / import), with Project::transfer
    $tabs = [ 'general', 'members' ];
    if (\V::app()->user->can('Project::transfer', [ 'object' => $project ]))
	$tabs[] = 'transfer';
    if (!in_array($tab, $tabs, true))
	$tab = 'general';
    require(dirname(__FILE__) . '/_header.php');
    require(dirname(__FILE__) . '/_' . $tab . '.php');
    ?>
</div>
