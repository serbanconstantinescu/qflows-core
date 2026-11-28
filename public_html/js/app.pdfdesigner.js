/*
 * CLD: 2026-11-22T12:00:00+02:00: new component: the PDF designer (from the stand-alone TCPDF Page Designer 2) - edits a PDF document as models\PdfDesign stores it
 * ({ page, header, body, footer, sample }); util\PdfDocument makes the PDF with tc-lib-pdf (the PDF document step).
 *
 *   const d = new app.PdfDesigner(el, { value, readonly, onChange: (value) => ..., expression: { url, params }, previewUrl });
 *
 * One page edited in place: the header (top of every page), the body (flows over pages: red lines where a new page starts) and the footer (bottom of every page),
 * their edges dragged to set the margins. A toolbar formats the text (styles, sizes, bold / italic / underline / strike, color, lists, alignment, indentation,
 * tables, conditional blocks, lines, images of the project's Files, links, the HTML source); "{{ }}" inserts a variable of the step (the expression help of the
 * step's settings - option `expression`, as app.ExprInput) or the page number / count. Expressions are shown as chips; {{#if …}} … {{else}} … {{/if}} as framed
 * blocks; a table row repeated per item of a list (<tr data-each="list" data-as="item">) is tinted, its list and item name set in the bar above the page.
 * Tables: right-click (Shift+F10) a cell for rows, columns, cell and table properties, the repeating row; drag column edges. Page setup and sample data (JSON:
 * the variables of the preview) in dialogs; Checks lists layout problems; Preview PDF posts the document to option previewUrl (WorkflowController::pdf_preview).
 * As app.EmailDesigner: option `fill`, setStepPanel(el) (the step's other settings in the right panel), select(), getValue(), setValue(v), problem().
 * The page's sizes are pt (1/72 in), shown in mm or inches (kept in this browser, as the zoom).
 */
