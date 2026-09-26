// PROTOTYPE. The page: a stand-in for the forum, driving the pure Model. Throwaway.

const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
function icon(name, cls = '') {
  const def = (window.ICONS || {})[name];
  if (!def) return '';
  return `<svg class="ic ${def.brand ? 'ic--brand' : ''} ${cls}" viewBox="0 0 24 24" aria-hidden="true">${def.svg}</svg>`;
}
const asset = n => (window.ASSETS || {})[n] || '';

const App = (() => {
  let S = null;
  let pathId = 'citations';
  const listeners = [];
  const events = [];
  const ui = {};
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

  // --- plumbing -----------------------------------------------------------------------------

  function emit(type, data = {}) {
    events.push({ type, ...data });
    for (const f of listeners) f(type, data);
  }
  function commit(result, onOk) {
    if (result.errors && result.errors.length) { errorOverlay(result.errors); return false; }
    S = result.state;
    emit('change');
    onOk && onOk(result);
    return true;
  }
  function persona() { return Model.member(S, S.personaId); }
  function isManager() { return pathId === 'milpacs' || pathId === 'hq'; }
  function canCitations() { return pathId === 'citations' || pathId === 'hq'; }

  function route() { return location.hash.replace(/^#/, '') || '/rosters'; }
  function go(r) { if (route() === r) render(); else location.hash = r; }

  function reset(pid, startRoute) {
    pathId = pid;
    S = Model.seed(pid);
    events.length = 0;
    for (const k of Object.keys(ui)) delete ui[k];
    closeOverlay(); closeMenus();
    renderShell();
    if (startRoute) { if (route() === startRoute) render(); else location.hash = startRoute; }
    else render();
    emit('reset', { pathId: pid });
  }

  let flashTimer;
  function flash(msg) {
    let el = $('#flash');
    if (!el) { el = document.createElement('div'); el.id = 'flash'; el.className = 'flashMessage'; document.body.appendChild(el); }
    el.textContent = msg;
    requestAnimationFrame(() => el.classList.add('is-active'));
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => el.classList.remove('is-active'), 2600);
  }
  const nyi = () => flash('That isn\'t part of this prototype.');

  // --- formatting -----------------------------------------------------------------------------

  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function fmtLong(iso) { const [y, m, d] = iso.split('-').map(Number); return `${MON[m - 1]} ${d}, ${y}`; }
  function span(from, to) {
    const [y1, m1, d1] = from.split('-').map(Number), [y2, m2, d2] = to.split('-').map(Number);
    let months = (y2 - y1) * 12 + (m2 - m1) - (d2 < d1 ? 1 : 0);
    months = Math.max(0, months);
    return `Years: [${Math.floor(months / 12)}] Months: [${months % 12}]`;
  }
  function rankOf(m) { return Model.rank(S, m.rankId); }
  function rankedName(m) { return `${rankOf(m).title} ${m.username}`; }
  const rankOrder = id => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 26, 27, 28, 29, 30, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23].indexOf(id);
  function rankImg(id) { const a = asset('rank-' + id); return a ? `<span class="rank"><img src="${a}" alt=""></span>` : ''; }
  function typeTitle(kind, id) { return Model.typeTitle(S, kind, id) || ''; }
  function grantTitle(g) { return typeTitle(g.kind, g.typeId); }
  function thumb(c, maxW = 320) {
    const s = Math.min(1, maxW * 2 / c.width);
    const t = document.createElement('canvas');
    t.width = Math.round(c.width * s); t.height = Math.round(c.height * s);
    t.getContext('2d').drawImage(c, 0, 0, t.width, t.height);
    return t.toDataURL('image/jpeg', 0.82);
  }

  // --- shell ----------------------------------------------------------------------------------

  function renderShell() {
    const p = persona();
    $('#app').innerHTML = `
<div class="p-pageWrapper" style="--page-bg:url('${asset('page-bg')}')">
  <div class="p-staffBar"><div class="p-staffBar-inner">
    <a class="p-staffBar-link" data-act="nyi">Tickets<span class="badge">7</span></a><a class="p-staffBar-link p-staffBar-link--menu" data-act="nyi">Moderator</a>
  </div></div>
  <div class="p-navSticky" id="navSticky"><nav class="p-nav"><div class="p-nav-inner">
    <a class="p-nav-menuTrigger" data-menu="mobile-nav">${icon('menu')} Menu</a>
    <a class="p-nav-logo" href="#/rosters"><img src="${asset('logo')}" alt="7th Cavalry Gaming"></a>
    <ul class="p-nav-list">
      <li><div class="p-navEl"><a class="p-navEl-link p-navEl-link--split" data-act="nyi">${icon('messages-square')}Forums</a><a class="p-navEl-caret" data-act="nyi"></a></div></li>
      <li><div class="p-navEl is-selected"><a class="p-navEl-link p-navEl-link--split" href="#/rosters">Milpacs</a><a class="p-navEl-caret" data-menu="milpacs" data-proto="nav-milpacs"></a></div></li>
      <li><div class="p-navEl"><a class="p-navEl-link" data-act="nyi">Calendar</a></div></li>
      <li><div class="p-navEl"><a class="p-navEl-link p-navEl-link--menuTrigger" data-act="nyi">Resources</a></div></li>
      <li><div class="p-navEl"><a class="p-navEl-link p-navEl-link--menuTrigger" data-act="nyi">Play</a></div></li>
    </ul>
    <div class="p-nav-opposite">
      <a class="p-navgroup-link" data-act="nyi" title="${esc(p.username)}"><span class="avatar avatar--xxs"><img src="${asset('default-avatar')}" alt=""></span><span class="p-navgroup-linkText">${esc(p.username)}</span></a>
      <a class="p-navgroup-link" data-act="nyi" title="Direct messages">${icon('mail')}</a>
      <a class="p-navgroup-link" data-act="nyi" title="Alerts">${icon('bell')}</a>
      <a class="p-navgroup-link" data-act="nyi" title="Search">${icon('search')}<span class="p-navgroup-linkText">Search</span></a>
    </div>
  </div></nav></div>
  <div class="p-body"><div class="p-body-inner" id="pbody"></div></div>
  <footer class="p-footer">
    <div class="p-footer-inner"><div class="p-footer-row">
      <ul class="p-footer-linkList"><li><a data-act="nyi">Change width</a></li></ul>
      <ul class="p-footer-linkList"><li><a data-act="nyi">Terms and rules</a></li><li><a data-act="nyi">Privacy policy</a></li><li><a data-act="nyi">Help</a></li><li><a data-act="nyi">Home</a></li><li><a data-act="nyi" title="RSS"><span class="p-footer-rss">${icon('rss')}</span></a></li></ul>
    </div></div>
    <div class="p-footer-copyright">Community platform by XenForo<sup>&reg;</sup> &copy; 2010-2026 XenForo Ltd. Design by: Pixel Exit<br><span class="dim">XenPorta 2 PRO</span> &copy; Jason Axelrod of <span class="dim">8WAYRUN</span></div>
  </footer>
</div>`;
  }

  const SHARE = ['brand-facebook', 'brand-x', 'brand-youtube', 'brand-discord', 'brand-steam', 'brand-instagram', 'brand-github', 'rss', 'bell'];
  function crumbsRow(crumbs) {
    return `<div class="p-breadcrumbs-row"><ul class="p-breadcrumbs">${crumbs.map(c => `<li><a href="${c.href}">${esc(c.label)}</a></li>`).join('')}</ul>
      <div class="shareButtons">${SHARE.map(n => `<a data-act="nyi">${icon(n)}</a>`).join('')}</div></div>`;
  }

  function render() {
    closeMenus();
    const page = resolve(route());
    $('#pbody').innerHTML = `
      ${crumbsRow(page.crumbs || [{ label: 'Milpacs', href: '#/rosters' }])}
      <div class="p-body-header"><div class="p-title"><h1 class="p-title-value">${page.title}</h1>
        ${page.actions ? `<div class="p-title-pageAction">${page.actions}</div>` : ''}</div>
        ${page.desc ? `<div class="p-description">${page.desc}</div>` : ''}</div>
      <div class="p-body-main">${page.body}</div>
      ${crumbsRow(page.crumbs || [{ label: 'Milpacs', href: '#/rosters' }])}`;
    document.title = page.docTitle || (stripTags(page.title) + ' | 7th Cavalry Gaming');
    page.after && page.after();
    emit('render', { route: route() });
  }
  const stripTags = h => String(h).replace(/<[^>]+>/g, '');

  function resolve(r) {
    let m;
    if ((m = r.match(/^\/rosters\/profile\/(\d+)\/awards\/add$/))) return pageRowAdd(+m[1], 'award');
    if ((m = r.match(/^\/rosters\/profile\/(\d+)\/service-record\/add$/))) return pageRowAdd(+m[1], 'record');
    if ((m = r.match(/^\/rosters\/profile\/(\d+)\/?$/))) return pageProfile(+m[1]);
    if (canCitations()) {
      if (r === '/citations' || r === '/citations/') return pageGrants();
      if (r === '/citations/issue') return pageIssue();
      if (r === '/citations/templates') return pageTemplates();
      if (r === '/citations/signatures') return pageSignatures();
      if ((m = r.match(/^\/citations\/templates\/(\d+)\/v\/(\d+)$/))) return pageVersion(+m[1], +m[2]);
      if ((m = r.match(/^\/citations\/templates\/(\d+)$/))) return pageTemplate(+m[1]);
      if ((m = r.match(/^\/citations\/(\d+)\/correct$/))) return pageCorrect(+m[1]);
      if ((m = r.match(/^\/citations\/(\d+)$/))) return pageGrant(+m[1]);
    } else if (r.startsWith('/citations')) {
      return { title: 'Oops! We ran into some problems.', body: `<div class="blockMessage">You do not have permission to view this page or perform this action.</div>` };
    }
    return pageRosters();
  }

  // --- menus ------------------------------------------------------------------------------------

  let openMenu = null;
  function closeMenus() {
    if (openMenu) { openMenu.el.remove(); openMenu.trigger.classList.remove('is-menuOpen'); openMenu.trigger.closest('.p-navEl')?.classList.remove('is-menuOpen'); openMenu = null; }
  }
  function toggleMenu(trigger) {
    const same = openMenu && openMenu.trigger === trigger;
    closeMenus();
    if (same) return;
    const html = menuHtml(trigger.dataset.menu, trigger);
    if (!html) return;
    const el = document.createElement('div');
    el.className = 'menu';
    el.innerHTML = `<span class="menu-arrow"></span><div class="menu-content">${html}</div>`;
    document.body.appendChild(el);
    const r = trigger.getBoundingClientRect();
    const w = Math.max(200, el.offsetWidth);
    let left = r.left + r.width / 2 - 30;
    if (left + w > window.innerWidth - 10 - guideWidth()) left = r.right - w;
    left = Math.max(8, left);
    el.style.left = left + window.scrollX + 'px';
    el.style.top = r.bottom + window.scrollY + 8 + 'px';
    el.querySelector('.menu-arrow').style.left = Math.min(w - 24, Math.max(8, r.left + r.width / 2 - left - 8)) + 'px';
    trigger.classList.add('is-menuOpen');
    trigger.closest('.p-navEl')?.classList.add('is-menuOpen');
    openMenu = { el, trigger };
    emit('menu', { id: trigger.dataset.menu });
  }
  function guideWidth() { const g = document.getElementById('guide'); return g && !g.classList.contains('is-collapsed') && window.innerWidth > 1100 ? g.offsetWidth : 0; }

  function menuHtml(id, trigger) {
    if (id === 'milpacs' || id === 'mobile-nav') {
      const rows = [`<a class="menu-linkRow" href="#/rosters">Rosters</a>`];
      if (canCitations()) {
        rows.push(`<a class="menu-linkRow" href="#/citations" data-proto="menu-citations">Citations</a>`);
        rows.push(`<a class="menu-linkRow" href="#/citations/issue" data-proto="menu-issue">Issue a citation</a>`);
        rows.push(`<hr class="menu-separator">`);
        rows.push(`<a class="menu-linkRow" href="#/citations/templates" data-proto="menu-templates">Citation templates</a>`);
        rows.push(`<a class="menu-linkRow" href="#/citations/signatures" data-proto="menu-signatures">Signatures</a>`);
      }
      return rows.join('');
    }
    if (id === 'profile-more') {
      const mid = trigger.dataset.member;
      return `<h4 class="menu-header">More options</h4>
        <a class="menu-linkRow" data-act="nyi">Uniform</a>
        <a class="menu-linkRow" href="#/rosters/profile/${mid}/awards/add" data-proto="menu-add-award">Add award</a>
        <a class="menu-linkRow" href="#/rosters/profile/${mid}/service-record/add" data-proto="menu-add-record">Add service record</a>
        <a class="menu-linkRow" data-act="nyi">Move user</a>
        <a class="menu-linkRow" data-act="nyi">Delete user</a>`;
    }
    if (id === 'row') {
      const rid = trigger.dataset.row;
      return `<h4 class="menu-header">More options</h4>
        <a class="menu-linkRow" data-act="edit-row" data-row="${rid}" data-proto="menu-edit-row">Edit</a>
        <a class="menu-linkRow" data-act="delete-row" data-row="${rid}">Delete</a>`;
    }
    if (id === 'grant-more') {
      return `<h4 class="menu-header">More options</h4>
        <a class="menu-linkRow" data-act="nyi">Add a member left off</a>
        <a class="menu-linkRow" data-act="nyi">Move to another template version</a>
        <a class="menu-linkRow" data-act="nyi">Remove a member</a>`;
    }
    return '';
  }

  // --- overlays -------------------------------------------------------------------------------

  function openOverlay({ title, body, small, after, wide }) {
    closeOverlay(); closeMenus();
    const el = document.createElement('div');
    el.className = 'overlay-container';
    el.id = 'overlay';
    el.innerHTML = `<div class="overlay ${small ? 'overlay--small' : ''} ${wide ? 'overlay--wide' : ''}" role="dialog" aria-label="${esc(title)}">
      <h2 class="overlay-title">${esc(title)}<a class="overlay-titleCloser" data-act="close-overlay" aria-label="Close">${icon('x')}</a></h2>
      <div class="overlay-content">${body}</div></div>`;
    el.addEventListener('mousedown', e => { if (e.target === el) closeOverlay(); });
    document.body.appendChild(el);
    document.body.classList.add('has-overlay');
    after && after(el);
    emit('overlay', { title });
  }
  function closeOverlay() {
    const el = $('#overlay');
    if (el) { el.remove(); document.body.classList.remove('has-overlay'); emit('overlay-closed'); }
  }
  function errorOverlay(errors) {
    const body = errors.length > 1
      ? `<div class="blockMessage">Please correct the following errors:<ul>${errors.map(e => `<li>${esc(e)}</li>`).join('')}</ul></div>`
      : `<div class="blockMessage">${esc(errors[0])}</div>`;
    openOverlay({ title: 'Oops! We ran into some problems.', small: true, body });
  }
  function confirmOverlay({ title, message, button, onConfirm, iconName = 'check' }) {
    openOverlay({
      title, small: true,
      body: `<div class="block"><div class="block-container"><div class="block-body"><div class="block-row">${message}</div></div>
        <div class="formSubmitRow formSubmitRow--full"><div class="formSubmitRow-bar"><div class="formSubmitRow-controls">
        <button class="button button--primary" data-proto="confirm" id="confirmBtn">${icon(iconName)}<span>${esc(button)}</span></button></div></div></div></div></div>`,
      after: el => el.querySelector('#confirmBtn').addEventListener('click', () => { closeOverlay(); onConfirm(); }),
    });
  }

  // --- opening citations ------------------------------------------------------------------------

  function viewerFallback(dataUrl, filename) {
    openOverlay({ title: filename, wide: true, body: `<div style="background:#0e0e0e;padding:10px;text-align:center"><img src="${dataUrl}" style="max-width:100%"></div>` });
  }
  function openGrantMember(gmId, from) {
    const { grant, gm } = Model.grantMember(S, gmId);
    const ver = Model.version(S, grant.templateId, grant.v);
    const { canvas } = Render.renderGrant(S, { grant, gm, ver });
    Render.openImage(canvas, Render.renderFilename(S, grant, gm), viewerFallback);
    emit('open-citation', { gmId, grantId: grant.id, from });
  }
  function openRegiment(grantId, from) {
    const grant = Model.grant(S, grantId);
    const ver = Model.version(S, grant.templateId, grant.v);
    const { canvas } = Render.renderGrant(S, { grant, gm: null, ver });
    Render.openImage(canvas, Render.renderFilename(S, grant, null), viewerFallback);
    emit('open-citation', { grantId, from });
  }
  const uploads = {};
  function rowCitationCanvas(r) {
    const c = r.citation;
    if (!c) return null;
    if (c.type === 'grant') {
      const { grant, gm } = Model.grantMember(S, c.gmId);
      return Render.renderGrant(S, { grant, gm, ver: Model.version(S, grant.templateId, grant.v) }).canvas;
    }
    if (c.type === 'regiment') {
      const grant = Model.grant(S, c.grantId);
      return Render.renderGrant(S, { grant, gm: null, ver: Model.version(S, grant.templateId, grant.v) }).canvas;
    }
    if (c.upload && uploads[c.upload]) return uploads[c.upload];
    return Render.legacyImage(S, r);
  }
  function openRowCitation(rowId) {
    const r = Model.row(S, rowId);
    if (!r || !r.citation) return;
    if (r.citation.type === 'grant') return openGrantMember(r.citation.gmId, { rowId });
    if (r.citation.type === 'regiment') return openRegiment(r.citation.grantId, { rowId });
    Render.openImage(rowCitationCanvas(r), `${r.id}.jpg`, viewerFallback);
    emit('open-citation', { rowId, image: true });
  }

  // --- pages: rosters ---------------------------------------------------------------------------

  function pageRosters() {
    const groups = Model.ROSTER_GROUPS.map(gname => {
      const ms = S.members.filter(m => m.group === gname).sort((a, b) => rankOrder(a.rankId) - rankOrder(b.rankId) || a.username.localeCompare(b.username));
      return `<tr class="dataList-row dataList-row--subSection"><td class="dataList-cell" colspan="5">${esc(gname)}</td></tr>
        <tr class="dataList-row dataList-row--header"><th class="dataList-cell">&nbsp;</th><th class="dataList-cell">Username</th><th class="dataList-cell">Position</th><th class="dataList-cell">Join Date</th><th class="dataList-cell">Promotion Date</th></tr>
        ${ms.map(m => `<tr class="dataList-row">
          <td class="dataList-cell dataList-cell--min dataList-cell--imageSmall">${rankImg(m.rankId)}</td>
          <td class="dataList-cell"><a href="#/rosters/profile/${m.id}" data-proto="roster-${m.username}">${esc(rankedName(m))}</a></td>
          <td class="dataList-cell">${esc(m.position)}</td>
          <td class="dataList-cell">${fmtLong(m.joined)}</td><td class="dataList-cell">${fmtLong(m.promoted)}</td></tr>`).join('')}`;
    }).join('');
    return {
      title: 'Rosters', crumbs: [{ label: 'Milpacs', href: '#/rosters' }], desc: 'Active duty Roster',
      actions: `<div class="buttonGroup"><a class="button" data-act="nyi">${icon('search')}<span>Gamertag Search</span></a> <a class="button button--cta" data-act="nyi" style="margin-left:5px">${icon('square-plus')}<span>Add user</span></a></div>`,
      body: `<div class="block"><div class="block-container">
        <div class="block-tabHeader">${['Combat', 'Reserve', 'ELOA', 'Wall of Honor', 'Arlington Memorial Cemetery', 'Past members'].map((t, i) => `<a class="tabs-tab ${i ? '' : 'is-active'}" ${i ? 'data-act="nyi"' : ''}>${t}</a>`).join('')}</div>
        <div class="block-body"><div class="dataList"><table class="dataList-table">${groups}</table></div></div></div></div>`,
    };
  }

  function citationCell(r) {
    return r.citation ? `<a href="#" data-act="open-row-citation" data-row="${r.id}" data-proto="cite-${r.id}">Citation</a>` : '-';
  }
  function menuCell(r) {
    return `<td class="dataList-cell dataList-cell--action"><a class="button menuTrigger" data-menu="row" data-row="${r.id}" data-proto="rowmenu-${r.id}" title="More options"><span class="dots">&bull;&bull;&bull;</span></a></td>`;
  }

  function pageProfile(id) {
    const m = Model.member(S, id);
    if (!m) return pageRosters();
    const mgr = isManager();
    const recs = Model.rowsForMember(S, id, 'record');
    const awards = Model.rowsForMember(S, id, 'award');
    const info = [['Rank', rankOf(m).title], ['Primary position', m.position], ['Forum account', `<a class="username">${esc(m.username)}</a>`, true],
      ['Full name', m.fullName], ['MOS', m.mos], ['Time in service', span(m.joined, S.today)], ['Time in grade', span(m.promoted, S.today)]];
    const recRows = recs.map(r => `<tr class="dataList-row" data-proto="row-${r.id}">
        <td class="dataList-cell">${r.date}</td><td class="dataList-cell">${esc(r.details)}</td>
        <td class="dataList-cell">${r.typeId ? esc(typeTitle('record', r.typeId)) : '-'}</td>
        <td class="dataList-cell">${citationCell(r)}</td>${mgr ? menuCell(r) : ''}</tr>`).join('');
    const awardRows = awards.map(r => {
      const img = Model.AWARD_IMAGES[r.typeId];
      return `<tr class="dataList-row" data-proto="row-${r.id}">
        <td class="dataList-cell">${r.date}</td><td class="dataList-cell">${esc(typeTitle('award', r.typeId))}</td>
        <td class="dataList-cell">${img ? `<img class="awardImg" src="${asset(img)}" alt="${esc(typeTitle('award', r.typeId))}">` : '&nbsp;'}</td>
        <td class="dataList-cell">${esc(r.details)}</td><td class="dataList-cell">${citationCell(r)}</td>${mgr ? menuCell(r) : ''}</tr>`;
    }).join('');
    return {
      title: esc(rankedName(m)),
      crumbs: [{ label: 'Milpacs', href: '#/rosters' }, { label: 'Combat', href: '#/rosters' }],
      actions: mgr ? `<div class="buttonGroup"><a class="button" data-act="nyi">Edit user</a><a class="button menuTrigger" data-menu="profile-more" data-member="${id}" data-proto="profile-more" title="More options"><span class="dots">&bull;&bull;&bull;</span></a></div>` : '',
      body: `<div class="block"><div class="block-container">
        <h3 class="block-formSectionHeader">Information</h3>
        <div class="block-body block-row">${info.map(([k, v, raw]) => `<dl class="pairs pairs--columns"><dt>${k}</dt><dd>${raw ? v : esc(v)}</dd></dl>`).join('')}</div>
        ${recs.length ? `<h3 class="block-formSectionHeader">Service record</h3>
        <div class="block-body block-row"><div class="dataList"><table class="dataList-table">
          <tr class="dataList-row dataList-row--header"><th class="dataList-cell">Date</th><th class="dataList-cell">Record</th><th class="dataList-cell">Type</th><th class="dataList-cell">Citation</th>${mgr ? '<th class="dataList-cell">&nbsp;</th>' : ''}</tr>
          ${recRows}</table></div></div>` : ''}
        ${awards.length ? `<h3 class="block-formSectionHeader">Awards and recognition</h3>
        <div class="block-body block-row"><div class="dataList"><table class="dataList-table">
          <tr class="dataList-row dataList-row--header"><th class="dataList-cell">Date</th><th class="dataList-cell">Award</th><th class="dataList-cell">&nbsp;</th><th class="dataList-cell">Details</th><th class="dataList-cell">Citation</th>${mgr ? '<th class="dataList-cell">&nbsp;</th>' : ''}</tr>
          ${awardRows}</table></div></div>` : ''}
      </div></div>`,
    };
  }

  // --- the vendor's row forms, with the citation picker where the upload was ---------------------

  function editorHtml(value, small) {
    const B = (n, extra = '') => `<span class="fr-btn ${extra}">${icon(n)}</span>`;
    const tools = small
      ? `${B('bold')}${B('italic')}<span class="fr-sep"></span>${B('list', 'fr-btn--drop')}<span class="fr-sep"></span>${B('link')}${B('image')}${B('smile')}<span class="fr-sep"></span><span class="fr-grow"></span>${B('undo-2')}${B('ellipsis-vertical')}`
      : `${B('eraser')}${B('bold')}${B('italic')}${B('type', 'fr-btn--drop')}${B('palette')}${B('type', 'fr-btn--drop')}${B('strikethrough')}${B('underline')}${B('code')}${B('eye')}${B('ellipsis-vertical')}
         ${B('list', 'fr-btn--drop')}${B('align-left', 'fr-btn--drop')}${B('pilcrow', 'fr-btn--drop')}${B('anchor')}${B('ellipsis-vertical')}
         ${B('link')}${B('image')}${B('smile')}${B('film')}${B('quote')}${B('table')}${B('minus')}${B('ellipsis-vertical')}
         <span style="flex-basis:100%;height:0"></span>${B('undo-2')}${B('redo-2')}${B('brackets')}`;
    const words = String(value || '').trim() ? String(value).trim().split(/\s+/).length : 0;
    return `<div class="fr-box ${small ? 'fr-box--small' : ''}"><div class="fr-toolbar">${tools}</div>
      <div class="fr-element" contenteditable="true" data-editor>${esc(value || '')}</div></div>
      <div class="fr-counter" data-counter>${words} Words</div>`;
  }
  function bindEditor(root, onInput) {
    const ed = $('[data-editor]', root);
    const counter = $('[data-counter]', root);
    ed.addEventListener('input', () => {
      const t = ed.innerText.trim();
      counter.textContent = (t ? t.split(/\s+/).length : 0) + ' Words';
      onInput(ed.innerText.replace(/\n$/, ''));
    });
  }

  function newRowForm(memberId, kind, r) {
    return {
      memberId, kind, rowId: r ? r.id : null,
      typeId: r ? r.typeId : (kind === 'award' ? S.awards[0].id : 0),
      date: r ? r.date : S.today, details: r ? r.details : '',
      pick: r && r.citation && r.citation.type === 'grant' ? 'gm:' + r.citation.gmId : r && r.citation && r.citation.type === 'image' ? 'keep' : 'none',
      deleteImage: false, upload: null, filter: '',
    };
  }

  function currentCitationRow(r) {
    if (!r || !r.citation) return '';
    const c = rowCitationCanvas(r);
    const src = thumb(c);
    if (r.citation.type === 'grant') {
      const { grant } = Model.grantMember(S, r.citation.gmId);
      return `<dl class="formRow"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Current citation</label></div></dt>
        <dd><a data-act="open-row-citation" data-row="${r.id}" href="#"><img class="thumbCitation" src="${src}" alt=""></a>
        <div class="formRow-explain">${esc(grantTitle(grant))}, ${grant.date}. To swap it, pick another below. To take it off this row, pick No citation.</div></dd></dl>`;
    }
    return `<dl class="formRow"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Current image</label></div></dt>
      <dd><a data-act="open-row-citation" data-row="${r.id}" href="#"><img class="thumbCitation" src="${src}" alt=""></a>
      <div style="margin-top:12px"><label class="iconic iconic--checkbox iconic--standalone"><input type="checkbox" data-f="deleteImage"><i></i><span class="iconic-label">Delete current image</span></label></div></dd></dl>`;
  }

  function pickerHtml(f, r) {
    const m = Model.member(S, f.memberId);
    const list = Model.citationsForMember(S, f.memberId);
    const q = f.filter.trim().toLowerCase();
    const hasImage = r && r.citation && r.citation.type === 'image';
    const choices = [];
    if (hasImage) choices.push(`<li class="inputChoices-choice"><label class="iconic iconic--radio"><input type="radio" name="pick" value="keep" ${f.pick === 'keep' ? 'checked' : ''}><i></i><span class="iconic-label">Keep the current image</span></label></li>`);
    choices.push(`<li class="inputChoices-choice"><label class="iconic iconic--radio"><input type="radio" name="pick" value="none" ${f.pick === 'none' ? 'checked' : ''}><i></i><span class="iconic-label">No citation</span></label></li>`);
    let shown = 0;
    for (const { grant, gm, rows } of list) {
      const title = grantTitle(grant);
      const onRows = rows.map(x => Model.rowLabel(S, x));
      const hay = (title + ' ' + grant.date + ' ' + onRows.join(' ')).toLowerCase();
      if (q && !hay.includes(q)) continue;
      shown++;
      const ver = Model.version(S, grant.templateId, grant.v);
      choices.push(`<li class="inputChoices-choice" data-proto="pick-${grant.typeId}"><label class="iconic iconic--radio"><input type="radio" name="pick" value="gm:${gm.id}" ${f.pick === 'gm:' + gm.id ? 'checked' : ''}><i></i>
        <span class="iconic-label">${esc(title)} <span class="u-muted">&middot; ${grant.date}</span></span></label>
        <dfn class="inputChoices-explain">${onRows.length ? 'Already on: ' + onRows.map(esc).join('; ') : 'Not on any row yet'} &middot; ${ver.generic ? 'Generic' : 'Written'} &middot; <a href="#" data-act="preview-gm" data-gm="${gm.id}">View</a></dfn></li>`);
    }
    const none = !list.length ? `<div class="formRow-explain">S1 Citations hasn't issued any citations to ${esc(m.username)} yet. You can come back and pick one once they have.</div>`
      : !shown ? `<div class="formRow-explain">No citations match "${esc(f.filter)}".</div>` : '';
    return `<dl class="formRow" data-proto="picker"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Citation</label><dfn class="formRow-hint">Issued to ${esc(m.username)}</dfn></div></dt>
      <dd>${list.length > 3 ? `<input class="input input--small" style="margin-bottom:12px" placeholder="Filter..." data-f="filter" value="${esc(f.filter)}">` : ''}
      <ul class="inputChoices" data-picker>${choices.join('')}</ul>${none}
      ${list.length ? `<div class="formRow-explain">Every citation S1 Citations has issued to ${esc(m.username)}, newest first. A citation can go on more than one row.</div>` : ''}</dd></dl>`;
  }

  function uploadRowHtml() {
    return `<dl class="formRow formRow--input" data-proto="upload-row"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Upload new image</label></div></dt>
      <dd><input type="file" class="input" accept=".gif,.jpeg,.jpg,.jpe,.png" data-f="upload"></dd></dl>`;
  }

  function rowFieldsHtml(f, r, small) {
    const isDisc = f.kind === 'record' && f.typeId === Model.DISCIPLINARY;
    const cit = `<div data-citation-part>${currentCitationRow(r)}${isDisc ? uploadRowHtml() : pickerHtml(f, r)}</div>`;
    if (f.kind === 'award') {
      return `<dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Award</label></div></dt>
          <dd><select class="input" data-f="typeId" data-proto="award-select">${S.awards.map(a => `<option value="${a.id}" ${a.id === f.typeId ? 'selected' : ''}>${esc(a.title)}</option>`).join('')}</select></dd></dl>
        <hr class="formRowSep">
        <dl class="formRow"><dt></dt><dd>${editorHtml(f.details, small)}</dd></dl>
        <dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Award date</label></div></dt>
          <dd><input type="date" class="input input--date" data-f="date" value="${f.date}"></dd></dl>
        ${cit}`;
    }
    return `<dl class="formRow"><dt></dt><dd>${editorHtml(f.details, small)}</dd></dl>
      <dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Record Type</label></div></dt>
        <dd><select class="input" data-f="typeId" data-proto="record-type">${[{ id: 0, title: '(none)' }, ...S.recordTypes].map(t => `<option value="${t.id}" ${t.id === f.typeId ? 'selected' : ''}>${esc(t.title)}</option>`).join('')}</select></dd></dl>
      <dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Record date</label></div></dt>
        <dd><input type="date" class="input input--date" data-f="date" value="${f.date}"></dd></dl>
      ${cit}`;
  }

  function bindRowForm(root, f, r, small) {
    bindEditor(root, v => { f.details = v; });
    const rerenderCitation = () => {
      const part = $('[data-citation-part]', root);
      const isDisc = f.kind === 'record' && f.typeId === Model.DISCIPLINARY;
      part.innerHTML = currentCitationRow(r) + (isDisc ? uploadRowHtml() : pickerHtml(f, r));
      bindCitationPart();
      emit('form', { form: 'row' });
    };
    const bindCitationPart = () => {
      $$('input[name=pick]', root).forEach(i => i.addEventListener('change', () => { f.pick = i.value; emit('form', { form: 'row' }); }));
      const flt = $('[data-f=filter]', root);
      if (flt) flt.addEventListener('input', () => { f.filter = flt.value; const pos = flt.selectionStart; rerenderCitation(); const n = $('[data-f=filter]', root); n.focus(); n.setSelectionRange(pos, pos); });
      const del = $('[data-f=deleteImage]', root);
      if (del) del.addEventListener('change', () => { f.deleteImage = del.checked; });
      const up = $('[data-f=upload]', root);
      if (up) up.addEventListener('change', () => {
        const file = up.files[0];
        if (!file) { f.upload = null; return; }
        const rd = new FileReader();
        rd.onload = async () => { f.upload = rd.result; uploads[rd.result] = await Render.uploadedImage(rd.result); };
        rd.readAsDataURL(file);
      });
    };
    $('[data-f=typeId]', root).addEventListener('change', e => { f.typeId = +e.target.value; rerenderCitation(); });
    $('[data-f=date]', root).addEventListener('change', e => { f.date = e.target.value; });
    bindCitationPart();
  }

  function rowCitationChoice(f, r) {
    const isDisc = f.kind === 'record' && f.typeId === Model.DISCIPLINARY;
    if (isDisc) {
      if (f.upload) return { mode: 'upload', dataUrl: f.upload };
      if (f.deleteImage) return { mode: 'remove' };
      return { mode: 'keep' };
    }
    if (f.pick.startsWith('gm:')) {
      const gmId = +f.pick.slice(3);
      if (r && r.citation && r.citation.type === 'grant' && r.citation.gmId === gmId) return { mode: 'keep' };
      return { mode: 'pick', gmId };
    }
    if (f.pick === 'keep') return f.deleteImage ? { mode: 'remove' } : { mode: 'keep' };
    return r && r.citation ? { mode: 'remove' } : { mode: 'keep' };
  }

  function saveRowForm(f, r, done) {
    const res = Model.saveRow(S, { rowId: f.rowId, memberId: f.memberId, kind: f.kind, typeId: f.typeId, date: f.date, details: f.details, citation: rowCitationChoice(f, r) });
    commit(res, ok => { emit('row-saved', { rowId: ok.rowId }); done(); });
  }

  function pageRowAdd(memberId, kind) {
    const m = Model.member(S, memberId);
    if (!m || !isManager()) return pageProfile(memberId);
    const key = kind + ':' + memberId;
    if (!ui.rowAdd || ui.rowAdd.key !== key) ui.rowAdd = { key, f: newRowForm(memberId, kind, null) };
    const f = ui.rowAdd.f;
    return {
      title: kind === 'award' ? `Add award to ${esc(m.username)}` : `Add service record to ${esc(m.username)}`,
      crumbs: [{ label: 'Milpacs', href: '#/rosters' }, { label: 'Combat', href: '#/rosters' }, { label: rankedName(m), href: `#/rosters/profile/${m.id}` }],
      body: `<form class="block" id="rowForm" onsubmit="return false"><div class="block-container"><div class="block-body">${rowFieldsHtml(f, null, false)}</div>
        <div class="formSubmitRow formSubmitRow--sticky"><div class="formSubmitRow-bar"><div class="formSubmitRow-controls">
        <button class="button button--primary" id="rowSave" data-proto="row-save">${icon('save')}<span>Save</span></button></div></div></div></div></form>`,
      after: () => {
        const root = $('#rowForm');
        bindRowForm(root, f, null, false);
        $('#rowSave').addEventListener('click', () => saveRowForm(f, null, () => { ui.rowAdd = null; flash('Your changes have been saved.'); go(`/rosters/profile/${memberId}`); }));
      },
    };
  }

  function editRowOverlay(rowId) {
    const r = Model.row(S, rowId);
    const m = Model.member(S, r.memberId);
    emit('edit-row', { rowId, kind: r.kind, typeId: r.typeId });
    if (Model.isPucRow(r)) { errorOverlay([Model.PUC_LOCKED]); return; }
    const f = newRowForm(r.memberId, r.kind, r);
    const isRec = r.kind === 'record';
    openOverlay({
      title: isRec ? `Edit service record for ${m.username}` : `Edit award for ${m.username}`,
      body: `<form class="block" onsubmit="return false"><div class="block-container"><div class="block-body">${rowFieldsHtml(f, r, true)}</div>
        <div class="formSubmitRow"><div class="formSubmitRow-bar"><div class="formSubmitRow-controls">
        <button class="button button--primary" data-proto="row-save">${icon('save')}<span>Save</span></button>
        ${isRec ? `<a class="button" data-act="delete-row" data-row="${r.id}">${icon('trash-2')}<span>Delete</span></a>` : ''}</div></div></div></div></form>`,
      after: el => {
        bindRowForm(el, f, r, true);
        el.querySelector('[data-proto=row-save]').addEventListener('click', () => saveRowForm(f, r, () => { closeOverlay(); flash('Your changes have been saved.'); render(); }));
      },
    });
  }

  function deleteRow(rowId) {
    const r = Model.row(S, rowId);
    if (Model.isPucRow(r)) { errorOverlay([Model.PUC_LOCKED]); return; }
    confirmOverlay({
      title: 'Confirm action', button: 'Delete', iconName: 'trash-2',
      message: `Please confirm that you want to delete the following:<br><strong>${esc(r.details || typeTitle(r.kind, r.typeId))}</strong>`,
      onConfirm: () => { S = structuredClone(S); S.rows = S.rows.filter(x => x.id !== rowId); emit('change'); render(); },
    });
  }

  // --- pages: citations (S1 Citations) --------------------------------------------------------

  function signatureLines(sig) { return sig ? sig.lines.filter(Boolean).map(esc).join(' / ') : ''; }
  const stateLabel = st => st === 'draft' ? '<span class="label label--yellow">Draft</span>' : st === 'published' ? '<span class="label label--green">Published</span>' : '<span class="label label--grey">Retired</span>';

  function pageGrants() {
    const gs = S.grants.filter(g => !g.regiment).sort((a, b) => b.date.localeCompare(a.date) || b.id - a.id);
    const regs = S.grants.filter(g => g.regiment);
    const tr = g => {
      const t = Model.template(S, g.templateId);
      const names = g.members.map(gm => Model.member(S, gm.memberId).username);
      return `<tr class="dataList-row"><td class="dataList-cell">${g.date}</td>
        <td class="dataList-cell"><a href="#/citations/${g.id}" data-proto="grant-${g.typeId}">${esc(grantTitle(g))}</a>${g.kind === 'record' ? ' <span class="u-muted u-smaller">(record)</span>' : ''}</td>
        <td class="dataList-cell">${g.regiment ? '<span class="u-muted">Every milpac</span>' : names.slice(0, 3).map(esc).join(', ') + (names.length > 3 ? `, +${names.length - 3}` : '')}</td>
        <td class="dataList-cell">${esc(t.title)}, v${g.v}</td><td class="dataList-cell">${esc(g.issuedBy === 'maintainer' ? 'Set up by a maintainer' : g.issuedBy)}</td>
        <td class="dataList-cell">${g.revision > 1 ? `Corrected (rev. ${g.revision})` : '-'}</td></tr>`;
    };
    const head = `<tr class="dataList-row dataList-row--header"><th class="dataList-cell">Date</th><th class="dataList-cell">Citation</th><th class="dataList-cell">Members</th><th class="dataList-cell">Template</th><th class="dataList-cell">Issued by</th><th class="dataList-cell">Corrections</th></tr>`;
    return {
      title: 'Citations', crumbs: [{ label: 'Milpacs', href: '#/rosters' }],
      actions: `<a class="button button--cta" href="#/citations/issue" data-proto="btn-issue">${icon('square-plus')}<span>Issue a citation</span></a>`,
      body: `<div class="block"><div class="block-container">
        <h3 class="block-formSectionHeader">Issued</h3>
        <div class="block-body block-row"><div class="dataList"><table class="dataList-table">${head}${gs.map(tr).join('') || `<tr class="dataList-row"><td class="dataList-cell" colspan="6">Nothing has been issued yet.</td></tr>`}</table></div></div>
        <h3 class="block-formSectionHeader">Regiment citations</h3>
        <div class="block-body block-row"><div class="dataList"><table class="dataList-table">${head}${regs.map(tr).join('')}</table></div></div>
      </div></div>`,
    };
  }

  // The grant form. Used to issue, and again, locked down, to correct.
  function newIssueDraft() {
    return { kind: null, typeId: null, templateId: null, v: null, date: '', sigs: {}, showRetired: {}, members: [], inputs: {}, text: '', previewIdx: 0, addName: '', fillAll: {} };
  }
  function draftVersion(d) { return d.templateId ? Model.version(S, d.templateId, d.v) : null; }
  function preselectSigs(d) {
    const ver = draftVersion(d);
    d.sigs = {};
    if (!ver) return;
    for (const f of Model.slots(ver)) {
      const act = Model.signaturesForBillet(S, f.billetId, false);
      if (act.length === 1) d.sigs[f.key] = act[0].id;
    }
  }

  function issueFormHtml(d, mode) {
    const g = mode === 'correct' ? Model.grant(S, d.grantId) : null;
    const ver = draftVersion(d);
    const t = d.templateId ? Model.template(S, d.templateId) : null;
    const rows = [];
    rows.push('<h3 class="block-formSectionHeader">The citation</h3>');
    if (mode === 'issue') {
      const types = Model.issuableTypes(S);
      const opt = x => `<option value="${x.kind}:${x.id}" ${d.kind === x.kind && d.typeId === x.id ? 'selected' : ''}>${esc(typeTitle(x.kind, x.id))}</option>`;
      rows.push(`<dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Award or record type</label></div></dt>
        <dd><select class="input" data-d="type" data-proto="issue-type"><option value="">Choose...</option>
          <optgroup label="Awards">${types.filter(x => x.kind === 'award').map(opt).join('')}</optgroup>
          <optgroup label="Service records">${types.filter(x => x.kind === 'record').map(opt).join('')}</optgroup></select>
          <div class="formRow-explain">Only awards and record types with a published template are listed.</div></dd></dl>`);
      if (d.typeId) {
        const opts = Model.templatesServing(S, d.kind, d.typeId);
        rows.push(`<dl class="formRow"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Template</label></div></dt>
          <dd data-proto="issue-template"><ul class="inputChoices">${opts.map(({ t: tt, v }) => `<li class="inputChoices-choice"><label class="iconic iconic--radio"><input type="radio" name="tpl" value="${tt.id}:${v.v}" ${d.templateId === tt.id && d.v === v.v ? 'checked' : ''}><i></i>
            <span class="iconic-label">${esc(tt.title)}</span></label><dfn class="inputChoices-explain">Version ${v.v}, ${v.w > v.h ? 'landscape' : 'portrait'}, published ${v.publishedAt}${v.generic ? '. Generic: the text is part of the template' : ''}</dfn></li>`).join('')}</ul>
          <div class="formRow-explain">Only templates made for the ${esc(typeTitle(d.kind, d.typeId))} are offered.</div></dd></dl>`);
      }
    } else {
      rows.push(`<dl class="formRow"><dt><div class="formRow-labelWrapper"><label class="formRow-label">${g.kind === 'award' ? 'Award' : 'Record type'}</label></div></dt><dd>${esc(grantTitle(g))}</dd></dl>
        <dl class="formRow"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Template</label></div></dt><dd>${esc(t.title)}, version ${g.v}</dd></dl>`);
    }
    if (ver) {
      rows.push(`<dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Date</label><dfn class="formRow-hint">The approval date</dfn></div></dt>
        <dd><input type="date" class="input input--date" data-d="date" value="${d.date}" data-proto="issue-date">
        <div class="formRow-explain">It prints in the template's own wording.</div></dd></dl>`);
      const slots = Model.slots(ver);
      if (slots.length) {
        rows.push('<h3 class="block-formSectionHeader">Signatures</h3>');
        for (const f of slots) {
          const b = Model.billet(S, f.billetId);
          let sigs = Model.signaturesForBillet(S, f.billetId, !!d.showRetired[f.key]);
          if (d.sigs[f.key] && !sigs.some(x => x.id === d.sigs[f.key])) sigs = [...sigs, Model.signature(S, d.sigs[f.key])];
          const cur = d.sigs[f.key] && Model.signature(S, d.sigs[f.key]);
          rows.push(`<dl class="formRow formRow--input" data-proto="issue-sig-${f.key}"><dt><div class="formRow-labelWrapper"><label class="formRow-label">${esc(b.title)}</label></div></dt>
            <dd><select class="input" data-sig="${f.key}"><option value="">Choose a signature...</option>${sigs.map(x => `<option value="${x.id}" ${d.sigs[f.key] === x.id ? 'selected' : ''}>${esc(x.lines[0])}${x.state === 'retired' ? ' (retired)' : ''}</option>`).join('')}</select>
            <div class="formRow-explain">${cur ? 'Prints as: ' + signatureLines(cur) : Model.signaturesForBillet(S, f.billetId, false).length > 1 ? 'More than one signature is on file for this billet, so pick one.' : 'Pick the signature this slot carries.'}</div>
            <div style="margin-top:10px"><label class="iconic iconic--checkbox iconic--standalone"><input type="checkbox" data-retired="${f.key}" ${d.showRetired[f.key] ? 'checked' : ''}><i></i><span class="iconic-label">Show retired signatures</span></label></div></dd></dl>`);
        }
      }
      // Members
      const memberInputs = t.inputs.filter(i => i.scope === 'member');
      const showRank = t.rankSource === 'member';
      const printsName = ver.fields.some(f => /\{name\}/.test(f.pattern || ''));
      rows.push('<h3 class="block-formSectionHeader">Members</h3>');
      if (mode === 'issue') {
        rows.push(`<dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Add a member</label></div></dt>
          <dd><div class="inputGroup"><input class="input" list="memberNames" placeholder="Username, such as Quill.E" data-d="addName" value="${esc(d.addName)}" data-proto="issue-add-input"><a class="button" data-act="issue-add-member" data-proto="issue-add">${icon('plus')}<span>Add</span></a></div>
          <datalist id="memberNames">${S.members.map(m => `<option value="${esc(m.username)}">${esc(m.fullName)}</option>`).join('')}</datalist>
          <div class="formRow-explain">${printsName ? 'Name and rank are copied from the member\'s milpac. Correct them here if they need it.' : 'This design doesn\'t print a name, so every member\'s certificate looks the same. Each member still gets their own citation to pick on their rows.'}</div></dd></dl>`);
      }
      if (memberInputs.length && d.members.length > 1) {
        rows.push(memberInputs.map(inp => `<dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">${esc(inp.label)} for everyone</label></div></dt>
          <dd><div class="inputGroup"><input class="input" data-fillall="${inp.key}" value="${esc(d.fillAll[inp.key] || '')}"><a class="button" data-act="issue-apply-all" data-key="${inp.key}">Apply to all</a></div>
          <div class="formRow-explain">Fills it in for every member. You can still change it member by member below.</div></dd></dl>`).join(''));
      }
      if (d.members.length) {
        rows.push(`<div class="block-row memberCards">${d.members.map((mm, i) => {
          const mem = Model.member(S, mm.memberId);
          return `<div class="memberCard"><div class="memberCard-head"><a href="#/rosters/profile/${mem.id}" class="username">${esc(mem.username)}</a>
              ${mode === 'issue' ? `<a class="memberCard-remove" data-act="issue-remove-member" data-i="${i}" title="Remove">${icon('x')}</a>` : ''}</div>
            <div class="memberCard-fields">
              <label class="memberField memberField--name"><span>Name on certificate</span><input class="input input--small" data-mname="${i}" value="${esc(mm.name)}"></label>
              ${showRank ? `<label class="memberField"><span>Rank</span><select class="input input--small" data-mrank="${i}">${S.ranks.map(rk => `<option value="${rk.id}" ${mm.rank && mm.rank.rankId === rk.id ? 'selected' : ''}>${esc(rk.title)} (${rk.payGrade})</option>`).join('')}</select></label>` : ''}
              ${memberInputs.map(inp => `<label class="memberField"><span>${esc(inp.label)}${inp.required ? '' : ' <em>optional</em>'}</span><input class="input input--small" data-minput="${i}:${inp.key}" value="${esc((mm.inputs || {})[inp.key] || '')}"></label>`).join('')}
            </div></div>`;
        }).join('')}</div>`);
      } else if (mode === 'issue') {
        rows.push(`<div class="block-row u-muted u-smaller">Nobody added yet.</div>`);
      }
      // Grant inputs and text
      const grantInputs = t.inputs.filter(i => i.scope === 'grant');
      if (grantInputs.length || ver.fields.some(f => /\{citation_text\}/.test(f.pattern || ''))) rows.push('<h3 class="block-formSectionHeader">Text</h3>');
      for (const inp of grantInputs) {
        rows.push(`<dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">${esc(inp.label)}</label>${inp.required ? '<dfn class="formRow-hint">Required</dfn>' : ''}</div></dt>
          <dd><input class="input" data-ginput="${inp.key}" value="${esc(d.inputs[inp.key] || '')}" style="max-width:260px"></dd></dl>`);
      }
      if (ver.fields.some(f => /\{citation_text\}/.test(f.pattern || ''))) {
        if (ver.generic) {
          rows.push(`<dl class="formRow"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Citation text</label><dfn class="formRow-hint">Generic</dfn></div></dt>
            <dd><div class="genericText">${esc(ver.genericText)}</div><div class="formRow-explain">A generic citation's text belongs to its template, so there's nothing to type.</div></dd></dl>`);
        } else {
          rows.push(`<dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Citation text</label><dfn class="formRow-hint">Plain text</dfn></div></dt>
            <dd><textarea class="input" rows="9" data-d="text" data-proto="issue-text" placeholder="Paste the proofread citation text here.">${esc(d.text)}</textarea>
            <div class="formRow-explain">Paragraph breaks are kept. Name, rank and date are separate fields, so leave them out of the text unless the prose itself uses them.</div></dd></dl>`);
        }
      }
      if (mode === 'correct') {
        rows.push(`<h3 class="block-formSectionHeader">Correction</h3><dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Reason</label><dfn class="formRow-hint">Required, kept in the citation's history</dfn></div></dt>
          <dd><textarea class="input" rows="2" data-d="reason" data-proto="correct-reason" placeholder="For example: typo reported by the member">${esc(d.reason || '')}</textarea></dd></dl>`);
      }
    }
    const submit = mode === 'issue'
      ? `<button class="button button--primary" data-act="issue-submit" data-proto="issue-submit">${icon('check')}<span>Issue citation</span></button>`
      : `<button class="button button--primary" data-act="correct-submit" data-proto="correct-submit">${icon('save')}<span>Save correction</span></button> <a class="button" href="#/citations/${d.grantId}">Cancel</a>`;
    return `<div class="block-container"><div class="block-body">${rows.join('')}</div>
      <div class="formSubmitRow formSubmitRow--sticky"><div class="formSubmitRow-bar"><div class="formSubmitRow-controls">${submit}</div></div></div></div>`;
  }

  function previewHtml(d) {
    const tabs = d.members.length > 1 ? `<div class="block-tabHeader">${d.members.map((mm, i) => `<a class="tabs-tab ${i === d.previewIdx ? 'is-active' : ''}" data-act="issue-preview-tab" data-i="${i}" data-proto="preview-tab-${i}">${esc(Model.member(S, mm.memberId).username)}</a>`).join('')}</div>` : '';
    return `<div class="block-container previewBlock"><h3 class="block-header">Preview <span class="block-desc">What anyone opening the citation will see</span></h3>${tabs}
      <div class="block-row"><div id="previewMount" class="previewMount"></div><div id="previewFit"></div>
      <div class="previewLinks"><a href="#" data-act="issue-open-full">${icon('external-link')} Open full size</a></div></div></div>`;
  }

  let previewTimer;
  function refreshPreview(d, mode) {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => drawPreview(d, mode), 60);
  }
  function draftGrant(d) {
    const base = d.grantId ? Model.grant(S, d.grantId) : null;
    return {
      id: base ? base.id : 0, kind: base ? base.kind : d.kind, typeId: base ? base.typeId : d.typeId, date: d.date, templateId: d.templateId, v: d.v,
      sigs: d.sigs, text: d.text, inputs: d.inputs, revision: base ? base.revision : 1,
      members: d.members.map((mm, i) => ({ id: mm.id || -1 - i, memberId: mm.memberId, name: mm.name, rank: mm.rank, inputs: mm.inputs || {}, token: 'preview' })),
    };
  }
  function drawPreview(d, mode) {
    const mount = $('#previewMount');
    if (!mount) return;
    const ver = draftVersion(d);
    const fit = $('#previewFit');
    if (!ver) { mount.innerHTML = `<div class="previewEmpty">Choose what's being awarded and a template to see the certificate.</div>`; fit.innerHTML = ''; return; }
    const g = draftGrant(d);
    const gm = g.members[Math.min(d.previewIdx, g.members.length - 1)] || null;
    const { canvas, report } = Render.renderGrant(S, { grant: g, gm, ver, preview: true });
    canvas.className = 'previewCanvas';
    mount.replaceChildren(canvas);
    ui.previewCanvas = canvas;
    const body = report.find(r => r.multiline && r.key === 'body');
    const notes = [];
    if (body) {
      notes.push(`<div class="previewNote">Citation text set at <strong>${body.pt.toFixed(1)} pt</strong>${body.size >= (ver.fields.find(f => f.key === 'body') || {}).size - 0.01 ? ', the template\'s largest size' : ''}.</div>`);
      if (body.flagged) notes.push(`<div class="blockMessage blockMessage--warning blockMessage--iconic blockMessage--small" data-proto="legibility">${icon('triangle-alert', 'bm-icon')}The citation text comes out at ${body.pt.toFixed(1)} pt, below this template's ${body.minPt.toFixed(0)} pt reading size. You can still issue it. The template manager will list it so S1 can publish a version with a bigger box.</div>`);
    }
    for (const r of report) {
      if (r.flagged && r.key !== 'body') notes.push(`<div class="blockMessage blockMessage--warning blockMessage--iconic blockMessage--small">${icon('triangle-alert', 'bm-icon')}The ${esc(r.key)} line shrank to ${r.pt.toFixed(1)} pt to fit.</div>`);
    }
    if (!gm && !ver.generic && ver.fields.some(f => /\{name\}/.test(f.pattern || ''))) notes.push(`<div class="previewNote u-muted">Add a member to see their name on it.</div>`);
    fit.innerHTML = notes.join('');
    emit('preview', { mode });
  }

  function bindIssueForm(d, mode) {
    const root = $('#issueForm');
    const rerender = () => { root.innerHTML = issueFormHtml(d, mode); $('#previewCol').innerHTML = previewHtml(d); bindIssueForm(d, mode); drawPreview(d, mode); emit('form', { form: mode }); };
    const changed = () => { refreshPreview(d, mode); emit('form', { form: mode }); };
    const type = $('[data-d=type]', root);
    if (type) type.addEventListener('change', () => {
      const [k, id] = type.value.split(':');
      d.kind = k || null; d.typeId = id ? +id : null; d.templateId = null; d.v = null;
      const opts = d.typeId ? Model.templatesServing(S, d.kind, d.typeId) : [];
      if (opts.length === 1) { d.templateId = opts[0].t.id; d.v = opts[0].v.v; }
      preselectSigs(d); d.inputs = {};
      rerender();
    });
    $$('input[name=tpl]', root).forEach(i => i.addEventListener('change', () => { const [t, v] = i.value.split(':').map(Number); d.templateId = t; d.v = v; preselectSigs(d); rerender(); }));
    const date = $('[data-d=date]', root);
    if (date) date.addEventListener('change', () => { d.date = date.value; changed(); });
    $$('[data-sig]', root).forEach(s => s.addEventListener('change', () => { d.sigs[s.dataset.sig] = s.value ? +s.value : undefined; if (!s.value) delete d.sigs[s.dataset.sig]; rerender(); }));
    $$('[data-retired]', root).forEach(c => c.addEventListener('change', () => { d.showRetired[c.dataset.retired] = c.checked; rerender(); }));
    const add = $('[data-d=addName]', root);
    if (add) {
      add.addEventListener('input', () => { d.addName = add.value; });
      add.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addMember(d, mode); } });
    }
    $$('[data-mname]', root).forEach(i => i.addEventListener('input', () => { d.members[+i.dataset.mname].name = i.value; changed(); }));
    $$('[data-mrank]', root).forEach(i => i.addEventListener('change', () => { d.members[+i.dataset.mrank].rank = Model.rankSnapshot(+i.value); changed(); }));
    $$('[data-minput]', root).forEach(i => i.addEventListener('input', () => { const [idx, key] = i.dataset.minput.split(':'); const mm = d.members[+idx]; mm.inputs = { ...(mm.inputs || {}), [key]: i.value }; changed(); }));
    $$('[data-fillall]', root).forEach(i => i.addEventListener('input', () => { d.fillAll[i.dataset.fillall] = i.value; }));
    $$('[data-ginput]', root).forEach(i => i.addEventListener('input', () => { d.inputs = { ...d.inputs, [i.dataset.ginput]: i.value }; changed(); }));
    const text = $('[data-d=text]', root);
    if (text) text.addEventListener('input', () => { d.text = text.value; changed(); });
    const reason = $('[data-d=reason]', root);
    if (reason) reason.addEventListener('input', () => { d.reason = reason.value; emit('form', { form: mode }); });
    ui.rerenderIssue = rerender;
  }
  function addMember(d, mode) {
    const m = Model.memberByUsername(S, d.addName);
    if (!m) { errorOverlay([`The following members could not be found: ${d.addName || '(blank)'}`]); return; }
    if (d.members.some(x => x.memberId === m.id)) { errorOverlay([`${m.username} is already on this citation.`]); return; }
    const t = Model.template(S, d.templateId);
    const inputs = {};
    for (const inp of t.inputs.filter(i => i.scope === 'member')) if (d.fillAll[inp.key]) inputs[inp.key] = d.fillAll[inp.key];
    d.members.push({ memberId: m.id, name: m.fullName, rank: Model.rankSnapshot(m.rankId), inputs });
    d.addName = '';
    ui.rerenderIssue && ui.rerenderIssue();
    $('[data-d=addName]')?.focus();
  }

  function pageIssue() {
    if (!ui.issue) ui.issue = newIssueDraft();
    const d = ui.issue;
    return {
      title: 'Issue a citation', crumbs: [{ label: 'Milpacs', href: '#/rosters' }, { label: 'Citations', href: '#/citations' }],
      body: `<div class="issueLayout"><form class="block issueFormCol" id="issueForm" onsubmit="return false">${issueFormHtml(d, 'issue')}</form>
        <div class="issuePreviewCol" id="previewCol">${previewHtml(d)}</div></div>`,
      after: () => { bindIssueForm(d, 'issue'); drawPreview(d, 'issue'); },
    };
  }
  function submitIssue() {
    const d = ui.issue;
    const res = Model.issueGrant(S, { ...d, members: d.members.map(m => ({ ...m, name: m.name || '' })) }, persona().username);
    commit(res, ok => { ui.issue = null; flash('The citation has been issued.'); emit('grant-issued', { grantId: ok.grantId }); go('/citations/' + ok.grantId); });
  }

  function pageCorrect(grantId) {
    const g = Model.grant(S, grantId);
    if (!g || g.regiment) return pageGrants();
    if (!ui.correct || ui.correct.grantId !== grantId) {
      ui.correct = {
        grantId, kind: g.kind, typeId: g.typeId, templateId: g.templateId, v: g.v, date: g.date, sigs: { ...g.sigs }, showRetired: {},
        members: g.members.map(gm => ({ id: gm.id, memberId: gm.memberId, name: gm.name, rank: gm.rank, inputs: { ...gm.inputs } })),
        inputs: { ...g.inputs }, text: g.text, reason: '', previewIdx: 0, fillAll: {}, addName: '',
      };
    }
    const d = ui.correct;
    return {
      title: `Correct: ${esc(grantTitle(g))}, ${g.date}`,
      crumbs: [{ label: 'Milpacs', href: '#/rosters' }, { label: 'Citations', href: '#/citations' }, { label: `${grantTitle(g)}, ${g.date}`, href: `#/citations/${g.id}` }],
      body: `<div class="blockMessage blockMessage--important blockMessage--iconic">${icon('info', 'bm-icon')}A correction changes this citation for every member on it, and every row that shows it. It's saved to the citation's history with your reason.</div>
        <div class="issueLayout"><form class="block issueFormCol" id="issueForm" onsubmit="return false">${issueFormHtml(d, 'correct')}</form>
        <div class="issuePreviewCol" id="previewCol">${previewHtml(d)}</div></div>`,
      after: () => { bindIssueForm(d, 'correct'); drawPreview(d, 'correct'); },
    };
  }
  function submitCorrect() {
    const d = ui.correct;
    const res = Model.correctGrant(S, d.grantId, { text: d.text, date: d.date, sigs: d.sigs, inputs: d.inputs, members: d.members }, d.reason, persona().username);
    commit(res, () => { const id = d.grantId; ui.correct = null; flash('The correction has been saved.'); emit('grant-corrected', { grantId: id }); go('/citations/' + id); });
  }

  function pageGrant(id) {
    const g = Model.grant(S, id);
    if (!g) return pageGrants();
    const t = Model.template(S, g.templateId);
    const ver = Model.version(S, g.templateId, g.v);
    const memberInputs = t.inputs.filter(i => i.scope === 'member');
    const sigRows = Model.slots(ver).map(f => { const sig = Model.signature(S, g.sigs[f.key]); return `${esc(Model.billet(S, f.billetId).title)}: ${sig ? esc(sig.lines[0]) + (sig.state === 'retired' ? ' <span class="label label--grey">Retired</span>' : '') : '-'}`; });
    const info = [
      [g.kind === 'award' ? 'Award' : 'Record type', esc(grantTitle(g))], ['Date', g.date],
      ['Template', `<a href="#/citations/templates/${t.id}">${esc(t.title)}</a>, version ${g.v} ${stateLabel(ver.state)}`],
      ...(sigRows.length ? [['Signatures', sigRows.join('<br>')]] : []),
      ...t.inputs.filter(i => i.scope === 'grant').map(i => [esc(i.label), esc(g.inputs[i.key] || '-')]),
      ['Issued by', g.issuedBy === 'maintainer' ? 'Set up by a maintainer from the issued certificate' : `<span class="username">${esc(g.issuedBy)}</span>, ${g.issuedAt}`],
      ['Revision', String(g.revision)],
    ];
    const members = g.regiment ? `<div class="block-row">This citation goes to the whole regiment. Every milpac carries a row for it, kept by the PUC sync. <a href="#" data-act="open-regiment" data-grant="${g.id}">Open the certificate</a></div>`
      : `<div class="block-body block-row"><div class="dataList"><table class="dataList-table">
        <tr class="dataList-row dataList-row--header"><th class="dataList-cell">Member</th><th class="dataList-cell">Name on certificate</th><th class="dataList-cell">Rank</th>${memberInputs.map(i => `<th class="dataList-cell">${esc(i.label)}</th>`).join('')}<th class="dataList-cell">On rows</th><th class="dataList-cell">Citation</th></tr>
        ${g.members.map(gm => {
          const mem = Model.member(S, gm.memberId);
          const rows = Model.rowsOnGrantMember(S, gm.id);
          return `<tr class="dataList-row"><td class="dataList-cell"><a href="#/rosters/profile/${mem.id}">${esc(mem.username)}</a></td><td class="dataList-cell">${esc(gm.name)}</td>
            <td class="dataList-cell">${t.rankSource === 'member' ? esc(gm.rank.title) + ` <span class="u-muted">(${esc(gm.rank.payGrade)})</span>` : '<span class="u-muted">On the plate</span>'}</td>
            ${memberInputs.map(i => `<td class="dataList-cell">${esc(gm.inputs[i.key] || '-')}</td>`).join('')}
            <td class="dataList-cell">${rows.length ? rows.map(r => esc(Model.rowLabel(S, r))).join('<br>') : '<span class="u-muted">Not on a row yet</span>'}</td>
            <td class="dataList-cell"><a href="#" data-act="open-gm" data-gm="${gm.id}" data-proto="open-gm-${gm.id}">Citation</a></td></tr>`;
        }).join('')}</table></div></div>`;
    const text = ver.generic
      ? `<div class="block-row"><div class="u-muted u-smaller" style="margin-bottom:6px">Generic citation. Its text belongs to the template version.</div><div class="u-pre">${esc(ver.genericText || '-')}</div></div>`
      : g.text ? `<div class="block-row u-pre">${esc(g.text)}</div>` : '';
    return {
      title: `${esc(grantTitle(g))}, ${g.date}`,
      crumbs: [{ label: 'Milpacs', href: '#/rosters' }, { label: 'Citations', href: '#/citations' }],
      actions: g.regiment ? '' : `<div class="buttonGroup"><a class="button" href="#/citations/${g.id}/correct" data-proto="btn-correct">${icon('pencil')}<span>Correct</span></a><a class="button menuTrigger" data-menu="grant-more" title="More options"><span class="dots">&bull;&bull;&bull;</span></a></div>`,
      body: `<div class="block"><div class="block-container">
        <h3 class="block-formSectionHeader">Information</h3>
        <div class="block-body block-row">${info.map(([k, v]) => `<dl class="pairs pairs--columns pairs--tight"><dt>${k}</dt><dd>${v}</dd></dl>`).join('')}</div>
        ${text ? `<h3 class="block-formSectionHeader">Citation text</h3>${text}` : ''}
        <h3 class="block-formSectionHeader">${g.regiment ? 'Recipient' : 'Members'}</h3>${members}
        <h3 class="block-formSectionHeader">History</h3>
        <div class="block-body block-row"><div class="dataList"><table class="dataList-table">
          <tr class="dataList-row dataList-row--header"><th class="dataList-cell">Revision</th><th class="dataList-cell">Date</th><th class="dataList-cell">By</th><th class="dataList-cell">What changed</th><th class="dataList-cell">Reason</th></tr>
          ${g.log.slice().reverse().map(l => `<tr class="dataList-row"><td class="dataList-cell">${l.revision}</td><td class="dataList-cell">${l.at}</td><td class="dataList-cell">${esc(l.by === 'maintainer' ? 'Maintainer' : l.by)}</td><td class="dataList-cell">${esc(l.what)}</td><td class="dataList-cell">${esc(l.reason || '-')}</td></tr>`).join('')}
        </table></div></div>
      </div></div>`,
    };
  }

  // --- pages: template manager (S1 HQ) -------------------------------------------------------------

  const thumbs = {};
  function plateThumb(key) { if (!thumbs[key]) thumbs[key] = thumb(Render.plate(key), 70); return thumbs[key]; }
  function servesText(t) { return t.serves.map(x => typeTitle(x.kind, x.id) + (x.kind === 'record' ? ' records' : '')).join(', '); }

  function pageTemplates() {
    const ts = S.templates;
    return {
      title: 'Citation templates', crumbs: [{ label: 'Milpacs', href: '#/rosters' }],
      actions: `<div class="buttonGroup"><a class="button" href="#/citations/signatures" data-proto="btn-signatures">${icon('pen-line')}<span>Signatures</span></a></div> <a class="button button--cta" data-act="nyi" style="margin-left:5px">${icon('square-plus')}<span>Add template</span></a>`,
      body: `<div class="block"><div class="block-container"><div class="block-body"><div class="dataList"><table class="dataList-table">
        <tr class="dataList-row dataList-row--header"><th class="dataList-cell">&nbsp;</th><th class="dataList-cell">Template</th><th class="dataList-cell">Serves</th><th class="dataList-cell">Versions</th><th class="dataList-cell">Citations issued</th></tr>
        ${ts.map(t => {
          const latest = t.versions[t.versions.length - 1];
          return `<tr class="dataList-row"><td class="dataList-cell dataList-cell--min"><img src="${plateThumb(latest.plate)}" style="width:44px;display:block;border:1px solid var(--line)"></td>
            <td class="dataList-cell"><a href="#/citations/templates/${t.id}" data-proto="tpl-${t.id}">${esc(t.title)}</a>${t.regiment ? ' <span class="u-muted u-smaller">(regiment)</span>' : ''}</td>
            <td class="dataList-cell">${esc(servesText(t))}</td>
            <td class="dataList-cell">${t.versions.map(v => `v${v.v} ${stateLabel(v.state)}`).join(' &nbsp; ')}</td>
            <td class="dataList-cell">${S.grants.filter(g => g.templateId === t.id).length}</td></tr>`;
        }).join('')}</table></div></div></div></div>`,
    };
  }

  function pageTemplate(tid) {
    const t = Model.template(S, tid);
    if (!t) return pageTemplates();
    const rankText = { member: 'Each member\'s rank, copied from their milpac', grant: 'One rank the citation sets for everyone', plate: 'Not printed from data (on the plate, or not at all)' }[t.rankSource];
    return {
      title: esc(t.title), crumbs: [{ label: 'Milpacs', href: '#/rosters' }, { label: 'Citation templates', href: '#/citations/templates' }],
      body: `<div class="block"><div class="block-container">
        <h3 class="block-formSectionHeader">Information</h3>
        <div class="block-body block-row">
          <dl class="pairs pairs--columns pairs--tight"><dt>Serves</dt><dd>${esc(servesText(t))}</dd></dl>
          <dl class="pairs pairs--columns pairs--tight"><dt>Rank</dt><dd>${esc(rankText)}</dd></dl>
          <dl class="pairs pairs--columns pairs--tight"><dt>Template inputs</dt><dd>${t.inputs.length ? t.inputs.map(i => `${esc(i.label)} <span class="u-muted">(${i.scope === 'member' ? 'per member' : 'per citation'}, ${i.required ? 'required' : 'optional'})</span>`).join('<br>') : 'None'}</dd></dl>
        </div>
        <h3 class="block-formSectionHeader">Versions</h3>
        <div class="block-body block-row"><div class="dataList"><table class="dataList-table">
          <tr class="dataList-row dataList-row--header"><th class="dataList-cell">Version</th><th class="dataList-cell">Plate</th><th class="dataList-cell">Status</th><th class="dataList-cell">Published</th><th class="dataList-cell">Citations on it</th><th class="dataList-cell">&nbsp;</th></tr>
          ${t.versions.map(v => `<tr class="dataList-row" data-proto="version-${t.id}-${v.v}">
            <td class="dataList-cell"><a href="#/citations/templates/${t.id}/v/${v.v}" data-proto="open-version-${t.id}-${v.v}">Version ${v.v}</a></td>
            <td class="dataList-cell"><img src="${plateThumb(v.plate)}" style="width:52px;display:block;border:1px solid var(--line)"><span class="rowNote">${v.w} &times; ${v.h}, ${v.w > v.h ? 'landscape' : 'portrait'}</span></td>
            <td class="dataList-cell">${stateLabel(v.state)}</td><td class="dataList-cell">${v.publishedAt || '-'}</td>
            <td class="dataList-cell">${Model.versionPins(S, t.id, v.v)}</td>
            <td class="dataList-cell dataList-cell--action">${t.regiment ? '' : v.state === 'draft'
              ? `<a class="button button--small" href="#/citations/templates/${t.id}/v/${v.v}">${icon('pencil')}<span>Edit</span></a> <a class="button button--small" data-act="tpl-publish" data-t="${t.id}" data-v="${v.v}" data-proto="publish-${t.id}-${v.v}">Publish</a>`
              : v.state === 'published' ? `<a class="button button--small" data-act="tpl-retire" data-t="${t.id}" data-v="${v.v}" data-proto="retire-${t.id}-${v.v}">Retire</a>` : ''}</td></tr>`).join('')}
        </table></div></div>
      </div></div>`,
    };
  }

  const SAMPLE_TEXT = 'For meritorious achievement while serving as a squad leader in the 1st Battalion. Staff Sergeant Sample kept the squad trained, informed and ready through a demanding season of operations, and the example set reflects great credit upon the squad and the 7th Cavalry Regiment.';
  function sampleGrant(t, ver) {
    const sigs = {};
    for (const f of Model.slots(ver)) { const a = Model.signaturesForBillet(S, f.billetId, false); if (a.length) sigs[f.key] = a[0].id; }
    const inputs = {}; const minputs = {};
    for (const i of t.inputs) (i.scope === 'grant' ? inputs : minputs)[i.key] = i.key === 'class' ? '26-09' : i.key === 'unit' ? 'Alpha Company, 1st Battalion' : 'Sample';
    const serve = t.serves[0];
    return {
      grant: { id: 0, kind: serve.kind, typeId: serve.id, date: S.today, templateId: t.id, v: ver.v, sigs, text: SAMPLE_TEXT, inputs, revision: 1, members: [] },
      gm: { id: -1, name: 'Sample Member', rank: Model.rankSnapshot(17), inputs: minputs, token: 'sample' },
    };
  }

  function fieldSummary(f) {
    if (f.kind === 'signature') return `Signature slot: ${esc((Model.billet(S, f.billetId) || {}).title || '?')}`;
    if (f.kind === 'rankArt') return 'Rank art';
    return `<code>${esc(f.pattern.length > 44 ? f.pattern.slice(0, 44) + '...' : f.pattern)}</code>`;
  }

  function pageVersion(tid, v) {
    const t = Model.template(S, tid), ver = Model.version(S, tid, v);
    if (!t || !ver) return pageTemplates();
    const draft = ver.state === 'draft';
    ui.boxes = ui.boxes == null ? true : ui.boxes;
    return {
      title: `${esc(t.title)}: version ${v} ${stateLabel(ver.state)}`,
      crumbs: [{ label: 'Milpacs', href: '#/rosters' }, { label: 'Citation templates', href: '#/citations/templates' }, { label: t.title, href: `#/citations/templates/${t.id}` }],
      actions: draft ? `<a class="button" data-act="tpl-publish" data-t="${t.id}" data-v="${v}" data-proto="publish-${t.id}-${v}">${icon('check')}<span>Publish</span></a>` : '',
      body: `${draft ? '' : `<div class="blockMessage blockMessage--iconic">${icon('lock', 'bm-icon')}This version is ${ver.state}, so it's frozen. Its plate and fields can't change. ${ver.state === 'retired' ? 'It still renders for every citation issued on it.' : ''}</div>`}
        <div class="issueLayout issueLayout--editor"><div class="issueFormCol">
        <div class="block"><div class="block-container">
          <h3 class="block-formSectionHeader">Plate</h3>
          <div class="block-row"><div class="contentRow"><img src="${plateThumb(ver.plate)}" style="width:70px;border:1px solid var(--line)">
            <div class="contentRow-main"><div class="contentRow-title">${ver.w} &times; ${ver.h} pixels</div>
            <div class="contentRow-minor">Exported from S1's design file with the fixed wording in place and no signature. Boxes below are measured in these pixels.</div>
            ${draft ? `<div style="margin-top:8px"><a class="button button--small" data-act="nyi">${icon('upload')}<span>Replace plate</span></a></div>` : ''}</div></div></div>
          <h3 class="block-formSectionHeader">Fields</h3>
          <div class="block-body block-row"><div class="dataList"><table class="dataList-table">
            <tr class="dataList-row dataList-row--header"><th class="dataList-cell">Field</th><th class="dataList-cell">What it sets</th><th class="dataList-cell">Box (x, y, width &times; height)</th><th class="dataList-cell">&nbsp;</th></tr>
            ${ver.fields.map(f => `<tr class="dataList-row" data-proto="field-${f.key}"><td class="dataList-cell">${esc(f.key)}</td><td class="dataList-cell">${fieldSummary(f)}${f.kind === 'text' ? `<span class="rowNote">${f.font === 'bold' ? 'Tinos Bold' : f.font === 'italic' ? 'Tinos Italic' : 'Tinos'}, up to ${f.size}px${f.caps ? ', capitals' : ''}${f.multiline ? ', wraps' : ''}</span>` : ''}</td>
              <td class="dataList-cell">${f.x}, ${f.y}, ${f.w} &times; ${f.h}</td>
              <td class="dataList-cell dataList-cell--action">${draft ? `<a class="button button--small" data-act="field-edit" data-key="${esc(f.key)}">${icon('pencil')}<span>Edit</span></a> <a class="button button--small" data-act="field-delete" data-key="${esc(f.key)}" title="Delete">${icon('trash-2')}</a>` : ''}</td></tr>`).join('')}
          </table></div></div>
          ${draft ? `<div class="block-footer"><a class="button" data-act="field-add" data-proto="field-add">${icon('plus')}<span>Add field</span></a></div>` : ''}
        </div></div></div>
        <div class="issuePreviewCol"><div class="block-container previewBlock"><h3 class="block-header">Preview <span class="block-desc">With sample data</span></h3>
          <div class="block-row"><label class="iconic iconic--checkbox iconic--standalone" style="margin-bottom:10px"><input type="checkbox" id="boxesToggle" ${ui.boxes ? 'checked' : ''}><i></i><span class="iconic-label">Show field boxes</span></label>
          <div id="previewMount" class="previewMount"></div></div></div></div></div>`,
      after: () => {
        const draw = () => {
          const { grant, gm } = sampleGrant(t, ver);
          const { canvas } = Render.renderGrant(S, { grant, gm, ver, preview: true, boxes: ui.boxes });
          canvas.className = 'previewCanvas';
          $('#previewMount').replaceChildren(canvas);
        };
        $('#boxesToggle').addEventListener('change', e => { ui.boxes = e.target.checked; draw(); });
        draw();
      },
    };
  }

  const PLACEHOLDERS = ['name', 'rank', 'pay_grade', 'rank_abbr', 'day', 'day_ordinal', 'month', 'year', 'citation_text'];
  function fieldOverlay(tid, v, key) {
    const t = Model.template(S, tid), ver = Model.version(S, tid, v);
    const orig = ver.fields.find(f => f.key === key);
    const f = orig ? structuredClone(orig) : { key: '', kind: 'text', pattern: '', x: 40, y: 0, w: Math.min(1195, ver.w - 80), h: 44, font: 'regular', size: 36, minLegible: 20, align: 'center', multiline: false, caps: false, color: '#16161a' };
    const chips = [...PLACEHOLDERS.map(p => '{' + p + '}'), ...t.inputs.map(i => `{input:${i.key}}`)];
    const num = (k, label) => `<label class="numField"><span>${label}</span><input type="number" class="input input--number" data-ff="${k}" value="${f[k]}"></label>`;
    const body = () => `<form class="block" onsubmit="return false"><div class="block-container"><div class="fieldEditor"><div class="fieldEditor-form block-body">
      <dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Field name</label></div></dt><dd><input class="input" data-ff="key" value="${esc(f.key)}" placeholder="For example: member" data-proto="field-key"></dd></dl>
      <dl class="formRow"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Kind</label></div></dt><dd><ul class="inputChoices inputChoices--inline">
        ${[['text', 'Text'], ['signature', 'Signature slot'], ['rankArt', 'Rank art']].map(([k, l]) => `<li class="inputChoices-choice"><label class="iconic iconic--radio"><input type="radio" name="fkind" value="${k}" ${f.kind === k ? 'checked' : ''}><i></i><span class="iconic-label">${l}</span></label></li>`).join('')}</ul></dd></dl>
      ${f.kind === 'text' ? `<dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Pattern</label><dfn class="formRow-hint">Literal text and values</dfn></div></dt>
        <dd><textarea class="input" rows="2" data-ff="pattern" data-proto="field-pattern" style="min-height:0">${esc(f.pattern)}</textarea>
        <div class="chips">${chips.map(c => `<a class="chip" data-act="pattern-chip" data-ph="${esc(c)}">${esc(c)}</a>`).join('')}</div>
        <div class="formRow-explain">Put part in [square brackets] to print it only when every value in it is filled in, such as <code>{rank}[ ({pay_grade})]</code>.</div></dd></dl>` : ''}
      ${f.kind === 'signature' ? `<dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Signing billet</label></div></dt>
        <dd><select class="input" data-ff="billetId">${S.billets.map(b => `<option value="${b.id}" ${f.billetId === b.id ? 'selected' : ''}>${esc(b.title)}</option>`).join('')}</select></dd></dl>` : ''}
      <dl class="formRow"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Box</label><dfn class="formRow-hint">Plate is ${ver.w} &times; ${ver.h}</dfn></div></dt>
        <dd><div class="numRow" data-proto="field-box">${num('x', 'X')}${num('y', 'Y')}${num('w', 'Width')}${num('h', 'Height')}</div></dd></dl>
      ${f.kind === 'text' ? `<dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Font</label></div></dt>
        <dd><select class="input" data-ff="font" style="max-width:220px">${[['regular', 'Tinos'], ['bold', 'Tinos Bold'], ['italic', 'Tinos Italic']].map(([k, l]) => `<option value="${k}" ${f.font === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <div class="numRow" style="margin-top:10px">${num('size', 'Largest size (px)')}${num('minLegible', 'Reading size (px)')}</div>
        <div class="formRow-explain">Text is set at the largest size that fits the box. Below the reading size the clerk gets a warning and S1 gets the citation listed here.</div></dd></dl>
      <dl class="formRow"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Setting</label></div></dt><dd>
        <ul class="inputChoices inputChoices--inline">${[['center', 'Centred'], ['left', 'Left'], ['justify', 'Justified']].map(([k, l]) => `<li class="inputChoices-choice"><label class="iconic iconic--radio"><input type="radio" name="falign" value="${k}" ${f.align === k ? 'checked' : ''}><i></i><span class="iconic-label">${l}</span></label></li>`).join('')}</ul>
        <ul class="inputChoices" style="margin-top:12px">
          <li class="inputChoices-choice"><label class="iconic iconic--checkbox"><input type="checkbox" data-fc="caps" ${f.caps ? 'checked' : ''} data-proto="field-caps"><i></i><span class="iconic-label">Set in capitals</span></label></li>
          <li class="inputChoices-choice"><label class="iconic iconic--checkbox"><input type="checkbox" data-fc="multiline" ${f.multiline ? 'checked' : ''}><i></i><span class="iconic-label">Wrap onto several lines</span></label></li></ul></dd></dl>` : ''}
      </div><div class="fieldEditor-preview"><div class="u-muted u-smaller" style="margin-bottom:6px">Preview with sample data</div><div id="fieldPreview" class="previewMount"></div></div></div>
      <div class="formSubmitRow"><div class="formSubmitRow-bar"><div class="formSubmitRow-controls"><button class="button button--primary" data-proto="field-save" id="fieldSave">${icon('save')}<span>Save</span></button></div></div></div></div></form>`;
    const draw = el => {
      const tmp = structuredClone(ver);
      const k = f.key || '(new field)';
      const ff = { ...f, key: k };
      const i = tmp.fields.findIndex(x => x.key === key);
      if (i >= 0) tmp.fields[i] = ff; else tmp.fields.push(ff);
      const { grant, gm } = sampleGrant(t, tmp);
      const { canvas } = Render.renderGrant(S, { grant, gm, ver: tmp, preview: true, boxes: true, highlight: k });
      canvas.className = 'previewCanvas';
      el.querySelector('#fieldPreview').replaceChildren(canvas);
    };
    const bind = el => {
      $$('[data-ff]', el).forEach(i => i.addEventListener('input', () => {
        const k = i.dataset.ff;
        f[k] = ['x', 'y', 'w', 'h', 'size', 'minLegible', 'billetId'].includes(k) ? Number(i.value) : i.value;
        draw(el);
      }));
      $$('[data-fc]', el).forEach(i => i.addEventListener('change', () => { f[i.dataset.fc] = i.checked; draw(el); }));
      $$('input[name=falign]', el).forEach(i => i.addEventListener('change', () => { f.align = i.value; draw(el); }));
      $$('input[name=fkind]', el).forEach(i => i.addEventListener('change', () => {
        f.kind = i.value;
        if (f.kind === 'signature' && !f.billetId) { f.billetId = S.billets[0].id; f.h = Math.max(f.h, 160); f.w = Math.min(f.w, 340); }
        el.querySelector('.overlay-content').innerHTML = body(); bind(el); draw(el);
      }));
      el.querySelector('#fieldSave').addEventListener('click', () => {
        if (!String(f.key).trim()) f.key = f.kind === 'signature' ? 'sig' : 'field';
        f.key = String(f.key).trim().replace(/\s+/g, '_');
        const res = Model.saveField(S, tid, v, structuredClone(f), key);
        commit(res, () => { closeOverlay(); flash('Your changes have been saved.'); emit('field-saved', { tid, v }); render(); });
      });
      ui.fieldChip = ph => {
        const ta = el.querySelector('[data-ff=pattern]');
        if (!ta) return;
        const s0 = ta.selectionStart ?? ta.value.length, s1 = ta.selectionEnd ?? ta.value.length;
        ta.value = ta.value.slice(0, s0) + ph + ta.value.slice(s1);
        ta.focus(); ta.setSelectionRange(s0 + ph.length, s0 + ph.length);
        f.pattern = ta.value; draw(el);
      };
    };
    openOverlay({ title: orig ? `Edit field: ${orig.key}` : 'Add field', wide: true, body: body(), after: el => { bind(el); draw(el); } });
  }

  function pageSignatures() {
    const sigRow = x => {
      const uses = Model.signatureUses(S, x.id);
      const ink = Render.IMG[x.ink];
      const inkSrc = ink ? (ink.toDataURL ? ink.toDataURL() : ink.src) : '';
      return `<tr class="dataList-row" data-proto="sig-${x.id}"><td class="dataList-cell">${x.lines.map((l, i) => i ? esc(l) : `<strong style="color:var(--strong);font-weight:500">${esc(l)}</strong>`).join('<br>')}</td>
        <td class="dataList-cell">${esc(Model.billet(S, x.billetId).title)}</td>
        <td class="dataList-cell"><span class="inkThumb"><img src="${inkSrc}" alt=""></span></td>
        <td class="dataList-cell">${x.state === 'active' ? '<span class="label label--green">Active</span>' : '<span class="label label--grey">Retired</span>'}</td>
        <td class="dataList-cell">${uses ? `${uses} citation${uses > 1 ? 's' : ''}<span class="rowNote">Frozen. Changes need a new signature.</span>` : '<span class="u-muted">Not used yet</span>'}</td>
        <td class="dataList-cell dataList-cell--action">${x.state === 'active' ? `<a class="button button--small" data-act="sig-retire" data-sig="${x.id}">Retire</a>` : `<a class="button button--small" data-act="sig-reinstate" data-sig="${x.id}">Reinstate</a>`}${uses ? '' : ` <a class="button button--small" data-act="nyi">${icon('pencil')}<span>Edit</span></a>`}</td></tr>`;
    };
    return {
      title: 'Signatures', crumbs: [{ label: 'Milpacs', href: '#/rosters' }, { label: 'Citation templates', href: '#/citations/templates' }],
      actions: `<a class="button button--cta" data-act="sig-add" data-proto="sig-add">${icon('square-plus')}<span>Add signature</span></a>`,
      body: `<div class="block"><div class="block-container">
        <h3 class="block-formSectionHeader">Signing billets</h3>
        <div class="block-body block-row"><div class="dataList"><table class="dataList-table">
          <tr class="dataList-row dataList-row--header"><th class="dataList-cell">Billet</th><th class="dataList-cell">Template slots</th><th class="dataList-cell">Active signatures</th></tr>
          ${S.billets.map(b => {
            const slotCount = S.templates.reduce((n, t) => n + t.versions.filter(v => v.state !== 'retired').reduce((m, v) => m + v.fields.filter(f => f.kind === 'signature' && f.billetId === b.id).length, 0), 0);
            return `<tr class="dataList-row"><td class="dataList-cell">${esc(b.title)}</td><td class="dataList-cell">${slotCount}</td><td class="dataList-cell">${Model.signaturesForBillet(S, b.id, false).length}</td></tr>`;
          }).join('')}</table></div></div>
        <div class="block-footer">A billet is only a matching key between a template's slot and the signatures S1 keeps. It never prints. <a data-act="nyi">Add billet</a></div>
      </div></div>
      <div class="block"><div class="block-container">
        <h3 class="block-formSectionHeader">Signatures</h3>
        <div class="block-body block-row"><div class="dataList"><table class="dataList-table">
          <tr class="dataList-row dataList-row--header"><th class="dataList-cell">Printed lines</th><th class="dataList-cell">Billet</th><th class="dataList-cell">Ink</th><th class="dataList-cell">Status</th><th class="dataList-cell">Used on</th><th class="dataList-cell">&nbsp;</th></tr>
          ${S.signatures.map(sigRow).join('')}</table></div></div>
      </div></div>`,
    };
  }

  function signatureOverlay() {
    const f = { billetId: 2, lines: ['', 'Regimental Executive Officer', '7th Cavalry Regiment'], ink: '' };
    const samples = ['inkE', 'inkF', 'inkG'];
    const src = k => { const im = Render.IMG[k]; return im ? (im.toDataURL ? im.toDataURL() : im.src) : ''; };
    openOverlay({
      title: 'Add signature',
      body: `<form class="block" onsubmit="return false"><div class="block-container"><div class="block-body">
        <dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Signing billet</label></div></dt>
          <dd><select class="input" data-sf="billetId">${S.billets.map(b => `<option value="${b.id}" ${b.id === f.billetId ? 'selected' : ''}>${esc(b.title)}</option>`).join('')}</select>
          <div class="formRow-explain">Clerks see this signature in every slot that takes this billet.</div></dd></dl>
        <dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Printed lines</label><dfn class="formRow-hint">Set under the signature, as typed</dfn></div></dt>
          <dd><input class="input" data-sl="0" placeholder="Name, such as Lieutenant Colonel Ansel Marchbanks" data-proto="sig-line1">
            <input class="input" data-sl="1" value="${esc(f.lines[1])}" style="margin-top:8px">
            <input class="input" data-sl="2" value="${esc(f.lines[2])}" style="margin-top:8px"></dd></dl>
        <dl class="formRow formRow--input"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Ink image</label><dfn class="formRow-hint">The signature only, at plate scale</dfn></div></dt>
          <dd><input type="file" class="input" accept=".png" data-sf="file">
            <div class="formRow-explain">Or, for this prototype, use a sample:</div>
            <ul class="inputChoices inputChoices--inline" style="margin-top:8px" data-proto="sig-samples">${samples.map(k => `<li class="inputChoices-choice"><label class="iconic iconic--radio"><input type="radio" name="inkSample" value="${k}"><i></i><span class="iconic-label"><span class="inkThumb"><img src="${src(k)}"></span></span></label></li>`).join('')}</ul></dd></dl>
        <dl class="formRow"><dt><div class="formRow-labelWrapper"><label class="formRow-label">Preview</label></div></dt><dd><div id="sigPreview" class="sigPreview"></div></dd></dl>
      </div><div class="formSubmitRow"><div class="formSubmitRow-bar"><div class="formSubmitRow-controls"><button class="button button--primary" id="sigSave" data-proto="sig-save">${icon('save')}<span>Save</span></button></div></div></div></div></form>`,
      after: el => {
        const draw = () => {
          const c = document.createElement('canvas'); c.width = 660; c.height = 300;
          const g = c.getContext('2d');
          g.drawImage(Render.plate('msm'), 300, 1200, 660, 300, 0, 0, 660, 300);
          Render.drawSignature(g, { x: 165, y: 40, w: 330, h: 220 }, { lines: f.lines, ink: f.ink });
          el.querySelector('#sigPreview').replaceChildren(c);
        };
        $$('[data-sl]', el).forEach(i => i.addEventListener('input', () => { f.lines[+i.dataset.sl] = i.value; draw(); }));
        el.querySelector('[data-sf=billetId]').addEventListener('change', e => { f.billetId = +e.target.value; });
        $$('input[name=inkSample]', el).forEach(i => i.addEventListener('change', () => { f.ink = i.value; draw(); }));
        el.querySelector('[data-sf=file]').addEventListener('change', e => {
          const file = e.target.files[0]; if (!file) return;
          const rd = new FileReader();
          rd.onload = () => { const im = new Image(); im.onload = () => { const k = 'inkU' + Date.now(); Render.IMG[k] = im; f.ink = k; draw(); }; im.src = rd.result; };
          rd.readAsDataURL(file);
        });
        el.querySelector('#sigSave').addEventListener('click', () => {
          const res = Model.addSignature(S, { billetId: f.billetId, lines: f.lines, ink: f.ink });
          commit(res, () => { closeOverlay(); flash('Your changes have been saved.'); emit('signature-added'); render(); });
        });
        draw();
      },
    });
  }
  // --- actions -----------------------------------------------------------------------------------

  function act(name, el) {
    const d = el.dataset;
    switch (name) {
      case 'nyi': return nyi();
      case 'close-overlay': return closeOverlay();
      case 'open-row-citation': return openRowCitation(+d.row);
      case 'edit-row': closeMenus(); return editRowOverlay(+d.row);
      case 'delete-row': closeMenus(); closeOverlay(); return deleteRow(+d.row);
      case 'preview-gm': case 'open-gm': return openGrantMember(+d.gm, { via: name });
      case 'open-regiment': return openRegiment(+d.grant, { via: name });
      case 'issue-add-member': return addMember(ui.issue, 'issue');
      case 'issue-remove-member': ui.issue.members.splice(+d.i, 1); ui.issue.previewIdx = 0; return ui.rerenderIssue();
      case 'issue-apply-all': {
        const dd = ui.issue || ui.correct;
        for (const mm of dd.members) mm.inputs = { ...(mm.inputs || {}), [d.key]: dd.fillAll[d.key] || '' };
        return ui.rerenderIssue();
      }
      case 'issue-preview-tab': {
        const dd = route().endsWith('/correct') ? ui.correct : ui.issue;
        dd.previewIdx = +d.i;
        $('#previewCol').innerHTML = previewHtml(dd); drawPreview(dd, 'issue');
        return emit('preview-tab', { i: +d.i });
      }
      case 'issue-open-full': {
        if (!ui.previewCanvas) return;
        return Render.openImage(ui.previewCanvas, 'preview.jpg', viewerFallback);
      }
      case 'issue-submit': return submitIssue();
      case 'correct-submit': return submitCorrect();
      case 'tpl-publish': {
        const t = Model.template(S, +d.t);
        return confirmOverlay({
          title: `Publish version ${d.v}?`, button: 'Publish',
          message: `Publishing freezes version ${d.v} of <strong>${esc(t.title)}</strong>. Its plate and fields can't change after this. S1 Citations clerks will see it on the citation form straight away.`,
          onConfirm: () => commit(Model.publishVersion(S, +d.t, +d.v), () => { flash('Version ' + d.v + ' is published.'); emit('version-published'); go('/citations/templates/' + d.t); }),
        });
      }
      case 'tpl-retire': {
        const t = Model.template(S, +d.t);
        const n = Model.versionPins(S, +d.t, +d.v);
        return confirmOverlay({
          title: `Retire version ${d.v}?`, button: 'Retire',
          message: `Clerks won't be offered version ${d.v} of <strong>${esc(t.title)}</strong> any more. The ${n} citation${n === 1 ? '' : 's'} issued on it keep${n === 1 ? 's' : ''} rendering exactly as issued. Nothing moves to a newer version unless S1 moves it as a logged correction.`,
          onConfirm: () => commit(Model.retireVersion(S, +d.t, +d.v), () => { flash('Version ' + d.v + ' is retired.'); emit('version-retired'); render(); }),
        });
      }
      case 'field-edit': return fieldOverlay(...versionFromRoute(), d.key);
      case 'field-add': return fieldOverlay(...versionFromRoute(), null);
      case 'field-delete': {
        const [tid, v] = versionFromRoute();
        return confirmOverlay({ title: 'Confirm action', button: 'Delete', iconName: 'trash-2', message: `Delete the field <strong>${esc(d.key)}</strong>?`, onConfirm: () => commit(Model.deleteField(S, tid, v, d.key), () => render()) });
      }
      case 'pattern-chip': return ui.fieldChip && ui.fieldChip(d.ph);
      case 'sig-add': return signatureOverlay();
      case 'sig-retire': return commit(Model.setSignatureState(S, +d.sig, 'retired'), () => { flash('The signature is retired.'); render(); });
      case 'sig-reinstate': return commit(Model.setSignatureState(S, +d.sig, 'active'), () => { flash('The signature is active again.'); render(); });
      default: return null;
    }
  }
  function versionFromRoute() { const m = route().match(/templates\/(\d+)\/v\/(\d+)/); return [+m[1], +m[2]]; }

  function boot() {
    document.addEventListener('click', e => {
      const a = e.target.closest('[data-act]');
      if (a && !a.closest('#guide')) { e.preventDefault(); act(a.dataset.act, a, e); return; }
      const m = e.target.closest('[data-menu]');
      if (m) { e.preventDefault(); toggleMenu(m); return; }
      if (openMenu && !e.target.closest('.menu')) closeMenus();
      if (e.target.closest('.menu a[href]')) closeMenus();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeMenus(); closeOverlay(); } });
    window.addEventListener('hashchange', () => { closeOverlay(); render(); window.scrollTo(0, 0); emit('route', { route: route() }); });
    window.addEventListener('scroll', () => { const n = $('#navSticky'); if (n) n.classList.toggle('is-sticky', window.scrollY > 40); }, { passive: true });
    const navFit = () => { const l = $('.p-nav-list'); if (l) l.classList.toggle('is-overflowing', l.scrollWidth > l.clientWidth + 2); };
    window.addEventListener('resize', navFit);
    listeners.push(t => { if (t === 'reset' || t === 'render') requestAnimationFrame(navFit); });
    new MutationObserver(() => requestAnimationFrame(navFit)).observe(document.body, { attributes: true, attributeFilter: ['class'] });
  }

  return {
    boot, reset, render, go, route, emit, flash,
    on: f => listeners.push(f),
    get state() { return S; }, get pathId() { return pathId; }, get events() { return events; }, get ui() { return ui; },
    setIssueText(t) { if (!ui.issue) ui.issue = newIssueDraft(); ui.issue.text = t; const ta = document.querySelector('[data-d=text]'); if (ta) { ta.value = t; ta.dispatchEvent(new Event('input')); } },
    simulate(fn) { S = fn(S); emit('change'); render(); },
    persona,
  };
})();
