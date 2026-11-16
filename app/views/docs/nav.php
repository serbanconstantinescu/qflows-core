<?php
//CLD: 2026-11-16T17:00:00+02:00: the sidebar's Documentation view (DocsController::nav): the sections (collapsible), their pages and child pages, as the docs site's
//sidebar (.docs-nav, local/_docs.scss); app.sidebar opens the current page's section and its child pages
?>
<?php foreach ($sections as $sec): ?>
<div class="docs-nav-section">
    <button type="button" class="docs-nav-section-toggle" aria-expanded="false">
	<i class="bi <?= \V::app()->html->escape($sec['icon'] ?? 'bi-book') ?>"></i>
	<span class="flex-fill text-truncate"><?= \V::app()->html->escape($sec['title']) ?></span>
	<i class="bi bi-chevron-right docs-nav-chevron"></i>
    </button>
    <ul class="nav flex-column docs-nav-pages">
	<?php foreach ($sec['pages'] as $pg): ?>
	<li class="nav-item">
	    <a href="<?= \V::app()->html->escape(\controllers\DocsController::pageUrl($sec, $pg)) ?>" class="nav-link"><?= \V::app()->html->escape($pg['title']) ?></a>
	    <?php if (!empty($pg['pages'])): ?>
	    <ul class="nav flex-column docs-nav-children">
		<?php foreach ($pg['pages'] as $ch): ?>
		<li class="nav-item"><a href="<?= \V::app()->html->escape(\controllers\DocsController::pageUrl($sec, $pg, $ch)) ?>" class="nav-link"><?= \V::app()->html->escape($ch['title']) ?></a></li>
		<?php endforeach; ?>
	    </ul>
	    <?php endif; ?>
	</li>
	<?php endforeach; ?>
    </ul>
</div>
<?php endforeach; ?>
