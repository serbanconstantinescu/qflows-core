//CLD: 2026-11-19T12:00:00+02:00: app.credentialDialog: a new credential on the spot, from a step's credential field (app.smartform select with `createTypes`,
//WorkflowController::node_schema). The credential's form (credential/form, ?inline=1) opens in a second modal above the step's dialog, which stays open with its
//unsaved settings (not through app.pjax: it closes every modal); posted here, it answers { id, label, oauth, testable }. Then, as asked:
//  - a sign-in type (OAuth): Connect, the provider's consent in a popup (credential/connect ?popup=1; its last page sends { type: 'credential::oauth', ok, message,
//    id, label } on BroadcastChannel 'credential::oauth' and closes: the providers' pages cut window.opener, Cross-Origin-Opener-Policy)
//  - Create and test: the connection test (credential/test ?inline=1: { ok, message })
//open(type, { onChoice({ value, label }) }): onChoice when it is created, and again when its label changes (connected: the signed-in account)
window.app.credentialDialog = (function() {
    'use strict';

    const esc = (s) => app.utils.escapeHtml(String(s ?? ''));

    //a pjax request: a page answers { content, script, meta }, an inline action its JSON
    function request(url, formData) {
	const options = { url: url, type: formData ? 'POST' : 'GET', headers: { 'X-PJAX': 'true', 'X-Csrf-Token': app.utils.csrf() } };
	if (formData) {
	    const param = document.querySelector('meta[name=csrf-param]')?.getAttribute('content');
	    if (param)
		formData.append(param, app.utils.csrf());
	    Object.assign(options, { data: formData, processData: false, contentType: false });
	}
	return $.ajax(options);
    }

    function failed(xhr) {
	app.utils.toast({ type: 'error', title: 'Credential', body: 'The request failed (' + ((xhr && xhr.status) || 'no connection') + ')', delay: 5000 });
    }

    //the events of a page's answer (toasts, ...)
    function dispatch(resp) {
	((resp && resp.meta) || []).forEach((ev) => app.events.dispatch(ev));
    }

    //above the step's dialog: its own backdrop between the two; a modal still open keeps the page from scrolling (Bootstrap takes modal-open off the body)
    function stack(el) {
	el.style.zIndex = 1065;
	el.addEventListener('shown.bs.modal', () => {
	    const backdrops = document.querySelectorAll('.modal-backdrop');
	    if (backdrops.length)
		backdrops[backdrops.length - 1].style.zIndex = 1060;
	});
	el.addEventListener('hidden.bs.modal', () => {
	    if (document.querySelector('.modal.show'))
		document.body.classList.add('modal-open');
	});
    }

    function open(type, options) {
	const onChoice = (options && options.onChoice) || (() => {});
	request('/credential/create?inline=1&type=' + encodeURIComponent(type))
	    .done((resp) => {
		const el = $(resp && resp.content).filter('.modal').add($(resp && resp.content).find('.modal')).get(0);
		if (!el)
		    return failed(null);
		document.body.appendChild(el);
		stack(el);
		$('body').append($(resp.script || ''));
		const modal = new bootstrap.Modal(el);
		el.addEventListener('hidden.bs.modal', () => {
		    if (el._channel)
			el._channel.close();
		    modal.dispose();
		    el.remove();
		});
		bindForm(el, modal, onChoice);
		modal.show();
	    })
	    .fail(failed);
    }

    //the form's submits: posted here; the form again with its errors, or created
    function bindForm(el, modal, onChoice) {
	$(el).on('submit', 'form', (e) => {
	    e.preventDefault();
	    e.stopPropagation();
	    const form = e.currentTarget;
	    const then = (e.originalEvent && e.originalEvent.submitter && e.originalEvent.submitter.dataset.then) || '';
	    const buttons = el.querySelectorAll('.modal-footer button');
	    buttons.forEach((b) => (b.disabled = true));
	    const enable = () => buttons.forEach((b) => (b.disabled = false));
	    request(form.getAttribute('action'), new FormData(form))
		.done((resp) => {
		    if (resp && resp.id) {
			onChoice({ value: resp.id, label: resp.label });
			created(el, modal, resp, then === 'test', onChoice);
			return;
		    }
		    //the form with the server's errors: its body, footer (its buttons name the new form) and script in place of these
		    dispatch(resp);
		    const content = $(resp && resp.content);
		    const body = content.find('.modal-body').get(0);
		    const footer = content.find('.modal-footer').get(0);
		    if (!body || !footer)
			return enable();
		    el.querySelector('.modal-body').replaceWith(body);
		    el.querySelector('.modal-footer').replaceWith(footer);
		    $('body').append($(resp.script || ''));
		})
		.fail((xhr) => {
		    enable();
		    failed(xhr);
		});
	});
    }

    //created: what is left (Connect, the test), in the same modal, else closed
    function created(el, modal, cred, test, onChoice) {
	if (!cred.oauth && !(test && cred.testable))
	    return modal.hide();

	const body = el.querySelector('.modal-body');
	const footer = el.querySelector('.modal-footer');
	const show = (html, buttons) => {
	    body.innerHTML = html;
	    footer.innerHTML = buttons;
	};
	const status = (ok, message) => `<div class="alert alert-${ok ? 'success' : 'danger'} mb-0"><i class="bi ${ok ? 'bi-check-circle' : 'bi-exclamation-triangle'} me-2"></i>${esc(message)}</div>`;
	const done = `<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>`;
	const later = `<p class="small text-body-secondary mt-3 mb-0">It is chosen in the step; you can test it, or fix it, later on the Credentials page.</p>`;

	const runTest = (before) => {
	    show(before + `<div class="text-body-secondary"><span class="spinner-border spinner-border-sm me-2"></span>Testing the connection…</div>`, done);
	    request('/credential/test/' + encodeURIComponent(cred.id) + '?inline=1', new FormData())
		.done((r) => show(before + status(!!(r && r.ok), (r && r.message) || (r && r.ok ? 'Test passed' : 'Test failed')) + (r && r.ok ? '' : later), done))
		.fail((xhr) => show(before + status(false, 'The test could not run (' + (xhr.status || 'no connection') + ')') + later, done));
	};

	if (!cred.oauth)
	    return runTest('');

	//a sign-in type: Connect in a popup (opened by the click: no popup blocker), the answer of its last page
	const connectView = (note) => show(`<p>Credential created. Sign in at the provider to let the step use it.</p>${note || ''}`,
	    `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Later</button>
	     <button type="button" class="btn btn-primary btn-connect"><i class="bi bi-box-arrow-in-right me-1"></i>Connect</button>`);
	connectView('');
	const onMessage = (e) => {
	    if (!e.data || e.data.type !== 'credential::oauth' || (e.data.id && e.data.id !== cred.id))
		return;
	    if (!e.data.ok)
		return connectView(status(false, e.data.message || 'Not connected'));
	    if (e.data.label)
		onChoice({ value: cred.id, label: e.data.label });
	    const connected = status(true, e.data.message || 'Connected');
	    if (test && cred.testable)
		return runTest(connected.replace('mb-0', 'mb-3'));
	    show(connected, done);
	};
	if ('BroadcastChannel' in window) {
	    el._channel = new BroadcastChannel('credential::oauth');
	    el._channel.addEventListener('message', onMessage);
	}
	$(footer).on('click', '.btn-connect', () => {
	    const popup = window.open('/credential/connect/' + encodeURIComponent(cred.id) + '?popup=1', 'credential-connect', 'popup,width=640,height=760');
	    if (!popup)
		connectView(status(false, 'The browser blocked the sign-in window: allow popups for this site, then Connect again'));
	});
    }

    return { open: open };
})();
