/*
 * CLD: 2026-11-28T10:00:00Z: the live events of the workflow daemon (commands\DaemonCommand: Server-Sent Events on /push, nginx proxies to it), one stream per page.
 *
 *   app.live.start({ ticket, renew })        layouts/main.php, on a full page load: the page's ticket (util\Daemon::pushToken: account, project, user) and
 *                                            where a new one is asked (SiteController::live_ticket) when it expires or the daemon was away
 *   app.live.on(el, 'a::b c::d', fn)         fn(data, type) for these events while `el` is on the page (pjax replaced it: dropped); el = null: always
 *   app.live.connected()                     whether the stream is open (a page polls while it is not)
 *   app.live.paint()                         the status dots ([data-live-status], beside the version in the sidebar) painted again (app.sidebar.reload: new ones)
 *   app.live.grid(container, types, accept)  an app.GridView loaded again on these events (data tables, Files): see grid() below
 *
 * An event only says what changed ({ type, data }: ids, no records): the handlers load what they show themselves. The same event and data within
 * DEBOUNCE ms come once. `live::resync` comes after the stream was away (the daemon restarted, the network): the events meanwhile are lost,
 * the pages load again what they show. Its own handlers, not app.events: app.events.init() (each pjax page, the sidebar's refresh) forgets those.
 */
