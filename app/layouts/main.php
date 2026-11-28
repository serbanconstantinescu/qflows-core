<?php

//the app's scripts are registered by layouts/_scripts.php (shared with layouts/form.php)
require(dirname(__FILE__) . '/_scripts.php');

//the daemon's live events for this page (app.live): its ticket, a new one from SiteController::live_ticket; none without APP_SECRET
try {
    $liveTicket = \util\Daemon::pushToken([ 'aid' => \V::app()->var('rt.account')->_id ?? null, 'project' => \V::app()->user->crtProject, 'user' => \V::app()->user->_id ]);
    $params = \util\JSON::encode([ 'ticket' => $liveTicket, 'renew' => '/site/live_ticket' ]);
    \V::app()->cs->addPageScript("app.live.start({$params});");
} catch (\ErrorException $e) {
}

//SIDEBAR
//the width kept for the user (User prefs sidebar_width, set by the resize handle: app.sidebar)
$sidebarW = (int)(\V::app()->var('rt.prefs')['sidebar_width'] ?? 0);
//collapsed: User prefs sidebar_collapsed; the width limits (data-min-width / data-max-width: data\Constants) for app.sidebar
$sidebarClass = 'sidebar' . (empty(\V::app()->var('rt.prefs')['sidebar_collapsed']) ? '' : ' collapsed');


ob_start();
?>

<?php //the plugins' notices over the page (outside the content pjax replaces; e.g. the MultiTenant plugin's "viewing as" bar) ?>
<?php \V::app()->hooks->applyHook('layout:page-top'); ?>
<main class="app-shell">
    <!-- SIDEBAR -->
    <aside class="<?= $sidebarClass ?>" data-min-width="<?= \data\Constants::SIDEBAR_MIN_W ?>" data-max-width="<?= \data\Constants::SIDEBAR_MAX_W ?>"<?= $sidebarW ? ' style="--sidebar-w: ' . min(max($sidebarW, \data\Constants::SIDEBAR_MIN_W), \data\Constants::SIDEBAR_MAX_W) . 'px" data-width="' . $sidebarW . '"' : '' ?>>
	<?php require(dirname(__FILE__) . '/main_aside.php') ?>
    </aside>
    <!-- behind the sidebar when it is a drawer (small screens, theme local/_app.scss); a click closes it (app.sidebar) -->
    <div class="sidebar-backdrop"></div>

    <!-- MAIN -->
    <div class="main-area">
	<?= $content ?>
    </div>
</main>


<!-- toasts -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
</div>


<?php
$content = ob_get_clean();

echo $this->renderFile('/layouts/_webpage', compact('content'));
