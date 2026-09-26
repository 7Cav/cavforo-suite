// PROTOTYPE. The walkthrough panel: the guided paths, the per-step feedback box, and the export.
// This panel is the only part of the page that isn't a stand-in for the forum.

const Guide = (() => {
  const STORE = 'cavforo-citation-prototype-v1';

  // --- helpers the checks share ----------------------------------------------------------------

  const byClerk = (S, typeId) => S.grants.find(g => g.typeId === typeId && g.issuedBy === 'Ashgrove.P' && g.issuedAt === S.today);
  const rowWith = (S, memberId, kind, typeId) => S.rows.find(r => r.memberId === memberId && r.kind === kind && r.typeId === typeId && r.citation && r.citation.type === 'grant');
  const openedRow = (ctx, rowId) => ctx.events.some(e => e.type === 'open-citation' && e.from && e.from.rowId === rowId);
  const onRoute = (ctx, r) => ctx.route === r;
  const visible = sel => { const el = document.querySelector(sel); return el && el.offsetParent !== null ? el : null; };
  const menuOpen = () => !!document.querySelector('body > .menu');
  const overlayOpen = () => !!document.getElementById('overlay');
  function profileTarget(ctx, memberId, then) {
    if (ctx.route !== `/rosters/profile/${memberId}`) {
      if (ctx.route === '/rosters') return `[data-proto="roster-${Model.member(ctx.S, memberId).username}"]`;
      return '[data-proto=nav-milpacs]';
    }
    return then;
  }

  // --- the paths -----------------------------------------------------------------------------------

  const PATHS = {
    citations: {
      title: 'S1 Citations clerk',
      who: 'You\'re Corporal Priya Ashgrove (Ashgrove.P), an S1 Citations clerk.',
      summary: 'Issue a written MSM to two members, issue a generic EIB, then fix a typo on the MSM.',
      steps: [
        {
          key: 'find-form', title: 'Find the citation form',
          scenario: 'The MSM recommendation for Quill.E and Delacroix.M was proofread and approved on 24 September 2026. You\'re ready to make the certificate.',
          task: 'Open the arrow next to <b>Milpacs</b> in the top menu and choose <b>Issue a citation</b>.',
          changed: 'Today you\'d open the MSM design file in GIMP. Now every citation starts from a form on the forum, under Milpacs.',
          ask: 'Is this where you\'d expect to find it?',
          check: ctx => onRoute(ctx, '/citations/issue') || !!byClerk(ctx.S, 15),
          target: () => menuOpen() ? '[data-proto=menu-issue]' : '[data-proto=nav-milpacs]',
        },
        {
          key: 'award-template-date', title: 'Pick the award, template and date',
          scenario: 'The form asks what\'s being awarded first, because that decides which designs you can use.',
          task: 'Choose <b>Meritorious Service Medal</b>. Its template is picked for you because it\'s the only one. Set the date to the approval date, <b>24 September 2026</b>.',
          changed: 'Today you choose the design by opening the right file, and switch layers on for variants such as valor. Now the form lists only the templates made for the award you chose, and each variant is its own template. The date prints in the template\'s own wording, so you pick a date instead of typing "24TH DAY OF SEPTEMBER".',
          ask: 'Anything you\'d need to choose here that the form doesn\'t ask for?',
          check: ctx => { const d = ctx.ui.issue; return (d && d.typeId === 15 && d.templateId === 1 && !!d.date) || !!byClerk(ctx.S, 15); },
          target: ctx => { const d = ctx.ui.issue || {}; return d.typeId !== 15 ? '[data-proto=issue-type]' : !d.date ? '[data-proto=issue-date]' : null; },
        },
        {
          key: 'signatures', title: 'Check the signatures',
          scenario: 'The MSM design has two signature slots: the Regimental Commander and the Regimental Executive Officer.',
          task: 'The Commander\'s slot is filled in for you, because only one active Commander signature is on file. Two XO signatures are on file, so pick the XO yourself. Then tick <b>Show retired signatures</b> under the Commander\'s slot to see the previous commander\'s signature.',
          changed: 'Today the signature is part of each design, so after a change of command the old commander stays on some designs until someone edits them. Now you pick the signatures once per citation, from signatures S1 keeps for each billet. The form won\'t issue with a slot left empty.',
          ask: 'Would you know which signature to pick? Who tells you today?',
          check: ctx => { const d = ctx.ui.issue; return (d && d.sigs.sig1 && d.sigs.sig2) || !!byClerk(ctx.S, 15); },
          target: ctx => { const d = ctx.ui.issue || { sigs: {} }; return !d.sigs.sig2 ? '[data-proto=issue-sig-sig2] select' : null; },
        },
        {
          key: 'members', title: 'Add the members',
          scenario: 'The MSM goes to Staff Sergeant Elias Quill and Sergeant First Class Mara Delacroix.',
          task: 'Add <b>Quill.E</b> and <b>Delacroix.M</b>. Check the name and rank the form copied from each milpac. Give each a unit line: Alpha Company, 1st Battalion for Quill.E and Bravo Company, 1st Battalion for Delacroix.M.',
          changed: 'Today you type each name and rank into the design by hand. Now adding a member copies both from their milpac, and you can correct either. The copy stays with the citation, so a later promotion doesn\'t change it. Unit lines are typed, never read from the roster.',
          ask: 'Is the name on the milpac the name you\'d print? Anything else you type per member today?',
          check: ctx => { const d = ctx.ui.issue; return (d && [101, 102].every(id => d.members.some(m => m.memberId === id))) || !!byClerk(ctx.S, 15); },
          target: () => '[data-proto=issue-add-input]',
        },
        {
          key: 'text', title: 'Enter the citation text and watch the preview',
          scenario: 'The proofread citation text is ready. It has a typo in it that nobody has spotted yet.',
          task: 'Put the text into <b>Citation text</b>, or use the button below to fill it in. Watch the preview, and switch it between the two members.',
          changed: 'Today you retype the text into a GIMP text layer and shrink it by hand until it fits. Now you enter it once, as plain text, and the forum fits it to the box. If it comes out below the template\'s reading size, the preview warns you and S1 gets a list to fix the template. You can still issue it.',
          ask: 'Does the preview tell you what you need before you issue?',
          helper: { label: 'Fill in the text for me', run: () => App.setIssueText(Model.MSM_TEXT_WITH_TYPO) },
          check: ctx => { const d = ctx.ui.issue; return (d && d.text.trim().length > 40) || !!byClerk(ctx.S, 15); },
          target: () => '[data-proto=issue-text]',
        },
        {
          key: 'issue', title: 'Issue it',
          task: 'Press <b>Issue citation</b>. You land on the citation\'s page. If anything is missing, the form tells you what.',
          changed: 'Today you export a PNG from GIMP and file it on the Drive for S1 Milpacs to upload. Now there\'s no file to export or file. The citation is stored as data, and S1 Milpacs picks it on the member\'s row. You still write the Awards post as you do today.',
          ask: 'Does anything else use the exported file or the Drive copy today?',
          check: ctx => !!byClerk(ctx.S, 15),
          target: () => '[data-proto=issue-submit]',
        },
        {
          key: 'eib', title: 'Issue a generic EIB',
          scenario: 'Three troopers passed the EIB course, approved 25 September 2026: Okafor.T, Lindqvist.M and Halvorsen.J.',
          task: 'Issue another citation: <b>Expert Infantry Badge</b>, the date, and the three members. There\'s no text to type.',
          changed: 'Today every EIB citation is the same image. That stays true: the text belongs to the EIB template, and today\'s EIB design prints no name. Whether a generic certificate should print the name is for the look approval. What\'s new is that each member has a citation on file for S1 Milpacs to pick.',
          ask: 'Is anything about generic citations different from what you do today?',
          check: ctx => !!byClerk(ctx.S, 67),
          target: ctx => ctx.route !== '/citations/issue' ? (menuOpen() ? '[data-proto=menu-issue]' : visible('[data-proto=btn-issue]') ? '[data-proto=btn-issue]' : '[data-proto=nav-milpacs]')
            : ((ctx.ui.issue || {}).typeId !== 67 ? '[data-proto=issue-type]' : ((ctx.ui.issue || {}).members || []).length < 3 ? '[data-proto=issue-add-input]' : '[data-proto=issue-submit]'),
        },
        {
          key: 'correct', title: 'Fix a typo on an issued citation',
          scenario: 'Delacroix.M noticed "Regimnet" at the end of the MSM citation.',
          task: 'Open the MSM from <b>Milpacs &rsaquo; Citations</b>, choose <b>Correct</b>, fix the word, give a reason and save.',
          changed: 'Today a typo means fixing the design, exporting again and asking S1 Milpacs to upload the new file. Now you correct the citation once, for every member at once. The fix is logged with your reason, and every row showing the citation shows the fix the next time anyone opens it.',
          ask: 'Who else would need to know about a correction?',
          check: ctx => { const g = byClerk(ctx.S, 15); return !!g && g.revision >= 2; },
          target: ctx => {
            const g = byClerk(ctx.S, 15);
            if (!g) return null;
            if (ctx.route === `/citations/${g.id}`) return '[data-proto=btn-correct]';
            if (ctx.route === `/citations/${g.id}/correct`) return (ctx.ui.correct && ctx.ui.correct.reason) ? '[data-proto=correct-submit]' : '[data-proto=correct-reason]';
            if (ctx.route === '/citations') return '[data-proto=grant-15]';
            return menuOpen() ? '[data-proto=menu-citations]' : '[data-proto=nav-milpacs]';
          },
        },
        {
          key: 'open', title: 'Open it from the roster',
          scenario: 'Later that day S1 Milpacs added the MSM to Quill.E\'s milpac and picked your citation.',
          task: 'Go to <b>Milpacs</b>, open <b>Quill.E</b>, and click <b>Citation</b> on the MSM row. On the forum it opens in a new tab. Here it opens over the page instead.',
          changed: 'Nothing changes for whoever opens it. The Citation link opens the certificate as an image in a new tab, as it does today. The image is drawn from the stored citation, so it shows your correction.',
          ask: 'Does it look like a citation you\'d have made?',
          onEnter: () => App.simulate(S => {
            const g = byClerk(S, 15);
            if (!g) return S;
            const gm = g.members.find(m => m.memberId === 101) || g.members[0];
            if (S.rows.some(r => r.citation && r.citation.gmId === gm.id)) return S;
            return Model.saveRow(S, { memberId: gm.memberId, kind: 'award', typeId: 15, date: S.today, details: '1st Award', citation: { mode: 'pick', gmId: gm.id } }).state;
          }),
          check: ctx => { const g = byClerk(ctx.S, 15); return !!g && ctx.events.some(e => e.type === 'open-citation' && e.grantId === g.id && e.from && e.from.rowId); },
          target: ctx => {
            const g = byClerk(ctx.S, 15);
            if (!g) return null;
            const r = ctx.S.rows.find(x => x.citation && x.citation.grantId === g.id);
            return r ? profileTarget(ctx, r.memberId, `[data-proto=cite-${r.id}]`) : null;
          },
        },
        OVERALL('S1 Citations clerk'),
      ],
    },

    milpacs: {
      title: 'S1 Milpacs clerk',
      who: 'You\'re Specialist Theo Fenwick (Fenwick.T), an S1 Milpacs clerk.',
      summary: 'Add an award and pick its citation, put one boot camp certificate on two rows, then look at a PUC row and a Disciplinary record.',
      steps: [
        {
          key: 'find-member', title: 'Find the member',
          scenario: 'S1 Citations posted the awards for 24 September 2026. The Meritorious Service Medal goes to Quill.E and Delacroix.M, and their citations are already issued.',
          task: 'Open <b>Quill.E</b> from the Milpacs roster.',
          changed: 'Nothing. You find the member the way you do today.',
          check: ctx => onRoute(ctx, '/rosters/profile/101') || !!rowWith(ctx.S, 101, 'award', 15),
          target: ctx => ctx.route === '/rosters' ? '[data-proto="roster-Quill.E"]' : '[data-proto=nav-milpacs]',
        },
        {
          key: 'add-award', title: 'Add the award',
          task: 'Open the <b>&bull;&bull;&bull;</b> menu next to <b>Edit user</b>, choose <b>Add award</b>, and pick <b>Meritorious Service Medal</b>. Fill in the details and date as you do today.',
          changed: 'Nothing so far. The award, details and date are the same fields as today.',
          check: ctx => (onRoute(ctx, '/rosters/profile/101/awards/add') && ctx.ui.rowAdd && ctx.ui.rowAdd.f.typeId === 15) || !!rowWith(ctx.S, 101, 'award', 15),
          target: ctx => ctx.route === '/rosters/profile/101/awards/add' ? '[data-proto=award-select]' : profileTarget(ctx, 101, menuOpen() ? '[data-proto=menu-add-award]' : '[data-proto=profile-more]'),
        },
        {
          key: 'pick', title: 'Pick the citation',
          task: 'Where <b>Upload new image</b> used to be, pick the <b>Meritorious Service Medal</b> citation from the list, then <b>Save</b>.',
          changed: 'Today you upload the image S1 Citations made. Now the upload box is gone on every row except Disciplinary records. In its place is a list of every citation issued to this member, newest first, showing any rows each one is already on. You pick one and there\'s no file to handle.',
          ask: 'Is it clear which citation belongs on this row? What would you check before saving?',
          check: ctx => !!rowWith(ctx.S, 101, 'award', 15),
          target: ctx => ctx.route === '/rosters/profile/101/awards/add'
            ? (ctx.ui.rowAdd && ctx.ui.rowAdd.f.pick.startsWith('gm:') ? '[data-proto=row-save]' : '[data-proto=pick-15]') : null,
        },
        {
          key: 'graduation', title: 'Put a boot camp certificate on the Graduation record',
          scenario: 'Private Rory Galloway graduated Basic Combat Training, class 26-09. S1 Citations has issued the class\'s certificates.',
          task: 'Open <b>Galloway.R</b>. Add a service record with the record type <b>Graduation</b> and your usual details, and pick the <b>Graduation</b> citation.',
          changed: 'Same as the award: you pick instead of uploading.',
          check: ctx => !!rowWith(ctx.S, 106, 'record', Model.GRADUATION),
          target: ctx => {
            if (ctx.route === '/rosters/profile/106/service-record/add') {
              const f = ctx.ui.rowAdd && ctx.ui.rowAdd.f;
              return !f || f.typeId !== Model.GRADUATION ? '[data-proto=record-type]' : f.pick.startsWith('gm:') ? '[data-proto=row-save]' : '[data-proto=pick-9]';
            }
            return profileTarget(ctx, 106, menuOpen() ? '[data-proto=menu-add-record]' : '[data-proto=profile-more]');
          },
        },
        {
          key: 'asr', title: 'Put the same certificate on the Army Service Ribbon',
          task: 'Still on Galloway.R, add the <b>Army Service Ribbon</b> award and pick the same Graduation citation. The list shows it\'s already on the Graduation record.',
          changed: 'Today you upload the same certificate file to both rows. Now you pick the same citation on both. A citation can go on any number of rows.',
          ask: 'Would the "already on" line stop you putting it on the wrong row, or just get in the way?',
          check: ctx => { const a = rowWith(ctx.S, 106, 'award', 40), g = rowWith(ctx.S, 106, 'record', Model.GRADUATION); return !!a && (!g || a.citation.gmId === g.citation.gmId); },
          target: ctx => {
            if (ctx.route === '/rosters/profile/106/awards/add') {
              const f = ctx.ui.rowAdd && ctx.ui.rowAdd.f;
              return !f || f.typeId !== 40 ? '[data-proto=award-select]' : f.pick.startsWith('gm:') ? '[data-proto=row-save]' : '[data-proto=pick-9]';
            }
            return profileTarget(ctx, 106, menuOpen() ? '[data-proto=menu-add-award]' : '[data-proto=profile-more]');
          },
        },
        {
          key: 'puc', title: 'Try to edit a PUC row',
          scenario: 'Every milpac carries a row for each of the regiment\'s Presidential Unit Citations.',
          task: 'On Galloway.R\'s milpac, open the <b>&bull;&bull;&bull;</b> menu on an <b>Army &amp; Air Force Presidential Unit Citation</b> row and choose <b>Edit</b>.',
          changed: 'Today new milpacs get their PUC rows automatically, and anyone with award access can still edit or delete one. Now the forum keeps exactly one PUC row per Presidential Unit Citation on every milpac, linked to the regiment\'s certificate, and the forms refuse to add, edit or delete one by hand.',
          ask: 'Do you ever need to change a PUC row by hand today?',
          check: ctx => ctx.events.some(e => e.type === 'edit-row' && e.kind === 'award' && e.typeId === Model.PUC),
          target: ctx => {
            const r = ctx.S.rows.find(x => x.memberId === 106 && Model.isPucRow(x));
            return profileTarget(ctx, 106, menuOpen() ? '[data-proto=menu-edit-row]' : `[data-proto=rowmenu-${r.id}]`);
          },
        },
        {
          key: 'disciplinary', title: 'Open a Disciplinary record',
          scenario: 'Stroud.O has a letter of reprimand on file.',
          task: 'Open <b>Stroud.O</b> and edit the <b>Disciplinary</b> record from its <b>&bull;&bull;&bull;</b> menu.',
          changed: 'Nothing. Disciplinary records keep the file upload as today.',
          ask: 'Anything else you upload today that isn\'t a citation S1 Citations makes?',
          check: ctx => ctx.events.some(e => e.type === 'edit-row' && e.kind === 'record' && e.typeId === Model.DISCIPLINARY),
          target: ctx => {
            if (overlayOpen()) return null;
            const r = ctx.S.rows.find(x => x.memberId === 107 && x.kind === 'record' && x.typeId === Model.DISCIPLINARY);
            return profileTarget(ctx, 107, menuOpen() ? '[data-proto=menu-edit-row]' : `[data-proto=rowmenu-${r.id}]`);
          },
        },
        {
          key: 'open', title: 'Open a citation from the roster',
          task: 'Go back to <b>Quill.E</b> and click <b>Citation</b> on the MSM row you added. On the forum it opens in a new tab. Here it opens over the page instead.',
          changed: 'Nothing for whoever opens it. The certificate opens as an image in a new tab, as it does today.',
          ask: 'Does it look right next to the citations already on the milpac?',
          check: ctx => { const r = rowWith(ctx.S, 101, 'award', 15); return !!r && openedRow(ctx, r.id); },
          target: ctx => { const r = rowWith(ctx.S, 101, 'award', 15); return r ? profileTarget(ctx, 101, `[data-proto=cite-${r.id}]`) : null; },
        },
        OVERALL('S1 Milpacs clerk'),
      ],
    },

    hq: {
      title: 'S1 HQ',
      who: 'You\'re Captain Nora Whitcombe (Whitcombe.N), S1 Officer in Charge.',
      summary: 'Place a field on the new Bronze Star plate, add a signature, publish the new version and retire the old one.',
      steps: [
        {
          key: 'find-templates', title: 'Find the template manager',
          scenario: 'The Bronze Star is moving to the taller portrait layout leadership preferred. A draft of the new version is waiting.',
          task: 'Open the arrow next to <b>Milpacs</b>, choose <b>Citation templates</b>, and open <b>Bronze Star Medal</b>.',
          changed: 'Today each design is a GIMP file on the Drive. Now each design S1 approves is a template on the forum, with its own versions. S1 still makes the artwork in GIMP and exports it as a plate, which is the artwork with its fixed wording and no signature.',
          check: ctx => ctx.route.startsWith('/citations/templates/4'),
          target: ctx => ctx.route === '/citations/templates' ? '[data-proto=tpl-4]' : menuOpen() ? '[data-proto=menu-templates]' : '[data-proto=nav-milpacs]',
        },
        {
          key: 'place-field', title: 'Place a field on the plate',
          scenario: 'Version 2\'s plate is uploaded and most of its fields are placed. The member\'s rank and name line isn\'t.',
          task: 'Open <b>Version 2</b> and choose <b>Add field</b>. Call it <b>member</b>, give it the pattern <b>{rank} {name}</b>, set it in capitals and Tinos Bold, and place it under the word TO: X <b>40</b>, Y <b>486</b>, width <b>1195</b>, height <b>46</b>. Watch the preview as you type.',
          changed: 'Today the wording goes into each design\'s layers by hand for every citation. Now a field is placed once per version and filled in for every citation. You type the box\'s position and size in plate pixels, and the preview shows where it lands. There\'s no dragging in the first version.',
          ask: 'Could you place fields this way without help? What would you want to see while you do it?',
          check: ctx => { const v = Model.version(ctx.S, 4, 2); return v.fields.some(f => f.kind === 'text' && /\{name\}/.test(f.pattern)); },
          target: ctx => {
            if (overlayOpen()) return '[data-proto=field-save]';
            if (ctx.route === '/citations/templates/4/v/2') return '[data-proto=field-add]';
            if (ctx.route === '/citations/templates/4') return '[data-proto=open-version-4-2]';
            return null;
          },
        },
        {
          key: 'signature', title: 'Add a signature',
          scenario: 'Lieutenant Colonel Ansel Marchbanks takes over as Regimental Executive Officer.',
          task: 'Open <b>Signatures</b> (from the Milpacs menu, or the button on Citation templates) and add a signature for the <b>Regimental Executive Officer</b> billet, with the printed lines and an ink image. For this prototype you can use a sample ink.',
          changed: 'Today a new officer means editing every design that carries the signature. Now you add the signature once, and clerks can pick it on the next citation. A signature freezes once a citation uses it, so changed printed lines mean a new signature.',
          ask: 'Who would send S1 the ink image and printed lines after a change of command?',
          check: ctx => ctx.S.signatures.some(x => x.billetId === 2 && x.addedAt === ctx.S.today),
          target: ctx => {
            if (overlayOpen()) return '[data-proto=sig-save]';
            if (ctx.route === '/citations/signatures') return '[data-proto=sig-add]';
            if (ctx.route === '/citations/templates') return '[data-proto=btn-signatures]';
            return menuOpen() ? '[data-proto=menu-signatures]' : '[data-proto=nav-milpacs]';
          },
        },
        {
          key: 'publish', title: 'Publish version 2',
          task: 'Go back to <b>Bronze Star Medal</b> and publish <b>Version 2</b>.',
          changed: 'Publishing freezes the version, so its plate and fields can\'t change after this. Clerks see it on the citation form straight away.',
          ask: 'Who should be allowed to publish? Should anyone look at it first?',
          check: ctx => Model.version(ctx.S, 4, 2).state !== 'draft',
          target: ctx => {
            if (overlayOpen()) return '[data-proto=confirm]';
            if (ctx.route.startsWith('/citations/templates/4')) return '[data-proto=publish-4-2]';
            if (ctx.route === '/citations/templates') return '[data-proto=tpl-4]';
            return menuOpen() ? '[data-proto=menu-templates]' : '[data-proto=nav-milpacs]';
          },
        },
        {
          key: 'retire', title: 'Retire version 1',
          task: 'On <b>Bronze Star Medal</b>, retire <b>Version 1</b>, the landscape design.',
          changed: 'A retired version is no longer offered to clerks. Every citation already issued on it keeps rendering the way it was issued. Nothing moves to the new version unless S1 moves it as a logged correction.',
          ask: 'Would you ever want old citations moved to the new design? Which ones?',
          check: ctx => Model.version(ctx.S, 4, 1).state === 'retired',
          target: ctx => overlayOpen() ? '[data-proto=confirm]' : ctx.route === '/citations/templates/4' ? '[data-proto=retire-4-1]' : null,
        },
        {
          key: 'open-old', title: 'Open a citation issued on the old version',
          task: 'Open <b>Delacroix.M</b> from the Milpacs roster and click <b>Citation</b> on the <b>Bronze Star</b> row, which was issued on version 1. On the forum it opens in a new tab. Here it opens over the page instead.',
          changed: 'Nothing for whoever opens it. It still shows the landscape design it was issued on, with the commander who signed it at the time.',
          check: ctx => { const r = ctx.S.rows.find(x => x.memberId === 102 && x.kind === 'award' && x.typeId === 12); return !!r && openedRow(ctx, r.id); },
          target: ctx => { const r = ctx.S.rows.find(x => x.memberId === 102 && x.kind === 'award' && x.typeId === 12); return profileTarget(ctx, 102, `[data-proto=cite-${r.id}]`); },
        },
        OVERALL('S1 HQ'),
      ],
    },
  };

  function OVERALL() {
    return {
      key: 'overall', title: 'Your overall view', final: true,
      scenario: 'That\'s the whole walkthrough. You can keep clicking around the forum, or go back to any step.',
      task: 'Tell us whether your work stays the same apart from GIMP and the upload box. What would you miss? What would get in your way? Is anything here that you\'d do differently?',
      check: () => true,
    };
  }

  // --- storage ---------------------------------------------------------------------------------------

  function load() {
    try { return JSON.parse(localStorage.getItem(STORE)) || { notes: {} }; } catch (e) { return { notes: {} }; }
  }
  function save() { try { localStorage.setItem(STORE, JSON.stringify(store)); } catch (e) { /* private window: notes last until the tab closes */ } }
  const store = load();

  // --- state -----------------------------------------------------------------------------------------

  let view = 'home', pathId = null, idx = 0, collapsed = false, hints = true, confirmClear = false, notesFrom = 'home';
  const entered = new Set();
  const el = () => document.getElementById('guide');
  const esc2 = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const path = () => PATHS[pathId];
  const step = () => path().steps[idx];
  const noteKey = (p, s) => `${p}:${s}`;
  function ctx() { return { S: App.state, route: App.route(), events: App.events, ui: App.ui }; }
  function done(s) { try { return !!s.check(ctx()); } catch (e) { return false; } }
  function noteCount() { return Object.values(store.notes).filter(n => n.text.trim()).length; }

  function start(pid) {
    pathId = pid; idx = 0; view = 'step'; entered.clear();
    App.reset(pid, '/rosters');
    renderPanel();
  }
  function goStep(i) {
    idx = Math.max(0, Math.min(path().steps.length - 1, i));
    const s = step();
    if (s.onEnter && !entered.has(s.key)) { entered.add(s.key); s.onEnter(); }
    renderPanel();
    el().querySelector('.pg-body').scrollTop = 0;
  }

  // --- rendering -------------------------------------------------------------------------------------

  function homeHtml() {
    return `<div class="pg-intro">
        <h2 class="pg-h">How citations would work on the forum</h2>
        <p>This is a clickable mock-up of the forum after S1 stops making citations in GIMP. It isn't connected to the forum, and everyone in it is made up. Nothing you do here goes anywhere, except the notes you choose to send.</p>
        <p>Pick a walkthrough. Each step says what's going on, what to do, and what changed from today, and has a box for your comments. Anyone can walk any path.</p>
        <p>Your comments stay in this browser. When you're done, the guide gives you all of them to copy into the forum DM thread you got this link from.</p>
        <p class="pg-muted">The certificate design is a stand-in. Comments on fonts and layout are welcome, but they belong to the separate look approval before go-live.</p>
      </div>
      <div class="pg-paths">${Object.entries(PATHS).map(([k, p]) => `<button class="pg-path" data-g="start" data-path="${k}">
        <span class="pg-path-title">${esc2(p.title)}</span><span class="pg-path-sum">${esc2(p.summary)}</span><span class="pg-path-n">${p.steps.length} steps</span></button>`).join('')}</div>
      <div class="pg-foot"><button class="pg-link" data-g="feedback">Your notes (${noteCount()})</button></div>`;
  }

  function stepHtml() {
    const p = path(), s = step(), ok = done(s);
    const n = store.notes[noteKey(pathId, s.key)];
    const task = typeof s.task === 'function' ? s.task(ctx()) : s.task;
    return `<div class="pg-crumb"><button class="pg-link" data-g="home">&larr; All walkthroughs</button><span>${esc2(p.title)}</span></div>
      <div class="pg-progress">${p.steps.map((x, i) => `<button class="pg-dot ${i === idx ? 'is-current' : ''} ${done(x) ? 'is-done' : ''}" data-g="step" data-i="${i}" title="${i + 1}. ${esc2(x.title)}"></button>`).join('')}</div>
      <div class="pg-stepNo">Step ${idx + 1} of ${p.steps.length}</div>
      <h2 class="pg-h">${esc2(s.title)}</h2>
      ${idx === 0 ? `<p class="pg-who">${esc2(p.who)}</p>` : ''}
      ${s.scenario ? `<p class="pg-scenario">${esc2(s.scenario)}</p>` : ''}
      <div class="pg-card pg-task"><div class="pg-cardHead">${s.final ? 'Over to you' : 'What to do'}</div><div>${task}</div>
        ${s.helper ? `<button class="pg-btn pg-btn--quiet" data-g="helper">${esc2(s.helper.label)}</button>` : ''}
        ${s.final ? '' : `<div class="pg-status ${ok ? 'is-done' : ''}">${ok ? '&#10003; Done' : 'Not done yet'}</div>`}</div>
      ${s.changed ? `<div class="pg-card pg-changed"><div class="pg-cardHead">What changed from today</div><div>${esc2(s.changed)}</div></div>` : ''}
      <div class="pg-card pg-fb">
        <div class="pg-cardHead">Your feedback on this step</div>
        ${s.ask ? `<div class="pg-ask">${esc2(s.ask)}</div>` : ''}
        <textarea id="pgNote" rows="${s.final ? 7 : 4}" placeholder="${s.final ? 'Your overall view' : 'Anything that surprised you, is missing, or would get in your way'}">${esc2(n ? n.text : '')}</textarea>
        <div class="pg-fbMeta"><span id="pgSaved" class="pg-saved"></span></div>
      </div>
      <div class="pg-nav">
        <button class="pg-btn" data-g="prev" ${idx === 0 ? 'disabled' : ''}>Back</button>
        ${s.final ? `<button class="pg-btn pg-btn--primary" data-g="feedback">Send your notes</button>` : `<button class="pg-btn ${ok ? 'pg-btn--primary' : ''}" data-g="next">${ok ? 'Next step' : 'Skip to next step'}</button>`}
      </div>
      <div class="pg-foot"><label class="pg-check"><input type="checkbox" id="pgHints" ${hints ? 'checked' : ''}> Point at what to click</label>
        <button class="pg-link" data-g="restart">Start this walkthrough again</button></div>`;
  }

  // BB code, because reviewers paste it into a forum DM.
  function exportText() {
    const written = Object.values(store.notes).filter(n => n.text.trim());
    const lines = ['[B]Citation prototype feedback[/B]', ''];
    for (const [pid, p] of Object.entries(PATHS)) {
      const ns = p.steps.map((s, i) => ({ s, i, n: store.notes[noteKey(pid, s.key)] })).filter(x => x.n && x.n.text.trim());
      if (!ns.length) continue;
      lines.push(`[U][B]${p.title} walkthrough[/B][/U]`, '');
      for (const { s, i, n } of ns) {
        lines.push(`[B]Step ${i + 1}. ${s.title}[/B]`);
        lines.push(n.text.trim(), '');
      }
    }
    if (!written.length) lines.push('(no notes yet)');
    return lines.join('\n').trim();
  }

  function feedbackHtml() {
    const n = noteCount();
    return `<div class="pg-crumb"><button class="pg-link" data-g="home">&larr; All walkthroughs</button>${notesFrom === 'step' ? `<button class="pg-link" data-g="back-step">Back to step ${idx + 1}</button>` : ''}</div>
      <h2 class="pg-h">Send your notes</h2>
      <p>Your notes are saved in this browser only. Copy them, then paste them as a reply in the forum DM thread you got this link from.</p>
      <div class="pg-btns"><button class="pg-btn pg-btn--primary" data-g="copy">Copy all notes</button><span id="pgCopied" class="pg-saved"></span></div>
      <textarea class="pg-export" readonly rows="16">${esc2(exportText())}</textarea>
      <p class="pg-muted">They're written in BB code, so the [B] tags turn into bold once you post them.</p>
      ${n ? `<div class="pg-foot">${confirmClear
        ? `<span>Delete all ${n} note${n === 1 ? '' : 's'}? You can't get them back.</span><span class="pg-btns pg-btns--tight"><button class="pg-btn pg-btn--danger" data-g="clear-yes">Delete</button><button class="pg-btn" data-g="clear-no">Keep them</button></span>`
        : `<button class="pg-link pg-link--danger" data-g="clear">Delete all my notes</button>`}</div>` : ''}`;
  }

  // A note saves 300 ms after the last keystroke. It keeps the step it was typed on, and any
  // pending save runs before the panel re-renders, so "Next step" straight after typing
  // can't drop the note or file it under the next step.
  let pendingNote = null, noteTimer;
  function flushNote() {
    clearTimeout(noteTimer);
    const f = pendingNote;
    pendingNote = null;
    if (f) f();
  }

  function renderPanel() {
    flushNote();
    const g = el();
    g.classList.toggle('is-collapsed', collapsed);
    document.body.classList.toggle('pg-open', !collapsed);
    g.querySelector('.pg-body').innerHTML = view === 'home' ? homeHtml() : view === 'feedback' ? feedbackHtml() : stepHtml();
    const note = g.querySelector('#pgNote');
    if (note) {
      const pid = pathId, i = idx, s = step();
      note.addEventListener('input', () => {
        pendingNote = () => {
          store.notes[noteKey(pid, s.key)] = { path: PATHS[pid].title, step: i + 1, stepTitle: s.title, text: note.value, at: new Date().toISOString() };
          save();
          const sv = g.querySelector('#pgSaved'); if (sv && note.isConnected) sv.textContent = 'Saved in this browser';
        };
        clearTimeout(noteTimer);
        noteTimer = setTimeout(flushNote, 300);
      });
    }
    const h = g.querySelector('#pgHints');
    if (h) h.addEventListener('change', () => { hints = h.checked; refreshHints(); });
    refreshHints();
  }

  function refreshStatus() {
    if (view !== 'step' || !pathId) return;
    const g = el();
    const s = step(), ok = done(s);
    const st = g.querySelector('.pg-status');
    if (st) { st.classList.toggle('is-done', ok); st.innerHTML = ok ? '&#10003; Done' : 'Not done yet'; }
    const nx = g.querySelector('[data-g=next]');
    if (nx) { nx.classList.toggle('pg-btn--primary', ok); nx.textContent = ok ? 'Next step' : 'Skip to next step'; }
    g.querySelectorAll('.pg-dot').forEach((d, i) => d.classList.toggle('is-done', done(path().steps[i])));
    refreshHints();
  }

  function refreshHints() {
    document.querySelectorAll('.pg-hint').forEach(e => e.classList.remove('pg-hint'));
    if (!hints || view !== 'step' || !pathId || collapsed) return;
    const s = step();
    if (!s.target || done(s)) return;
    let sel = null;
    try { sel = s.target(ctx()); } catch (e) { sel = null; }
    if (!sel) return;
    const t = document.querySelector(sel);
    if (t) t.classList.add('pg-hint');
  }

  function onGuideClick(e) {
    const b = e.target.closest('[data-g]');
    if (!b) return;
    const a = b.dataset.g;
    if (a === 'start') {
      return start(b.dataset.path);
    }
    if (a === 'home') { view = 'home'; return renderPanel(); }
    if (a === 'next') return goStep(idx + 1);
    if (a === 'prev') return goStep(idx - 1);
    if (a === 'step') return goStep(+b.dataset.i);
    if (a === 'helper') { step().helper.run(); return refreshStatus(); }
    if (a === 'restart') { const keep = pathId; return start(keep); }
    if (a === 'feedback') { notesFrom = view; view = 'feedback'; confirmClear = false; return renderPanel(); }
    if (a === 'back-step') { view = 'step'; return renderPanel(); }
    if (a === 'toggle') { collapsed = !collapsed; return renderPanel(); }
    if (a === 'copy') {
      // Clipboard writes only work inside the click. Where the browser refuses, select the text
      // so the reviewer can copy it by hand.
      const ta = el().querySelector('.pg-export');
      const say = m => { const c = el().querySelector('#pgCopied'); if (c) c.textContent = m; };
      const byHand = () => { ta.focus(); ta.select(); say(document.execCommand('copy') ? 'Copied' : 'Selected. Press Ctrl+C or ⌘C to copy.'); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(ta.value).then(() => say('Copied'), byHand);
      else byHand();
      return;
    }
    if (a === 'clear') { confirmClear = true; return renderPanel(); }
    if (a === 'clear-no') { confirmClear = false; return renderPanel(); }
    if (a === 'clear-yes') { confirmClear = false; store.notes = {}; save(); return renderPanel(); }
  }

  function boot() {
    const g = document.createElement('aside');
    g.id = 'guide';
    g.className = 'pg';
    g.innerHTML = `<div class="pg-head"><span class="pg-tag">Prototype<span class="pg-tagMore"> guide</span></span><span class="pg-headNote">Not part of the forum</span>
      <button class="pg-toggle" data-g="toggle" title="Hide or show the guide"><span class="pg-toggle-hide">Hide</span><span class="pg-toggle-show">Guide</span></button></div>
      <div class="pg-body"></div>`;
    document.body.appendChild(g);
    g.addEventListener('click', onGuideClick);
    window.addEventListener('pagehide', flushNote);
    App.on(type => {
      if (type === 'reset') return;
      if (['change', 'route', 'render', 'form', 'preview', 'menu', 'overlay', 'overlay-closed', 'open-citation', 'edit-row', 'preview-tab'].includes(type)) {
        requestAnimationFrame(refreshStatus);
      }
    });
    renderPanel();
  }

  return { boot, PATHS, exportText };
})();