window.app = window.app || {};
app.live = (function() {
    const DEBOUNCE = 300;
    const MAX_WAIT = 60000;
    const GRID_MIN = 2000;

    let source = null;
    let ticket = null;
    let claims = null;
    let renewUrl = null;
    let opened = false;
    let failures = 0;
    let retryTimer = null;
    const handlers = [];
    const pending = {};
    //the stream's state for the status dots: off (no stream on this page, signed out), connecting, on, down (a new ticket asked later)
    let state = 'off';
    const STATES = {
	off: [ 'text-secondary', 'Live updates: off' ],
	connecting: [ 'text-warning', 'Live updates: connecting' ],
	on: [ 'text-success', 'Live updates: on' ],
	down: [ 'text-danger', 'Live updates: not connected, trying again (the pages update less often meanwhile)' ],
    };

    function paint() {
	const [ cls, text ] = STATES[state];
	document.querySelectorAll('[data-live-status]').forEach((el) => {
	    el.classList.remove('text-secondary', 'text-warning', 'text-success', 'text-danger');
	    el.classList.add(cls);
	    el.title = text;
	    el.setAttribute('aria-label', text);
	});
    }

    function setState(s) {
	state = s;
	paint();
    }

    function decode(t) {
	try {
	    return JSON.parse(atob(String(t).split('.')[0].replace(/-/g, '+').replace(/_/g, '/')));
	} catch (e) {
	    return null;
	}
    }

    function deliver(type, data) {
	for (const h of handlers.slice()) {
	    if (h.el && !h.el.isConnected) {
		handlers.splice(handlers.indexOf(h), 1);
		continue;
	    }
	    if (!h.types.includes(type))
		continue;
	    try {
		h.fn(data, type);
	    } catch (e) {
		console.error('app.live: handler of ' + type + ':', e);
	    }
	}
    }

    function queue(type, data) {
	const key = type + ' ' + JSON.stringify(data || {});
	if (pending[key])
	    return;
	pending[key] = setTimeout(() => {
	    delete pending[key];
	    deliver(type, data || {});
	}, DEBOUNCE);
    }

    function close() {
	if (source) {
	    source.close();
	    source = null;
	}
    }

    function connect() {
	close();
	clearTimeout(retryTimer);
	source = new EventSource('/push?t=' + encodeURIComponent(ticket));
	setState('connecting');
	source.onopen = () => {
	    failures = 0;
	    setState('on');
	    //again after a break (the browser reconnected by itself, or a new ticket)
	    if (opened)
		queue('live::resync', {});
	    opened = true;
	};
	source.onmessage = (m) => {
	    let e = null;
	    try {
		e = JSON.parse(m.data);
	    } catch (err) {
		return;
	    }
	    if (e && typeof e.type === 'string')
		queue(e.type, e.data);
	};
	//the ticket ended: a new one
	source.addEventListener('expired', () => {
	    close();
	    renew();
	});
	//closed for good (a refused ticket, the daemon away: nginx answers 502): a new ticket, later and later
	source.onerror = () => {
	    if (source && source.readyState === EventSource.CLOSED) {
		close();
		later();
	    } else {
		//the browser connects again by itself
		setState('connecting');
	    }
	};
    }

    function later() {
	failures++;
	setState('down');
	clearTimeout(retryTimer);
	retryTimer = setTimeout(renew, Math.min(MAX_WAIT, 1000 * Math.pow(2, failures)));
    }

    async function renew() {
	if (!renewUrl)
	    return;
	try {
	    const r = await fetch(renewUrl + '?project=' + encodeURIComponent((claims && claims.project) || ''), {
		credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' },
	    });
	    //signed out: no more events on this page
	    if (r.status === 401 || r.status === 403) {
		setState('off');
		return;
	    }
	    const data = await r.json();
	    if (!r.ok || !data.ticket)
		throw new Error('no ticket');
	    ticket = data.ticket;
	    claims = decode(ticket);
	    connect();
	} catch (e) {
	    later();
	}
    }

    const api = {
	start: function(o) {
	    if (!o || !o.ticket || !window.EventSource)
		return;
	    ticket = o.ticket;
	    claims = decode(ticket);
	    renewUrl = o.renew || null;
	    connect();
	},

	on: function(el, types, fn) {
	    handlers.push({ el: el || null, types: String(types).split(' ').filter(Boolean), fn: fn });
	},

	//the page's claims (aid, project, user, exp)
	claims: function() {
	    return claims;
	},

	paint: paint,

	//whether the stream is open now (else the pages poll as before)
	connected: function() {
	    return !!source && source.readyState === EventSource.OPEN;
	},

	//the app.GridView of `container` (its _dataView) loaded again on `types` and live::resync: accept(data, type) says what: false (not this grid's), 'page' (the page
	//loaded again, e.g. new columns; not while a dialog is open), anything else its rows (the page of rows shown; not while rows are selected); at most once in GRID_MIN ms,
	//a change meanwhile loaded after it. Returns { own() }: the page's own change, which it loads itself: the events of the next GRID_MIN ms are not loaded again
	grid: function(container, types, accept) {
	    const g = { own: Date.now(), timer: null, pending: false, page: false };
	    const run = () => {
		const dv = container._dataView;
		if (!container.isConnected || !dv)
		    return;
		if (g.page) {
		    if (document.querySelector('.modal.show')) {
			g.pending = true;
			return;
		    }
		    g.page = false;
		    app.pjax.fetch(window.location.pathname + window.location.search, { push: false });
		    return;
		}
		if (dv.selected && dv.selected.size) {
		    g.pending = true;
		    return;
		}
		dv.loadData();
	    };
	    const load = () => {
		if (g.timer) {
		    g.pending = true;
		    return;
		}
		run();
		g.timer = setTimeout(() => {
		    g.timer = null;
		    if (g.pending) {
			g.pending = false;
			load();
		    }
		}, GRID_MIN);
	    };
	    api.on(container, types + ' live::resync', (data, type) => {
		const what = type === 'live::resync' ? true : accept(data, type);
		if (!what || Date.now() - g.own < GRID_MIN)
		    return;
		if (what === 'page')
		    g.page = true;
		load();
	    });
	    return { own: () => { g.own = Date.now(); } };
	},
    };

    //another one's change of the workflows, projects, members (sidebar::changed); this page's own was rendered just now (reloadedLately)
    api.on(null, 'sidebar::changed live::resync', () => {
	if (app.sidebar && !app.sidebar.reloadedLately())
	    app.sidebar.reload(false);
    });

    //a toast for this person (util\Toasts): plain texts, a link; one about a workflow (wf) not on that workflow's designer (its Executions panel says it)
    api.on(null, 'toast::live', (data) => {
	const here = new URL(window.location.href);
	if (data.wf && here.pathname === '/workflow/index' && here.searchParams.get('id') === data.wf)
	    return;
	const esc = app.utils.escapeHtml;
	const link = data.url ? ' <a href="' + esc(data.url) + '" class="ms-1"' + (data.full ? ' no-pjax' : '') + '>' + esc(data.label || 'Open') + '</a>' : '';
	app.utils.toast({ type: data.level || 'info', title: esc(data.title || ''), body: esc(data.body || '') + link, delay: 8000 });
    });

    //the sidebar's "My tasks" count (the tasks of the whole account: TaskController::count)
    api.on(null, 'tasks::changed live::resync', () => {
	const link = document.querySelector('aside.sidebar a[href="/task/list"]');
	if (!link)
	    return;
	fetch('/task/count', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
	    .then((r) => r.ok ? r.json() : null)
	    .then((data) => {
		if (!data || typeof data.count !== 'number')
		    return;
		let badge = link.querySelector('.qf-tasks-badge');
		if (!data.count) {
		    if (badge)
			badge.remove();
		    return;
		}
		if (!badge) {
		    badge = app.utils.el('span', 'badge rounded-pill text-bg-primary ms-1 qf-tasks-badge');
		    link.append(badge);
		}
		badge.textContent = String(data.count);
	    })
	    .catch(() => {});
    });

    return api;
})();
