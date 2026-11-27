//get all object keys and (if object) call `init`
if (!window.app)
    window.app = {};

window.app.sidebar = (function() {
    //the width limits (px) are the layout's (aside data-min-width / data-max-width: data\Constants), read by init
    let minWidth = 250;
    let maxWidth = 400;

    let sidebar;
    let lastWidth = 0;

    //the width set by the resize handle is kept for the user (User prefs sidebar_width: every browser):
    //rendered by the layout (aside data-width), saved by /site/prefs
    const storedWidth = () => {
	const w = parseInt(sidebar.attr('data-width'), 10);
	return Number.isFinite(w) ? Math.max(minWidth, Math.min(maxWidth, w)) : null;
    };
    //the collapsed state (User prefs sidebar_collapsed; rendered by the layout: aside.collapsed); storeWidth saves both (a drag expands the sidebar)
    const store = (data) => {
	$.ajax({ url: '/site/prefs', method: 'POST', data: data, dataType: 'json',
	    headers: { 'X-Csrf-Token': app.utils.csrf(), 'X-Requested-With': 'XMLHttpRequest' } })
	    .fail((xhr) => console.warn('app.sidebar: the state was not saved', xhr.status));
    };
    const storeWidth = (w) => {
	w = Math.round(w);
	sidebar.attr('data-width', w);
	store({ sidebar_width: w, sidebar_collapsed: 0 });
    };

    //the width goes in the --sidebar-w custom property, so the theme's media queries can replace it
    const setWidth = (w) => {
	if (sidebar && sidebar[0])
	    sidebar[0].style.setProperty('--sidebar-w', w + 'px');
    };

    //below lg (theme local/_app.scss: media-breakpoint-down(lg)) the sidebar is a drawer: .sidebar-open on .app-shell shows it;
    //the desktop width and collapsed state are left as they are
    const mobileQuery = window.matchMedia('(max-width: 991.98px)');
    const isMobile = () => mobileQuery.matches;
    const openDrawer = () => $('.app-shell').addClass('sidebar-open');
    const closeDrawer = () => $('.app-shell').removeClass('sidebar-open');

    //CLD: 2026-11-16T17:00:00+02:00: the sidebar's views (.sidebar-view-<name>: primary, secondary = Settings, docs = Documentation), one shown at a time
    const showView = (view) => {
	sidebar.find('.sidebar-view').addClass('d-none');
	view.removeClass('d-none');
    };

    //the Documentation view's contents (DocsController::nav), fetched when the view first opens (not with every page: docs.json is large)
    let docsLoading = null;
    const loadDocs = () => {
	if (sidebar.find('.sidebar-view-docs .docs-nav').children().length)
	    return Promise.resolve();
	if (!docsLoading)
	    docsLoading = fetch('/docs/nav', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
		.then((r) => r.ok ? r.text() : Promise.reject(r.status))
		.then((html) => { sidebar.find('.sidebar-view-docs .docs-nav').html(html); })
		.catch((err) => console.log('app.sidebar: the documentation contents', err))
		.finally(() => { docsLoading = null; });
	return docsLoading;
    };

    //the documentation page shown (docs/index?s=&p=&c=; no s: the first page, no p: the section's first) active, its section and child pages open
    const markDocs = () => {
	const url = new URL(location.href);
	if (url.pathname !== '/docs/index')
	    return;
	const q = url.searchParams;
	const links = sidebar.find('.docs-nav a.nav-link');
	const link = q.get('s') ? links.filter(function() {
	    const p = new URL(this.href).searchParams;
	    return p.get('s') === q.get('s') && (!q.get('p') || p.get('p') === q.get('p')) && (p.get('c') || '') === (q.get('c') || '');
	}).first() : links.first();
	if (!link.length)
	    return;
	sidebar.find('a.nav-link').removeClass('active');
	link.addClass('active');
	link.closest('.docs-nav-section').addClass('open').children('.docs-nav-section-toggle').attr('aria-expanded', 'true');
	link.parents('.docs-nav-children').addClass('open');
	link.closest('li').children('.docs-nav-children').addClass('open');
	link[0].scrollIntoView({ block: 'nearest' });
    };

    // Resize (sidebar width)
    let isCollapsed = false;
    let _document = $(document);


    const api = {

	//routing highlight on location.href change
	highlight: function (e) {
	    console.log('app.sidebar.highlight: current location.href=', location.href);
	    //locate an `a` with location href in it and add class active
	    const url = URL.parse(location.href);

	    //a documentation page: the Documentation view, the page in it
	    if (url.pathname === '/docs/index') {
		showView(sidebar.find('.sidebar-view-docs'));
		loadDocs().then(markDocs);
		return;
	    }

	    //if an `a` with current url is not found, leave active the latest
	    const crtLink = sidebar.find('a[href="' + url.pathname + '"]');
	    if (crtLink.length) {
		sidebar.find('a.nav-link').removeClass('active');
		crtLink.addClass('active');
		//the view holding the link shown
		const sidebarViewParent = crtLink.closest('.sidebar-view');
		if (sidebarViewParent.length)
		    showView(sidebarViewParent);
	    }
	},

	init: function() {
	    sidebar = $('.sidebar');
	    minWidth = parseInt(sidebar.attr('data-min-width'), 10) || minWidth;
	    maxWidth = parseInt(sidebar.attr('data-max-width'), 10) || maxWidth;

	    if (!sidebar)
		return;

	    //rendered collapsed (the state kept for the user)
	    isCollapsed = sidebar.hasClass('collapsed');
	    //on a small screen (and when collapsed: its width is 0) the drawer's width is not the desktop one: 16rem, the theme's default
	    lastWidth = isMobile() || isCollapsed ? 16 * parseFloat(getComputedStyle(document.documentElement).fontSize) : sidebar.width();
	    //the stored width, if any
	    const stored = storedWidth();
	    if (stored !== null) {
		lastWidth = stored;
		setWidth(lastWidth);
	    }

	    // Collapse / expand
	    _document.on('click', '.btn-collapse-sidebar', (e) => {
		    console.log('sidebar: click on collapsed');
		    e.preventDefault();
		    //small screen: closes the drawer
		    if (isMobile())
			return closeDrawer();
		    isCollapsed = true;
		    sidebar.addClass('collapsed');
		    $('.btn-expand-sidebar').removeClass('d-none');
		    store({ sidebar_collapsed: 1 });
	    })
	    _document.on('click', '.btn-expand-sidebar', (e) => {
		    //preventDefault (an <a href="#">); small screen: opens the drawer
		    e.preventDefault();
		    if (isMobile())
			return openDrawer();
		    isCollapsed = false;
		    sidebar.removeClass('collapsed');
		    setWidth(lastWidth);
		    $('.btn-expand-sidebar').addClass('d-none');
		    store({ sidebar_collapsed: 0 });
	    });

	    //the drawer closes on its backdrop, Escape, a link that navigates, going back, or when the window gets wide
	    _document.on('click', '.sidebar-backdrop', closeDrawer);
	    _document.on('keydown', (e) => {
		if (e.key === 'Escape' && $('.app-shell').hasClass('sidebar-open'))
		    closeDrawer();
	    });
	    sidebar.on('click', 'a[href]', function(e) {
		const href = $(this).attr('href');
		if (isMobile() && href && href !== '#' && !$(this).is('[data-bs-toggle]'))
		    closeDrawer();
	    });
	    window.addEventListener('popstate', closeDrawer);
	    mobileQuery.addEventListener('change', closeDrawer);

	    //CLD: 2026-11-27T15:00:00+02:00: a list of the sidebar reordered by dragging its names (.sidebar-sortable: the project's workflows, layouts/main_aside.php). Mouse / pen:
	    //a press that moves more than DRAG_PX starts the drag (a click still opens the item; the click after a drag is dropped); touch: a press held HOLD_MS (a swipe still
	    //scrolls). The item moves where the pointer is; on release the new order (the items' data-id, separated by commas) is POSTed to the list's data-order-url; a refused
	    //save shows a toast and renders the sidebar again (the order saved before)
	    const DRAG_PX = 5, HOLD_MS = 400;
	    sidebar.on('dragstart', '.sidebar-sortable .nav-link', (e) => e.preventDefault());
	    sidebar.on('pointerdown', '.sidebar-sortable > .nav-item > .nav-link', (e) => {
		if (e.button !== 0)
		    return;
		const ev0 = e.originalEvent;
		const link = e.currentTarget;
		const item = link.closest('.nav-item');
		const list = item.parentElement;
		const pointerId = ev0.pointerId;
		const startY = ev0.clientY;
		const touch = ev0.pointerType === 'touch';
		const ids = () => [...list.querySelectorAll(':scope > .nav-item[data-id]')].map((li) => li.dataset.id).join(',');
		const before = ids();
		let dragging = false;
		let timer = null;

		const begin = () => {
		    dragging = true;
		    list.classList.add('sorting');
		    item.classList.add('sorting-item');
		    $('body').css('user-select', 'none');
		    window.getSelection()?.removeAllRanges();
		};
		const place = (y) => {
		    const others = [...list.querySelectorAll(':scope > .nav-item[data-id]')].filter((li) => li !== item);
		    //the first item whose middle is below the pointer: the dragged one goes before it; none: after the last
		    const next = others.find((li) => { const r = li.getBoundingClientRect(); return y < r.top + r.height / 2; });
		    if (next && item.nextElementSibling !== next)
			list.insertBefore(item, next);
		    else if (!next && others.length && others[others.length - 1].nextElementSibling !== item)
			others[others.length - 1].after(item);
		};
		const move = (ev) => {
		    if (ev.pointerId !== pointerId)
			return;
		    if (!dragging) {
			//touch: moving before the hold is a scroll, not a drag
			if (touch) {
			    if (Math.abs(ev.clientY - startY) > DRAG_PX * 2)
				stop();
			    return;
			}
			if (Math.abs(ev.clientY - startY) < DRAG_PX)
			    return;
			begin();
		    }
		    place(ev.clientY);
		};
		//while dragging by touch the page does not scroll
		const noScroll = (ev) => { if (dragging) ev.preventDefault(); };
		const stop = () => {
		    clearTimeout(timer);
		    window.removeEventListener('pointermove', move);
		    window.removeEventListener('pointerup', end);
		    window.removeEventListener('pointercancel', end);
		    window.removeEventListener('touchmove', noScroll);
		};
		const end = (ev) => {
		    if (ev.pointerId !== pointerId)
			return;
		    stop();
		    if (!dragging)
			return;
		    list.classList.remove('sorting');
		    item.classList.remove('sorting-item');
		    $('body').css('user-select', '');
		    //the click that ends a drag does not open the item
		    const eat = (c) => { c.preventDefault(); c.stopImmediatePropagation(); };
		    link.addEventListener('click', eat, { capture: true, once: true });
		    setTimeout(() => link.removeEventListener('click', eat, { capture: true }), 0);
		    const order = ids();
		    if (order === before)
			return;
		    const body = new FormData();
		    body.append('order', order);
		    //an ajax request: the CSRF token in its header (sys\BaseController::validateRequest)
		    fetch(list.dataset.orderUrl, { method: 'POST', body: body,
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': $('meta[name=csrf-token]').attr('content') || '' } })
			.then((r) => { if (!r.ok) throw new Error('HTTP ' + r.status); })
			.catch((err) => {
			    app.utils.toast({ type: 'error', title: 'Order not saved', body: String(err.message || err) });
			    app.events.dispatch({ type: 'sidebar::refresh', data: {} });
			});
		};
		if (touch)
		    timer = setTimeout(begin, HOLD_MS);
		window.addEventListener('pointermove', move);
		window.addEventListener('pointerup', end);
		window.addEventListener('pointercancel', end);
		window.addEventListener('touchmove', noScroll, { passive: false });
	    });

	    //pointer events (mouse, touch and pen), as the designer's panels
	    //(app.workflow.js setupPanelResize): the pointer is captured by the handle, pointercancel ends the drag like pointerup; touch-action none on the
	    //handle (theme local/_app.scss). Delegated on the sidebar: the handle is new after the aside is rendered again (sidebar::refresh)
	    sidebar.on('pointerdown', '.sidebar-resize-handle', (e) => {
		//main button only (a touch or a pen contact is button 0 too)
		if (e.button !== 0)
		    return;
		e.preventDefault();
		const handle = $(e.currentTarget);
		const pointerId = e.originalEvent.pointerId;
		try {
		    e.currentTarget.setPointerCapture(pointerId);
		} catch (err) {}

		handle.addClass('dragging');
		sidebar.removeClass('collapsed');
		//expanded by the drag, as by the expand button (saved with the width: storeWidth)
		isCollapsed = false;
		$('.btn-expand-sidebar').addClass('d-none');
		$('body').css({ userSelect: 'none', cursor: 'col-resize' });

		handle
		    .on('pointermove.sbresize', (e) => {
			if (e.originalEvent.pointerId !== pointerId)
			    return;
			lastWidth = Math.max(minWidth, Math.min(maxWidth, e.originalEvent.clientX));
			setWidth(lastWidth);
		    })
		    .on('pointerup.sbresize pointercancel.sbresize', (e) => {
			if (e.originalEvent.pointerId !== pointerId)
			    return;
    			handle.removeClass('dragging');
			$('body').css({ userSelect: '', cursor: '' });
			handle.off('.sbresize');
			try {
			    handle[0].releasePointerCapture(pointerId);
			} catch (err) {}
			//kept for the next page loads
			storeWidth(lastWidth);
		    });
	    });

	    //clicks
	    sidebar.on('click', 'a.nav-link', function(e) {
		sidebar.find('a.nav-link').removeClass('active');
		$(this).addClass('active');
	    });

	    //click on secondary-menu toggle (Settings), the Documentation icon; their back links
	    sidebar.on('click', 'a.open-secondary-menu', (e) => {
		e.preventDefault();
		showView(sidebar.find('.sidebar-view-secondary'));
	    });
	    sidebar.on('click', 'a.open-docs-menu', (e) => {
		e.preventDefault();
		showView(sidebar.find('.sidebar-view-docs'));
		loadDocs().then(markDocs);
	    });
	    sidebar.on('click', 'a.close-secondary-menu', (e) => {
		e.preventDefault();
		showView(sidebar.find('.sidebar-view-primary'));
	    });
	    //a documentation section opens / closes
	    sidebar.on('click', '.docs-nav-section-toggle', function() {
		const section = $(this).closest('.docs-nav-section').toggleClass('open');
		this.setAttribute('aria-expanded', section.hasClass('open') ? 'true' : 'false');
	    });

	    //initialization
	    window.removeEventListener('popstate', () => { api.highlight() });
	    window.addEventListener('popstate', () => { api.highlight() });

	    api.highlight();
	},
    };

    return api;
})();


//event handler
app.events = (function() {
    let _listeners = {};

    const api = {
	//register an event handler
	on: function(eventNames, fn) {
	    console.log('app.events.on: registering events:', eventNames);
    	    for (const eventName of eventNames.split(' ').filter(Boolean)) {
		console.log('app.events.on: registering handler for event:', eventName);
    		(_listeners[eventName] ||= []).push(fn);
    	    }
	},
	off: function(eventNames, fn) {
    	    for (const eventName of eventNames.split(' ').filter(Boolean)) {
    		if (!fn)
    		    delete _listeners[eventName];
    		else
    		    _listeners[eventName] = (_listeners[eventName] || []).filter(f => f !== fn);
    	    }
        },

	dispatch: function(e) {
	    if (!_listeners[e.type]) {
		console.log('app.events.dispatch: no handlers for event:', e.type);
		console.log('app.events.dispatch: listeners:', _listeners);
	    }

    	    for (const fn of (_listeners[e.type] || []).slice()) {
    		fn.call(this, e.data);
    	    }
	},

	init: function() {
	    _listeners = {};
	    //pre-register some system handlers
	    api.on('toast::add', app.utils.toast);
	    api.on('sidebar::refresh', (data) => {
		var timer = setInterval(() => {
		    if (!app.pjax.isActive()) {
			clearInterval(timer);
			app.pjax.fetch('/site/render_aside', { push: false, destination: $('aside.sidebar') });
		    }
		}, 100);
	    });
	},
    };


    return api;
})();

app.utils = (function() {
    var uidCounter = 0;

    return {
	uid: function(prefix) {
	    uidCounter++;
	    return (prefix || 'fld') + '-' + uidCounter;
	},

	//toast display utility
	toast: function(data) {
	    const defaults = {
		type: 'info',
		delay: 3000,
		title: '',
		body: '',
	    }

    	    data = $.extend({}, defaults, data);

	    //CLD: 2026-11-15T15:00:00+02:00: compact (.toast-compact, theme local/_toast.scss): one row, the type's icon and color (error: danger), the title in bold followed
	    //by the text, a small close button; errors are announced at once (role alert), the others politely (role status)
	    const kinds = {
		success: [ 'bi-check-circle-fill', 'text-success' ],
		error: [ 'bi-x-circle-fill', 'text-danger' ],
		warning: [ 'bi-exclamation-triangle-fill', 'text-warning' ],
		info: [ 'bi-info-circle-fill', 'text-info' ],
	    };
	    const [ icon, color ] = kinds[data.type] || kinds.info;
	    const live = data.type === 'error' ? 'role="alert" aria-live="assertive"' : 'role="status" aria-live="polite"';

	    var toast = $(`<div class="toast toast-compact" ${live} aria-atomic="true" data-bs-autohide="true" data-bs-delay="${data.delay}">
		<div class="toast-row">
		    <i class="bi ${icon} ${color} toast-icon" aria-hidden="true"></i>
		    <div class="toast-text">${data.title ? `<strong>${data.title}</strong>` : ''}${data.title && data.body ? ' ' : ''}${data.body ? `<span class="toast-msg">${data.body}</span>` : ''}</div>
		    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
		</div>
	    </div>`);
	    //removed once hidden: the stack keeps only the toasts on screen
	    toast[0].addEventListener('hidden.bs.toast', () => toast.remove());

	    $(toast).appendTo($('.toast-container'))
	    bootstrap.Toast.getOrCreateInstance(toast[0]).show();
	},

	//a toast of plain texts (escaped), shown 5 s: type error, warning, success, info
	toastText: function(type, title, body) {
	    this.toast({ type: type, title: this.escapeHtml(title), body: this.escapeHtml(body), delay: 5000 });
	},

	//the page's CSRF token (<meta name=csrf-token>), for the X-Csrf-Token header of a POST
	csrf: function() {
	    return document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
	},

	//a Bootstrap badge: <span class='badge text-bg-COLOR'>label</span>, the label escaped; color: success, danger, primary, info, warning, secondary
	badge: function(color, label) {
	    return `<span class='badge text-bg-${color}'>${this.escapeHtml(label)}</span>`;
	},

	//create a DOM element with given className and attributes
	el: function (tag, className, attrs) {
	    const n = document.createElement(tag);
	    if (className)
		n.className = className;
	    if (attrs) {
		for (const k in attrs)
		    n.setAttribute(k, attrs[k]);
	    }
	    return n;
	},

	//any value (null/undefined -> '', numbers -> text) and single quotes too, so the files' own esc() helpers could go
	escapeHtml: function(s) {
	    return String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
	},
    };
})();

// JavaScript micro-templating
// Adapted from Underscore templating
// handlebars-style, preserves whitespace, and correctly escapes quotes within interpolated code.
app.template = (function() {
    function escapeChars(text) {
	const escapeRegExp = /\\|'|\r|\n/g;
	return text.replace(escapeRegExp, (match) => {
		    const escapes = { "'": "'", '\\': '\\', '\r': 'r', '\n': 'n', };
		    return '\\' + escapes[match];
	});
    }

    // Combine delimiters into one regular expression via alternation.
    const delimiters = {
	print: /{{([\s\S]+?)}}/g,
	exec: /{%([\s\S]+?)%}/g,
	escape: /{{-([\s\S]+?)}}/g,
    };


    return {
	vars: function(text) {
	    return text.match(delimiters.print).map(el => el.replace('{{','').replace('}}','').trim());
	},
	compile: function(text) {
	    const matcher = RegExp([
		delimiters.escape.source, //escape: {{- .... }}
    		delimiters.print.source, //print: {{ .... }}
    		delimiters.exec.source, //execute: {% ... %}
	    ].join('|') + '|$', 'g');

	    // Compile the template source, escaping string literals appropriately.
	    var index = 0;
	    var source = '';
	    text.replace(matcher, function(match, escape, print, execute, offset) {
    		source += escapeChars(text.slice(index, offset));
    		index = offset + match.length;

    		if (escape) {
    		    //app.utils.escapeHtml; the compiled function (new Function) sees only globals, never the local _escape it called
    		    source += `'+((_t=(${escape}))==null?'':app.utils.escapeHtml(_t))+'`;
    		    return;
    		}
    		if (print) {
    		    source += `'+((_t=(${print}))==null?'':_t)+'`;
    		    return;
    		}
    		if (execute) {
    		    source += `';${execute} _p+='`;
    		    return;
    		}
	    });

	    source = `var _t,_p;with(obj||{}){_p='${source}';}return _p;`;

	    //validate source code
	    try {
    		const render = new Function('obj', source);
	    } catch (e) {
    		e.source = source;
    		throw e;
	    }

	    return function(data) {
		return (new Function('obj', source))(data);
	    };
	},
    };
})();

//the theme, light, dark or auto (the system's), chosen per browser (localStorage qflows.theme; read first by the <head> script of
//layouts/_webpage.php, which sets data-bs-theme / data-theme-choice on <html> before the styles load). CLD: 2026-11-21T10:00:00+02:00: the switcher: one button [data-theme-cycle]
//(the sidebar's, as the website's) going auto > light > dark, its icon shown by the theme's CSS from data-theme-choice, its title said here; auto follows
//the system's changes, another tab's choice is followed too; when the theme shown changes: `app:themechange` on document ({ detail: { theme } }), e.g. the overview's charts
app.theme = (function() {
    const KEY = 'qflows.theme';
    const CHOICES = [ 'light', 'dark', 'auto' ];
    const NEXT = { auto: 'light', light: 'dark', dark: 'auto' };
    const LABELS = { auto: 'Theme: automatic (the system\'s)', light: 'Theme: light', dark: 'Theme: dark' };
    const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    const choice = () => {
	const c = document.documentElement.getAttribute('data-theme-choice');
	return CHOICES.includes(c) ? c : 'auto';
    };
    const resolved = (c) => (c === 'dark' || (c === 'auto' && media && media.matches)) ? 'dark' : 'light';

    //shows choice $c: the theme on <html>, the switchers' buttons
    const apply = (c) => {
	const html = document.documentElement;
	const before = html.getAttribute('data-bs-theme');
	const theme = resolved(c);
	html.setAttribute('data-theme-choice', c);
	html.setAttribute('data-bs-theme', theme);
	document.querySelectorAll('[data-theme-cycle]').forEach((b) => {
	    b.title = LABELS[c] + ', click to change';
	    b.setAttribute('aria-label', LABELS[c] + ' (change)');
	});
	if (before !== theme)
	    document.dispatchEvent(new CustomEvent('app:themechange', { detail: { theme: theme } }));
    };

    return {
	init() {
	    apply(choice());
	    document.addEventListener('click', (e) => {
		if (!e.target.closest('[data-theme-cycle]'))
		    return;
		e.preventDefault();
		this.set(NEXT[choice()]);
	    });
	    if (media)
		media.addEventListener('change', () => {
		    if (choice() === 'auto')
			apply('auto');
		});
	    window.addEventListener('storage', (e) => {
		if (e.key === KEY && CHOICES.includes(e.newValue))
		    apply(e.newValue);
	    });
	},

	//chooses $c (light, dark, auto) for this browser
	set(c) {
	    if (!CHOICES.includes(c))
		return;
	    try {
		localStorage.setItem(KEY, c);
	    } catch (e) {}
	    apply(c);
	},

	//the choice (light, dark, auto) and the theme shown (light, dark)
	choice: choice,
	current() {
	    return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
	},
    };
})();

+function() {
    console.log('app: initializing');
    const app_modules = Object.keys(window.app);
    app_modules.forEach((el) => {
	if (typeof window.app[el].init === "function") {
	    window.app[el].init();
	}
    });
}();

