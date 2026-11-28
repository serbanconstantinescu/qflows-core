/*
 * CLD: 2026-11-28T10:00:00Z: the live events of the workflow daemon (commands\DaemonCommand: Server-Sent Events on /push, nginx proxies to it), one stream per page.
 *
 *   app.live.start({ ticket, renew })        layouts/main.php, on a full page load: the page's ticket (util\Daemon::pushToken: account, project, user) and
 *                                            where a new one is asked (SiteController::live_ticket) when it expires or the daemon was away
 *   app.live.on(el, 'a::b c::d', fn)         fn(data, type) for these events while `el` is on the page (pjax replaced it: dropped); el = null: always
 *   app.live.connected()                     whether the stream is open (a page polls while it is not)
 *
 * An event only says what changed ({ type, data }: ids, no records): the handlers load what they show themselves. The same event and data within
 * DEBOUNCE ms come once. `live::resync` comes after the stream was away (the daemon restarted, the network): the events meanwhile are lost,
 * the pages load again what they show. Its own handlers, not app.events: app.events.init() (each pjax page, the sidebar's refresh) forgets those.
 */
window.app = window.app || {};
app.live = (function() {
    const DEBOUNCE = 300;
    const MAX_WAIT = 60000;

    let source = null;
    let ticket = null;
    let claims = null;
    let renewUrl = null;
    let opened = false;
    let failures = 0;
    let retryTimer = null;
    const handlers = [];
    const pending = {};

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
	source.onopen = () => {
	    failures = 0;
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
	    }
	};
    }

    function later() {
	failures++;
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
	    if (r.status === 401 || r.status === 403)
		return;
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

	//whether the stream is open now (else the pages poll as before)
	connected: function() {
	    return !!source && source.readyState === EventSource.OPEN;
	},
    };

    //another one's change of the workflows, projects, members (sidebar::changed); this page's own was rendered just now (reloadedLately)
    api.on(null, 'sidebar::changed live::resync', () => {
	if (app.sidebar && !app.sidebar.reloadedLately())
	    app.sidebar.reload(false);
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