window.app.PdfDesigner = (function() {

    const PT = 96 / 72; //CSS px per point
    const FORMATS = { A4: [ 595.28, 841.89 ], A5: [ 419.53, 595.28 ], LETTER: [ 612, 792 ], LEGAL: [ 612, 1008 ] };
    //models\PdfDesign::FONTS, as the browser shows them
    const FONTS = {
	dejavusans: [ 'DejaVu Sans', '"DejaVu Sans", Verdana, Geneva, sans-serif' ],
	dejavuserif: [ 'DejaVu Serif', '"DejaVu Serif", Georgia, serif' ],
	dejavusansmono: [ 'DejaVu Sans Mono', '"DejaVu Sans Mono", Menlo, Consolas, monospace' ],
	dejavusanscondensed: [ 'DejaVu Sans Condensed', '"DejaVu Sans Condensed", "DejaVu Sans", "Arial Narrow", sans-serif' ],
	freesans: [ 'FreeSans', 'FreeSans, Helvetica, Arial, sans-serif' ],
	freeserif: [ 'FreeSerif', 'FreeSerif, "Times New Roman", Times, serif' ],
	freemono: [ 'FreeMono', 'FreeMono, "Courier New", Courier, monospace' ],
	helvetica: [ 'Helvetica (core)', 'Helvetica, Arial, sans-serif' ],
	times: [ 'Times (core)', '"Times New Roman", Times, serif' ],
	courier: [ 'Courier (core)', '"Courier New", Courier, monospace' ],
    };
    const CORE_FONTS = [ 'helvetica', 'times', 'courier' ];
    //models\PdfDesign::DEFAULT_PAGE, as the designer keeps it: margins and indent in pt (34, 24, 16, 16, 10 and 18 mm; indent 10 mm)
    const PAGE = { format: 'A4', orient: 'P', mt: 96.38, mb: 68.03, ml: 45.35, mr: 45.35, mh: 28.35, mf: 51.02, font: 'dejavusans', size: 10, indent: 28.35, headerOn: true, footerOn: true };
    //a new document
    const STARTER = {
	header: '<table width="100%" cellpadding="0"><tr><td width="60%"><b>Document title</b></td><td width="40%" align="right">{{ now() }}</td></tr></table><hr>',
	body: '<h2>Heading</h2><p>Start writing here. Insert the values of the workflow with <b>{{ }}</b>.</p>',
	footer: '<hr><p align="right">Page {PAGE} of {PAGES}</p>',
	sample: '{\n}',
    };
    //Page setup: "Replace with the sample invoice"
    const SAMPLE = {
	header: '<table width="100%" cellpadding="0"><tr>'
	    + '<td width="62%"><span style="font-size:14pt"><b>Carpatia Freight SRL</b></span><br><span style="color:#666666;font-size:8pt">Str. Fabricii 12, 400632 Cluj-Napoca · CUI RO18452291</span></td>'
	    + '<td width="38%" align="right"><span style="font-size:16pt;color:#0f6e6a"><b>INVOICE</b></span><br><span style="font-size:9pt">No. {{ invoice.number }}</span></td>'
	    + '</tr></table><hr>',
	body: '<table width="100%" cellpadding="2"><tr>'
	    + '<td width="55%"><span style="color:#666666;font-size:8pt">BILLED TO</span><br><b>{{ invoice.client.name }}</b><br>{{ invoice.client.address }}</td>'
	    + '<td width="45%" align="right"><span style="color:#666666;font-size:8pt">ISSUE DATE</span><br>{{ invoice.issued }}<br><span style="color:#666666;font-size:8pt">DUE DATE</span><br>{{ invoice.due }}</td>'
	    + '</tr></table><p></p>'
	    + '<table border="1" cellpadding="5" width="100%"><thead><tr>'
	    + '<th width="8%" align="right" bgcolor="#e6eeed"><b>#</b></th><th width="44%" align="left" bgcolor="#e6eeed"><b>Description</b></th><th width="12%" align="right" bgcolor="#e6eeed"><b>Qty</b></th><th width="18%" align="right" bgcolor="#e6eeed"><b>Unit price</b></th><th width="18%" align="right" bgcolor="#e6eeed"><b>Amount</b></th>'
	    + '</tr></thead>'
	    + '<tr data-each="invoice.items" data-as="item"><td width="8%" align="right">{{ loop.number }}</td><td width="44%">{{ item.description }}</td><td width="12%" align="right">{{ item.qty }}</td><td width="18%" align="right">{{ item.unit_price }}</td><td width="18%" align="right">{{ item.amount }}</td></tr>'
	    + '</table>'
	    + '<p align="right"><span style="font-size:12pt"><b>Total due: {{ invoice.total }} RON</b></span></p>'
	    + '{{#if invoice.notes}}<p><i>Note: {{ invoice.notes }}</i></p>{{/if}}'
	    + '{{#if invoice.paid}}<p align="right"><span style="font-size:16pt;color:#276238"><b>PAID</b></span> on {{ invoice.paid_on }}</p>{{else}}<p>Please pay by bank transfer to IBAN <b>{{ invoice.iban }}</b> by {{ invoice.due }}, quoting the invoice number.</p>{{/if}}',
	footer: '<hr><table width="100%" cellpadding="0"><tr>'
	    + '<td width="72%" style="color:#666666;font-size:7.5pt">Carpatia Freight SRL · Reg. Com. J12/3456/2019 · contact@carpatia-freight.example</td>'
	    + '<td width="28%" align="right" style="font-size:7.5pt">Page {PAGE} of {PAGES}</td>'
	    + '</tr></table>',
	sample: JSON.stringify({ invoice: {
	    number: 'CF-2026-0142', issued: '12.10.2026', due: '26.10.2026', client: { name: 'Delta Agro SRL', address: 'Bd. Unirii 45, București' }, total: '6,180.00',
	    iban: 'RO49 AAAA 1B31 0075 9384 0000', paid: false, paid_on: '', notes: 'Deliver to gate 3, Arad warehouse.',
	    items: [
		{ description: 'Road freight Cluj-Napoca – Timișoara, full truck', qty: 2, unit_price: '1,450.00', amount: '2,900.00' },
		{ description: 'Warehousing, October 2026', qty: 1, unit_price: '2,400.00', amount: '2,400.00' },
		{ description: 'Customs paperwork', qty: 4, unit_price: '220.00', amount: '880.00' },
	    ] } }, null, 2),
    };
    //the units shown: everything is stored in points
    const UNITS = { mm: { k: 72 / 25.4, step: 0.5, dec: 1, name: 'mm' }, in: { k: 72, step: 0.01, dec: 2, name: 'in' } };
    const UI_KEY = 'pdfdesigner.ui';
    const IMAGE = /\.(png|jpe?g|gif)$/i;
    let seq = 0;

    //the tags, attributes and styles tc-lib-pdf prints (models\PdfDesign: the server cleans the same way)
    const TAGS = new Set('a b blockquote br dd del div dl dt em font h1 h2 h3 h4 h5 h6 hr i img li ol p pre small span strong sub sup table thead tr td th tt u ul s strike'.split(' '));
    const DROP = new Set('script style iframe object embed meta link input button select textarea svg video audio canvas form noscript head title template'.split(' '));
    const SILENT_UNWRAP = new Set([ 'tbody', 'tfoot' ]);
    const ATTRS = new Set('align valign border cellpadding cellspacing colspan rowspan width height bgcolor color face size src href style alt nobr'.split(' '));
    const NOISE_ATTRS = /^(class|id|dir|lang|contenteditable|spellcheck|data-.*|aria-.*|role|tabindex|title)$/;
    const PROPS = new Set(('color background-color font-family font-size font-weight font-style text-decoration text-align width height line-height border border-top border-bottom '
	+ 'border-left border-right vertical-align text-transform letter-spacing margin-left text-indent padding padding-top padding-right padding-bottom padding-left').split(' '));
    const IMAGE_SRC = /^\/file\/content\/[0-9a-f]{24}$/;

    //conditions: {{#if expr}} … {{else}} … {{/if}} (innermost first)
    const COND_RE = /\{\{#if\s+((?:(?!\}\})[\s\S])*)\}\}((?:(?!\{\{#if\s)[\s\S])*?)(?:\{\{else\s*\}\}((?:(?!\{\{#if\s)[\s\S])*?))?\{\{\/if\s*\}\}/;
    //expressions (not the condition markers) and the page numbers, shown as chips
    const PH_RE = /\{\{(?!\s*(?:#if\s|else\s*\}\}|\/if\s*\}\}))(?:(?!\}\})[\s\S])+?\}\}|\{(?:PAGE|PAGES)\}/g;
    const PH_LABEL = { '{PAGE}': 'Page no.', '{PAGES}': 'Page count' };
    const BLOCK_TAG_RE = /<(p|div|table|h[1-6]|ul|ol|hr|blockquote|pre)\b/i;

    const decodeEnt = (s) => { const t = document.createElement('textarea'); t.innerHTML = s; return t.value; };
    const num = (v) => { const n = parseFloat(v); return isFinite(n) ? n : 0; };
    const fmt = (n) => (Math.round(n * 10) / 10).toString();
    const r2 = (n) => Math.round(n * 100) / 100;
    const clone = (o) => JSON.parse(JSON.stringify(o));
    //an expression input holds {{ expr }} (app.ExprInput's live help); the expression without them
    const bare = (v) => { const m = String(v || '').match(/^\s*\{\{([\s\S]*)\}\}\s*$/); return (m ? m[1] : String(v || '')).trim(); };
    const SVG = (d) => `<svg viewBox="0 0 16 16">${d}</svg>`;

    const CSS = `
.pd{position:relative;--paper:#ffffff;--paper-ink:#111111;--zone-line:rgba(var(--bs-primary-rgb),.45);--zone-fill:rgba(var(--bs-primary-rgb),.045);
  --chip-bg:#dcecea;--chip-ink:#0a5551;--chip-line:#b3d5d1;--chip-page-bg:#f3ead6;--chip-page-ink:#6b4800;--chip-page-line:#e0cfa6;
  --cond-line:#9a9ee0;--cond-bg:#eceefc;--cond-ink:#3b3f9c;--z:1}
.pd [hidden]{display:none!important}
body.pd-resizing,body.pd-resizing *{cursor:ns-resize!important;user-select:none!important}
.pd .pd-toolbar .btn{display:inline-flex;align-items:center;justify-content:center;min-width:2rem}
.pd svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.pd .pd-toolbar .form-select{width:auto;max-width:9.5rem}
.pd .pd-toolbar .form-control-color{width:2.25rem;height:calc(1.5em + .5rem + 2px);padding:.2rem}
.pd .pd-toolbar .dropdown-item{display:flex;align-items:center;gap:.6rem;font-size:.875rem}
.pd .pd-toolbar .dd-icon{display:inline-flex}
.pd .pd-toolbar .dd-hint{margin-left:1.5rem;font-size:.75rem;color:var(--bs-secondary-color)}
.pd .pd-vars{max-height:50vh;overflow:auto}
.pd .ctx-bar .field-sm{width:7.25rem}
.pd .ctx-label{align-self:center;font-size:.75rem;letter-spacing:.06em}
.pd .tpicker{position:absolute;z-index:1050;width:15rem}
.pd .tp-grid{display:grid;grid-template-columns:repeat(8,1.35rem);gap:3px}
.pd .tp-grid span{width:1.35rem;height:1.1rem;border:1px solid var(--bs-border-color);border-radius:2px;background:var(--bs-body-bg);cursor:pointer}
.pd .tp-grid span.on{background:var(--bs-primary-bg-subtle);border-color:var(--bs-primary)}
.pd .pd-desk{position:relative;background:var(--bs-secondary-bg);padding:28px 16px 40px;overflow:auto}
.pd .pd-sheet{position:relative;display:flow-root;margin:0 auto;background:var(--paper);box-shadow:0 1px 2px rgba(0,0,0,.12),0 10px 30px rgba(0,0,0,.12);zoom:var(--z)}
.pd .zone{position:absolute;left:0;right:0}
.pd .zone-h{top:0}
.pd .zone-h,.pd .zone-f{background:var(--zone-fill)}
.pd .zone.off{background:repeating-linear-gradient(-45deg,transparent 0 6pt,rgba(var(--bs-secondary-rgb),.08) 6pt 7pt)}
.pd .zone-b{position:relative}
.pd .editor{outline:none;color:var(--paper-ink);min-height:1em}
.pd .zone .editor{position:absolute}
.pd .editor:focus{box-shadow:0 0 0 calc(1px / var(--z)) var(--zone-line)}
.pd .zone-h .editor:empty::before,.pd .zone-f .editor:empty::before{content:attr(data-empty);color:#9aa5a7;font-style:italic}
.pd .zone-off-label{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font:500 calc(11px / var(--z))/1 var(--bs-body-font-family);letter-spacing:.06em;text-transform:uppercase;color:#8a9496;pointer-events:none}
.pd .content-box{position:absolute;outline:1px dashed var(--zone-line);outline-offset:0;pointer-events:none}
.pd .zone-handle{position:absolute;left:0;right:0;height:calc(12px / var(--z));margin-top:calc(-6px / var(--z));cursor:ns-resize;z-index:5;touch-action:none}
.pd .zone-handle::before{content:"";position:absolute;left:0;right:0;top:50%;border-top:calc(1px / var(--z)) solid var(--zone-line)}
.pd .zone-handle::after{content:"";position:absolute;left:50%;top:50%;width:calc(36px / var(--z));height:calc(5px / var(--z));transform:translate(-50%,-50%);border-radius:calc(3px / var(--z));background:var(--bs-primary);opacity:.55}
.pd .zone-handle:hover::before,.pd .zone-handle:focus-visible::before,.pd .zone-handle.drag::before{border-top-width:calc(2px / var(--z));border-top-color:var(--bs-primary)}
.pd .zone-handle:hover::after,.pd .zone-handle:focus-visible::after,.pd .zone-handle.drag::after{opacity:1}
.pd .zone-handle:focus-visible{outline:none}
.pd .size-tag{position:absolute;right:calc(8px / var(--z));top:50%;transform:translateY(calc(-100% - 5px / var(--z)));padding:calc(2px / var(--z)) calc(7px / var(--z));border-radius:calc(4px / var(--z));background:var(--bs-primary);color:#ffffff;font:500 calc(11px / var(--z))/1.3 var(--bs-font-monospace);white-space:nowrap;font-variant-numeric:tabular-nums;opacity:0;transition:opacity .12s;pointer-events:none}
.pd .zone-handle.below .size-tag{transform:translateY(calc(5px / var(--z)))}
.pd .zone-handle:hover .size-tag,.pd .zone-handle:focus-visible .size-tag,.pd .zone-handle.drag .size-tag,.pd .pd-sheet:hover .zone-handle .size-tag{opacity:1}
.pd .pd-sheet:hover .zone-handle:not(:hover):not(.drag) .size-tag{opacity:.75;background:var(--bs-secondary)}
.pd .page-break{position:absolute;left:0;right:0;border-top:calc(1px / var(--z)) dashed #e0626a;pointer-events:none;z-index:4}
.pd .page-break span{position:absolute;left:calc(6px / var(--z));top:calc(2px / var(--z));font:500 calc(10px / var(--z))/1 var(--bs-body-font-family);letter-spacing:.04em;color:#c4434c;background:var(--paper);padding:0 calc(3px / var(--z))}
.pd .pd-imgframe{position:absolute;pointer-events:none;outline:1.5px solid var(--bs-primary);z-index:6}
.pd .pd-imgframe .h{position:absolute;width:12px;height:12px;background:var(--paper);border:1.5px solid var(--bs-primary);border-radius:2px;pointer-events:auto;touch-action:none}
.pd .pd-imgframe .h.nw{left:-7px;top:-7px;cursor:nwse-resize}.pd .pd-imgframe .h.ne{right:-7px;top:-7px;cursor:nesw-resize}
.pd .pd-imgframe .h.sw{left:-7px;bottom:-7px;cursor:nesw-resize}.pd .pd-imgframe .h.se{right:-7px;bottom:-7px;cursor:nwse-resize}
.pd .pd-imgframe .dim{position:absolute;left:0;bottom:-24px;background:var(--bs-primary);color:#ffffff;font:500 11px/1 var(--bs-font-monospace);padding:4px 6px;border-radius:3px;white-space:nowrap}
.pd .pd-tbllayer{position:absolute;left:0;top:0;width:0;height:0;z-index:6;pointer-events:none}
.pd .colh{position:absolute;width:9px;margin-left:-4.5px;cursor:col-resize;pointer-events:auto;touch-action:none}
.pd .colh::after{content:"";position:absolute;left:4px;top:0;bottom:0;width:1px;background:var(--chip-ink);opacity:.25}
.pd .colh:hover::after,.pd .colh.drag::after{opacity:1;width:2px;left:3.5px}
.pd .colh .tip{position:absolute;bottom:100%;left:50%;transform:translate(-50%,-4px);font:500 10px/1 var(--bs-font-monospace);background:var(--chip-ink);color:#ffffff;padding:3px 5px;border-radius:3px;white-space:nowrap;display:none}
.pd .colh:hover .tip,.pd .colh.drag .tip{display:block}
.pd .editor td.curcell,.pd .editor th.curcell{box-shadow:inset 0 0 0 1.5px var(--chip-ink)}
.pd .editor table:not([border="1"]) td,.pd .editor table:not([border="1"]) th{outline:1px dashed #cfd9d8;outline-offset:-1px}
.pd .editor tr[data-each] > td,.pd .editor tr[data-each] > th{box-shadow:inset 0 0 0 9999px rgba(59,63,156,.07)}
.pd .editor tr[data-each] > :first-child{box-shadow:inset 3px 0 0 var(--cond-ink),inset 0 0 0 9999px rgba(59,63,156,.07)}
.pd .editor tr[data-each] > .curcell{box-shadow:inset 0 0 0 1.5px var(--chip-ink),inset 0 0 0 9999px rgba(59,63,156,.07)}
.pd .editor img{max-width:none;cursor:pointer}
.pd .pd-swatches button{width:1.6rem;height:1.6rem;border:1px solid var(--bs-border-color);border-radius:var(--bs-border-radius-sm);padding:0}
.pd .pd-swatches button:hover,.pd .pd-swatches button:focus-visible{outline:2px solid var(--bs-primary);outline-offset:1px}
.pd .cond-row{row-gap:.5rem}
.pd .cond-actions .form-check{min-height:0;padding-top:.1rem}
.pd .pd-tmenu{position:fixed;z-index:1080;min-width:12.5rem;margin:0}
.pd .pd-tmenu .dropdown-item{display:flex;align-items:center;gap:.6rem;font-size:.875rem;white-space:nowrap}
.pd .pd-tmenu svg{stroke-width:1.5}
.pd .pd-tmenu .sub{position:relative}
.pd .pd-tmenu .has-sub::after{content:"";margin-left:auto;border:solid currentColor;border-width:0 1.5px 1.5px 0;padding:2.5px;transform:rotate(-45deg);opacity:.7}
.pd .pd-tmenu .sub-menu{display:none;position:absolute;left:100%;top:calc(-1 * var(--bs-dropdown-padding-y) - 1px);margin:0 0 0 .125rem}
.pd .pd-tmenu.flip-sub .sub-menu{left:auto;right:100%;margin:0 .125rem 0 0}
.pd .pd-tmenu.lift-sub .sub-menu{top:auto;bottom:calc(-1 * var(--bs-dropdown-padding-y) - 1px)}
.pd .pd-tmenu .sub:hover > .sub-menu,.pd .pd-tmenu .sub.open > .sub-menu{display:block}
.pd .pd-tmenu .sub:hover > .has-sub,.pd .pd-tmenu .sub.open > .has-sub{background:var(--bs-dropdown-link-hover-bg)}
.pd .pd-tmenu [aria-checked="true"]::after{content:"\\2713";margin-left:auto;padding-left:1rem;font-weight:600}
.pd .pd-tmenu .dropdown-item:disabled{opacity:.45}
.pd .editor .cond{display:block;position:relative;margin:0;padding:0;outline:1px dashed var(--cond-line);outline-offset:2px;border-radius:2px}
.pd .editor .cond-bar{position:absolute;z-index:1;display:flex;align-items:center;gap:6px;padding:1px 6px;background:var(--cond-bg);color:var(--cond-ink);border:1px solid var(--cond-line);border-radius:3px 3px 0 0;font:400 10px/1.4 var(--bs-font-monospace);font-style:normal;font-weight:normal;text-decoration:none;text-align:left;white-space:nowrap;cursor:pointer;user-select:none;-webkit-user-select:none;max-width:60ch;overflow:hidden;text-overflow:ellipsis}
.pd .editor .cond > .cond-bar:first-child{right:-3px;bottom:calc(100% + 2px);border-bottom:0}
.pd .editor .cond-else{position:relative;display:block;height:0;padding:0;border:0;background:none;margin:0}
.pd .editor .cond-else::before{content:"";position:absolute;left:-3px;right:-3px;top:0;border-top:1px dashed var(--cond-line)}
.pd .editor .cond-else .cond-tag{position:absolute;right:-3px;bottom:0;padding:1px 6px;background:var(--cond-bg);border:1px solid var(--cond-line);border-bottom:0;border-radius:3px 3px 0 0}
.pd .editor .cond-tag{font-weight:600;letter-spacing:.06em}
.pd .editor .cond:hover > .cond-bar,.pd .editor .cond.sel > .cond-bar,.pd .editor .cond.sel > .cond-else .cond-tag{background:var(--cond-ink);color:#ffffff;border-color:var(--cond-ink)}
.pd .editor .cond.sel{outline-style:solid;outline-color:var(--cond-ink)}
.pd .editor .cond-inline{display:inline;outline-offset:1px}
.pd .editor .cond-inline > .cond-body{display:inline}
.pd .editor .cond-inline > .cond-bar:first-child{right:auto;left:-2px;bottom:calc(100% + 1px)}
.pd .editor .cond-inline > .cond-else{display:inline;position:relative}
.pd .editor .cond-inline > .cond-else::before{left:0;right:auto;top:-.1em;bottom:-.1em;border-top:0;border-left:1px dashed var(--cond-line)}
.pd .editor .cond > .cond-bar:first-child,.pd .editor .cond > .cond-else .cond-tag{display:none}
.pd .editor .cond:hover > .cond-bar:first-child,.pd .editor .cond.caret > .cond-bar:first-child,.pd .editor .cond.sel > .cond-bar:first-child{display:flex}
.pd .editor .cond:hover > .cond-else .cond-tag,.pd .editor .cond.caret > .cond-else .cond-tag,.pd .editor .cond.sel > .cond-else .cond-tag{display:block}
.pd .editor .cond:has(.cond:hover):not(.sel):not(.caret) > .cond-bar:first-child,.pd .editor .cond:has(.cond:hover):not(.sel):not(.caret) > .cond-else .cond-tag{display:none}
.pd .editor .cond.cond-inline > .cond-else .cond-tag{display:none!important}
.pd .editor .ph{display:inline-block;padding:0 5px;margin:0 1px;border-radius:4px;border:1px solid var(--chip-line);background:var(--chip-bg);color:var(--chip-ink);font-family:var(--bs-font-monospace);font-size:.82em;line-height:1.35;font-style:normal;text-decoration:none;white-space:nowrap;cursor:default;user-select:all;-webkit-user-select:all;vertical-align:baseline;max-width:40ch;overflow:hidden;text-overflow:ellipsis}
.pd .editor .ph-page{background:var(--chip-page-bg);color:var(--chip-page-ink);border-color:var(--chip-page-line)}
.pd .editor .ph:hover{border-color:var(--chip-ink)}
.pd .pd-checks .list-group-item{display:flex;gap:.5rem;align-items:flex-start;font-size:.875rem}
.pd .pd-checks .list-group-item::before{content:"";flex:none;width:.5rem;height:.5rem;border-radius:50%;margin-top:.45rem;background:currentColor}
.pd .pd-overlay{position:absolute;inset:0;z-index:1070;background:rgba(0,0,0,.35);display:flex;align-items:flex-start;justify-content:center;padding:3rem 1rem;overflow:auto}
.pd .pd-overlay > .card{width:100%;max-width:40rem}
.pd .pd-overlay.wide > .card{max-width:60rem}
.pd .pd-toast{position:absolute;left:50%;bottom:1rem;transform:translateX(-50%);z-index:1075;max-width:90%}
.pd .pdf{font-family:var(--pdf-font);font-size:var(--pdf-size);line-height:1.25;color:var(--paper-ink)}
.pd .pdf p{margin:0 0 .9em}
.pd .pdf h1,.pd .pdf h2,.pd .pdf h3,.pd .pdf h4,.pd .pdf h5,.pd .pdf h6{font-family:inherit;font-weight:bold;margin:0 0 .5em;line-height:1.2;color:inherit}
.pd .pdf h1{font-size:calc(var(--pdf-size) + 6pt)}.pd .pdf h2{font-size:calc(var(--pdf-size) + 4pt)}.pd .pdf h3{font-size:calc(var(--pdf-size) + 2pt)}
.pd .pdf h4{font-size:var(--pdf-size)}.pd .pdf h5{font-size:calc(var(--pdf-size) - 2pt)}.pd .pdf h6{font-size:calc(var(--pdf-size) - 4pt)}
.pd .pdf table{border-collapse:collapse;font-size:inherit;caption-side:top}
.pd .pdf th{font-weight:bold}
.pd .pdf th:not([align]){text-align:center}
.pd .pdf table:not([cellpadding]) td,.pd .pdf table:not([cellpadding]) th{padding:0}
.pd .pdf td:not([valign]),.pd .pdf th:not([valign]){vertical-align:top}
.pd .pdf table[border="1"] td,.pd .pdf table[border="1"] th{border:.2mm solid var(--paper-ink)}
.pd .pdf hr{border:0;border-top:.2mm solid var(--paper-ink);margin:.4em 0;opacity:1;color:inherit}
.pd .pdf ul,.pd .pdf ol{margin:0 0 .9em;padding-left:2.4em}
.pd .pdf li > ul,.pd .pdf li > ol{margin-bottom:0;padding-left:4em}
.pd .pdf blockquote{margin:0 0 .9em;font-size:inherit}
.pd .pdf a{color:#0000ee;text-decoration:underline}
.pd .pdf img{vertical-align:baseline}
.pd .pdf pre{font-family:"Courier New",monospace;margin:0 0 .9em;font-size:inherit}
.pd .pdf b,.pd .pdf strong{font-weight:bold}
.pd .pd-measure{position:absolute;left:-10000px;top:0;visibility:hidden;pointer-events:none}
`;

    function injectCss() {
	if (document.getElementById('pd-style'))
	    return;
	const st = document.createElement('style');
	st.id = 'pd-style';
	st.textContent = CSS;
	document.head.appendChild(st);
    }

    //the markup of one designer; A(name): data-pd + id (unique: the labels' for)
    //CLD: 2026-11-28T16:00:00+02:00: the toolbar fits one line at 1440 px: Header / Footer, Page setup, Sample data and Checks are icons (their names in title +
    //aria-label), Preview PDF is Preview; in the step's dialog (fill) the columns' row does not wrap from md up, so the page scrolls in its column (a wrapping
    //row took the page's height)
    function markup(P, ro, fill) {
	const A = (n) => `data-pd="${n}" id="${P}${n}"`;
	const D = (n) => `data-pd="${n}"`;
	const lenField = (n, label, col) => `<div class="${col}"><label class="form-label small mb-1" for="${P}${n}">${label}</label><div class="input-group input-group-sm"><input class="form-control" ${A(n)} type="number" min="0"><span class="input-group-text u-label">mm</span></div></div>`;
	const overlay = (n, title, body, footer, wide) => `<div class="pd-overlay${wide ? ' wide' : ''}" ${D(n)} hidden role="dialog" aria-modal="true" aria-label="${title}"><div class="card shadow">
	    <div class="card-header d-flex align-items-center"><span class="fw-semibold" ${D(n + 'Title')}>${title}</span><button type="button" class="btn-close ms-auto" data-close aria-label="Close"></button></div>
	    <div class="card-body vstack gap-3">${body}</div>
	    <div class="card-footer d-flex justify-content-end gap-2">${footer}</div></div></div>`;
	const fonts = Object.entries(FONTS).map(([k, f]) => `<option value="${k}">${app.utils.escapeHtml(f[0])}</option>`).join('');
	return `
<div class="pd border rounded ${fill ? 'd-flex flex-column flex-grow-1' : ''}" style="${fill ? 'min-height:0' : ''}">
  <div class="p-2 border-bottom bg-body-tertiary flex-shrink-0 vstack gap-2 position-relative" ${D('editPanel')}>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <div class="btn-toolbar gap-2 pd-toolbar" ${D('toolbar')} role="toolbar" aria-label="Formatting" ${ro ? 'hidden' : ''}>
	<select class="form-select form-select-sm" ${D('blockSel')} aria-label="Paragraph style"><option value="">Style</option><option value="p">Paragraph</option><option value="h1">Heading 1</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option><option value="h4">Heading 4</option></select>
	<select class="form-select form-select-sm" ${D('sizeSel')} aria-label="Font size"><option value="">Size</option><option>7</option><option>8</option><option>9</option><option>10</option><option>11</option><option>12</option><option>14</option><option>16</option><option>18</option><option>22</option><option>28</option></select>
	<div class="btn-group btn-group-sm" role="group" aria-label="Text style">
	  <button class="btn btn-light border" type="button" data-cmd="bold" title="Bold"><b>B</b></button>
	  <button class="btn btn-light border" type="button" data-cmd="italic" title="Italic"><i>I</i></button>
	  <button class="btn btn-light border" type="button" data-cmd="underline" title="Underline"><u>U</u></button>
	  <button class="btn btn-light border" type="button" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
	</div>
	<input type="color" class="form-control form-control-color" ${D('colorIn')} value="#0f6e6a" title="Text color" aria-label="Text color">
	<div class="btn-group btn-group-sm" role="group" aria-label="Lists">
	  <button class="btn btn-light border" type="button" data-cmd="insertUnorderedList" title="Bulleted list">${SVG('<path d="M6 4h8M6 8h8M6 12h8"/><circle cx="2.8" cy="4" r=".8"/><circle cx="2.8" cy="8" r=".8"/><circle cx="2.8" cy="12" r=".8"/>')}</button>
	  <button class="btn btn-light border" type="button" data-cmd="insertOrderedList" title="Numbered list">${SVG('<path d="M6 4h8M6 8h8M6 12h8M2.3 2.8v2.6M1.8 7.2h1.5l-1.5 2h1.5"/>')}</button>
	</div>
	<div class="btn-group btn-group-sm" ${D('alignDd')}>
	  <button class="btn btn-light border dropdown-toggle" type="button" data-dd aria-haspopup="menu" aria-expanded="false" title="Alignment" aria-label="Alignment"><span class="dd-icon" ${D('alignIcon')}>${SVG('<path d="M2 3h12M2 6.5h8M2 10h12M2 13.5h8"/>')}</span></button>
	  <ul class="dropdown-menu shadow-sm" data-bs-popper="static" role="menu" aria-label="Alignment">
	    <li><button class="dropdown-item" type="button" role="menuitem" data-cmd="justifyLeft" data-align="left">${SVG('<path d="M2 3h12M2 6.5h8M2 10h12M2 13.5h8"/>')}<span class="flex-grow-1">Align left</span></button></li>
	    <li><button class="dropdown-item" type="button" role="menuitem" data-cmd="justifyCenter" data-align="center">${SVG('<path d="M2 3h12M4 6.5h8M2 10h12M4 13.5h8"/>')}<span class="flex-grow-1">Center</span></button></li>
	    <li><button class="dropdown-item" type="button" role="menuitem" data-cmd="justifyRight" data-align="right">${SVG('<path d="M2 3h12M6 6.5h8M2 10h12M6 13.5h8"/>')}<span class="flex-grow-1">Align right</span></button></li>
	    <li><button class="dropdown-item" type="button" role="menuitem" data-cmd="justifyFull" data-align="justify">${SVG('<path d="M2 3h12M2 6.5h12M2 10h12M2 13.5h12"/>')}<span class="flex-grow-1">Justify</span></button></li>
	  </ul>
	</div>
	<div class="btn-group btn-group-sm">
	  <button class="btn btn-light border dropdown-toggle" type="button" data-dd aria-haspopup="menu" aria-expanded="false" title="Indentation" aria-label="Indentation"><span class="dd-icon">${SVG('<path d="M2 3h12M7 6.5h7M7 10h7M2 13.5h12M2 6l2.5 2.25L2 10.5"/>')}</span></button>
	  <ul class="dropdown-menu shadow-sm" data-bs-popper="static" role="menu" aria-label="Indentation">
	    <li><button class="dropdown-item" type="button" role="menuitem" data-cmd="indent">${SVG('<path d="M2 3h12M7 6.5h7M7 10h7M2 13.5h12M2 6l2.5 2.25L2 10.5"/>')}<span class="flex-grow-1">Indent</span><span class="dd-hint">Tab</span></button></li>
	    <li><button class="dropdown-item" type="button" role="menuitem" data-cmd="outdent">${SVG('<path d="M2 3h12M7 6.5h7M7 10h7M2 13.5h12M4.5 6L2 8.25l2.5 2.25"/>')}<span class="flex-grow-1">Outdent</span><span class="dd-hint">Shift+Tab</span></button></li>
	    <li><hr class="dropdown-divider"></li>
	    <li><button class="dropdown-item" type="button" role="menuitem" data-cmd="firstLine" ${D('firstLineBtn')} aria-pressed="false">${SVG('<path d="M6 3h8M2 6.5h12M2 10h12M2 13.5h9M2 1.5v3"/>')}<span class="flex-grow-1">First-line indent</span></button></li>
	  </ul>
	</div>
	<div class="btn-group btn-group-sm" role="group" aria-label="Insert">
	  <button class="btn btn-light border" type="button" data-cmd="table" title="Insert table">${SVG('<rect x="2" y="3" width="12" height="10" rx="1"/><path d="M2 6.5h12M2 10h12M7 3v10"/>')}</button>
	  <button class="btn btn-light border" type="button" data-cmd="rtable" title="Insert a table with a row repeated for each item of a list">${SVG('<rect x="2" y="3" width="12" height="10" rx="1"/><path d="M2 6.5h12"/><path d="M5 10.2h6M9.6 8.8l1.4 1.4-1.4 1.4"/>')}</button>
	  <button class="btn btn-light border font-monospace fw-semibold" type="button" data-cmd="cond" title="Conditional block: wrap the selection, the selected image, or insert a new block">if</button>
	  <button class="btn btn-light border" type="button" data-cmd="hr" title="Horizontal line">${SVG('<path d="M2 8h12"/>')}</button>
	  <button class="btn btn-light border" type="button" data-cmd="image" title="Insert an image of the project's Files">${SVG('<rect x="2" y="3" width="12" height="10" rx="1"/><path d="M2.5 11.5l3.5-3.5 3 3 2-2 2.5 2.5"/><circle cx="10.5" cy="6" r="1"/>')}</button>
	  <button class="btn btn-light border" type="button" data-cmd="link" title="Link">${SVG('<path d="M7 9a3 3 0 0 0 4.2 0l2-2a3 3 0 0 0-4.2-4.2l-.8.8M9 7a3 3 0 0 0-4.2 0l-2 2a3 3 0 0 0 4.2 4.2l.8-.8"/>')}</button>
	</div>
	<div class="btn-group btn-group-sm">
	  <button class="btn btn-light border dropdown-toggle font-monospace" type="button" data-dd="vars" aria-haspopup="menu" aria-expanded="false" title="Insert a variable or the page number">{{ }}</button>
	  <ul class="dropdown-menu shadow-sm pd-vars small" data-bs-popper="static" role="menu" aria-label="Insert a variable" ${D('varsMenu')}></ul>
	</div>
	<div class="btn-group btn-group-sm" role="group" aria-label="Source">
	  <button class="btn btn-light border" type="button" data-cmd="removeFormat" title="Clear formatting">${SVG('<path d="M4 3h8M8 3l-2 10M3 13l10-10"/>')}</button>
	  <button class="btn btn-light border font-monospace" type="button" data-cmd="source" title="Edit the HTML of the section you are in">&lt;/&gt;</button>
	</div>
	<div class="btn-group btn-group-sm" role="group" aria-label="Page sections">
	  <button class="btn btn-light border" type="button" ${D('headerTgl')} aria-pressed="true" title="Header: show or hide it on every page" aria-label="Header">${SVG('<rect x="3" y="1.5" width="10" height="13" rx="1"/><path d="M3.5 2h9v3.5h-9z" fill="currentColor" stroke="none" opacity=".5"/><path d="M5.5 8.5h5M5.5 11h5"/>')}</button>
	  <button class="btn btn-light border" type="button" ${D('footerTgl')} aria-pressed="true" title="Footer: show or hide it on every page" aria-label="Footer">${SVG('<rect x="3" y="1.5" width="10" height="13" rx="1"/><path d="M3.5 10.5h9V14h-9z" fill="currentColor" stroke="none" opacity=".5"/><path d="M5.5 5h5M5.5 7.5h5"/>')}</button>
	</div>
      </div>
      <div class="d-flex flex-wrap gap-2 ms-auto">
	<select class="form-select form-select-sm w-auto" ${D('zoomSel')} aria-label="Zoom" title="Zoom"><option value="fit">Fit width</option><option value="0.5">50%</option><option value="0.75">75%</option><option value="1">100%</option><option value="1.25">125%</option><option value="1.5">150%</option></select>
	<div class="btn-group btn-group-sm" role="group" aria-label="Document">
	  <button class="btn btn-light border" type="button" ${D('pageBtn')} title="Page setup: size, orientation, margins, font" aria-label="Page setup"><i class="bi bi-file-earmark-ruled"></i></button>
	  <button class="btn btn-light border" type="button" ${D('dataBtn')} title="Sample data: the variables of the preview, as JSON" aria-label="Sample data"><i class="bi bi-braces"></i></button>
	  <button class="btn btn-light border" type="button" ${D('checksBtn')} title="Layout checks" aria-label="Layout checks"><i class="bi bi-clipboard-check"></i></button>
	</div>
	<button class="btn btn-primary btn-sm" type="button" ${D('pdfBtn')} title="Make the PDF with the sample data, in a new tab"><i class="bi bi-file-earmark-pdf me-1"></i>Preview</button>
      </div>
    </div>

    <div class="card shadow tpicker" ${D('tablePicker')} hidden role="dialog" aria-label="Insert table">
      <div class="card-body vstack gap-2 p-3">
	<div class="tp-grid" ${D('tpGrid')} aria-hidden="true"></div>
	<div class="small text-body-secondary" ${D('tpLabel')}></div>
	<div class="row g-2">
	  <div class="col-6"><label class="form-label small mb-1" for="${P}tpCols">Columns</label><input class="form-control form-control-sm" ${A('tpCols')} type="number" min="1" max="20" value="3"></div>
	  <div class="col-6"><label class="form-label small mb-1" for="${P}tpRows">Rows</label><input class="form-control form-control-sm" ${A('tpRows')} type="number" min="1" max="200" value="3"></div>
	</div>
	<div class="form-check mb-0" title="An extra first row that repeats at the top of every page"><input class="form-check-input" type="checkbox" ${A('tpHead')}><label class="form-check-label small" for="${P}tpHead">Add header row</label></div>
	<div class="form-check mb-0"><input class="form-check-input" type="checkbox" ${A('tpBorder')} checked><label class="form-check-label small" for="${P}tpBorder">Borders</label></div>
	<button class="btn btn-primary btn-sm" ${D('tpInsert')} type="button">Insert table</button>
      </div>
    </div>

    <div class="input-group input-group-sm" ${D('linkRow')} hidden>
      <input class="form-control" ${D('linkUrl')} type="text" placeholder="https://example.com, mailto:… or {{ variable }}" aria-label="Link address">
      <button class="btn btn-primary" ${D('linkApply')} type="button">Add link</button>
      <button class="btn btn-light border" ${D('linkCancel')} type="button">Cancel</button>
    </div>

    <div class="ctx-bar d-flex flex-wrap align-items-end gap-2 p-2 border rounded bg-body" ${D('imgRow')} hidden>
      <span class="ctx-label text-uppercase fw-semibold text-body-secondary">Image</span>
      <div class="field-sm"><label class="form-label small mb-1" for="${P}imgW">Width</label><div class="input-group input-group-sm"><input class="form-control" ${A('imgW')} type="number" min="0" step="0.5"><span class="input-group-text u-label">mm</span></div></div>
      <div class="field-sm"><label class="form-label small mb-1" for="${P}imgH">Height</label><div class="input-group input-group-sm"><input class="form-control" ${A('imgH')} type="number" min="0" step="0.5"><span class="input-group-text u-label">mm</span></div></div>
      <button class="btn btn-light border btn-sm" ${D('imgFull')} type="button">Full width</button>
      <button class="btn btn-light border btn-sm" ${D('imgOrig')} type="button">Original size</button>
      <button class="btn btn-outline-danger btn-sm" ${D('imgDel')} type="button">Remove</button>
    </div>

    <div class="ctx-bar cond-row d-flex flex-wrap align-items-center gap-2 p-2 border rounded bg-body" ${D('condRow')} hidden>
      <label class="ctx-label text-uppercase fw-semibold text-body-secondary mb-0" for="${P}condExpr">Show when</label>
      <input class="form-control form-control-sm font-monospace flex-grow-1" style="min-width:16rem;max-width:36rem" ${A('condExpr')} type="text" placeholder="{{ invoice.paid }}" title="Shown when the value is not empty, false or 0">
      <div class="cond-actions d-flex flex-wrap align-items-center gap-2 ms-auto">
	<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" ${A('condElse')}><label class="form-check-label small" for="${P}condElse" title="Add a part that prints when the condition is false">Otherwise part</label></div>
	<div class="btn-group btn-group-sm" role="group" aria-label="Block">
	  <button class="btn btn-light border" ${D('condUnwrap')} type="button" title="Keep the content, always shown">Remove condition</button>
	  <button class="btn btn-outline-danger" ${D('condDel')} type="button" title="Delete the block and its content">Delete block</button>
	</div>
      </div>
    </div>

    <div class="ctx-bar d-flex flex-wrap align-items-center gap-2 p-2 border rounded bg-body" ${D('rowBar')} hidden>
      <label class="ctx-label text-uppercase fw-semibold text-body-secondary mb-0" for="${P}rowEach">Repeat this row for each item of</label>
      <input class="form-control form-control-sm font-monospace" style="width:22rem" ${A('rowEach')} type="text" placeholder="{{ invoice.items }}">
      <label class="ctx-label text-uppercase fw-semibold text-body-secondary mb-0" for="${P}rowAs">as</label>
      <input class="form-control form-control-sm font-monospace" style="width:8rem" ${A('rowAs')} type="text" placeholder="item">
      <span class="small text-body-secondary">in its cells: <code>{{ <span ${D('rowAsHint')}>item</span>.field }}</code>, <code>{{ loop.number }}</code></span>
      <button class="btn btn-light border btn-sm ms-auto" ${D('rowStop')} type="button">Stop repeating</button>
    </div>
  </div>

  <div class="row g-0 ${fill ? 'flex-grow-1 flex-md-nowrap overflow-auto' : ''}" style="${fill ? 'min-height:0' : ''}">
    <div class="col-md-9 d-flex flex-column" style="${fill ? 'min-height:0' : ''}">
      <div class="pd-desk flex-grow-1" ${D('desk')} aria-label="Page" style="${fill ? 'min-height:0' : 'max-height:70vh'}">
	<div class="pd-tbllayer" ${D('tblLayer')}></div>
	<div class="pd-imgframe" ${D('imgFrame')} hidden><span class="h nw" data-dir="nw"></span><span class="h ne" data-dir="ne"></span><span class="h sw" data-dir="sw"></span><span class="h se" data-dir="se"></span><span class="dim"></span></div>
	<div class="pd-sheet" ${D('sheet')}>
	  <section class="zone zone-h" ${D('zoneHeader')} aria-label="Header">
	    <div class="editor pdf" ${D('ed-header')} contenteditable="${ro ? 'false' : 'true'}" spellcheck="false" data-empty="Header" aria-label="Header content"></div>
	    <div class="zone-off-label" hidden>Header off</div>
	  </section>
	  <section class="zone-b" ${D('zoneBody')} aria-label="Body">
	    <div class="editor pdf" ${D('ed-body')} contenteditable="${ro ? 'false' : 'true'}" spellcheck="false" aria-label="Body content"></div>
	  </section>
	  <section class="zone zone-f" ${D('zoneFooter')} aria-label="Footer">
	    <div class="editor pdf" ${D('ed-footer')} contenteditable="${ro ? 'false' : 'true'}" spellcheck="false" data-empty="Footer" aria-label="Footer content"></div>
	    <div class="zone-off-label" hidden>Footer off</div>
	  </section>
	  <div class="content-box" ${D('boxHeader')}></div>
	  <div class="content-box" ${D('boxFooter')}></div>
	  <div ${D('breaks')}></div>
	  <div class="zone-handle" ${D('handleHeader')} role="separator" aria-orientation="horizontal" tabindex="${ro ? '-1' : '0'}" aria-label="Header height: drag to resize"><span class="size-tag"></span></div>
	  <div class="zone-handle below" ${D('handleFooter')} role="separator" aria-orientation="horizontal" tabindex="${ro ? '-1' : '0'}" aria-label="Footer height: drag to resize"><span class="size-tag"></span></div>
	</div>
      </div>
      <div class="d-flex gap-3 px-3 py-1 border-top small text-body-secondary flex-shrink-0">
	<span class="flex-grow-1" ${D('editHint')}></span><span class="text-nowrap" ${D('metaText')}></span>
      </div>
      <div class="px-3 py-1 border-top small text-danger flex-shrink-0" ${D('problem')} hidden></div>
    </div>
    <div class="col-md-3 p-3 border-start" ${D('props')} style="${fill ? 'height:100%; overflow:auto' : 'max-height:70vh; overflow:auto'}"></div>
  </div>

  <div class="pd-measure pdf" ${D('measure')} aria-hidden="true"></div>

  <ul class="dropdown-menu shadow pd-tmenu" ${D('tableMenu')} role="menu" aria-label="Table" hidden>
    <li class="sub"><button class="dropdown-item has-sub" type="button" role="menuitem" aria-haspopup="menu" aria-expanded="false">${SVG('<rect x="2" y="3" width="12" height="10" rx="1"/><path d="M8 5.5v5M5.5 8h5"/>')}<span>Insert</span></button>
      <ul class="dropdown-menu sub-menu shadow" role="menu" aria-label="Insert">
	<li><button class="dropdown-item" type="button" role="menuitem" data-top="rowAbove">${SVG('<rect x="2" y="8" width="12" height="6" rx="1"/><path d="M8 2v4M6 4h4"/>')}<span>Insert Rows Above</span></button></li>
	<li><button class="dropdown-item" type="button" role="menuitem" data-top="rowBelow">${SVG('<rect x="2" y="2" width="12" height="6" rx="1"/><path d="M8 10v4M6 12h4"/>')}<span>Insert Rows Below</span></button></li>
	<li><hr class="dropdown-divider"></li>
	<li><button class="dropdown-item" type="button" role="menuitem" data-top="colLeft">${SVG('<rect x="8" y="2" width="6" height="12" rx="1"/><path d="M2 8h4M4 6v4"/>')}<span>Insert Columns to the Left</span></button></li>
	<li><button class="dropdown-item" type="button" role="menuitem" data-top="colRight">${SVG('<rect x="2" y="2" width="6" height="12" rx="1"/><path d="M10 8h4M12 6v4"/>')}<span>Insert Columns to the Right</span></button></li>
      </ul></li>
    <li class="sub"><button class="dropdown-item has-sub" type="button" role="menuitem" aria-haspopup="menu" aria-expanded="false">${SVG('<rect x="2" y="3" width="12" height="10" rx="1"/><path d="M5.5 8h5"/>')}<span>Delete</span></button>
      <ul class="dropdown-menu sub-menu shadow" role="menu" aria-label="Delete">
	<li><button class="dropdown-item" type="button" role="menuitem" data-top="rowDel">${SVG('<rect x="2" y="5" width="12" height="6" rx="1"/><path d="M6 7l4 2M10 7l-4 2"/>')}<span>Delete Rows</span></button></li>
	<li><button class="dropdown-item" type="button" role="menuitem" data-top="colDel">${SVG('<rect x="5" y="2" width="6" height="12" rx="1"/><path d="M7 6l2 4M9 6l-2 4"/>')}<span>Delete Columns</span></button></li>
	<li><hr class="dropdown-divider"></li>
	<li><button class="dropdown-item text-danger" type="button" role="menuitem" data-top="del">${SVG('<rect x="2" y="3" width="12" height="10" rx="1"/><path d="M2 6.5h12M7 3v10M5 9l6 3M11 9l-6 3"/>')}<span>Delete Table</span></button></li>
      </ul></li>
    <li><hr class="dropdown-divider"></li>
    <li><button class="dropdown-item" type="button" role="menuitemcheckbox" aria-checked="false" data-top="repeat">${SVG('<rect x="2" y="3" width="12" height="10" rx="1"/><path d="M5 8.2h6M9.6 6.8l1.4 1.4-1.4 1.4"/>')}<span>Repeat Row for Each Item</span></button></li>
    <li><button class="dropdown-item" type="button" role="menuitem" data-dlg="cell">${SVG('<rect x="2" y="3" width="12" height="10" rx="1"/><rect x="5" y="6" width="6" height="4" fill="currentColor" stroke="none" opacity=".35"/>')}<span>Cell Properties…</span></button></li>
    <li><button class="dropdown-item" type="button" role="menuitem" data-dlg="table">${SVG('<rect x="2" y="3" width="12" height="10" rx="1"/><path d="M2 6.5h12M2 10h12M7 3v10"/>')}<span>Table Properties…</span></button></li>
    <li><hr class="dropdown-divider"></li>
    <li><button class="dropdown-item" type="button" role="menuitemcheckbox" aria-checked="false" data-top="header">${SVG('<rect x="2" y="3" width="12" height="10" rx="1"/><path d="M2 6.5h12"/><path d="M2 3.5h12v3H2z" fill="currentColor" stroke="none" opacity=".35"/>')}<span>Header Row</span></button></li>
    <li><button class="dropdown-item" type="button" role="menuitemcheckbox" aria-checked="false" data-top="border">${SVG('<rect x="2" y="3" width="12" height="10"/><path d="M2 8h12M8 3v10"/>')}<span>Borders</span></button></li>
    <li><button class="dropdown-item" type="button" role="menuitem" data-top="distribute">${SVG('<path d="M2 3v10M14 3v10M6 3v10M10 3v10M3.5 8h1M11.5 8h1"/>')}<span>Distribute Columns Evenly</span></button></li>
  </ul>

  <div class="toast align-items-center text-bg-dark border-0 pd-toast" ${D('toast')} role="status" aria-live="polite" aria-atomic="true"><div class="toast-body" ${D('toastBody')}></div></div>

  ${overlay('pageModal', 'Page setup', `
      <fieldset>
	<legend class="fs-6 fw-semibold mb-2">Sheet</legend>
	<div class="row g-2">
	  <div class="col-6 col-md-3"><label class="form-label small mb-1" for="${P}f-format">Format</label><select class="form-select form-select-sm" ${A('f-format')}><option value="A4">A4</option><option value="A5">A5</option><option value="LETTER">Letter</option><option value="LEGAL">Legal</option></select></div>
	  <div class="col-6 col-md-3"><label class="form-label small mb-1" for="${P}f-orient">Orientation</label><select class="form-select form-select-sm" ${A('f-orient')}><option value="P">Portrait</option><option value="L">Landscape</option></select></div>
	  <div class="col-6 col-md-3"><label class="form-label small mb-1" for="${P}f-font">Font</label><select class="form-select form-select-sm" ${A('f-font')}>${fonts}</select></div>
	  <div class="col-6 col-md-3"><label class="form-label small mb-1" for="${P}f-size">Base size</label><div class="input-group input-group-sm"><input class="form-control" ${A('f-size')} type="number" min="4" max="72" step="0.5"><span class="input-group-text">pt</span></div></div>
	</div>
	<div class="form-text">Core fonts print Western European text only (no ș, ț, Greek or Cyrillic); DejaVu and Free fonts print them.</div>
      </fieldset>
      <fieldset>
	<legend class="fs-6 fw-semibold mb-2">Margins <span class="small fw-normal text-body-secondary">(you can also drag the header and footer edges on the page)</span></legend>
	<div class="row g-2">
	  ${lenField('f-mt', 'Top (body starts)', 'col-6 col-md-4')}${lenField('f-mb', 'Bottom (page break)', 'col-6 col-md-4')}${lenField('f-ml', 'Left', 'col-6 col-md-4')}
	  ${lenField('f-mr', 'Right', 'col-6 col-md-4')}${lenField('f-mh', 'Header from top', 'col-6 col-md-4')}${lenField('f-mf', 'Footer from bottom', 'col-6 col-md-4')}
	</div>
      </fieldset>
      <fieldset>
	<legend class="fs-6 fw-semibold mb-2">Editing</legend>
	<div class="row g-2">
	  <div class="col-6 col-md-4"><label class="form-label small mb-1" for="${P}f-unit">Units</label><select class="form-select form-select-sm" ${A('f-unit')}><option value="mm">Millimetres (mm)</option><option value="in">Inches (in)</option></select></div>
	  ${lenField('f-indent', 'Indent step (Tab)', 'col-6 col-md-4')}
	</div>
      </fieldset>`,
      `<button type="button" class="btn btn-light border btn-sm me-auto" ${D('sampleBtn')} ${ro ? 'hidden' : ''}>Replace with the sample invoice</button><button type="button" class="btn btn-primary btn-sm" data-close>Done</button>`)}

  ${overlay('dataModal', 'Sample data', `
      <textarea class="form-control form-control-sm font-monospace" ${D('dataSrc')} rows="18" spellcheck="false" aria-label="Sample data" ${ro ? 'readonly' : ''}></textarea>
      <div class="form-text mt-0">The variables of <b>Preview PDF</b>, as a JSON object: <code>{ "invoice": { "number": "…", "items": [ … ] } }</code> fills <code>{{ invoice.number }}</code> and the rows repeated for each item of <code>{{ invoice.items }}</code>. When the workflow runs, the step uses the real variables.</div>`,
      `<button type="button" class="btn btn-primary btn-sm" data-close>Done</button>`)}

  ${overlay('checksModal', 'Layout checks', `<ul class="list-group pd-checks" ${D('checks')} aria-live="polite"></ul>`, `<button type="button" class="btn btn-primary btn-sm" data-close>Close</button>`)}

  ${overlay('srcModal', 'HTML', `
      <textarea class="form-control form-control-sm font-monospace" ${D('htmlSrc')} rows="20" spellcheck="false" aria-label="HTML source"></textarea>
      <div class="form-text mt-0">Tags and styles tc-lib-pdf can't print are removed when saved; Checks lists what was removed.</div>`,
      `<button type="button" class="btn btn-light border btn-sm" data-close>Cancel</button><button type="button" class="btn btn-primary btn-sm" ${D('srcApply')}>Apply</button>`, true)}

  ${overlay('imageModal', 'Insert an image', `
      <div class="d-flex gap-2 align-items-center"><button type="button" class="btn btn-sm btn-outline-primary" ${D('imgUpload')}><i class="bi bi-upload me-1"></i>Upload</button><span class="small text-muted" ${D('imgUploadMsg')}></span><input type="file" class="d-none" ${D('imgFile')} accept=".png,.jpg,.jpeg,.gif"></div>
      <div class="d-flex flex-wrap gap-2" ${D('gallery')}></div>
      <div class="form-text mt-0">PNG, JPEG or GIF images of the project's Files; they are embedded in the PDF.</div>`,
      `<button type="button" class="btn btn-light border btn-sm" data-close>Cancel</button>`)}

  ${overlay('cellModal', 'Cell properties', `
      <div><label class="form-label small mb-1" for="${P}cpScope">Apply to</label><select class="form-select form-select-sm" ${A('cpScope')}><option value="cell">This cell</option><option value="row">This row</option><option value="col">This column</option><option value="table">The whole table</option></select></div>
      <fieldset>
	<legend class="fs-6 fw-semibold mb-2">Background</legend>
	<div class="d-flex flex-wrap align-items-center gap-2">
	  <input type="color" class="form-control form-control-color" ${D('cpBg')} value="#e6eeed" aria-label="Background colour">
	  <div class="d-flex flex-wrap gap-1 pd-swatches" ${D('cpSwatches')} role="group" aria-label="Colour presets"></div>
	  <div class="form-check mb-0 ms-sm-2"><input class="form-check-input" type="checkbox" ${A('cpBgNone')}><label class="form-check-label small" for="${P}cpBgNone">No background</label></div>
	</div>
      </fieldset>
      <fieldset>
	<legend class="fs-6 fw-semibold mb-2">Padding</legend>
	<div class="form-check mb-1"><input class="form-check-input" type="checkbox" ${A('cpPadDefault')}><label class="form-check-label small" for="${P}cpPadDefault">Use the table's cell padding (<span ${D('cpTablePad')}></span>)</label></div>
	<div class="form-check mb-2"><input class="form-check-input" type="checkbox" ${A('cpPadSame')}><label class="form-check-label small" for="${P}cpPadSame">Same on all sides</label></div>
	<div class="row g-2">${lenField('cpPadTop', 'Top', 'col-6 col-sm-3')}${lenField('cpPadRight', 'Right', 'col-6 col-sm-3')}${lenField('cpPadBottom', 'Bottom', 'col-6 col-sm-3')}${lenField('cpPadLeft', 'Left', 'col-6 col-sm-3')}</div>
      </fieldset>`,
      `<button type="button" class="btn btn-light border btn-sm" data-close>Cancel</button><button type="button" class="btn btn-primary btn-sm" ${D('cpApply')}>Apply</button>`)}

  ${overlay('tableModal', 'Table properties', `
      <div class="row g-2">
	<div class="col-6"><label class="form-label small mb-1" for="${P}tpColW">Width of this column</label><div class="input-group input-group-sm"><input class="form-control" ${A('tpColW')} type="number" min="3" max="97" step="0.5"><span class="input-group-text">%</span></div><div class="form-text">The other columns adjust.</div></div>
	<div class="col-6"><label class="form-label small mb-1" for="${P}tpPad">Cell padding, all cells</label><div class="input-group input-group-sm"><input class="form-control" ${A('tpPad')} type="number" min="0" max="40" step="1"><span class="input-group-text">px</span></div><div class="form-text" ${D('tpPadHint')}></div></div>
      </div>
      <div class="form-check mb-0"><input class="form-check-input" type="checkbox" ${A('tpHeadChk')}><label class="form-check-label small" for="${P}tpHeadChk">Header row (repeats at the top of every page)</label></div>
      <div class="form-check mb-0"><input class="form-check-input" type="checkbox" ${A('tpBorderChk')}><label class="form-check-label small" for="${P}tpBorderChk">Borders</label></div>`,
      `<button type="button" class="btn btn-light border btn-sm" data-close>Cancel</button><button type="button" class="btn btn-primary btn-sm" ${D('tpApply')}>Apply</button>`)}
</div>`;
    }

    //one designer in `container`: the editor's state and functions in this closure (from the stand-alone page designer)
    function create(container, o) {
	injectCss();
	const P = 'pd' + (++seq) + '-';
	const ro = !!o.readonly;
	container.innerHTML = markup(P, ro, !!o.fill);
	const root = container.firstElementChild;
	const $ = (n) => root.querySelector(`[data-pd="${n}"]`);
	const $$ = (s) => root.querySelectorAll(s);

	//listeners outside the designer (document, window): removed once the designer is no longer on the page (its dialog closed)
	const outside = [];
	const listen = (target, type, fn, opts) => {
	    const h = (e) => {
		if (!root.isConnected) {
		    outside.forEach(([t, ty, f, op]) => t.removeEventListener(ty, f, op));
		    outside.length = 0;
		    return;
		}
		fn(e);
	    };
	    target.addEventListener(type, h, opts);
	    outside.push([ target, type, h, opts ]);
	};

	let ui = { unit: 'mm', zoom: 'fit' };
	try { ui = Object.assign(ui, JSON.parse(localStorage.getItem(UI_KEY)) || {}); } catch (e) { }
	const saveUi = () => { try { localStorage.setItem(UI_KEY, JSON.stringify(ui)); } catch (e) { } };

	let state = { header: '', body: '', footer: '', sample: '', page: clone(PAGE) };
	let emitted = null;
	let leftTab = 'body', sourceMode = false, savedRange = null;
	const editors = { header: $('ed-header'), body: $('ed-body'), footer: $('ed-footer') };
	const isSection = (t) => t === 'header' || t === 'body' || t === 'footer';

	/* ---------- the value (models\PdfDesign) ---------- */
	function toState(v) {
	    const p = (v && v.page) || {}, m = p.margins || {};
	    const len = (x, d) => (x === undefined || x === null || x === '' || !isFinite(Number(x))) ? d : Number(x);
	    return {
		header: String((v && v.header) || ''), body: String((v && v.body) || ''), footer: String((v && v.footer) || ''), sample: String((v && v.sample) || ''),
		page: {
		    format: FORMATS[p.format] ? p.format : PAGE.format, orient: p.orientation === 'L' ? 'L' : 'P',
		    mt: len(m.top, PAGE.mt), mb: len(m.bottom, PAGE.mb), ml: len(m.left, PAGE.ml), mr: len(m.right, PAGE.mr), mh: len(m.header, PAGE.mh), mf: len(m.footer, PAGE.mf),
		    font: FONTS[p.font] ? p.font : PAGE.font, size: len(p.size, PAGE.size), indent: len(p.indent, PAGE.indent),
		    headerOn: p.showHeader !== false, footerOn: p.showFooter !== false,
		},
	    };
	}
	//the value of the current state: the sections' HTML cleaned (sanitize)
	function emit() {
	    const p = state.page;
	    emitted = {
		page: {
		    format: p.format, orientation: p.orient,
		    margins: { top: r2(num(p.mt)), bottom: r2(num(p.mb)), left: r2(num(p.ml)), right: r2(num(p.mr)), header: r2(num(p.mh)), footer: r2(num(p.mf)) },
		    font: p.font, size: num(p.size), indent: r2(num(p.indent)), showHeader: p.headerOn !== false, showFooter: p.footerOn !== false,
		},
		header: sanitize(state.header).html, body: sanitize(state.body).html, footer: sanitize(state.footer).html, sample: state.sample,
	    };
	    if (o.onChange)
		o.onChange(emitted);
	    showProblem();
	    return emitted;
	}

	/* ---------- Sanitizer: what tc-lib-pdf prints ---------- */
	function sanitize(html) {
	    const warn = new Set();
	    const tpl = document.createElement('template');
	    tpl.innerHTML = html || '';
	    const walk = (parent) => {
		[ ...parent.childNodes ].forEach((n) => {
		    if (n.nodeType === 8) { n.remove(); return; }
		    if (n.nodeType !== 1) return;
		    walk(n);
		    const tag = n.tagName.toLowerCase();
		    if (!TAGS.has(tag)) {
			if (DROP.has(tag)) { warn.add(`Removed <code>&lt;${tag}&gt;</code> and its content; tc-lib-pdf can't print it.`); n.remove(); return; }
			if (!SILENT_UNWRAP.has(tag)) warn.add(`Replaced <code>&lt;${tag}&gt;</code> with its text; tc-lib-pdf doesn't print that tag.`);
			n.replaceWith(...n.childNodes); return;
		    }
		    [ ...n.attributes ].forEach((a) => {
			const name = a.name.toLowerCase();
			if (tag === 'tr' && (name === 'data-each' || name === 'data-as')) return;
			if (name === 'style') {
			    const kept = [], dropped = [];
			    a.value.split(';').forEach((d) => {
				const i = d.indexOf(':'); if (i < 0) return;
				const p = d.slice(0, i).trim().toLowerCase(), v = d.slice(i + 1).trim();
				if (!p || !v) return;
				if (p === 'font-family' && !FONTS[v.replace(/["']/g, '').trim().toLowerCase()]) { dropped.push(p); return; }
				(PROPS.has(p) && !/url\s*\(|[<>"\\]/i.test(v) ? kept : dropped).push(p + ':' + v);
			    });
			    dropped.forEach((d) => warn.add(`Dropped CSS <code>${app.utils.escapeHtml(d.split(':')[0])}</code>; tc-lib-pdf ignores it.`));
			    if (kept.length) n.setAttribute('style', kept.join(';')); else n.removeAttribute('style');
			} else if (!ATTRS.has(name)) {
			    if (!NOISE_ATTRS.test(name)) warn.add(`Removed attribute <code>${app.utils.escapeHtml(name)}</code>.`);
			    n.removeAttribute(a.name);
			} else if (name === 'face' && !FONTS[a.value.trim().toLowerCase()]) {
			    n.removeAttribute(a.name);
			} else if (name === 'href' && !/^(https?:\/\/|mailto:|\{\{)/i.test(a.value.trim())) {
			    warn.add('Removed a link that is not https://, http://, mailto: or an expression.');
			    n.removeAttribute(a.name);
			}
		    });
		    if (tag === 'img' && !IMAGE_SRC.test(n.getAttribute('src') || '')) { warn.add('Removed an image that is not a file of the project (insert images with the image button).'); n.remove(); return; }
		    fixAlign(n);
		    if (tag === 'span' && !n.attributes.length) n.replaceWith(...n.childNodes);
		});
	    };
	    walk(tpl.content);
	    unquote(tpl.content);
	    let out = tpl.innerHTML.replace(/<(br|hr)\s*\/?>/gi, '<$1 />').replace(/<img([^>]*?)\s*\/?>/gi, '<img$1 />').trim();
	    if (out === '<br />') out = '';
	    return { html: out, warn: [ ...warn ] };
	}

	function pretty(html) {
	    return html.replace(/\s*\n\s*/g, '').replace(/(<\/(p|div|h[1-6]|tr|thead|table|ul|ol|li|blockquote|pre)>|<hr \/>)/gi, '$1\n')
		.replace(/(<(table|thead|ul|ol)[^>]*>)/gi, '$1\n').replace(/\n{2,}/g, '\n').trim();
	}

	/* ---------- Sample data (the preview's variables) ---------- */
	let dataError = null;
	function parseData() {
	    const src = (state.sample || '').trim();
	    dataError = null;
	    if (!src) return {};
	    try {
		const d = JSON.parse(src);
		if (d && typeof d === 'object' && !Array.isArray(d)) return d;
		dataError = 'Sample data must be a JSON object, like <code>{"invoice": { … }}</code>.';
	    } catch (e) { dataError = 'Sample data isn\'t valid JSON (' + app.utils.escapeHtml(e.message) + ').'; }
	    return {};
	}
	//the lists in the sample data (paths, three levels deep at most): [ { path, fields } ]
	function listsIn(d, path, depth, out) {
	    out = out || [];
	    Object.keys(d || {}).forEach((k) => {
		if (!/^[A-Za-z_$][\w$]*$/.test(k)) return;
		const v = d[k], p = path ? path + '.' + k : k;
		if (Array.isArray(v)) {
		    const f = [];
		    v.slice(0, 50).forEach((r) => { if (r && typeof r === 'object' && !Array.isArray(r)) Object.keys(r).forEach((x) => { if (!f.includes(x) && /^[A-Za-z_$][\w$]*$/.test(x)) f.push(x); }); });
		    out.push({ path: p, fields: f });
		} else if (v && typeof v === 'object' && (depth || 0) < 2) {
		    listsIn(v, p, (depth || 0) + 1, out);
		}
	    });
	    return out;
	}

	/* ---------- Conditions: {{#if expr}} … {{else}} … {{/if}} ---------- */
	function condMarkup(expr, body, elseBody, inline) {
	    const t = inline ? 'span' : 'div';
	    return `<${t} class="cond${inline ? ' cond-inline' : ''}" data-expr="${app.utils.escapeHtml(decodeEnt(expr).trim())}">`
		+ `<${t} class="cond-bar" contenteditable="false"></${t}><${t} class="cond-body">${body}</${t}>`
		+ (elseBody != null ? `<${t} class="cond-bar cond-else" contenteditable="false"></${t}><${t} class="cond-body">${elseBody}</${t}>` : '')
		+ `</${t}>`;
	}
	function condToEditor(html) {
	    let m, guard = 0;
	    while ((m = html.match(COND_RE)) && guard++ < 500) {
		const inline = !BLOCK_TAG_RE.test(m[2] + (m[3] || ''));
		html = html.slice(0, m.index) + condMarkup(m[1], m[2], m[3], inline) + html.slice(m.index + m[0].length);
	    }
	    return html;
	}
	function makeCond(expr, inline, body) {
	    const tpl = document.createElement('template');
	    tpl.innerHTML = condMarkup(expr, body || '', undefined, inline);
	    return document.importNode(tpl.content.firstChild, true);
	}
	function decorateConds(rootEl) {
	    rootEl.querySelectorAll('.cond').forEach((el) => {
		const t = el.classList.contains('cond-inline') ? 'span' : 'div';
		if (!el.firstElementChild || !el.firstElementChild.classList.contains('cond-bar')) {
		    const b = document.createElement(t); b.className = 'cond-bar'; b.contentEditable = 'false'; el.prepend(b);
		}
		const kids = [ ...el.children ];
		kids[0].innerHTML = `<span class="cond-tag">IF</span><span class="cond-expr">${app.utils.escapeHtml(el.dataset.expr || '(no condition)')}</span>`;
		kids[0].title = ro ? 'Shown when this is not empty' : 'Click to change the condition';
		kids.slice(1).forEach((k) => { if (k.classList.contains('cond-bar')) k.innerHTML = '<span class="cond-tag">OTHERWISE</span>'; });
	    });
	}

	/* ---------- Placeholders: expressions as chips, stored as text ---------- */
	function chip(ph) {
	    const s = app.utils.el('span', 'ph'); s.contentEditable = 'false'; s.dataset.ph = ph;
	    if (PH_LABEL[ph]) { s.classList.add('ph-page'); s.textContent = PH_LABEL[ph]; s.title = ph + ' · filled in when the PDF is made'; }
	    else { s.textContent = ph.slice(2, -2).trim() || ' '; s.title = ph; }
	    return s;
	}
	function chipify(rootEl) {
	    const made = [], nodes = [];
	    const w = document.createTreeWalker(rootEl, NodeFilter.SHOW_TEXT, {
		acceptNode: (n) => (n.parentElement && n.parentElement.closest('.ph, .cond-bar')) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT,
	    });
	    while (w.nextNode()) nodes.push(w.currentNode);
	    nodes.forEach((t) => {
		const txt = t.nodeValue; PH_RE.lastIndex = 0;
		if (!PH_RE.test(txt)) return;
		const frag = document.createDocumentFragment(); let last = 0, m; PH_RE.lastIndex = 0;
		while ((m = PH_RE.exec(txt))) {
		    if (m.index > last) frag.appendChild(document.createTextNode(txt.slice(last, m.index)));
		    const c = chip(m[0]); frag.appendChild(c); made.push(c);
		    last = m.index + m[0].length;
		}
		if (last < txt.length) frag.appendChild(document.createTextNode(txt.slice(last)));
		t.replaceWith(frag);
	    });
	    return made;
	}
	function setEditor(ed, html) { ed.innerHTML = condToEditor(html || ''); unquote(ed); chipify(ed); decorateConds(ed); }
	function fromEditor(ed) {
	    const c = ed.cloneNode(true);
	    c.querySelectorAll('.ph').forEach((s) => s.replaceWith(document.createTextNode(s.dataset.ph)));
	    c.querySelectorAll('.curcell').forEach((x) => { x.classList.remove('curcell'); if (!x.className) x.removeAttribute('class'); });
	    [ ...c.querySelectorAll('.cond') ].reverse().forEach((el) => {
		const bodies = [ ...el.children ].filter((x) => x.classList.contains('cond-body'));
		const frag = document.createDocumentFragment();
		frag.append('{{#if ' + (el.dataset.expr || '') + '}}');
		if (bodies[0]) frag.append(...bodies[0].childNodes);
		if (bodies[1]) { frag.append('{{else}}'); frag.append(...bodies[1].childNodes); }
		frag.append('{{/if}}');
		el.replaceWith(frag);
	    });
	    return c.innerHTML.replace(/\u200b/g, '');
	}
	function caretAfter(node) {
	    const r = document.createRange(); r.setStartAfter(node); r.collapse(true);
	    const s = getSelection(); s.removeAllRanges(); s.addRange(r); savedRange = r.cloneRange();
	}
	function syncFromEditor() { if (isSection(leftTab) && !sourceMode) state[leftTab] = fromEditor(active()); }
	let t = null;
	//a change: the value at once (the step's form has it), the checks and the page's layout a moment later
	function changed(immediate) {
	    syncFromEditor(); emit();
	    clearTimeout(t);
	    if (immediate) render(); else t = setTimeout(render, 160);
	}
	const active = () => editors[leftTab];

	/* ---------- Page geometry ---------- */
	function dims() {
	    const p = state.page; const f = FORMATS[p.format] || FORMATS.A4;
	    return p.orient === 'L' ? { W: f[1], H: f[0] } : { W: f[0], H: f[1] };
	}
	const unit = () => UNITS[ui.unit] || UNITS.mm;
	const toU = (pt) => { const u = unit(), f = 10 ** u.dec; return Math.round(pt / u.k * f) / f; };
	const fromU = (v) => r2(num(v) * unit().k);
	const fmtU = (pt) => toU(pt) + ' ' + unit().name;
	const snapU = (pt) => { const u = unit(); return Math.round(pt / u.k / u.step) * u.step * u.k; };

	function applyPdfVars() {
	    root.style.setProperty('--pdf-font', (FONTS[state.page.font] || FONTS.dejavusans)[1]);
	    root.style.setProperty('--pdf-size', num(state.page.size) + 'pt');
	}
	function measure(html, widthPt) {
	    const m = $('measure');
	    m.style.width = widthPt + 'pt';
	    m.innerHTML = '<div class="inner" style="display:flow-root">' + html + '</div>';
	    const h = m.firstChild.getBoundingClientRect().height / PT;
	    m.innerHTML = '';
	    return h;
	}
	//the expressions shown as their text in the measured copy (the values are not known here)
	const shown = (html) => html.replace(/\{\{#if\s+(?:(?!\}\})[\s\S])*\}\}|\{\{else\s*\}\}|\{\{\/if\s*\}\}/g, '');

	/* ---------- Checks, the status line and the page's layout ---------- */
	function render() {
	    applyPdfVars();
	    const p = state.page;
	    parseData();
	    const { W, H } = dims();
	    const ml = num(p.ml), mr = num(p.mr), mt = num(p.mt), mb = num(p.mb), mh = num(p.mh), mf = num(p.mf);
	    const hOn = p.headerOn !== false, fOn = p.footerOn !== false;
	    const cw = W - ml - mr, bodyH = H - mt - mb;
	    const none = { html: '', warn: [] };
	    const c = { header: hOn ? sanitize(state.header) : none, body: sanitize(state.body), footer: fOn ? sanitize(state.footer) : none };
	    const hh = c.header.html ? measure(shown(c.header.html), cw) : 0;
	    const fh = c.footer.html ? measure(shown(c.footer.html), cw) : 0;

	    const checks = [];
	    if (cw <= 0) checks.push([ 'err', 'Left and right margins leave no room for content.' ]);
	    if (bodyH <= 0) checks.push([ 'err', 'Top and bottom margins leave no room for the body.' ]);
	    if (c.header.html && mh + hh > mt + 0.5)
		checks.push([ 'warn', `The header ends ${fmtU(mh + hh)} from the top, but the body starts at ${fmtU(mt)}. Drag the header's bottom edge down to at least ${fmtU(snapU(mh + hh + 8))}.` ]);
	    if (c.footer.html && mf > mb + 0.5)
		checks.push([ 'warn', `The footer starts ${fmtU(mf)} from the bottom, above where the body stops (${fmtU(mb)}). Set "Footer from bottom" in Page setup to at most ${fmtU(mb)}.` ]);
	    if (c.footer.html && fh > mf + 0.5)
		checks.push([ 'warn', `The footer is ${fmtU(fh)} tall but starts only ${fmtU(mf)} from the bottom edge, so it gets cut off. Drag the footer's top edge up to at least ${fmtU(snapU(fh + 12 + (mb - mf)))}.` ]);
	    if (dataError) checks.push([ 'err', dataError ]);
	    const all = c.header.html + c.body.html + c.footer.html;
	    const opens = (all.match(/\{\{#if\s/g) || []).length, closes = (all.match(/\{\{\/if\s*\}\}/g) || []).length;
	    if (opens !== closes) checks.push([ 'err', `Conditions don't match up: ${opens} <code>{{#if …}}</code> but ${closes} <code>{{/if}}</code>. Check the HTML source.` ]);
	    if ((all.match(/\{\{#if\s*\}\}/g) || []).length) checks.push([ 'err', 'A conditional block has no condition.' ]);
	    const rows = [ ...all.matchAll(/<tr\b[^>]*\bdata-each="([^"]*)"[^>]*>/g) ];
	    rows.forEach((m) => {
		if (!bare(decodeEnt(m[1]))) checks.push([ 'err', 'A repeating row has no list: put the cursor in it and set the list above the page.' ]);
		const as = (m[0].match(/\bdata-as="([^"]*)"/) || [ '', 'item' ])[1];
		if (!/^[A-Za-z_$][\w$]*$/.test(as)) checks.push([ 'err', `A repeating row's item name <code>${app.utils.escapeHtml(as)}</code> must be letters, digits or underscore.` ]);
	    });
	    if (rows.length) checks.push([ 'ok', `${rows.length} row${rows.length > 1 ? 's' : ''} repeated for each item of a list.` ]);
	    if (opens) checks.push([ 'ok', `${opens} conditional block${opens > 1 ? 's' : ''}.` ]);
	    if (CORE_FONTS.includes(p.font)) {
		const tmp = document.createElement('div'); tmp.innerHTML = all.replace(PH_RE, '');
		const odd = [ ...new Set((tmp.textContent.match(/[Ā-ɏͰ-ϿЀ-ӿ]/g) || []).filter((ch) => !'ŠšŽžŒœŸƒ'.includes(ch))) ];
		if (odd.length) checks.push([ 'warn', `Core font "${app.utils.escapeHtml(FONTS[p.font][0])}" can't print ${app.utils.escapeHtml(odd.slice(0, 6).join(' '))}. Choose a DejaVu or Free font in Page setup.` ]);
		else checks.push([ 'ok', `Core font "${app.utils.escapeHtml(FONTS[p.font][0])}": values with letters outside Western European ones (ș, ț, Greek, Cyrillic) won't print.` ]);
	    }
	    new Set([ ...c.header.warn, ...c.body.warn, ...c.footer.warn ]).forEach((w) => checks.push([ 'warn', w ]));
	    if (!checks.some((x) => x[0] !== 'ok')) checks.unshift([ 'ok', 'Layout fits: the header, body and footer don\'t overlap.' ]);
	    const LG = { ok: 'success', warn: 'warning', err: 'danger' };
	    $('checks').innerHTML = checks.map(([k, txt]) => `<li class="list-group-item list-group-item-${LG[k]}"><span>${txt}</span></li>`).join('');

	    //Checks: green when everything fits, else the number of issues
	    const errs = checks.filter((x) => x[0] === 'err').length, warns = checks.filter((x) => x[0] === 'warn').length;
	    const cb = $('checksBtn');
	    cb.classList.remove('text-success', 'text-warning-emphasis', 'text-danger');
	    cb.classList.add(errs ? 'text-danger' : warns ? 'text-warning-emphasis' : 'text-success');
	    cb.innerHTML = '<i class="bi bi-clipboard-check"></i>' + (errs + warns ? `<span class="ms-1">${errs + warns}</span>` : '');
	    cb.title = errs + warns ? `Layout checks: ${errs + warns} to look at` : 'Layout checks: everything fits';
	    cb.setAttribute('aria-label', cb.title);

	    layoutSheet();
	    const fmtName = p.format === 'LETTER' ? 'Letter' : p.format === 'LEGAL' ? 'Legal' : p.format;
	    $('metaText').textContent = `${fmtName} ${p.orient === 'L' ? 'landscape' : 'portrait'} · ${toU(W)} × ${toU(H)} ${unit().name} · ${bodyPages} page${bodyPages > 1 ? 's' : ''} as written`;
	    showProblem();
	}

	/* ---------- Editing ---------- */
	Object.values(editors).forEach((ed) => {
	    if (ro) return;
	    ed.addEventListener('input', () => { changed(); placeFrame(); });
	    ed.addEventListener('blur', () => {
		tabEscape = false;
		if (ed !== active()) return;
		if (/\{\{#if\s/.test(ed.textContent)) { const keep = selCond; setEditor(ed, fromEditor(ed)); if (keep) selectCond(null); changed(); return; }
		if (chipify(ed).length) changed();
	    });
	    ed.addEventListener('paste', (e) => {
		const cd = e.clipboardData; if (!cd) return;
		const html = cd.getData('text/html'), text = cd.getData('text/plain');
		e.preventDefault();
		if (html) {
		    const clean = sanitize(html.replace(/<!--[\s\S]*?-->/g, '')).html.replace(/\sstyle="[^"]*"/g, (m) => /font-(weight|style)|color|text-align|margin-left|text-indent/.test(m) ? m : '');
		    document.execCommand('insertHTML', false, clean);
		} else document.execCommand('insertText', false, text);
		unquote(ed);
		const made = chipify(ed); if (made.length) caretAfter(made[made.length - 1]);
		changed();
	    });
	    ed.addEventListener('click', (e) => {
		const img = e.target.closest('img');
		selectImg(img && ed.contains(img) ? img : null);
		selectCond(e.target.closest('.cond'));
	    });
	    ed.addEventListener('keydown', (e) => {
		//Escape stays in the designer (the step's dialog would close); then Tab leaves the page
		if (e.key === 'Escape') { tabEscape = true; e.stopPropagation(); return; }
		if (e.key === 'Tab' && !e.ctrlKey && !e.altKey && !e.metaKey) {
		    if (tabEscape) { tabEscape = false; return; }
		    e.preventDefault();
		    onTab(e.shiftKey);
		    return;
		}
		if (![ 'Shift', 'Control', 'Alt', 'Meta' ].includes(e.key)) tabEscape = false;
		if (selImg) {
		    if (e.key === 'Backspace' || e.key === 'Delete') { e.preventDefault(); selImg.remove(); selectImg(null); changed(); return; }
		    if (![ 'Shift', 'Control', 'Alt', 'Meta' ].includes(e.key)) selectImg(null);
		}
		//Backspace at the start of an indented paragraph or sub-item removes the indent first
		if (e.key === 'Backspace' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey && backspaceOutdent()) { e.preventDefault(); return; }
		if (e.key !== 'Backspace' && e.key !== 'Delete') return;
		//Backspace and Delete remove a chip as one unit
		const s = getSelection(); if (!s.isCollapsed || !s.rangeCount) return;
		const r = s.getRangeAt(0); let n = null;
		if (r.startContainer.nodeType === 3) {
		    const tn = r.startContainer;
		    if (e.key === 'Backspace' && r.startOffset === 0) n = tn.previousSibling;
		    if (e.key === 'Delete' && r.startOffset === tn.length) n = tn.nextSibling;
		} else {
		    const kids = r.startContainer.childNodes;
		    n = e.key === 'Backspace' ? kids[r.startOffset - 1] : kids[r.startOffset];
		}
		if (n && n.nodeType === 1 && n.classList.contains('ph')) {
		    e.preventDefault();
		    const prev = n.previousSibling; n.remove();
		    if (prev) caretAfter(prev); changed();
		}
	    });
	});
	listen(document, 'selectionchange', () => {
	    if (!isSection(leftTab)) return;
	    const s = getSelection();
	    if (s.rangeCount && active().contains(s.anchorNode)) savedRange = s.getRangeAt(0).cloneRange();
	});
	//the innermost conditional block holding the caret, while the editor has the focus
	let caretCond = null;
	function markCaret() {
	    let c = null;
	    const s = getSelection();
	    if (isSection(leftTab) && !sourceMode && document.activeElement === active() && s.rangeCount && active().contains(s.anchorNode)) {
		const n = s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentElement;
		c = n ? n.closest('.cond') : null;
		if (c && !active().contains(c)) c = null;
	    }
	    if (c !== caretCond) {
		if (caretCond) caretCond.classList.remove('caret');
		caretCond = c;
		if (c) c.classList.add('caret');
	    }
	}
	listen(document, 'selectionchange', markCaret);
	Object.values(editors).forEach((ed) => { ed.addEventListener('focus', markCaret); ed.addEventListener('blur', () => setTimeout(markCaret, 0)); });

	function restore() {
	    const ed = active(); ed.focus();
	    const s = getSelection();
	    if (savedRange && ed.contains(savedRange.startContainer)) { s.removeAllRanges(); s.addRange(savedRange); }
	    else { const r = document.createRange(); r.selectNodeContents(ed); r.collapse(false); s.removeAllRanges(); s.addRange(r); }
	}
	//alignment: browsers follow CSS text-align over the align attribute, tc-lib-pdf the other way round; one align attribute holding what the browser shows
	const ALIGN_TAGS = new Set([ 'P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'LI', 'TD', 'TH', 'BLOCKQUOTE', 'PRE', 'OL', 'UL', 'TABLE' ]);
	function fixAlign(el) {
	    if (!ALIGN_TAGS.has(el.tagName)) return;
	    const st = el.getAttribute('style'); if (!st) return;
	    const m = st.match(/(?:^|;)\s*text-align\s*:\s*(left|right|center|justify|start|end)\s*(?=;|$)/i);
	    if (!m) return;
	    const v = m[1].toLowerCase(), rest = st.replace(/(?:^|;)\s*text-align\s*:[^;]*/gi, '').replace(/^[\s;]+|[\s;]+$/g, '');
	    if (rest) el.setAttribute('style', rest); else el.removeAttribute('style');
	    el.setAttribute('align', v === 'start' ? 'left' : v === 'end' ? 'right' : v);
	}
	function exec(cmd, arg) {
	    if (sourceMode) { toast('Switch off HTML source to use the toolbar.'); return false; }
	    restore();
	    try { document.execCommand('styleWithCSS', false, false); } catch (e) { }
	    document.execCommand(cmd, false, arg == null ? null : arg);
	    if (/^justify/.test(cmd)) active().querySelectorAll('[style*="text-align"]').forEach(fixAlign);
	    const made = chipify(active());
	    if (cmd === 'insertText' && made.length) caretAfter(made[made.length - 1]);
	    changed();
	    return true;
	}
	try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { }
	try { document.execCommand('enableObjectResizing', false, 'false'); } catch (e) { }

	/* ---------- Images ---------- */
	let selImg = null;
	const frame = $('imgFrame'), wrap = $('desk');
	function contentWidthPt() { const { W } = dims(); return Math.max(28, W - num(state.page.ml) - num(state.page.mr)); }
	function imgRatio(img) {
	    if (img.naturalWidth) return img.naturalHeight / img.naturalWidth;
	    const r = img.getBoundingClientRect(); return r.width ? r.height / r.width : 1;
	}
	//sizes in points; on screen scaled by the zoom
	function setImgSize(img, w, h) {
	    w = Math.max(6, r2(w)); h = Math.max(3, r2(h));
	    img.setAttribute('width', w + 'pt'); img.setAttribute('height', h + 'pt');
	    img.style.width = w + 'pt'; img.style.height = h + 'pt';
	}
	function placeFrame() {
	    placeTableHandles();
	    if (!selImg) return;
	    if (!selImg.isConnected || selImg.closest('.editor').hidden) { selectImg(null); return; }
	    const wr = wrap.getBoundingClientRect(), r = selImg.getBoundingClientRect();
	    frame.style.left = (r.left - wr.left - wrap.clientLeft + wrap.scrollLeft) + 'px';
	    frame.style.top = (r.top - wr.top - wrap.clientTop + wrap.scrollTop) + 'px';
	    frame.style.width = r.width + 'px'; frame.style.height = r.height + 'px';
	    frame.querySelector('.dim').textContent = `${toU(r.width / PT / zoomNow)} × ${toU(r.height / PT / zoomNow)} ${unit().name}`;
	}
	function fillImgRow() {
	    if (!selImg) return;
	    const r = selImg.getBoundingClientRect();
	    if (document.activeElement !== $('imgW')) $('imgW').value = toU(r.width / PT / zoomNow);
	    if (document.activeElement !== $('imgH')) $('imgH').value = toU(r.height / PT / zoomNow);
	    $('imgW').step = $('imgH').step = unit().step;
	}
	function selectImg(img) {
	    selImg = ro ? null : (img || null);
	    frame.hidden = !selImg; $('imgRow').hidden = !selImg;
	    if (selImg) { placeFrame(); fillImgRow(); }
	}
	frame.addEventListener('pointerdown', (e) => {
	    const h = e.target.closest('.h'); if (!h || !selImg) return;
	    e.preventDefault(); h.setPointerCapture(e.pointerId);
	    const r = selImg.getBoundingClientRect(), ratio = imgRatio(selImg);
	    const sx = e.clientX, sw = r.width, dir = h.dataset.dir.includes('w') ? -1 : 1;
	    const z = zoomNow, maxW = contentWidthPt() * PT * z;
	    const move = (ev) => {
		const w = Math.min(maxW, Math.max(12, sw + (ev.clientX - sx) * dir));
		setImgSize(selImg, w / PT / z, w * ratio / PT / z); placeFrame(); fillImgRow();
	    };
	    const up = () => {
		h.removeEventListener('pointermove', move); h.removeEventListener('pointerup', up); h.removeEventListener('pointercancel', up);
		changed(true);
	    };
	    h.addEventListener('pointermove', move); h.addEventListener('pointerup', up); h.addEventListener('pointercancel', up);
	});
	$('imgW').addEventListener('input', (e) => {
	    const w = fromU(e.target.value); if (!selImg || w < 6) return;
	    setImgSize(selImg, w, w * imgRatio(selImg)); placeFrame(); fillImgRow(); changed();
	});
	$('imgH').addEventListener('input', (e) => {
	    const h = fromU(e.target.value); if (!selImg || h < 3) return;
	    setImgSize(selImg, h / imgRatio(selImg), h); placeFrame(); fillImgRow(); changed();
	});
	$('imgFull').addEventListener('click', () => {
	    if (!selImg) return; const w = contentWidthPt();
	    setImgSize(selImg, w, w * imgRatio(selImg)); placeFrame(); fillImgRow(); changed(true);
	});
	$('imgOrig').addEventListener('click', () => {
	    if (!selImg || !selImg.naturalWidth) return;
	    const w = Math.min(selImg.naturalWidth / PT, contentWidthPt());
	    setImgSize(selImg, w, w * imgRatio(selImg)); placeFrame(); fillImgRow(); changed(true);
	});
	$('imgDel').addEventListener('click', () => { if (!selImg) return; selImg.remove(); selectImg(null); changed(true); });
	wrap.addEventListener('scroll', placeFrame);
	listen(window, 'resize', placeFrame);
	listen(document, 'pointerdown', (e) => {
	    const keep = e.target.closest('.pd-desk, [data-pd="imgRow"], [data-pd="condRow"], [data-pd="rowBar"], .pd-toolbar, .tpicker, .pd-overlay, .pd-tmenu');
	    if (selImg && !keep) selectImg(null);
	    if (selCond && !keep) selectCond(null);
	    if (curCell && !keep) setCur(null);
	    if (!$('tablePicker').hidden && !e.target.closest('.tpicker, [data-cmd="table"]')) closePicker();
	});

	//the image picker: the project's images (newest first), or a new upload; inserted at the cursor
	let images = null;
	async function openImagePicker() {
	    if (sourceMode) { toast('Switch off HTML source to use the toolbar.'); return; }
	    openModal('imageModal');
	    const box = $('gallery');
	    box.innerHTML = '<span class="small text-muted">Loading the project\'s images…</span>';
	    if (!images) {
		try {
		    const res = await fetch('/file/list?data=1&__limit=200&__sortField=_id&__sortDir=desc', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
		    const data = res.ok ? await res.json() : null;
		    images = ((data && data.data) || []).filter((r) => IMAGE.test(String(r.filename || ''))).map((r) => ({ id: r._id, name: String(r.filename) }));
		} catch (err) {
		    images = [];
		}
	    }
	    box.innerHTML = images.length ? '' : '<span class="small text-muted">No PNG, JPEG or GIF images in the project\'s Files: upload one</span>';
	    images.forEach((im) => {
		const b = document.createElement('button');
		b.type = 'button';
		b.className = 'btn p-0 border';
		b.title = im.name;
		b.innerHTML = `<img src="/file/content/${app.utils.escapeHtml(im.id)}" alt="" style="width:72px; height:72px; object-fit:cover; display:block">`;
		b.addEventListener('click', () => insertImage(im.id));
		box.appendChild(b);
	    });
	}
	function insertImage(id) {
	    closeModal();
	    const img = new Image();
	    img.onload = () => {
		const w = r2(Math.min(img.naturalWidth / PT, 170, contentWidthPt())); //at most about 60 mm
		const h = r2(w * img.naturalHeight / img.naturalWidth);
		exec('insertHTML', `<img src="/file/content/${app.utils.escapeHtml(id)}" width="${w}pt" height="${h}pt" style="width:${w}pt;height:${h}pt" alt="">`);
	    };
	    img.onerror = () => toast('That image could not be loaded.');
	    img.src = '/file/content/' + id;
	}
	if (!ro && window.app.FileUpload) {
	    const msg = $('imgUploadMsg');
	    const up = new window.app.FileUpload($('imgFile'), {
		url: '/file/upload',
		accept: '.png,.jpg,.jpeg,.gif',
		formData: () => {
		    const p = document.querySelector('meta[name=csrf-param]')?.content, tk = app.utils.csrf();
		    return p && tk ? { [p]: tk } : {};
		},
		onUploadStart: () => { msg.textContent = 'Uploading…'; },
		onFileUploadError: (item) => { msg.textContent = (item.response && item.response.error) || 'The upload failed'; },
		onFileUploadComplete: (item) => {
		    msg.textContent = '';
		    if (item.response && item.response.id) {
			images = null;
			insertImage(item.response.id);
		    }
		},
	    });
	    $('imgUpload').addEventListener('click', () => up.browse());
	}

	/* ---------- Conditional blocks ---------- */
	let selCond = null;
	function blockOf(n, ed) {
	    let el = n.nodeType === 1 ? n : n.parentElement;
	    while (el && el !== ed) {
		if (/^(P|H[1-6]|LI|PRE|BLOCKQUOTE)$/.test(el.tagName)) return el;
		if (el.classList.contains('cond-body') || el.tagName === 'TD' || el.tagName === 'TH') return null;
		el = el.parentElement;
	    }
	    return null;
	}
	function insertCond() {
	    if (sourceMode) { toast('Switch off HTML source to use the toolbar.'); return; }
	    const ed = active(), expr = '';
	    let node;
	    if (selImg) {
		node = makeCond(expr, true);
		selImg.replaceWith(node); node.querySelector('.cond-body').appendChild(selImg); selectImg(null);
	    } else {
		restore();
		const r = getSelection().getRangeAt(0);
		if (!r.collapsed) {
		    const sb = blockOf(r.startContainer, ed), eb = blockOf(r.endContainer, ed);
		    const inline = !!sb && sb === eb;
		    if (!inline) { if (sb) r.setStartBefore(sb); if (eb) r.setEndAfter(eb); }
		    const content = r.extractContents();
		    node = makeCond(expr, inline);
		    node.querySelector('.cond-body').appendChild(content);
		    r.insertNode(node);
		} else {
		    node = makeCond(expr, false, '<p>Shown only when the condition is true.</p>');
		    const b = blockOf(r.startContainer, ed);
		    if (b) b.after(node); else r.insertNode(node);
		}
	    }
	    chipify(ed); decorateConds(ed); selectCond(node); changed(true);
	    $('condExpr').focus();
	}
	function selectCond(el) {
	    if (ro) el = null;
	    if (selCond) selCond.classList.remove('sel');
	    selCond = el || null;
	    $('condRow').hidden = !selCond;
	    if (!selCond) return;
	    selCond.classList.add('sel');
	    $('condExpr').value = selCond.dataset.expr ? '{{ ' + selCond.dataset.expr + ' }}' : '';
	    $('condElse').checked = selCond.querySelectorAll(':scope > .cond-bar').length > 1;
	}
	$('condExpr').addEventListener('input', () => {
	    if (!selCond) return;
	    selCond.dataset.expr = bare($('condExpr').value);
	    decorateConds(active()); changed();
	});
	$('condElse').addEventListener('change', (e) => {
	    if (!selCond) return;
	    const tg = selCond.classList.contains('cond-inline') ? 'span' : 'div';
	    if (e.target.checked) {
		const bar = document.createElement(tg); bar.className = 'cond-bar cond-else'; bar.contentEditable = 'false';
		const body = document.createElement(tg); body.className = 'cond-body';
		body.innerHTML = tg === 'span' ? 'otherwise text' : '<p>Shown when the condition is false.</p>';
		selCond.append(bar, body);
	    } else {
		[ ...selCond.children ].slice(2).forEach((k) => k.remove());
	    }
	    decorateConds(active()); changed(true);
	});
	$('condUnwrap').addEventListener('click', () => {
	    if (!selCond) return;
	    const body = selCond.querySelector(':scope > .cond-body');
	    selCond.replaceWith(...(body ? [ ...body.childNodes ] : []));
	    selectCond(null); changed(true);
	});
	$('condDel').addEventListener('click', () => { if (!selCond) return; selCond.remove(); selectCond(null); changed(true); });

	/* ---------- Indentation: tc-lib-pdf indents with margin-left (all lines) and text-indent (first line); it ignores <blockquote> ---------- */
	let tabEscape = false;
	const INDENT_BLOCKS = new Set([ 'P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'DIV', 'PRE' ]);
	const indentStep = () => { const v = num(state.page.indent); return v >= 2 && v <= 145 ? v : 28.35; };
	const LEN_K = { pt: 1, mm: 72 / 25.4, cm: 72 / 2.54, in: 72, px: 0.75 };
	const lenOf = (el, prop) => { const m = (el.style[prop] || '').match(/^(-?[\d.]+)(pt|mm|cm|in|px)$/); return m ? parseFloat(m[1]) * LEN_K[m[2]] : 0; };
	function setLen(el, prop, v) {
	    v = r2(v);
	    el.style[prop] = v > 0 ? v + 'pt' : '';
	    if (!el.getAttribute('style')) el.removeAttribute('style');
	}
	function contentWidth() { const { W } = dims(); return Math.max(56, W - num(state.page.ml) - num(state.page.mr)); }
	//the blocks the selection touches (paragraphs, headings), never table cells or list items
	function selectedBlocks(ed, r) {
	    const isBlock = (el) => el && el !== ed && INDENT_BLOCKS.has(el.tagName) && !el.classList.contains('cond') && !el.classList.contains('cond-body')
		&& !el.querySelector('p, h1, h2, h3, h4, h5, h6, div:not(.cond-bar), table, ul, ol, pre');
	    const up = (n) => { let el = n.nodeType === 1 ? n : n.parentElement; while (el && el !== ed) { if (isBlock(el)) return el; if (/^(TD|TH|LI)$/.test(el.tagName)) return null; el = el.parentElement; } return null; };
	    const out = [];
	    if (r.collapsed) { const b = up(r.startContainer); return b ? [ b ] : []; }
	    const w = document.createTreeWalker(ed, NodeFilter.SHOW_ELEMENT, { acceptNode: (el) => isBlock(el) && r.intersectsNode(el) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP });
	    while (w.nextNode()) if (!w.currentNode.closest('td, th, li')) out.push(w.currentNode);
	    if (!out.length) { const b = up(r.startContainer); if (b) out.push(b); }
	    return out;
	}
	function selectedItems(ed, r) {
	    const items = [ ...ed.querySelectorAll('li') ].filter((li) => r.intersectsNode(li) && !li.closest('td, th'));
	    return items.filter((li) => !items.some((x) => x !== li && li.contains(x) && (r.collapsed || !rangeCovers(r, li))));
	}
	function rangeCovers(r, el) { const tr = document.createRange(); tr.selectNodeContents(el); return r.compareBoundaryPoints(Range.START_TO_START, tr) <= 0 && r.compareBoundaryPoints(Range.END_TO_END, tr) >= 0; }
	function keepRange(fn) {
	    const s = getSelection(); const r = s.rangeCount ? s.getRangeAt(0) : null;
	    const saved = r && [ r.startContainer, r.startOffset, r.endContainer, r.endOffset ];
	    fn();
	    if (saved && saved[0].isConnected && saved[2].isConnected) {
		try { const n = document.createRange(); n.setStart(saved[0], saved[1]); n.setEnd(saved[2], saved[3]); s.removeAllRanges(); s.addRange(n); savedRange = n.cloneRange(); } catch (e) { }
	    }
	}
	function onTab(outdent, fromButton) {
	    const ed = active(), s = getSelection();
	    if (!s.rangeCount || !ed.contains(s.anchorNode)) return;
	    const r = s.getRangeAt(0);
	    const anchor = r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentElement;
	    //a table cell: Tab / Shift+Tab move between cells (the buttons still indent the paragraph)
	    const cell = anchor && anchor.closest('td, th');
	    if (cell && ed.contains(cell) && !fromButton && !anchor.closest('li')) { moveCell(cell, outdent); return; }
	    const items = selectedItems(ed, r);
	    if (items.length) { keepRange(() => items.forEach((li) => outdent ? outdentItem(li) : indentItem(li))); changed(true); return; }
	    let blocks = selectedBlocks(ed, r);
	    if (!blocks.length && !cell) { document.execCommand('formatBlock', false, 'p'); blocks = selectedBlocks(ed, getSelection().getRangeAt(0)); }
	    if (!blocks.length) return;
	    const step = indentStep(), max = contentWidth() - 56;
	    let capped = false;
	    blocks.forEach((b) => {
		const cur = lenOf(b, 'marginLeft');
		let v = outdent ? cur - step : cur + step;
		if (v > max) { v = Math.max(cur, Math.floor(max / step) * step); capped = true; }
		setLen(b, 'marginLeft', Math.max(0, v));
	    });
	    if (capped) toast('That is as far as text can be indented within the page margins.');
	    changed(true); updateFirstLineBtn();
	}
	function indentItem(li) {
	    const prev = li.previousElementSibling; if (!prev || prev.tagName !== 'LI') return;
	    const type = li.parentElement.tagName.toLowerCase();
	    let sub = [ ...prev.children ].reverse().find((c) => c.tagName === 'OL' || c.tagName === 'UL');
	    if (!sub) { sub = document.createElement(type); prev.appendChild(sub); }
	    sub.appendChild(li);
	}
	function outdentItem(li) {
	    const list = li.parentElement, parentLi = list.parentElement;
	    if (!parentLi || parentLi.tagName !== 'LI') return;
	    //the items after this one become its children, so the order stays the same
	    const after = [];
	    for (let n = li.nextElementSibling; n; n = n.nextElementSibling) after.push(n);
	    if (after.length) {
		let own = [ ...li.children ].find((c) => c.tagName === 'OL' || c.tagName === 'UL');
		if (!own) { own = document.createElement(list.tagName.toLowerCase()); li.appendChild(own); }
		after.forEach((n) => own.appendChild(n));
	    }
	    parentLi.after(li);
	    if (!list.children.length) list.remove();
	}
	function moveCell(cell, back) {
	    const table = cell.closest('table'), cells = [ ...table.querySelectorAll('td, th') ].filter((c) => c.closest('table') === table);
	    const i = cells.indexOf(cell);
	    let next = cells[back ? i - 1 : i + 1];
	    if (!next && !back) {
		setCur(cell); tableOp('rowBelow'); //Tab in the last cell adds a row
		const row = cell.parentElement.nextElementSibling || table.rows[table.rows.length - 1];
		next = row && row.cells[0];
	    }
	    if (!next) return;
	    const r = document.createRange(); r.selectNodeContents(next);
	    const last = next.lastChild;
	    if (last && last.nodeName === 'BR') r.setStartBefore(last); else r.collapse(false);
	    r.collapse(true);
	    const s = getSelection(); s.removeAllRanges(); s.addRange(r); savedRange = r.cloneRange();
	    trackCell();
	}
	//whether nothing visible (text, chip, image) comes before the caret in el
	function caretAtStart(el, r) {
	    const pre = document.createRange();
	    pre.selectNodeContents(el);
	    try { pre.setEnd(r.startContainer, r.startOffset); } catch (e) { return false; }
	    if (pre.toString().replace(/\u200b/g, '').length) return false;
	    return !pre.cloneContents().querySelector('img, .ph, table, .cond-body');
	}
	//as in Word: the first-line indent goes first, then one indent step; a sub-item moves up a level
	function backspaceOutdent() {
	    const ed = active(), s = getSelection();
	    if (!s.rangeCount || !s.isCollapsed || !ed.contains(s.anchorNode)) return false;
	    const r = s.getRangeAt(0);
	    const el = r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentElement;
	    const li = el && el.closest('li');
	    if (li && ed.contains(li)) {
		if (!li.parentElement.parentElement || li.parentElement.parentElement.tagName !== 'LI' || !caretAtStart(li, r)) return false;
		keepRange(() => outdentItem(li));
		changed(true);
		return true;
	    }
	    const block = selectedBlocks(ed, r)[0];
	    if (!block || !caretAtStart(block, r)) return false;
	    if (lenOf(block, 'textIndent') > 0) setLen(block, 'textIndent', 0);
	    else if (lenOf(block, 'marginLeft') > 0) setLen(block, 'marginLeft', Math.max(0, lenOf(block, 'marginLeft') - indentStep()));
	    else return false;
	    changed(true);
	    updateFirstLineBtn();
	    return true;
	}
	function toggleFirstLine() {
	    const ed = active(), s = getSelection(); if (!s.rangeCount) return;
	    const blocks = selectedBlocks(ed, s.getRangeAt(0)).filter((b) => !/^H\d$/.test(b.tagName));
	    if (!blocks.length) { toast('Put the cursor in a paragraph to give it a first-line indent.'); return; }
	    const on = blocks.every((b) => lenOf(b, 'textIndent') > 0);
	    blocks.forEach((b) => setLen(b, 'textIndent', on ? 0 : indentStep()));
	    changed(true); updateFirstLineBtn();
	}
	function updateFirstLineBtn() {
	    const btn = $('firstLineBtn'), ed = active(), s = getSelection();
	    let on = false;
	    if (isSection(leftTab) && !sourceMode && s.rangeCount && ed.contains(s.anchorNode)) {
		const b = selectedBlocks(ed, s.getRangeAt(0));
		on = b.length > 0 && b.every((x) => lenOf(x, 'textIndent') > 0);
	    }
	    press(btn, on);
	}
	listen(document, 'selectionchange', updateFirstLineBtn);
	//<blockquote> prints without an indent: its indent moved onto the blocks inside it
	function unquote(rootEl) {
	    [ ...rootEl.querySelectorAll('blockquote') ].reverse().forEach((q) => {
		const step = indentStep() + lenOf(q, 'marginLeft');
		const blocks = [ ...q.children ].filter((c) => INDENT_BLOCKS.has(c.tagName) || c.tagName === 'TABLE' || c.tagName === 'OL' || c.tagName === 'UL');
		const hasLoose = [ ...q.childNodes ].some((n) => (n.nodeType === 3 && n.nodeValue.trim()) || (n.nodeType === 1 && !blocks.includes(n)));
		if (!blocks.length || hasLoose) {
		    const p = document.createElement('p');
		    [ ...q.attributes ].forEach((a) => { if (a.name !== 'style') p.setAttribute(a.name, a.value); });
		    while (q.firstChild) p.appendChild(q.firstChild);
		    setLen(p, 'marginLeft', step); q.replaceWith(p); return;
		}
		blocks.forEach((b) => setLen(b, 'marginLeft', lenOf(b, 'marginLeft') + step));
		q.replaceWith(...q.childNodes);
	    });
	}

	/* ---------- Tables ---------- */
	let curCell = null, hotTable = null, colDragging = false;
	const tblLayer = $('tblLayer');
	const r1t = (x) => Math.round(x * 10) / 10;
	//each row's cells with their starting column and span
	function gridOf(table) {
	    let n = 0;
	    const rows = [ ...table.rows ].map((row) => {
		let c = 0;
		const cells = [ ...row.cells ].map((cell) => { const span = Math.max(1, cell.colSpan || 1); const x = { cell, start: c, span }; c += span; return x; });
		n = Math.max(n, c);
		return { row, cells };
	    });
	    return { rows, n };
	}
	function widthsOf(table, g) {
	    g = g || gridOf(table);
	    const tw = table.getBoundingClientRect().width || 1;
	    const w = new Array(g.n).fill(null);
	    g.rows.forEach((r) => r.cells.forEach((x) => {
		if (x.span !== 1 || w[x.start] != null) return;
		const a = x.cell.getAttribute('width');
		w[x.start] = (a && /%\s*$/.test(a)) ? parseFloat(a) : x.cell.getBoundingClientRect().width / tw * 100;
	    }));
	    const known = w.filter((x) => x != null && isFinite(x));
	    const unk = g.n - known.length, rest = Math.max(0, 100 - known.reduce((a, b) => a + b, 0));
	    for (let i = 0; i < g.n; i++) if (w[i] == null || !isFinite(w[i])) w[i] = unk ? (rest / unk || 100 / g.n) : 100 / g.n;
	    const sum = w.reduce((a, b) => a + b, 0) || 1;
	    return w.map((x) => x * 100 / sum);
	}
	function applyWidths(table, w, g) {
	    g = g || gridOf(table);
	    g.rows.forEach((r) => r.cells.forEach((x) => {
		let s = 0; for (let i = x.start; i < x.start + x.span && i < w.length; i++) s += w[i];
		x.cell.setAttribute('width', r1t(s) + '%');
		x.cell.style.removeProperty('width'); if (!x.cell.getAttribute('style')) x.cell.removeAttribute('style');
	    }));
	}
	function retag(el, tag) {
	    const n = document.createElement(tag);
	    [ ...el.attributes ].forEach((a) => n.setAttribute(a.name, a.value));
	    while (el.firstChild) n.appendChild(el.firstChild);
	    el.replaceWith(n); return n;
	}
	function newCell(tag) { const c = document.createElement(tag); c.innerHTML = '<br>'; if (tag === 'th') c.setAttribute('align', 'left'); return c; }
	function cellAt(table, ri, ci) {
	    const g = gridOf(table), r = g.rows[Math.max(0, Math.min(ri, g.rows.length - 1))];
	    if (!r) return null;
	    const x = r.cells.find((y) => y.start <= ci && ci < y.start + y.span) || r.cells[r.cells.length - 1];
	    return x ? x.cell : null;
	}
	function caretIn(cell) {
	    if (!cell) return;
	    active().focus();
	    const r = document.createRange(); r.selectNodeContents(cell); r.collapse(true);
	    const s = getSelection(); s.removeAllRanges(); s.addRange(r); savedRange = r.cloneRange();
	}
	function setCur(cell) {
	    if (curCell) curCell.classList.remove('curcell');
	    curCell = cell || null;
	    if (curCell) curCell.classList.add('curcell');
	    placeTableHandles();
	    showRowBar();
	}
	function trackCell() {
	    if (!isSection(leftTab) || sourceMode) { if (curCell) setCur(null); return; }
	    if (document.activeElement !== active()) return; //the bars keep their inputs while in use
	    const s = getSelection(); if (!s.rangeCount) return;
	    const n = s.anchorNode && (s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentElement);
	    const c = (n && active().contains(n)) ? n.closest('td, th') : null;
	    if (c !== curCell) setCur(c && active().contains(c) ? c : null);
	}
	listen(document, 'selectionchange', trackCell);

	//the repeating row of the current cell: its bar above the page (list, item name)
	let barRow = null;
	function showRowBar() {
	    const row = !ro && curCell && curCell.parentElement && curCell.parentElement.hasAttribute('data-each') ? curCell.parentElement : null;
	    if (row === barRow) return;
	    barRow = row;
	    $('rowBar').hidden = !row;
	    if (!row) return;
	    $('rowEach').value = row.getAttribute('data-each') ? '{{ ' + row.getAttribute('data-each') + ' }}' : '';
	    $('rowAs').value = row.getAttribute('data-as') || 'item';
	    $('rowAsHint').textContent = $('rowAs').value || 'item';
	}
	$('rowEach').addEventListener('input', () => { if (barRow) { barRow.setAttribute('data-each', bare($('rowEach').value)); changed(); } });
	$('rowAs').addEventListener('input', () => { if (barRow) { barRow.setAttribute('data-as', $('rowAs').value.trim()); $('rowAsHint').textContent = $('rowAs').value.trim() || 'item'; changed(); } });
	$('rowStop').addEventListener('click', () => {
	    if (!barRow) return;
	    barRow.removeAttribute('data-each'); barRow.removeAttribute('data-as');
	    const cell = curCell; barRow = null; $('rowBar').hidden = true; setCur(cell); changed(true);
	});
	//a list of the sample data for a new repeating row: [ list path, its fields ]
	function guessList() {
	    const l = listsIn(parseData())[0];
	    return l ? [ l.path, l.fields ] : [ 'items', [] ];
	}

	function tableOp(op) {
	    if (!curCell || !curCell.isConnected) return;
	    const table = curCell.closest('table'), g = gridOf(table), w = widthsOf(table, g);
	    const row = curCell.parentElement, ri = g.rows.findIndex((r) => r.row === row);
	    const info = g.rows[ri].cells.find((x) => x.cell === curCell), ci = info.start, pos = g.rows[ri].cells.indexOf(info);
	    let focus = null;
	    const done = () => { applyWidths(table, widthsOf(table)); setCur(null); caretIn(focus); trackCell(); changed(true); };
	    const gone = () => { table.remove(); setCur(null); changed(true); };

	    if (op === 'rowAbove' || op === 'rowBelow') {
		const below = op === 'rowBelow', inHead = row.parentElement.tagName === 'THEAD';
		const tr = document.createElement('tr');
		g.rows[ri].cells.forEach((x) => {
		    const tag = (inHead && below) ? 'td' : x.cell.tagName.toLowerCase();
		    const c = newCell(tag); if (x.span > 1) c.colSpan = x.span;
		    const al = x.cell.getAttribute('align'); if (al && tag === x.cell.tagName.toLowerCase()) c.setAttribute('align', al);
		    tr.appendChild(c);
		});
		if (inHead && below) { const body = table.tBodies[0] || table.createTBody(); body.insertBefore(tr, body.firstChild); }
		else row.parentElement.insertBefore(tr, below ? row.nextSibling : row);
		applyWidths(table, w);
		focus = tr.cells[Math.min(pos, tr.cells.length - 1)];
	    } else if (op === 'rowDel') {
		const sec = row.parentElement;
		row.remove();
		if (sec !== table && !sec.rows.length) sec.remove();
		if (!table.rows.length) return gone();
		focus = cellAt(table, ri, ci);
	    } else if (op === 'colLeft' || op === 'colRight') {
		const at = op === 'colLeft' ? ci : ci + info.span;
		g.rows.forEach((r) => {
		    const cover = r.cells.find((x) => x.start < at && at < x.start + x.span);
		    if (cover) { cover.cell.colSpan = cover.span + 1; return; }
		    const before = r.cells.find((x) => x.start >= at);
		    const tag = (r.row.parentElement.tagName === 'THEAD' || (r.cells.length && r.cells.every((x) => x.cell.tagName === 'TH'))) ? 'th' : 'td';
		    const c = newCell(tag); r.row.insertBefore(c, before ? before.cell : null);
		    if (r.row === row) focus = c;
		});
		const src = op === 'colLeft' ? ci : ci + info.span - 1, half = w[src] / 2;
		w[src] = half; w.splice(at, 0, half);
		applyWidths(table, w);
	    } else if (op === 'colDel') {
		if (g.n <= 1) return gone();
		g.rows.forEach((r) => {
		    const x = r.cells.find((y) => y.start <= ci && ci < y.start + y.span); if (!x) return;
		    if (x.span > 1) x.cell.colSpan = x.span - 1; else x.cell.remove();
		});
		[ ...table.rows ].forEach((r) => { if (!r.cells.length) r.remove(); });
		if (!table.rows.length) return gone();
		const removed = w.splice(ci, 1)[0], sum = w.reduce((a, b) => a + b, 0) || 1;
		for (let i = 0; i < w.length; i++) w[i] += removed * w[i] / sum;
		applyWidths(table, w);
		focus = cellAt(table, ri, Math.min(ci, w.length - 1));
	    } else if (op === 'header') {
		if (table.tHead) {
		    const body = table.tBodies[0] || table.createTBody();
		    [ ...table.tHead.rows ].reverse().forEach((r) => { [ ...r.cells ].forEach((c) => { if (c.tagName === 'TH') retag(c, 'td'); }); body.insertBefore(r, body.firstChild); });
		    table.tHead.remove();
		} else {
		    const first = table.rows[0], head = table.createTHead();
		    first.removeAttribute('data-each'); first.removeAttribute('data-as');
		    head.appendChild(first);
		    [ ...first.cells ].forEach((c) => { if (c.tagName === 'TD') { const n = retag(c, 'th'); if (!n.getAttribute('align')) n.setAttribute('align', 'left'); } });
		}
		focus = cellAt(table, ri, ci);
	    } else if (op === 'border') {
		if (table.getAttribute('border') === '1') table.removeAttribute('border'); else table.setAttribute('border', '1');
		focus = curCell;
	    } else if (op === 'distribute') {
		applyWidths(table, w.map(() => 100 / w.length));
		focus = curCell;
	    } else if (op === 'repeat') {
		if (row.hasAttribute('data-each')) { row.removeAttribute('data-each'); row.removeAttribute('data-as'); }
		else if (row.parentElement.tagName !== 'THEAD') { row.setAttribute('data-each', guessList()[0]); row.setAttribute('data-as', 'item'); }
		else toast('A header row can\'t repeat: use a row below it.');
		focus = curCell;
	    } else if (op === 'del') {
		return gone();
	    }
	    done();
	}
	//one column's width in %; the other columns share the difference in proportion (each keeps at least 3%)
	function setColWidth(cell, pct) {
	    const table = cell.closest('table'), g = gridOf(table), w = widthsOf(table, g);
	    const info = g.rows.flatMap((r) => r.cells).find((x) => x.cell === cell);
	    if (!info || info.span > 1 || g.n < 2) return;
	    const i = info.start, others = w.map((x, k) => k).filter((k) => k !== i);
	    const v = Math.max(3, Math.min(100 - 3 * others.length, pct));
	    const rest = 100 - v;
	    const free = others.reduce((s, k) => s + w[k], 0) || 1;
	    others.forEach((k) => { w[k] = Math.max(3, w[k] * rest / free); });
	    const over = others.reduce((s, k) => s + w[k], 0) - rest;
	    if (over > 0.01) { const big = others.filter((k) => w[k] > 3), bs = big.reduce((s, k) => s + w[k] - 3, 0) || 1; big.forEach((k) => { w[k] -= over * (w[k] - 3) / bs; }); }
	    w[i] = v;
	    applyWidths(table, w, g); placeTableHandles(); changed(true);
	}
	//the column handles over the table under the mouse, or the one holding the cursor
	function placeTableHandles() {
	    if (colDragging) return;
	    if (curCell && !curCell.isConnected) curCell = null;
	    if (hotTable && (!hotTable.isConnected || !editorOf(hotTable))) hotTable = null;
	    const table = hotTable || (curCell && curCell.closest('table'));
	    tblLayer.innerHTML = ''; tblLayer._table = null;
	    if (ro || !table || sourceMode || !isSection(leftTab) || active().hidden) return;
	    const g = gridOf(table); if (g.n < 2) return;
	    const w = widthsOf(table, g);
	    const wr = wrap.getBoundingClientRect(), r = table.getBoundingClientRect();
	    const ox = wr.left + wrap.clientLeft - wrap.scrollLeft, oy = r.top - wr.top - wrap.clientTop + wrap.scrollTop;
	    let acc = 0;
	    for (let i = 0; i < g.n - 1; i++) {
		acc += w[i];
		let x = r.left + r.width * acc / 100;
		for (const row of g.rows) { const c = row.cells.find((y) => y.start + y.span - 1 === i); if (c) { x = c.cell.getBoundingClientRect().right; break; } }
		const h = app.utils.el('div', 'colh'); h.dataset.i = i;
		h.style.left = (x - ox) + 'px'; h.style.top = oy + 'px'; h.style.height = r.height + 'px';
		h.innerHTML = `<span class="tip">${fmt(w[i])}% | ${fmt(w[i + 1])}%</span>`;
		h.title = 'Drag to resize columns';
		tblLayer.appendChild(h);
	    }
	    tblLayer._table = table;
	}
	tblLayer.addEventListener('pointerdown', (e) => {
	    const h = e.target.closest('.colh'), table = tblLayer._table; if (!h || !table) return;
	    e.preventDefault(); h.setPointerCapture(e.pointerId);
	    const i = +h.dataset.i, g = gridOf(table), w0 = widthsOf(table, g), tw = table.getBoundingClientRect().width || 1, x0 = e.clientX;
	    const pair = w0[i] + w0[i + 1], tip = h.querySelector('.tip'), left0 = parseFloat(h.style.left);
	    colDragging = true; h.classList.add('drag'); document.body.classList.add('pd-resizing');
	    const move = (ev) => {
		const a = Math.max(3, Math.min(pair - 3, w0[i] + (ev.clientX - x0) / tw * 100));
		const w = w0.slice(); w[i] = a; w[i + 1] = pair - a;
		applyWidths(table, w, g);
		tip.textContent = `${fmt(w[i])}% | ${fmt(w[i + 1])}%`;
		h.style.left = (left0 + (a - w0[i]) / 100 * tw) + 'px';
	    };
	    const up = () => {
		h.removeEventListener('pointermove', move); h.removeEventListener('pointerup', up); h.removeEventListener('pointercancel', up);
		colDragging = false; document.body.classList.remove('pd-resizing');
		changed(true); placeTableHandles();
	    };
	    h.addEventListener('pointermove', move); h.addEventListener('pointerup', up); h.addEventListener('pointercancel', up);
	});
	wrap.addEventListener('mousemove', (e) => {
	    if (colDragging || e.target.closest('.colh')) return;
	    const tb = e.target.closest('table'), nt = (tb && editorOf(tb)) ? tb : null;
	    if (nt !== hotTable) { hotTable = nt; placeTableHandles(); }
	});
	wrap.addEventListener('mouseleave', () => { if (!colDragging && hotTable) { hotTable = null; placeTableHandles(); } });

	/* ---------- Cell and table properties (dialogs of the table menu): background as bgcolor, padding as CSS in pt ---------- */
	const PAD_SIDES = [ 'Top', 'Right', 'Bottom', 'Left' ];
	const SWATCHES = [ '#ffffff', '#f2f2f2', '#d9d9d9', '#e6eeed', '#dcecea', '#cfe8ff', '#e2f0d9', '#fff2cc', '#fde2e1' ];
	let dlgCell = null;
	$('cpSwatches').innerHTML = SWATCHES.map((c) => `<button type="button" style="background:${c}" data-c="${c}" title="${c}" aria-label="Background ${c}"></button>`).join('');
	const toHex = (c) => {
	    if (!c) return '';
	    if (/^#[0-9a-f]{6}$/i.test(c)) return c.toLowerCase();
	    if (/^#[0-9a-f]{3}$/i.test(c)) return '#' + [ ...c.slice(1) ].map((x) => x + x).join('').toLowerCase();
	    const m = c.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
	    return m ? '#' + m.slice(1, 4).map((n) => (+n).toString(16).padStart(2, '0')).join('') : '';
	};
	const padPt = (cell, side) => { const m = (cell.style['padding' + side] || '').match(/^([\d.]+)(pt|mm|cm|in|px)$/); return m ? parseFloat(m[1]) * LEN_K[m[2]] : null; };
	function cellsFor(cell, scope) {
	    const table = cell.closest('table'), all = gridOf(table).rows.flatMap((r) => r.cells), me = all.find((x) => x.cell === cell);
	    if (scope === 'row') return [ ...cell.parentElement.cells ];
	    if (scope === 'col') return all.filter((x) => x.start <= me.start && me.start < x.start + x.span).map((x) => x.cell);
	    if (scope === 'table') return all.map((x) => x.cell);
	    return [ cell ];
	}
	function openCellDialog() {
	    if (!curCell) return;
	    dlgCell = curCell;
	    const table = dlgCell.closest('table'), tablePx = num(table.getAttribute('cellpadding'));
	    const bg = toHex(dlgCell.getAttribute('bgcolor') || dlgCell.style.backgroundColor);
	    $('cpScope').value = 'cell';
	    $('cpBgNone').checked = !bg; $('cpBg').value = bg || '#e6eeed';
	    const pads = PAD_SIDES.map((s) => padPt(dlgCell, s)), own = pads.some((v) => v != null);
	    $('cpTablePad').textContent = `${tablePx} px, about ${toU(tablePx * 0.75)} ${unit().name}`;
	    $('cpPadDefault').checked = !own;
	    $('cpPadSame').checked = !own || pads.every((v) => v === pads[0]);
	    PAD_SIDES.forEach((s, i) => { const el = $('cpPad' + s); el.step = unit().step; el.value = toU(pads[i] != null ? pads[i] : tablePx * 0.75); });
	    syncPadFields();
	    openModal('cellModal', $('cpScope'));
	}
	function syncPadFields() {
	    const def = $('cpPadDefault').checked, same = $('cpPadSame').checked;
	    $('cpPadSame').disabled = def;
	    PAD_SIDES.forEach((s, i) => { $('cpPad' + s).disabled = def || (same && i > 0); });
	    if (same) PAD_SIDES.slice(1).forEach((s) => { $('cpPad' + s).value = $('cpPadTop').value; });
	}
	$('cpPadDefault').addEventListener('change', syncPadFields);
	$('cpPadSame').addEventListener('change', syncPadFields);
	$('cpPadTop').addEventListener('input', () => { if ($('cpPadSame').checked) syncPadFields(); });
	$('cpBg').addEventListener('input', () => { $('cpBgNone').checked = false; });
	$('cpSwatches').addEventListener('click', (e) => { const b = e.target.closest('[data-c]'); if (b) { $('cpBg').value = b.dataset.c; $('cpBgNone').checked = false; } });
	$('cpApply').addEventListener('click', () => {
	    const cell = dlgCell;
	    if (!cell || !cell.isConnected) { closeModal(); return; }
	    const cells = cellsFor(cell, $('cpScope').value), noBg = $('cpBgNone').checked, bg = $('cpBg').value;
	    const def = $('cpPadDefault').checked, same = $('cpPadSame').checked;
	    const vals = PAD_SIDES.map((s) => Math.max(0, fromU($('cpPad' + (same ? 'Top' : s)).value)));
	    cells.forEach((c) => {
		c.style.backgroundColor = '';
		if (noBg) c.removeAttribute('bgcolor'); else c.setAttribute('bgcolor', bg);
		[ 'padding', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft' ].forEach((p) => { c.style[p] = ''; });
		if (!def) {
		    if (vals.every((v) => v === vals[0])) c.style.padding = r2(vals[0]) + 'pt';
		    else PAD_SIDES.forEach((s, i) => { c.style['padding' + s] = r2(vals[i]) + 'pt'; });
		}
		if (!c.getAttribute('style')) c.removeAttribute('style');
	    });
	    closeModal(); setCur(cell); changed(true); placeTableHandles();
	    toast(`Cell properties applied to ${cells.length} cell${cells.length > 1 ? 's' : ''}.`);
	});
	function openTableDialog() {
	    if (!curCell) return;
	    dlgCell = curCell;
	    const table = dlgCell.closest('table'), g = gridOf(table), w = widthsOf(table, g);
	    const info = g.rows.flatMap((r) => r.cells).find((x) => x.cell === dlgCell);
	    let cw = 0; for (let i = info.start; i < info.start + info.span; i++) cw += w[i];
	    $('tpColW').value = Math.round(cw * 10) / 10;
	    $('tpColW').disabled = info.span > 1 || g.n < 2;
	    $('tpPad').value = num(table.getAttribute('cellpadding'));
	    $('tpHeadChk').checked = !!table.tHead;
	    $('tpBorderChk').checked = table.getAttribute('border') === '1';
	    padHint();
	    openModal('tableModal', $('tpColW').disabled ? $('tpPad') : $('tpColW'));
	}
	function padHint() { $('tpPadHint').textContent = `About ${toU(num($('tpPad').value) * 0.75)} ${unit().name} in the PDF.`; }
	$('tpPad').addEventListener('input', padHint);
	$('tpApply').addEventListener('click', () => {
	    const cell = dlgCell;
	    if (!cell || !cell.isConnected) { closeModal(); return; }
	    const table = cell.closest('table');
	    closeModal();
	    if (!$('tpColW').disabled) setColWidth(cell, num($('tpColW').value));
	    table.setAttribute('cellpadding', String(Math.max(0, Math.min(40, Math.round(num($('tpPad').value))))));
	    setCur(cell);
	    if ($('tpBorderChk').checked !== (table.getAttribute('border') === '1')) tableOp('border');
	    if ($('tpHeadChk').checked !== !!table.tHead) { if (!curCell || !curCell.isConnected) setCur(table.rows[0].cells[0]); tableOp('header'); }
	    changed(true); placeTableHandles();
	    toast('Table properties applied.');
	});

	/* ---------- The table menu (right-click), as in Word ---------- */
	const tmenu = $('tableMenu');
	let tmenuByKey = 0;
	function closeTableMenu(refocus) {
	    if (tmenu.hidden) return;
	    tmenu.hidden = true; tmenu.classList.remove('show');
	    tmenu.querySelectorAll('.sub.open').forEach((s) => { s.classList.remove('open'); s.firstElementChild.setAttribute('aria-expanded', 'false'); });
	    if (refocus && curCell && curCell.isConnected) restore();
	}
	function openTableMenu(cell, x, y) {
	    closeMenus(); closePicker();
	    setCur(cell);
	    const table = cell.closest('table'), cols = gridOf(table).n;
	    tmenu.querySelector('[data-top="header"]').setAttribute('aria-checked', String(!!table.tHead));
	    tmenu.querySelector('[data-top="border"]').setAttribute('aria-checked', String(table.getAttribute('border') === '1'));
	    tmenu.querySelector('[data-top="repeat"]').setAttribute('aria-checked', String(cell.parentElement.hasAttribute('data-each')));
	    tmenu.querySelector('[data-top="distribute"]').disabled = cols < 2;
	    tmenu.hidden = false; tmenu.classList.add('show');
	    //the menu and its submenus on screen
	    const w = tmenu.offsetWidth, h = tmenu.offsetHeight, vw = document.documentElement.clientWidth, vh = innerHeight;
	    const left = x + w > vw - 8 ? Math.max(8, x - w) : x, top = y + h > vh - 8 ? Math.max(8, y - h) : y;
	    tmenu.style.left = left + 'px'; tmenu.style.top = top + 'px';
	    tmenu.classList.toggle('flip-sub', left + w + 260 > vw);
	    tmenu.classList.toggle('lift-sub', top + h + 120 > vh);
	    tmenu.querySelector('.dropdown-item').focus({ preventScroll: true });
	}
	Object.values(editors).forEach((ed) => {
	    if (ro) return;
	    ed.addEventListener('contextmenu', (e) => {
		//the Menu key / Shift+F10 also fire a contextmenu event (button 0): the menu is already open
		if (e.button !== 2 && Date.now() - tmenuByKey < 600) { tmenuByKey = 0; e.preventDefault(); return; }
		const cell = e.target.closest('td, th');
		if (!cell || !ed.contains(cell) || e.shiftKey) return; //Shift+right-click: the browser's own menu
		e.preventDefault();
		const s = getSelection();
		if (!s.rangeCount || !cell.contains(s.anchorNode)) {
		    const r = document.caretRangeFromPoint && document.caretRangeFromPoint(e.clientX, e.clientY);
		    if (r && cell.contains(r.startContainer)) { ed.focus({ preventScroll: true }); s.removeAllRanges(); s.addRange(r); savedRange = r.cloneRange(); }
		}
		openTableMenu(cell, e.clientX, e.clientY);
	    });
	    ed.addEventListener('keydown', (e) => {
		if (!((e.key === 'F10' && e.shiftKey) || e.key === 'ContextMenu')) return;
		const s = getSelection(), n = s.anchorNode && (s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentElement);
		const cell = n && n.closest('td, th');
		if (!cell || !ed.contains(cell)) return;
		e.preventDefault(); tmenuByKey = Date.now();
		const r = cell.getBoundingClientRect();
		openTableMenu(cell, r.left + 8, r.bottom - 4);
	    });
	});
	tmenu.addEventListener('mousedown', (e) => e.preventDefault()); //the editor keeps its selection
	tmenu.addEventListener('click', (e) => {
	    const d = e.target.closest('[data-dlg]');
	    if (d) { closeTableMenu(true); if (d.dataset.dlg === 'cell') openCellDialog(); else openTableDialog(); return; }
	    const b = e.target.closest('[data-top]');
	    if (!b || b.disabled) return;
	    closeTableMenu();
	    tableOp(b.dataset.top);
	});
	function openSub(sub, focusFirst) {
	    tmenu.querySelectorAll('.sub.open').forEach((s) => { if (s !== sub) { s.classList.remove('open'); s.firstElementChild.setAttribute('aria-expanded', 'false'); } });
	    sub.classList.add('open'); sub.firstElementChild.setAttribute('aria-expanded', 'true');
	    if (focusFirst) sub.querySelector('.sub-menu .dropdown-item').focus();
	}
	tmenu.addEventListener('mouseover', (e) => {
	    const sub = e.target.closest('.sub');
	    tmenu.querySelectorAll('.sub.open').forEach((s) => { if (s !== sub) { s.classList.remove('open'); s.firstElementChild.setAttribute('aria-expanded', 'false'); } });
	});
	//the keys in the table menu (from the window's capture listener below)
	function tmenuKeys(e) {
	    const it = e.target.closest('.dropdown-item'); if (!it) return;
	    const level = it.closest('.dropdown-menu');
	    const items = [ ...level.children ].map((li) => li.querySelector(':scope > .dropdown-item')).filter((x) => x && !x.disabled);
	    const i = items.indexOf(it), sub = it.classList.contains('has-sub') ? it.parentElement : null;
	    if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
	    else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
	    else if (e.key === 'Home') { e.preventDefault(); items[0].focus(); }
	    else if (e.key === 'End') { e.preventDefault(); items[items.length - 1].focus(); }
	    else if ((e.key === 'ArrowRight' || e.key === 'Enter' || e.key === ' ') && sub) { e.preventDefault(); openSub(sub, true); }
	    else if (e.key === 'ArrowLeft' && level.classList.contains('sub-menu')) {
		e.preventDefault(); const parent = level.parentElement; parent.classList.remove('open'); parent.firstElementChild.setAttribute('aria-expanded', 'false'); parent.firstElementChild.focus();
	    }
	    else if (e.key === 'Escape') { e.preventDefault(); closeTableMenu(true); }
	    else if (e.key === 'Tab') { e.preventDefault(); closeTableMenu(true); }
	}
	listen(document, 'pointerdown', (e) => { if (!tmenu.hidden && !tmenu.contains(e.target)) closeTableMenu(); }, true);
	listen(window, 'resize', () => closeTableMenu());
	listen(window, 'blur', () => closeTableMenu());
	listen(document, 'scroll', () => closeTableMenu(), true);

	/* ---------- Insert table ---------- */
	const TP = 8;
	$('tpGrid').innerHTML = Array.from({ length: TP * TP }, (_, k) => `<span data-r="${Math.floor(k / TP) + 1}" data-c="${k % TP + 1}"></span>`).join('');
	function tpShow(c, r) {
	    $('tpGrid').querySelectorAll('span').forEach((s) => s.classList.toggle('on', +s.dataset.c <= c && +s.dataset.r <= r));
	    $('tpLabel').textContent = `${c} column${c > 1 ? 's' : ''} × ${r} row${r > 1 ? 's' : ''}${$('tpHead').checked ? ' + header' : ''}`;
	}
	function tpSync() { tpShow(Math.max(1, Math.min(20, Math.round(num($('tpCols').value)) || 1)), Math.max(1, Math.min(200, Math.round(num($('tpRows').value)) || 1))); }
	function togglePicker(btn) {
	    const pk = $('tablePicker');
	    if (!pk.hidden) { closePicker(); return; }
	    pk.hidden = false;
	    const panel = $('editPanel'), pr = panel.getBoundingClientRect(), br = btn.getBoundingClientRect();
	    pk.style.top = (br.bottom - pr.top + 6) + 'px';
	    pk.style.left = Math.max(8, Math.min(br.left - pr.left, panel.clientWidth - pk.offsetWidth - 8)) + 'px';
	    tpSync();
	}
	function closePicker() { $('tablePicker').hidden = true; }
	$('tablePicker').addEventListener('mousedown', (e) => { if (e.target.closest('span[data-r], button')) e.preventDefault(); });
	$('tpGrid').addEventListener('mouseover', (e) => { const s = e.target.closest('span[data-r]'); if (s) tpShow(+s.dataset.c, +s.dataset.r); });
	$('tpGrid').addEventListener('mouseleave', tpSync);
	$('tpGrid').addEventListener('click', (e) => {
	    const s = e.target.closest('span[data-r]'); if (!s) return;
	    $('tpCols').value = s.dataset.c; $('tpRows').value = s.dataset.r; insertTable();
	});
	[ 'tpCols', 'tpRows', 'tpHead' ].forEach((id) => $(id).addEventListener('input', tpSync));
	$('tpInsert').addEventListener('click', insertTable);
	$('tablePicker').addEventListener('keydown', (e) => {
	    if (e.key === 'Escape') { e.stopPropagation(); closePicker(); active().focus(); }
	    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type === 'number') { e.preventDefault(); insertTable(); }
	});
	function insertTable() {
	    const cols = Math.max(1, Math.min(20, Math.round(num($('tpCols').value)) || 1));
	    const rows = Math.max(1, Math.min(200, Math.round(num($('tpRows').value)) || 1));
	    const head = $('tpHead').checked, border = $('tpBorder').checked;
	    const ws = []; let used = 0;
	    for (let i = 0; i < cols; i++) { const v = i < cols - 1 ? r1t(100 / cols) : r1t(100 - used); ws.push(v); used += v; }
	    const cells = (tag) => ws.map((w) => `<${tag} width="${w}%"${tag === 'th' ? ' align="left"' : ''}><br></${tag}>`).join('');
	    const html = `<table${border ? ' border="1"' : ''} cellpadding="4" width="100%">`
		+ (head ? `<thead><tr>${cells('th')}</tr></thead>` : '')
		+ Array.from({ length: rows }, () => `<tr>${cells('td')}</tr>`).join('') + '</table><p><br></p>';
	    closePicker();
	    const ed = active(), before = new Set(ed.querySelectorAll('table'));
	    exec('insertHTML', html);
	    const tb = [ ...ed.querySelectorAll('table') ].find((x) => !before.has(x));
	    if (tb && tb.rows[0]) { caretIn(tb.rows[0].cells[0]); trackCell(); toast('Right-click a cell (or press Shift+F10) for rows, columns, cell and table properties.'); }
	}
	//a table whose row repeats for each item of a list (the first list of the sample data, its fields)
	function insertRepeatTable() {
	    const [ list, found ] = guessList();
	    const fields = found.length ? found.slice(0, 6) : [ 'description', 'qty', 'price' ];
	    const w = Math.floor(100 / fields.length);
	    const ws = fields.map((f, i) => i === 0 ? 100 - w * (fields.length - 1) : w);
	    const label = (f) => f.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
	    const ed = active(), before = new Set(ed.querySelectorAll('table'));
	    exec('insertHTML', '<table border="1" cellpadding="4" width="100%"><thead><tr>'
		+ fields.map((f, i) => `<th width="${ws[i]}%" align="left" bgcolor="#e6eeed"><b>${app.utils.escapeHtml(label(f))}</b></th>`).join('')
		+ `</tr></thead><tr data-each="${app.utils.escapeHtml(list)}" data-as="item">`
		+ fields.map((f, i) => `<td width="${ws[i]}%">{{ item.${f} }}</td>`).join('')
		+ '</tr></table><p><br></p>');
	    const tb = [ ...ed.querySelectorAll('table') ].find((x) => !before.has(x));
	    if (tb && tb.tBodies[0] && tb.tBodies[0].rows[0]) { caretIn(tb.tBodies[0].rows[0].cells[0]); trackCell(); }
	    toast(found.length ? `The row repeats for each item of ${list}; set another list above the page.` : 'Set the list the row repeats for above the page (e.g. {{ invoice.items }}).');
	}

	/* ---------- Toolbar ---------- */
	$('toolbar').addEventListener('mousedown', (e) => { if (e.target.closest('button')) e.preventDefault(); });
	function closeMenus(focusToggle) {
	    $$('.pd-toolbar .dropdown-menu.show').forEach((m) => {
		const tg = m.previousElementSibling;
		m.classList.remove('show'); tg.classList.remove('show'); tg.setAttribute('aria-expanded', 'false');
		if (focusToggle) tg.focus();
	    });
	}
	function openMenu(toggle, focusFirst) {
	    closeMenus();
	    const m = toggle.nextElementSibling;
	    m.classList.remove('dropdown-menu-end');
	    m.classList.add('show'); toggle.classList.add('show'); toggle.setAttribute('aria-expanded', 'true');
	    if (m.getBoundingClientRect().right > document.documentElement.clientWidth - 8) m.classList.add('dropdown-menu-end');
	    if (toggle.dataset.dd === 'vars') loadVariables(m);
	    updateAlignUI(); updateFirstLineBtn();
	    if (focusFirst) (m.querySelector('.dropdown-item.active') || m.querySelector('.dropdown-item')).focus();
	}
	listen(document, 'pointerdown', (e) => { if (!e.target.closest('.pd-toolbar .btn-group')) closeMenus(); });
	listen(document, 'keydown', (e) => {
	    const open = root.querySelector('.pd-toolbar .dropdown-menu.show');
	    if (open && e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenus(open.contains(document.activeElement)); }
	}, true);
	$('toolbar').addEventListener('keydown', (e) => {
	    const toggle = e.target.closest('[data-dd]');
	    if (toggle && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); openMenu(toggle, true); }
	});
	//the keys in a toolbar menu (from the window's capture listener below)
	function menuKeys(e) {
	    const it = e.target.closest('.dropdown-item'); if (!it) return;
	    const items = [ ...it.closest('.dropdown-menu').querySelectorAll('.dropdown-item') ], i = items.indexOf(it);
	    if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
	    else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
	    else if (e.key === 'Home') { e.preventDefault(); items[0].focus(); }
	    else if (e.key === 'End') { e.preventDefault(); items[items.length - 1].focus(); }
	    else if (e.key === 'Escape') { e.preventDefault(); closeMenus(true); }
	    else if (e.key === 'Tab') closeMenus();
	}
	//the keys in the designer's menus are handled here, first: Bootstrap's dropdown listens on the document's capture phase for any .dropdown-menu (and fails
	//on these, which are not its dropdowns); the step's dialog would close on Escape
	listen(window, 'keydown', (e) => {
	    const m = e.target && e.target.closest ? e.target.closest('.dropdown-menu') : null;
	    if (!m || !root.contains(m)) return;
	    e.stopPropagation();
	    if (tmenu.contains(m)) tmenuKeys(e); else menuKeys(e);
	}, true);
	//"{{ }}": the page numbers, the variables of the step, the project variables (vars.<name>; app.ExprInput.menuVariables)
	async function loadVariables(menu) {
	    const page = '<li><h6 class="dropdown-header">Page</h6></li>'
		+ '<li><button type="button" class="dropdown-item" data-ins="{PAGE}">Page number</button></li><li><button type="button" class="dropdown-item" data-ins="{PAGES}">Page count</button></li>';
	    const e = o.expression;
	    if (!e) { menu.innerHTML = page; return; }
	    menu.innerHTML = page + '<li><hr class="dropdown-divider"></li><li class="dropdown-item-text text-muted">Loading the variables…</li>';
	    const v = await window.app.ExprInput.menuVariables(e.url, e.params ? e.params() : {});
	    const list = (items) => items.map((it) => `<li><button type="button" class="dropdown-item" data-ins="{{ ${app.utils.escapeHtml(it.label)} }}"><code>${app.utils.escapeHtml(it.label)}</code><span class="text-muted ms-2 text-truncate">${app.utils.escapeHtml(it.detail || '')}</span></button></li>`).join('');
	    menu.innerHTML = page + '<li><hr class="dropdown-divider"></li><li><h6 class="dropdown-header">Variables</h6></li>'
		+ (v.step.length ? list(v.step) : '<li class="dropdown-item-text text-muted">No variables before this step</li>')
		+ (v.project.length ? '<li><hr class="dropdown-divider"></li><li><h6 class="dropdown-header">Project variables</h6></li>' + list(v.project) : '');
	}
	$('varsMenu').addEventListener('click', (e) => {
	    const b = e.target.closest('[data-ins]'); if (!b) return;
	    closeMenus();
	    exec('insertText', b.dataset.ins);
	});
	//the alignment button shows the paragraph's alignment, its menu item marked
	function currentAlign() {
	    const ed = active(), s = getSelection();
	    if (!isSection(leftTab) || sourceMode || !s.rangeCount || !ed.contains(s.anchorNode)) return null;
	    let el = s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentElement;
	    el = el && el.closest('p, h1, h2, h3, h4, h5, h6, li, td, th, div:not(.editor), pre');
	    if (!el || !ed.contains(el)) return 'left';
	    const a = (el.getAttribute('align') || getComputedStyle(el).textAlign || '').toLowerCase();
	    return a.includes('center') ? 'center' : (a.includes('right') || a === 'end') ? 'right' : a === 'justify' ? 'justify' : 'left';
	}
	function updateAlignUI() {
	    const a = currentAlign() || 'left', dd = $('alignDd');
	    dd.querySelectorAll('[data-align]').forEach((b) => b.classList.toggle('active', b.dataset.align === a));
	    const src = dd.querySelector(`[data-align="${a}"] svg`);
	    if (src && $('alignIcon').dataset.a !== a) { $('alignIcon').innerHTML = src.outerHTML; $('alignIcon').dataset.a = a; }
	}
	listen(document, 'selectionchange', updateAlignUI);
	$('toolbar').addEventListener('click', (e) => {
	    const toggle = e.target.closest('[data-dd]');
	    if (toggle) { if (toggle.nextElementSibling.classList.contains('show')) closeMenus(); else openMenu(toggle, e.detail === 0); return; }
	    const b = e.target.closest('button[data-cmd]'); if (!b) return;
	    if (b.closest('.dropdown-menu')) { closeMenus(); setTimeout(() => { updateAlignUI(); updateFirstLineBtn(); }, 0); }
	    const cmd = b.dataset.cmd;
	    const plain = () => { if (sourceMode) { toast('Switch off HTML source to use the toolbar.'); return false; } return true; };
	    if (cmd === 'table') { if (plain()) togglePicker(b); }
	    else if (cmd === 'rtable') { if (plain()) insertRepeatTable(); }
	    else if (cmd === 'indent' || cmd === 'outdent') { if (plain()) { restore(); onTab(cmd === 'outdent', true); } }
	    else if (cmd === 'firstLine') { if (plain()) { restore(); toggleFirstLine(); } }
	    else if (cmd === 'cond') insertCond();
	    else if (cmd === 'hr') exec('insertHorizontalRule');
	    else if (cmd === 'image') openImagePicker();
	    else if (cmd === 'source') openSource();
	    else if (cmd === 'link') { if (plain()) { $('linkRow').hidden = false; $('linkUrl').value = ''; $('linkUrl').focus(); } }
	    else exec(cmd);
	});
	$('linkApply').addEventListener('click', () => {
	    const u = $('linkUrl').value.trim(); $('linkRow').hidden = true;
	    if (u) exec('createLink', u);
	});
	$('linkCancel').addEventListener('click', () => { $('linkRow').hidden = true; });
	$('linkUrl').addEventListener('keydown', (e) => {
	    if (e.key === 'Enter') { e.preventDefault(); $('linkApply').click(); }
	    if (e.key === 'Escape') { e.stopPropagation(); $('linkCancel').click(); }
	});
	$('blockSel').addEventListener('change', (e) => { const v = e.target.value; e.target.value = ''; if (v) exec('formatBlock', '<' + v + '>'); });
	$('sizeSel').addEventListener('change', (e) => {
	    const v = e.target.value; e.target.value = ''; if (!v) return;
	    if (!exec('fontSize', '7')) return;
	    active().querySelectorAll('font[size="7"]').forEach((f) => {
		const s = document.createElement('span'); s.setAttribute('style', 'font-size:' + v + 'pt');
		while (f.firstChild) s.appendChild(f.firstChild); f.replaceWith(s);
	    });
	    changed();
	});
	$('colorIn').addEventListener('change', (e) => exec('foreColor', e.target.value));

	/* ---------- The HTML source ---------- */
	const ZONE_NAMES = { header: 'Header', body: 'Body', footer: 'Footer' };
	let srcZone = 'body';
	function openSource() {
	    selectImg(null); selectCond(null); setCur(null); closePicker();
	    syncFromEditor(); srcZone = leftTab;
	    $('srcModalTitle').textContent = 'HTML · ' + ZONE_NAMES[srcZone];
	    $('htmlSrc').value = pretty(state[srcZone] || '');
	    openModal('srcModal', $('htmlSrc'));
	}
	$('srcApply').addEventListener('click', () => {
	    state[srcZone] = $('htmlSrc').value;
	    setEditor(editors[srcZone], state[srcZone]);
	    closeModal(); emit(); render();
	});

	/* ---------- The sections on the page ---------- */
	const HINTS = {
	    header: 'Header: printed at the top of every page. Drag its bottom edge to change where the body starts. Tables with widths place things left and right.',
	    body: 'Body: flows over pages (red lines: where a new page starts, as written; the values make it longer or shorter). Tab / Shift+Tab indent and move between table cells.',
	    footer: 'Footer: printed at the bottom of every page. Drag its top edge to change where the body stops. Page no. and Page count are filled in when the PDF is made.',
	};
	function setHint() { $('editHint').textContent = ro ? '' : (HINTS[leftTab] || ''); }
	const editorOf = (n) => Object.values(editors).find((ed) => ed.contains(n)) || null;
	//the section being edited follows the focus, so the toolbar acts on it
	Object.entries(editors).forEach(([k, ed]) => ed.addEventListener('focusin', () => {
	    if (leftTab === k) return;
	    syncFromEditor();
	    selectImg(null); selectCond(null); setCur(null); closePicker(); $('linkRow').hidden = true;
	    leftTab = k; savedRange = null; setHint();
	}));

	const sheet = $('sheet'), desk = $('desk');
	const Z_ = { zh: $('zoneHeader'), zb: $('zoneBody'), zf: $('zoneFooter'), hh: $('handleHeader'), hf: $('handleFooter') };
	let zoomNow = 1;
	function applyZoom() {
	    const { W } = dims();
	    const fit = Math.min(1.25, Math.max(0.3, (desk.clientWidth - 32) / (W * PT)));
	    const z = ui.zoom === 'fit' ? fit : (num(ui.zoom) || 1);
	    if (Math.abs(z - zoomNow) < 0.001 && sheet.style.getPropertyValue('--z')) return;
	    zoomNow = Math.round(z * 1000) / 1000;
	    sheet.style.setProperty('--z', zoomNow);
	    placeFrame();
	}
	//the page: the header zone (0 → top margin), the body (whole pages), the footer zone
	let bodyPages = 1;
	function layoutSheet() {
	    const p = state.page, { W, H } = dims();
	    const ml = num(p.ml), mr = num(p.mr), mt = num(p.mt), mb = num(p.mb), mh = num(p.mh), mf = num(p.mf);
	    const cw = Math.max(10, W - ml - mr), bodyH = Math.max(10, H - mt - mb);
	    const hOn = p.headerOn !== false, fOn = p.footerOn !== false;
	    sheet.style.width = W + 'pt';
	    Object.assign(Z_.zh.style, { height: mt + 'pt' });
	    Z_.zh.classList.toggle('off', !hOn);
	    Object.assign(editors.header.style, { left: ml + 'pt', top: mh + 'pt', width: cw + 'pt' });
	    editors.header.hidden = !hOn; Z_.zh.querySelector('.zone-off-label').hidden = hOn;
	    Object.assign(Z_.zb.style, { marginTop: mt + 'pt', paddingLeft: ml + 'pt', paddingRight: mr + 'pt' });
	    editors.body.style.minHeight = bodyH + 'pt';
	    const contentPt = editors.body.offsetHeight / PT; //the layout size, not the zoomed one
	    bodyPages = Math.min(99, Math.max(1, Math.ceil(contentPt / bodyH - 0.001)));
	    Z_.zb.style.height = (bodyPages * bodyH) + 'pt';
	    const fTop = mt + bodyPages * bodyH;
	    Object.assign(Z_.zf.style, { top: fTop + 'pt', height: mb + 'pt' });
	    Z_.zf.classList.toggle('off', !fOn);
	    Object.assign(editors.footer.style, { left: ml + 'pt', top: Math.max(0, mb - mf) + 'pt', width: cw + 'pt' });
	    editors.footer.hidden = !fOn; Z_.zf.querySelector('.zone-off-label').hidden = fOn;
	    sheet.style.height = (fTop + mb) + 'pt';
	    //the dashed boxes: where the header and the footer are printed
	    Object.assign($('boxHeader').style, { left: ml + 'pt', width: cw + 'pt', top: mh + 'pt', height: Math.max(0, mt - mh) + 'pt' });
	    Object.assign($('boxFooter').style, { left: ml + 'pt', width: cw + 'pt', top: (fTop + Math.max(0, mb - mf)) + 'pt', height: Math.min(mb, mf) + 'pt' });
	    $('boxHeader').hidden = !hOn; $('boxFooter').hidden = !fOn;
	    let br = '';
	    for (let k = 1; k < bodyPages; k++) br += `<div class="page-break" style="top:${mt + k * bodyH}pt"><span>Page ${k + 1}</span></div>`;
	    if ($('breaks').innerHTML !== br) $('breaks').innerHTML = br;
	    Z_.hh.style.top = mt + 'pt'; Z_.hf.style.top = fTop + 'pt';
	    Z_.hh.querySelector('.size-tag').textContent = (hOn ? 'Header ' : 'Top margin ') + fmtU(mt);
	    Z_.hf.querySelector('.size-tag').textContent = (fOn ? 'Footer ' : 'Bottom margin ') + fmtU(mb);
	    Z_.hh.setAttribute('aria-valuetext', Z_.hh.querySelector('.size-tag').textContent);
	    Z_.hf.setAttribute('aria-valuetext', Z_.hf.querySelector('.size-tag').textContent);
	    placeFrame();
	}
	//the header's bottom edge sets the top margin; the footer's top edge the bottom margin (the footer keeps its distance from that edge)
	function setTopMargin(v) {
	    const p = state.page, { H } = dims();
	    const min = p.headerOn !== false ? num(p.mh) + 6 : 0, max = H - num(p.mb) - 36;
	    p.mt = r2(Math.min(max, Math.max(min, snapU(v))));
	}
	function setBottomMargin(v, gap) {
	    const p = state.page, { H } = dims();
	    const min = p.footerOn !== false ? Math.max(6, gap + 6) : 0, max = H - num(p.mt) - 36;
	    p.mb = r2(Math.min(max, Math.max(min, snapU(v))));
	    p.mf = r2(Math.max(0, p.mb - gap));
	}
	function commitLayout() { emit(); fillForm(); render(); }
	function wireHandle(h, kind) {
	    if (ro) return;
	    h.addEventListener('pointerdown', (e) => {
		e.preventDefault(); h.setPointerCapture(e.pointerId); h.focus({ preventScroll: true });
		const p = state.page, y0 = e.clientY, z = zoomNow, mt0 = num(p.mt), mb0 = num(p.mb), gap = num(p.mb) - num(p.mf);
		h.classList.add('drag'); document.body.classList.add('pd-resizing');
		const move = (ev) => {
		    const d = (ev.clientY - y0) / z / PT;
		    if (kind === 'header') setTopMargin(mt0 + d); else setBottomMargin(mb0 - d, gap);
		    layoutSheet();
		};
		const up = () => {
		    h.removeEventListener('pointermove', move); h.removeEventListener('pointerup', up); h.removeEventListener('pointercancel', up);
		    h.classList.remove('drag'); document.body.classList.remove('pd-resizing');
		    commitLayout();
		};
		h.addEventListener('pointermove', move); h.addEventListener('pointerup', up); h.addEventListener('pointercancel', up);
	    });
	    h.addEventListener('keydown', (e) => {
		if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
		e.preventDefault();
		const p = state.page, d = unit().step * unit().k * (e.shiftKey ? 10 : 2) * (e.key === 'ArrowDown' ? 1 : -1);
		if (kind === 'header') setTopMargin(num(p.mt) + d); else setBottomMargin(num(p.mb) - d, num(p.mb) - num(p.mf));
		commitLayout();
	    });
	}
	wireHandle(Z_.hh, 'header'); wireHandle(Z_.hf, 'footer');
	//a click in a section's empty space: the cursor at the end of its text
	[ [ Z_.zh, 'header' ], [ Z_.zb, 'body' ], [ Z_.zf, 'footer' ] ].forEach(([z, k]) => z.addEventListener('mousedown', (e) => {
	    if (ro || e.target !== z || editors[k].hidden) return;
	    e.preventDefault();
	    const ed = editors[k]; ed.focus();
	    const r = document.createRange(); r.selectNodeContents(ed); r.collapse(false);
	    const s = getSelection(); s.removeAllRanges(); s.addRange(r);
	}));
	//the header and the footer on or off
	function syncSectionButtons() {
	    press($('headerTgl'), state.page.headerOn !== false);
	    press($('footerTgl'), state.page.footerOn !== false);
	}
	function setSection(kind, on) {
	    syncFromEditor();
	    state.page[kind + 'On'] = on;
	    if (!on && leftTab === kind) { editors[kind].blur(); leftTab = 'body'; setHint(); }
	    syncSectionButtons(); emit(); render();
	    toast(`${ZONE_NAMES[kind]} ${on ? 'on' : 'off'}${on ? '' : '. Its content is kept, but not printed while it is off'}.`);
	}
	$('headerTgl').addEventListener('click', () => setSection('header', state.page.headerOn === false));
	$('footerTgl').addEventListener('click', () => setSection('footer', state.page.footerOn === false));
	$('zoomSel').addEventListener('change', (e) => { ui.zoom = e.target.value; saveUi(); applyZoom(); layoutSheet(); });
	$('f-unit').addEventListener('change', (e) => { ui.unit = UNITS[e.target.value] ? e.target.value : 'mm'; saveUi(); fillForm(true); render(); if (selImg) fillImgRow(); });

	/* ---------- Dialogs: overlays on the designer (inside the step's dialog) ---------- */
	let openModalEl = null, modalReturn = null, modalRange = null;
	function openModal(name, focusEl) {
	    closeMenus(); closePicker();
	    if (openModalEl) closeModal();
	    const m = $(name);
	    modalReturn = document.activeElement;
	    //the caret, put back where it was when the dialog closes (e.g. in a table cell)
	    { const sel = getSelection(); modalRange = (sel.rangeCount && modalReturn && modalReturn.isContentEditable) ? sel.getRangeAt(0).cloneRange() : null; }
	    m.hidden = false;
	    openModalEl = m;
	    (focusEl || m.querySelector('.card-body input:not([type=file]), .card-body select, .card-body textarea') || m.querySelector('[data-close]')).focus();
	}
	function closeModal() {
	    const m = openModalEl; if (!m) return;
	    m.hidden = true;
	    openModalEl = null;
	    if (modalReturn && modalReturn.isConnected && modalReturn.focus) modalReturn.focus({ preventScroll: true });
	    if (modalRange && modalRange.startContainer.isConnected && modalReturn && modalReturn.contains(modalRange.startContainer)) { const sel = getSelection(); sel.removeAllRanges(); sel.addRange(modalRange); }
	    modalRange = null;
	}
	root.addEventListener('mousedown', (e) => { if (openModalEl && e.target === openModalEl) closeModal(); });
	root.addEventListener('click', (e) => { if (openModalEl && e.target.closest('[data-close]')) closeModal(); });
	//Escape closes the overlay, not the step's dialog; Tab stays in the overlay
	listen(document, 'keydown', (e) => {
	    if (!openModalEl) return;
	    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeModal(); return; }
	    if (e.key === 'Tab') {
		const f = [ ...openModalEl.querySelectorAll('button, input, select, textarea') ].filter((x) => !x.disabled && x.offsetParent !== null);
		if (!f.length) return;
		if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
		else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
	    }
	}, true);
	$('pageBtn').addEventListener('click', () => { fillForm(); openModal('pageModal'); });
	$('dataBtn').addEventListener('click', () => { $('dataSrc').value = state.sample; openModal('dataModal', $('dataSrc')); });
	$('checksBtn').addEventListener('click', () => { render(); openModal('checksModal', $('checksModal').querySelector('[data-close]')); });

	/* ---------- Page setup, sample data ---------- */
	const FIELDS = { format: 'f-format', orient: 'f-orient', font: 'f-font', size: 'f-size', mt: 'f-mt', mb: 'f-mb', ml: 'f-ml', mr: 'f-mr', mh: 'f-mh', mf: 'f-mf', indent: 'f-indent' };
	const LEN = new Set([ 'mt', 'mb', 'ml', 'mr', 'mh', 'mf', 'indent' ]); //stored in pt, shown in the unit chosen
	function fillForm(force) {
	    Object.entries(FIELDS).forEach(([k, n]) => {
		const el = $(n);
		el.disabled = ro;
		if (LEN.has(k)) { el.step = unit().step; if (force || document.activeElement !== el) el.value = toU(num(state.page[k])); }
		else el.value = state.page[k];
	    });
	    $('f-unit').value = ui.unit;
	    $('zoomSel').value = ui.zoom;
	    $$('.u-label').forEach((l) => { l.textContent = unit().name; });
	}
	Object.entries(FIELDS).forEach(([k, n]) => $(n).addEventListener('input', (e) => {
	    state.page[k] = LEN.has(k) ? fromU(e.target.value) : (k === 'size' ? num(e.target.value) : e.target.value);
	    emit(); if (k === 'format' || k === 'orient') applyZoom();
	    clearTimeout(t); t = setTimeout(render, 120);
	}));
	$('dataSrc').addEventListener('input', (e) => { state.sample = e.target.value; emit(); clearTimeout(t); t = setTimeout(render, 200); });
	//the sample invoice (a button armed by a first click)
	$('sampleBtn').addEventListener('click', () => {
	    const b = $('sampleBtn');
	    if (!b.classList.contains('armed')) {
		b.classList.add('armed', 'btn-danger'); b.classList.remove('btn-light', 'border'); b.textContent = 'Replace the document?';
		setTimeout(() => { b.classList.remove('armed', 'btn-danger'); b.classList.add('btn-light', 'border'); b.textContent = 'Replace with the sample invoice'; }, 3200);
		return;
	    }
	    closeModal();
	    load(Object.assign({}, SAMPLE, { page: emitted ? emitted.page : null }));
	    emit();
	    toast('The sample invoice: Preview PDF fills it with the sample data.');
	});

	/* ---------- Preview: the PDF of the sample data, in a new tab ---------- */
	$('pdfBtn').addEventListener('click', async () => {
	    syncFromEditor(); const value = emit(); clearTimeout(t); render();
	    if (!o.previewUrl) { toast('No preview here.'); return; }
	    const tab = window.open('', '_blank');
	    const btn = $('pdfBtn');
	    btn.disabled = true;
	    try {
		const body = new URLSearchParams();
		body.set('design', JSON.stringify(value));
		const res = await fetch(o.previewUrl, { method: 'POST', body, credentials: 'same-origin',
		    headers: { 'X-Csrf-Token': app.utils.csrf(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/pdf, application/json' } });
		const type = res.headers.get('Content-Type') || '';
		if (res.ok && type.includes('application/pdf')) {
		    const url = URL.createObjectURL(await res.blob());
		    if (tab) tab.location = url; else window.open(url, '_blank');
		    setTimeout(() => URL.revokeObjectURL(url), 60000);
		} else {
		    const data = type.includes('json') ? await res.json() : null;
		    if (tab) tab.close();
		    toast('Preview: ' + ((data && data.error) || ('the server answered ' + res.status)), 6000);
		}
	    } catch (err) {
		if (tab) tab.close();
		toast('Preview: the request failed.', 6000);
	    } finally {
		btn.disabled = false;
	    }
	});

	let tt = null;
	function toast(msg, ms) { const el = $('toast'); $('toastBody').textContent = msg; el.classList.add('show'); clearTimeout(tt); tt = setTimeout(() => el.classList.remove('show'), ms || 2600); }
	function press(btn, on) { btn.classList.toggle('active', !!on); btn.setAttribute('aria-pressed', String(!!on)); }

	/* ---------- The right panel: the step's settings ---------- */
	let stepPanel = null;
	function renderProps() {
	    const panel = $('props');
	    panel.innerHTML = '';
	    if (stepPanel) {
		panel.insertAdjacentHTML('beforeend', '<div class="small text-uppercase text-muted fw-semibold mb-2">Step settings</div>');
		panel.appendChild(stepPanel);
	    } else {
		panel.innerHTML = '<div class="text-muted small">Page setup, sample data and the preview are above the page.</div>';
	    }
	}

	//the problem that keeps the step from being saved ('' when none)
	function problem() {
	    const v = emitted || {};
	    if (!String(v.body || '').replace(/<(?!img|table|hr)[^>]*>|&nbsp;|\s/gi, '')) return 'Write the document\'s body.';
	    const all = String(v.header || '') + String(v.body || '') + String(v.footer || '');
	    const opens = (all.match(/\{\{#if\s/g) || []).length, closes = (all.match(/\{\{\/if\s*\}\}/g) || []).length;
	    if (opens !== closes) return 'The conditions don\'t match up: check the HTML source.';
	    if (/\{\{#if\s*\}\}/.test(all)) return 'A conditional block has no condition.';
	    if (/<tr\b[^>]*\bdata-each=""/.test(all)) return 'A repeating row has no list.';
	    parseData();
	    if (dataError) return dataError.replace(/<[^>]+>/g, '');
	    return '';
	}
	function showProblem() {
	    const el = $('problem'), p = problem();
	    el.textContent = p;
	    el.hidden = !p;
	}

	/* ---------- Load a value ---------- */
	function load(v) {
	    const empty = !v || (!v.header && !v.body && !v.footer);
	    state = toState(empty ? Object.assign({}, STARTER, { page: v && v.page }) : v);
	    selectImg(null); selectCond(null); setCur(null);
	    Object.entries(editors).forEach(([k, ed]) => setEditor(ed, state[k]));
	    fillForm(); syncSectionButtons(); applyZoom(); setHint(); render();
	    return empty;
	}
	//the expression inputs of the bars: live help (app.ExprInput) with the step's variables
	if (!ro && o.expression && window.app.ExprInput) {
	    [ 'condExpr', 'rowEach' ].forEach((n) => new window.app.ExprInput($(n), { url: o.expression.url, params: o.expression.params }));
	}

	if (window.ResizeObserver) {
	    //the page laid out again when a section grows or shrinks, the zoom fitted again to the room
	    let q = 0, lastW = 0;
	    const rob = new ResizeObserver(() => { cancelAnimationFrame(q); q = requestAnimationFrame(layoutSheet); });
	    Object.values(editors).forEach((ed) => rob.observe(ed));
	    new ResizeObserver(() => { if (desk.clientWidth !== lastW) { lastW = desk.clientWidth; requestAnimationFrame(() => { applyZoom(); layoutSheet(); }); } }).observe(desk);
	} else listen(window, 'resize', () => { applyZoom(); layoutSheet(); });
	if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { if (root.isConnected) render(); });

	return {
	    setValue(v) {
		if (v && v === emitted) return;
		//a new document starts with the starter text, given to the step at once (what is shown is what is saved)
		const empty = !v || (!v.header && !v.body && !v.footer);
		emitted = empty ? null : v;
		load(v);
		if (empty) emit(); else showProblem();
	    },
	    getValue() { return emitted || emit(); },
	    problem,
	    setStepPanel(el) { stepPanel = el; renderProps(); },
	    select() { renderProps(); },
	};
    }

    class PdfDesigner {
	constructor(container, options) {
	    this.container = container;
	    this.o = Object.assign({ value: null, readonly: false, onChange: null, expression: null, fill: false, previewUrl: null }, options || {});
	    this.stepPanel = null;
	    this.d = null;
	    this.render();
	    this.setValue(this.o.value);
	}

	//the designer drawn again (option fill, set by the step's dialog, changes its layout); the document kept
	render() {
	    const value = this.d ? this.d.getValue() : null;
	    this.d = create(this.container, this.o);
	    if (value)
		this.d.setValue(value);
	    if (this.stepPanel)
		this.d.setStepPanel(this.stepPanel);
	}

	setValue(v) { this.d.setValue(v); }
	getValue() { return this.d.getValue(); }
	problem() { return this.d.problem(); }
	select() { this.d.select(); }

	//the step's other settings in the right panel; the designer is drawn again (the dialog has set option fill by now)
	setStepPanel(el) {
	    this.stepPanel = el;
	    this.render();
	}
    }

    return PdfDesigner;
})();
