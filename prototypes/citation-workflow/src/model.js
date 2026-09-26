// PROTOTYPE. The citation model as the map decided it, as plain data plus pure functions.
// No DOM in here: the page calls in, nothing calls out. Every function that changes
// something takes a state and returns { state, errors, ... } without touching the input.
//
// Everything below is invented. No member, officer, citation text or record here belongs
// to anyone real; every surname was checked against the forum's user list first.

const Model = (() => {
  const TODAY = '2026-09-26';

  // --- reference data (roster configuration, as NF/Rosters holds it) -------------------

  // The new addon's per-rank list: printed title plus pay grade and abbreviation.
  const RANKS = [
    [1, 'General of the Army', 'O-10', 'GA'], [2, 'General', 'O-10', 'GEN'],
    [3, 'Lieutenant General', 'O-9', 'LTG'], [4, 'Major General', 'O-8', 'MG'],
    [5, 'Brigadier General', 'O-7', 'BG'], [6, 'Colonel', 'O-6', 'COL'],
    [7, 'Lieutenant Colonel', 'O-5', 'LTC'], [8, 'Major', 'O-4', 'MAJ'],
    [9, 'Captain', 'O-3', 'CPT'], [10, 'First Lieutenant', 'O-2', '1LT'],
    [11, 'Second Lieutenant', 'O-1', '2LT'], [26, 'Chief Warrant Officer 5', 'W-5', 'CW5'],
    [27, 'Chief Warrant Officer 4', 'W-4', 'CW4'], [28, 'Chief Warrant Officer 3', 'W-3', 'CW3'],
    [29, 'Chief Warrant Officer 2', 'W-2', 'CW2'], [30, 'Warrant Officer 1', 'W-1', 'WO1'],
    [12, 'Command Sergeant Major', 'E-9', 'CSM'], [13, 'Sergeant Major', 'E-9', 'SGM'],
    [14, 'First Sergeant', 'E-8', '1SG'], [15, 'Master Sergeant', 'E-8', 'MSG'],
    [16, 'Sergeant First Class', 'E-7', 'SFC'], [17, 'Staff Sergeant', 'E-6', 'SSG'],
    [18, 'Sergeant', 'E-5', 'SGT'], [19, 'Corporal', 'E-4', 'CPL'],
    [20, 'Specialist', 'E-4', 'SPC'], [21, 'Private First Class', 'E-3', 'PFC'],
    [22, 'Private', 'E-2', 'PV2'], [23, 'Recruit', 'E-1', 'PVT'],
  ].map(([id, title, payGrade, abbr]) => ({ id, title, payGrade, abbr }));

  // The award list in display order, as the forum's award select shows it.
  const AWARDS = [
    [1, 'James "Krazee" Foster Lifetime Achievement Medal'], [2, 'Ronnie "Coldblud" Bussey Lifetime Achievement Medal'],
    [140, '7th Cavalry Lifetime Dedication Award'], [3, 'Army Distinguished Service Cross'],
    [4, 'Defense Distinguished Service Medal'], [5, 'Army Distinguished Service Medal'], [6, 'Silver Star'],
    [7, 'Defense Superior Service Medal'], [8, 'Legion of Merit'], [9, 'Distinguished Flying Cross'],
    [10, 'Soldiers Medal'], [11, 'Bronze Star with Valor Device'], [12, 'Bronze Star'], [13, 'Purple Heart'],
    [14, 'Defense Meritorious Service Medal'], [15, 'Meritorious Service Medal'], [16, 'Air Medal'],
    [17, 'Joint Service Commendation Medal'], [18, 'Army Commendation Medal with Valor Device'],
    [19, 'Army Commendation Medal'], [20, 'Joint Service Achievement Medal'], [21, 'Army Achievement Medal'],
    [22, 'Prisoner of War Medal'], [23, 'Army Good Conduct Medal'], [26, 'European/African/Middle Eastern Campaign Medal'],
    [27, 'Armed Forces Expeditionary Medal'], [28, 'Iraq Campaign Medal'], [29, 'Afghanistan Campaign Medal'],
    [30, 'National Defense Service Medal'], [31, 'Global War on Terrorism Expeditionary Medal'],
    [32, 'Armed Forces Service Medal'], [33, 'Humanitarian Service Medal'], [34, 'Donation Ribbon'],
    [35, '7th Cavalry Server Upgrade Award'], [36, 'StackUp Donation Medal'], [37, 'Outstanding Volunteer Service Medal'],
    [38, 'NCO Professional Development Ribbon'], [39, 'Honor Graduate Ribbon'], [40, 'Army Service Ribbon'],
    [41, 'Cavalry Centurion Medal'], [42, 'United Nations Service Medal'], [43, 'Overseas Service Ribbon'],
    [44, 'Vietnam Service Ribbon'], [46, 'WWII Service Ribbon'], [45, 'Squad Service Ribbon'],
    [47, 'Hell Let Loose Service Ribbon'], [49, 'Recruiting Ribbon'], [50, 'D-Day Commemorative Medal'],
    [52, 'Ranger Selection Ribbon'], [53, 'Sniper Ribbon'], [54, 'Basic Assault Course Ribbon'],
    [99, '--- Other ---'], [56, 'Master Instructor Badge'], [57, 'Senior Instructor Badge'],
    [58, 'Master Recruiter Badge'], [59, 'Gold Recruiter Badge'], [60, 'Senior Recruiter Badge'],
    [94, '--- Unit Awards ---'], [61, 'Army & Air Force Presidential Unit Citation'],
    [62, 'Joint Meritorious Unit Citation'], [63, 'Army Valorous Unit Citation'], [64, 'Army Meritorious Unit Citation'],
    [65, 'Army Superior Unit Citation'], [98, '--- Skill 1 ---'], [67, 'Expert Infantry Badge'],
    [68, 'Combat Infantry Badge'], [97, '--- Skill 2 ---'], [72, 'Expert Field Medical Badge'],
    [73, 'Combat Field Medical Badge'], [95, '--- Skill 4 ---'], [83, 'Army Parachutist Badge'],
    [86, 'Air Assault Badge'], [89, 'Ranger Tab'], [90, 'Sapper Tab'], [92, 'Cavalry Spurs'],
    [100, 'Rifle Marksman'], [101, 'Rifle Sharpshooter'], [102, 'Rifle Expert'],
  ].map(([id, title]) => ({ id, title }));
  const AWARD_IMAGES = { 12: 'award-12', 15: 'award-15', 19: 'award-19', 21: 'award-21', 39: 'award-39', 40: 'award-40', 61: 'award-61', 67: 'award-67' };
  const PUC = 61; // the one award the new addon's setting lists for regiment grants

  const RECORD_TYPES = [
    [1, 'Promotion'], [3, 'Transfer'], [2, 'Operation'], [4, 'Disciplinary'], [5, 'Discharge'],
    [6, 'Assignment'], [7, 'Name Change'], [8, 'ELOA'], [9, 'Graduation'],
  ].map(([id, title]) => ({ id, title }));
  const DISCIPLINARY = 4, GRADUATION = 9, PROMOTION = 1;

  // --- the people (all invented) ------------------------------------------------------

  const MEMBERS = [
    // Regimental HQ: the three officers whose signatures S1 keeps
    { id: 3, username: 'Castellan.S', fullName: 'Silas Castellan', rankId: 2, group: 'Regimental HQ', position: 'Regimental Commanding Officer', mos: '00A', joined: '2012-02-18', promoted: '2026-08-01' },
    { id: 4, username: 'Drummond.I', fullName: 'Iris Drummond', rankId: 6, group: 'Regimental HQ', position: 'Regimental Executive Officer', mos: '00A', joined: '2015-06-09', promoted: '2025-03-22' },
    { id: 5, username: 'Nakashima.D', fullName: 'Dev Nakashima', rankId: 7, group: 'Regimental HQ', position: 'Regimental Operations Officer', mos: '00A', joined: '2017-10-30', promoted: '2025-11-02' },
    // S1: the three people reviewers play
    { id: 21, username: 'Whitcombe.N', fullName: 'Nora Whitcombe', rankId: 9, group: 'S1 - Personnel', position: 'S1 Officer in Charge', mos: '42B', joined: '2019-01-12', promoted: '2025-07-19' },
    { id: 22, username: 'Ashgrove.P', fullName: 'Priya Ashgrove', rankId: 19, group: 'S1 - Personnel', position: 'S1 Citations Clerk', mos: '42A', joined: '2023-05-03', promoted: '2025-02-14' },
    { id: 23, username: 'Fenwick.T', fullName: 'Theo Fenwick', rankId: 20, group: 'S1 - Personnel', position: 'S1 MILPACS Clerk', mos: '42A', joined: '2024-01-27', promoted: '2025-06-30' },
    // The troopers the walkthroughs touch
    { id: 101, username: 'Quill.E', fullName: 'Elias Quill', rankId: 17, group: 'Alpha Company, 1st Battalion', position: 'Squad Leader, 1st Squad, 2nd Platoon', mos: '11B', joined: '2021-03-14', promoted: '2026-05-02' },
    { id: 105, username: 'Halvorsen.J', fullName: 'Jonas Halvorsen', rankId: 19, group: 'Alpha Company, 1st Battalion', position: 'Team Leader, 1st Squad, 2nd Platoon', mos: '11B', joined: '2024-04-20', promoted: '2026-02-11' },
    { id: 103, username: 'Okafor.T', fullName: 'Tunde Okafor', rankId: 20, group: 'Alpha Company, 1st Battalion', position: 'Rifleman, 1st Squad, 2nd Platoon', mos: '11B', joined: '2025-01-08', promoted: '2025-09-15' },
    { id: 104, username: 'Lindqvist.M', fullName: 'Maja Lindqvist', rankId: 21, group: 'Alpha Company, 1st Battalion', position: 'Automatic Rifleman, 2nd Squad, 2nd Platoon', mos: '11B', joined: '2025-06-02', promoted: '2025-10-04' },
    { id: 107, username: 'Stroud.O', fullName: 'Owen Stroud', rankId: 21, group: 'Alpha Company, 1st Battalion', position: 'Rifleman, 2nd Squad, 2nd Platoon', mos: '11B', joined: '2025-04-17', promoted: '2025-08-30' },
    { id: 102, username: 'Delacroix.M', fullName: 'Mara Delacroix', rankId: 16, group: 'Bravo Company, 1st Battalion', position: 'Platoon Sergeant, 1st Platoon', mos: '11B', joined: '2019-08-24', promoted: '2025-12-06' },
    { id: 106, username: 'Galloway.R', fullName: 'Rory Galloway', rankId: 22, group: 'Basic Combat Training', position: 'Trainee, Class 26-09', mos: '09S', joined: '2026-09-06', promoted: '2026-09-06' },
    { id: 108, username: 'Ellery.S', fullName: 'Sam Ellery', rankId: 22, group: 'Basic Combat Training', position: 'Trainee, Class 26-09', mos: '09S', joined: '2026-09-07', promoted: '2026-09-07' },
    { id: 109, username: 'Tamsworth.K', fullName: 'Kit Tamsworth', rankId: 22, group: 'Basic Combat Training', position: 'Trainee, Class 26-09', mos: '09S', joined: '2026-09-06', promoted: '2026-09-06' },
  ];
  const ROSTER_GROUPS = ['Regimental HQ', 'S1 - Personnel', 'Alpha Company, 1st Battalion', 'Bravo Company, 1st Battalion', 'Basic Combat Training'];

  // Who the reviewer is logged in as on each path.
  const PERSONAS = {
    citations: { memberId: 22, role: 'S1 Citations clerk' },
    milpacs: { memberId: 23, role: 'S1 Milpacs clerk' },
    hq: { memberId: 21, role: 'S1 HQ' },
  };

  // --- citations text used by the seeds --------------------------------------------------

  const MSM_TEXT =
    'Staff Sergeant Elias Quill and Sergeant First Class Mara Delacroix distinguished themselves through ' +
    'exceptionally meritorious service while planning and running the 1st Battalion winter training cycle ' +
    'from January to August 2026. Across thirty-one training events they built every lesson plan, trained ' +
    'eleven new instructors and kept attendance above ninety percent through three game updates. Their ' +
    'preparation, patience and steady leadership raised the readiness of every platoon in the battalion.\n' +
    'Their actions reflect great credit upon themselves, the 1st Battalion and the 7th Cavalry Regiment.';
  // The walkthrough plants a typo in this one for the clerk to correct.
  const MSM_TEXT_WITH_TYPO = MSM_TEXT.replace('7th Cavalry Regiment.', '7th Cavalry Regimnet.');
  const BSM_TEXT =
    'For meritorious achievement while serving as Platoon Sergeant, 1st Platoon, Bravo Company, during ' +
    'Operation Iron Lantern. Sergeant First Class Delacroix kept the platoon supplied, informed and moving ' +
    'through six consecutive nights of contested objectives, and personally led the recovery of two ' +
    'disabled vehicles under fire. That composure set the example for the whole company and reflects great ' +
    'credit upon the platoon and the 7th Cavalry Regiment.';
  const EIB_TEXT =
    'For successfully completing the Expert Infantry Badge course, demonstrating mastery of the critical ' +
    'tasks required of an infantryman, including marksmanship, land navigation, casualty care and fire ' +
    'team movement under evaluation. This achievement reflects great credit upon the recipient and the ' +
    '7th Cavalry Regiment.';

  // --- templates (S1's designs as the template manager holds them) ------------------------
  // Boxes are in plate pixels. Every portrait plate is 1275 x 1554, the landscape BSM is the
  // 640 x 500 design S1 uses today.

  const GIVEN = 'GIVEN UNDER MY HAND ON THIS {day_ordinal} DAY OF {month}, {year} AT FORT HOOD, TEXAS';
  function portraitSig(key, billetId, x, extra = {}) {
    return { key, kind: 'signature', billetId, x, y: 1306, w: 330, h: 180, ...extra };
  }

  const TEMPLATES = [
    {
      id: 1, title: 'Meritorious Service Medal', serves: [{ kind: 'award', id: 15 }],
      inputs: [{ key: 'unit', label: 'Unit line', scope: 'member', required: false }],
      rankSource: 'member',
      versions: [{
        v: 1, state: 'published', plate: 'msm', w: 1275, h: 1554, createdAt: '2026-05-20', publishedAt: '2026-06-01', generic: false,
        fields: [
          { key: 'award', kind: 'text', pattern: 'MERITORIOUS SERVICE MEDAL', x: 40, y: 386, w: 1195, h: 64, font: 'regular', size: 52, minLegible: 30, align: 'center', multiline: false, caps: true, color: '#16161a' },
          { key: 'member', kind: 'text', pattern: '{rank} {name}', x: 40, y: 486, w: 1195, h: 46, font: 'bold', size: 40, minLegible: 26, align: 'center', multiline: false, caps: true, color: '#3a3a3a' },
          { key: 'unit', kind: 'text', pattern: '[{input:unit}]', x: 40, y: 534, w: 1195, h: 32, font: 'regular', size: 24, minLegible: 20, align: 'center', multiline: false, caps: true, color: '#3a3a3a' },
          { key: 'body', kind: 'text', pattern: '{citation_text}', x: 140, y: 588, w: 995, h: 640, font: 'regular', size: 30, minLegible: 23, align: 'justify', multiline: true, caps: false, color: '#16161a' },
          { key: 'given', kind: 'text', pattern: GIVEN, x: 30, y: 1258, w: 1215, h: 30, font: 'regular', size: 24, minLegible: 18, align: 'center', multiline: false, caps: true, color: '#16161a' },
          portraitSig('sig1', 1, 290),
          portraitSig('sig2', 2, 655),
        ],
      }],
    },
    {
      id: 2, title: 'Expert Infantry Badge', serves: [{ kind: 'award', id: 67 }],
      inputs: [], rankSource: 'member',
      versions: [{
        v: 1, state: 'published', plate: 'eib', w: 1275, h: 1554, createdAt: '2026-05-20', publishedAt: '2026-06-01', generic: true,
        genericText: EIB_TEXT,
        // Today's EIB design prints no name, so every holder's certificate is the same.
        fields: [
          { key: 'body', kind: 'text', pattern: '{citation_text}', x: 140, y: 560, w: 995, h: 560, font: 'regular', size: 32, minLegible: 23, align: 'justify', multiline: true, caps: false, color: '#16161a' },
          { key: 'given', kind: 'text', pattern: GIVEN, x: 30, y: 1210, w: 1215, h: 30, font: 'regular', size: 24, minLegible: 18, align: 'center', multiline: false, caps: true, color: '#16161a' },
          portraitSig('sig1', 1, 472, { y: 1290 }),
        ],
      }],
    },
    {
      id: 3, title: 'Basic Combat Training', serves: [{ kind: 'record', id: GRADUATION }],
      inputs: [{ key: 'class', label: 'Class number', scope: 'grant', required: true }],
      rankSource: 'plate',
      versions: [{
        v: 1, state: 'published', plate: 'bct', w: 1275, h: 1554, createdAt: '2026-05-20', publishedAt: '2026-06-01', generic: false,
        genericText: '',
        fields: [
          { key: 'member', kind: 'text', pattern: 'PRIVATE {name}', x: 40, y: 640, w: 1195, h: 56, font: 'bold', size: 48, minLegible: 26, align: 'center', multiline: false, caps: true, color: '#3a3a3a' },
          { key: 'class', kind: 'text', pattern: 'BASIC COMBAT TRAINING, CLASS {input:class}', x: 40, y: 790, w: 1195, h: 44, font: 'regular', size: 36, minLegible: 22, align: 'center', multiline: false, caps: true, color: '#16161a' },
          { key: 'given', kind: 'text', pattern: 'GIVEN ON THIS {day_ordinal} DAY OF {month}, {year}', x: 30, y: 1150, w: 1215, h: 32, font: 'regular', size: 26, minLegible: 18, align: 'center', multiline: false, caps: true, color: '#16161a' },
          portraitSig('sig1', 1, 472, { y: 1270 }),
        ],
      }],
    },
    {
      id: 4, title: 'Bronze Star Medal', serves: [{ kind: 'award', id: 12 }],
      inputs: [], rankSource: 'member',
      versions: [
        {
          v: 1, state: 'published', plate: 'bsm-land', w: 640, h: 500, createdAt: '2026-05-20', publishedAt: '2026-06-01', generic: false,
          // Today's landscape design, the .xcf's own geometry. Its fixed wording is set as literal text.
          fields: [
            { key: 'branch', kind: 'text', pattern: 'THE UNITED STATES ARMY', x: 202, y: 136, w: 235, h: 24, font: 'bold', size: 20, minLegible: 10, align: 'center', multiline: false, caps: true, color: '#16161a' },
            { key: 'preamble', kind: 'text', pattern: 'TO ALL WHO SHALL SEE THESE PRESENTS, GREETINGS;\nTHIS IS TO CERTIFY THAT THE SECRETARY OF THE ARMY HAS AWARDED THE', x: 51, y: 166, w: 539, h: 28, font: 'regular', size: 12, minLegible: 8, align: 'center', multiline: true, caps: true, color: '#16161a', leading: 1.12 },
            { key: 'award', kind: 'text', pattern: 'BRONZE STAR MEDAL', x: 16, y: 194, w: 607, h: 30, font: 'regular', size: 26, minLegible: 14, align: 'center', multiline: false, caps: true, color: '#16161a' },
            { key: 'to', kind: 'text', pattern: 'TO', x: 300, y: 226, w: 40, h: 14, font: 'regular', size: 12, minLegible: 8, align: 'center', multiline: false, caps: true, color: '#16161a' },
            { key: 'member', kind: 'text', pattern: '{rank} {name}', x: 17, y: 243, w: 608, h: 24, font: 'bold', size: 20, minLegible: 12, align: 'center', multiline: false, caps: true, color: '#3a3a3a' },
            { key: 'body', kind: 'text', pattern: '{citation_text}', x: 16, y: 271, w: 607, h: 100, font: 'regular', size: 13, minLegible: 11, align: 'justify', multiline: true, caps: false, color: '#16161a', leading: 1.16 },
            { key: 'given', kind: 'text', pattern: GIVEN, x: 15, y: 375, w: 610, h: 15, font: 'regular', size: 12, minLegible: 9, align: 'center', multiline: false, caps: true, color: '#16161a' },
            { key: 'sig1', kind: 'signature', billetId: 1, x: 236, y: 392, w: 169, h: 92 },
          ],
        },
        {
          v: 2, state: 'draft', plate: 'bsm-port', w: 1275, h: 1554, createdAt: '2026-09-18', publishedAt: null, generic: false,
          // The portrait plate carries its fixed wording as pixels. The rank-and-name line is
          // the one field S1 HQ places in the walkthrough.
          fields: [
            { key: 'award', kind: 'text', pattern: 'BRONZE STAR MEDAL', x: 40, y: 388, w: 1195, h: 62, font: 'regular', size: 52, minLegible: 30, align: 'center', multiline: false, caps: true, color: '#16161a' },
            { key: 'body', kind: 'text', pattern: '{citation_text}', x: 140, y: 570, w: 995, h: 660, font: 'regular', size: 30, minLegible: 23, align: 'justify', multiline: true, caps: false, color: '#16161a' },
            { key: 'given', kind: 'text', pattern: GIVEN, x: 30, y: 1262, w: 1215, h: 30, font: 'regular', size: 24, minLegible: 18, align: 'center', multiline: false, caps: true, color: '#16161a' },
            { key: 'sig1', kind: 'signature', billetId: 1, x: 473, y: 1300, w: 330, h: 180, rule: false },
          ],
        },
      ],
    },
    // The PUCs are regiment grants drawn from their issued images: no fields, no slots.
    { id: 5, title: 'Presidential Unit Citation, 4 July 2011', serves: [{ kind: 'award', id: PUC }], inputs: [], rankSource: 'plate', regiment: true,
      versions: [{ v: 1, state: 'published', plate: 'puc-2011', w: 1275, h: 985, createdAt: '2026-07-01', publishedAt: '2026-07-01', generic: true, genericText: '', fields: [] }] },
    { id: 6, title: 'Presidential Unit Citation, 16 May 2021', serves: [{ kind: 'award', id: PUC }], inputs: [], rankSource: 'plate', regiment: true,
      versions: [{ v: 1, state: 'published', plate: 'puc-2021', w: 1275, h: 985, createdAt: '2026-07-01', publishedAt: '2026-07-01', generic: true, genericText: '', fields: [] }] },
  ];

  const BILLETS = [{ id: 1, title: 'Regimental Commander' }, { id: 2, title: 'Regimental Executive Officer' }];
  const SIGNATURES = [
    { id: 1, billetId: 1, lines: ['General Silas Castellan', 'Regimental Commander', '7th Cavalry Regiment'], ink: 'inkA', state: 'active', addedAt: '2026-08-01' },
    { id: 2, billetId: 1, lines: ['General Hugo Radcliffe', 'Regimental Commander', '7th Cavalry Regiment'], ink: 'inkB', state: 'retired', addedAt: '2026-05-20' },
    { id: 3, billetId: 2, lines: ['Colonel Iris Drummond', 'Regimental Executive Officer', '7th Cavalry Regiment'], ink: 'inkC', state: 'active', addedAt: '2026-05-20' },
    { id: 4, billetId: 2, lines: ['Lieutenant Colonel Dev Nakashima', 'Acting Executive Officer', '7th Cavalry Regiment'], ink: 'inkD', state: 'active', addedAt: '2026-09-02' },
  ];

  // --- seeding ------------------------------------------------------------------------------

  function rankSnapshot(rankId) {
    const r = RANKS.find(x => x.id === rankId);
    return r ? { rankId: r.id, title: r.title, payGrade: r.payGrade, abbr: r.abbr } : null;
  }

  function seed(pathId) {
    const s = {
      today: TODAY,
      seq: 1000,
      personaId: (PERSONAS[pathId] || PERSONAS.citations).memberId,
      ranks: structuredClone(RANKS),
      awards: structuredClone(AWARDS),
      recordTypes: structuredClone(RECORD_TYPES),
      members: structuredClone(MEMBERS),
      billets: structuredClone(BILLETS),
      signatures: structuredClone(SIGNATURES),
      templates: structuredClone(TEMPLATES),
      grants: [],
      rows: [],
    };
    const row = (memberId, kind, typeId, date, details, citation = null) => {
      const r = { id: ++s.seq, memberId, kind, typeId, date, details, citation };
      s.rows.push(r);
      return r;
    };
    const legacy = () => ({ type: 'image' });

    // Regiment grants, and the PUC sync's rows on every milpac.
    const puc2011 = addGrant(s, { kind: 'award', typeId: PUC, date: '2011-07-04', templateId: 5, v: 1, regiment: true, issuedBy: 'maintainer', issuedAt: '2026-07-01' });
    const puc2021 = addGrant(s, { kind: 'award', typeId: PUC, date: '2021-05-16', templateId: 6, v: 1, regiment: true, issuedBy: 'maintainer', issuedAt: '2026-07-01' });
    for (const m of s.members) {
      row(m.id, 'award', PUC, '2021-05-16', '', { type: 'regiment', grantId: puc2021.id });
      row(m.id, 'award', PUC, '2011-07-04', '', { type: 'regiment', grantId: puc2011.id });
    }

    // Enlistment and training history, with the image citations everyone has today.
    for (const m of s.members) {
      if (m.group === 'Basic Combat Training') {
        row(m.id, 'record', 6, m.joined, 'Assigned to Basic Combat Training, Class 26-09');
        continue;
      }
      const cls = m.joined.slice(2, 4) + '-' + m.joined.slice(5, 7);
      row(m.id, 'record', 6, m.joined, `Assigned to Basic Combat Training, Class ${cls}`);
      row(m.id, 'record', GRADUATION, addDays(m.joined, 19), `Graduated Basic Combat Training, Class ${cls}`, legacy());
      row(m.id, 'award', 40, addDays(m.joined, 19), '', legacy());
    }

    const q = 101, d = 102, st = 107;
    row(q, 'record', PROMOTION, '2024-08-09', 'Promoted to Sergeant (E-5)', legacy());
    row(q, 'record', 2, '2025-11-20', 'Completed 212th Combat Mission (Operation Iron Lantern 12)');
    row(q, 'record', PROMOTION, '2026-05-02', 'Promoted to Staff Sergeant (E-6)', legacy());
    row(q, 'award', 21, '2025-12-01', '1st Award', legacy());
    row(d, 'record', PROMOTION, '2025-12-06', 'Promoted to Sergeant First Class (E-7)', legacy());
    row(d, 'record', 2, '2026-06-28', 'Completed 188th Combat Mission (Operation Iron Lantern 20)');
    row(d, 'award', 19, '2024-02-10', '1st Award', legacy());
    row(st, 'record', PROMOTION, '2025-08-30', 'Promoted to Private First Class (E-3)', legacy());
    // A discipline form: the one citation upload that survives cutover.
    row(st, 'record', DISCIPLINARY, '2026-08-02', 'Letter of Reprimand', legacy());
    row(103, 'record', PROMOTION, '2025-09-15', 'Promoted to Specialist (E-4)', legacy());
    row(104, 'record', PROMOTION, '2025-10-04', 'Promoted to Private First Class (E-3)', legacy());
    row(105, 'record', PROMOTION, '2026-02-11', 'Promoted to Corporal (E-4)', legacy());

    // A generated citation from before today, pinned to BSM version 1 and to the commander
    // who has since handed over.
    const bsm = addGrant(s, {
      kind: 'award', typeId: 12, date: '2026-07-03', templateId: 4, v: 1, sigs: { sig1: 2 }, text: BSM_TEXT,
      members: [{ memberId: d }], issuedBy: 'Ashgrove.P', issuedAt: '2026-07-04',
    });
    row(d, 'award', 12, '2026-07-04', 'Operation Iron Lantern', { type: 'grant', grantId: bsm.id, gmId: bsm.members[0].id });

    if (pathId !== 'citations') {
      const msm = addGrant(s, {
        kind: 'award', typeId: 15, date: '2026-09-24', templateId: 1, v: 1, sigs: { sig1: 1, sig2: 3 }, text: MSM_TEXT,
        members: [{ memberId: q, inputs: { unit: 'Alpha Company, 1st Battalion' } }, { memberId: d, inputs: { unit: 'Bravo Company, 1st Battalion' } }],
        issuedBy: 'Ashgrove.P', issuedAt: '2026-09-25',
      });
      addGrant(s, {
        kind: 'award', typeId: 67, date: '2026-09-25', templateId: 2, v: 1, sigs: { sig1: 1 },
        members: [{ memberId: 103 }, { memberId: 104 }, { memberId: 105 }], issuedBy: 'Ashgrove.P', issuedAt: '2026-09-25',
      });
      addGrant(s, {
        kind: 'record', typeId: GRADUATION, date: '2026-09-25', templateId: 3, v: 1, sigs: { sig1: 1 }, inputs: { class: '26-09' },
        members: [{ memberId: 106 }, { memberId: 108 }, { memberId: 109 }], issuedBy: 'Ashgrove.P', issuedAt: '2026-09-25',
      });
      if (pathId === 'hq') {
        row(q, 'award', 15, '2026-09-25', '1st Award', { type: 'grant', grantId: msm.id, gmId: msm.members[0].id });
        row(d, 'award', 15, '2026-09-25', '1st Award', { type: 'grant', grantId: msm.id, gmId: msm.members[1].id });
      }
    }
    return s;
  }

  function addGrant(s, g) {
    const grant = {
      id: ++s.seq, kind: g.kind, typeId: g.typeId, date: g.date, templateId: g.templateId, v: g.v,
      sigs: g.sigs || {}, text: g.text || '', inputs: g.inputs || {}, regiment: !!g.regiment,
      issuedBy: g.issuedBy, issuedAt: g.issuedAt, revision: 1, log: [], members: [],
    };
    for (const m of g.members || []) {
      const mem = s.members.find(x => x.id === m.memberId);
      grant.members.push({
        id: ++s.seq, memberId: mem.id, name: m.name || mem.fullName, rank: m.rank || rankSnapshot(mem.rankId),
        inputs: m.inputs || {}, token: token(s.seq),
      });
    }
    grant.token = token(grant.id * 7);
    grant.log.push({ revision: 1, at: g.issuedAt, by: g.issuedBy, what: 'Issued', reason: '' });
    s.grants.push(grant);
    return grant;
  }

  // --- small helpers ----------------------------------------------------------------------

  function token(n) {
    let x = (n * 2654435761) >>> 0, out = '';
    const abc = 'abcdefghijkmnpqrstuvwxyz23456789';
    for (let i = 0; i < 8; i++) { out += abc[x % abc.length]; x = Math.floor(x / abc.length) + (i + 1) * 977; }
    return out;
  }
  function addDays(iso, n) {
    const d = new Date(iso + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  }
  const clone = s => structuredClone(s);

  // --- selectors ---------------------------------------------------------------------------

  const member = (s, id) => s.members.find(m => m.id === id);
  const memberByUsername = (s, u) => s.members.find(m => m.username.toLowerCase() === String(u).trim().toLowerCase());
  const rank = (s, id) => s.ranks.find(r => r.id === id);
  const award = (s, id) => s.awards.find(a => a.id === id);
  const recordType = (s, id) => s.recordTypes.find(t => t.id === id);
  const template = (s, id) => s.templates.find(t => t.id === id);
  const version = (s, tid, v) => (template(s, tid) || { versions: [] }).versions.find(x => x.v === v);
  const grant = (s, id) => s.grants.find(g => g.id === id);
  const signature = (s, id) => s.signatures.find(x => x.id === id);
  const billet = (s, id) => s.billets.find(b => b.id === id);
  const row = (s, id) => s.rows.find(r => r.id === id);

  function typeTitle(s, kind, typeId) {
    return kind === 'award' ? (award(s, typeId) || {}).title : (recordType(s, typeId) || {}).title;
  }
  function grantMember(s, gmId) {
    for (const g of s.grants) {
      const gm = g.members.find(m => m.id === gmId);
      if (gm) return { grant: g, gm };
    }
    return null;
  }
  function rowsForMember(s, memberId, kind) {
    return s.rows.filter(r => r.memberId === memberId && r.kind === kind)
      .sort((a, b) => b.date.localeCompare(a.date) || b.id - a.id);
  }
  function rowsOnGrantMember(s, gmId) {
    return s.rows.filter(r => r.citation && r.citation.type === 'grant' && r.citation.gmId === gmId);
  }
  function rowLabel(s, r) {
    const t = typeTitle(s, r.kind, r.typeId);
    return r.kind === 'award' ? `${t} award, ${r.date}` : `${t} record, ${r.date}`;
  }
  // The citation picker: every citation issued to this member, newest first. Regiment
  // grants never show here.
  function citationsForMember(s, memberId) {
    const out = [];
    for (const g of s.grants) {
      if (g.regiment) continue;
      for (const gm of g.members) {
        if (gm.memberId === memberId) out.push({ grant: g, gm, rows: rowsOnGrantMember(s, gm.id) });
      }
    }
    return out.sort((a, b) => b.grant.date.localeCompare(a.grant.date) || b.grant.id - a.grant.id);
  }
  function isPucRow(r) { return r.kind === 'award' && r.typeId === PUC; }
  function templatesServing(s, kind, typeId) {
    const out = [];
    for (const t of s.templates) {
      if (t.regiment) continue;
      if (!t.serves.some(x => x.kind === kind && x.id === typeId)) continue;
      for (const v of t.versions) if (v.state === 'published') out.push({ t, v });
    }
    return out;
  }
  function issuableTypes(s) {
    const seen = new Map();
    for (const t of s.templates) {
      if (t.regiment || !t.versions.some(v => v.state === 'published')) continue;
      for (const x of t.serves) seen.set(x.kind + ':' + x.id, x);
    }
    return [...seen.values()];
  }
  function signaturesForBillet(s, billetId, includeRetired) {
    return s.signatures.filter(x => x.billetId === billetId && (includeRetired || x.state === 'active'));
  }
  function signatureUses(s, sigId) {
    return s.grants.filter(g => Object.values(g.sigs).includes(sigId)).length;
  }
  function versionPins(s, tid, v) {
    return s.grants.filter(g => g.templateId === tid && g.v === v).length;
  }
  function slots(ver) { return ver.fields.filter(f => f.kind === 'signature'); }
  function ordinal(n) { const s = ['th', 'st', 'nd', 'rd'], v = n % 100; return n + (s[(v - 20) % 10] || s[v] || s[0]); }
  const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  // The values a pattern can use, for one grant member (or none, for a regiment grant).
  function renderValues(s, g, gm, ver) {
    const [y, mo, d] = (g.date || TODAY).split('-').map(Number);
    const vals = {
      name: gm ? gm.name : '', rank: gm && gm.rank ? gm.rank.title : '',
      pay_grade: gm && gm.rank ? gm.rank.payGrade : '', rank_abbr: gm && gm.rank ? gm.rank.abbr : '',
      day: String(d), day_ordinal: ordinal(d), month: MONTHS[mo - 1], year: String(y),
      citation_text: ver.generic ? (ver.genericText || '') : (g.text || ''),
    };
    const inputs = { ...(g.inputs || {}), ...((gm && gm.inputs) || {}) };
    for (const [k, v] of Object.entries(inputs)) vals['input:' + k] = v;
    return vals;
  }

  // Patterns: literal text plus {placeholders}. A [bracketed] segment prints only when every
  // placeholder inside it has a value.
  function formatPattern(pattern, vals) {
    const fill = t => t.replace(/\{([a-z_:]+)\}/g, (_, k) => vals[k] != null ? vals[k] : '');
    const segOk = t => [...t.matchAll(/\{([a-z_:]+)\}/g)].every(m => String(vals[m[1]] || '').trim() !== '');
    const withSegs = pattern.replace(/\[([^\]]*)\]/g, (_, inner) => segOk(inner) ? inner : '');
    return fill(withSegs).replace(/[ \t]+/g, ' ').replace(/ ?\n ?/g, '\n').trim();
  }

  // --- actions -------------------------------------------------------------------------------

  // Issue a grant from the form's draft. The form always saves, except when a signature slot
  // or a required input is empty, or nobody is on it.
  function issueGrant(state, draft, actor) {
    const s = clone(state);
    const errors = [];
    const ver = draft.templateId && version(s, draft.templateId, draft.v);
    const t = draft.templateId && template(s, draft.templateId);
    if (!draft.kind || !draft.typeId) errors.push('Please choose what is being awarded.');
    if (!ver) errors.push('Please choose a template.');
    if (!draft.date) errors.push('Please enter the date the citation was approved.');
    if (ver) {
      for (const f of slots(ver)) {
        if (!draft.sigs[f.key]) errors.push(`Please choose a signature for the ${billet(s, f.billetId).title} slot.`);
      }
      if (!ver.generic && !String(draft.text || '').trim() && ver.fields.some(f => /\{citation_text\}/.test(f.pattern || ''))) {
        errors.push('Please enter the citation text.');
      }
      for (const inp of t.inputs) {
        if (!inp.required) continue;
        if (inp.scope === 'grant' && !String((draft.inputs || {})[inp.key] || '').trim()) errors.push(`Please fill in ${inp.label}.`);
        if (inp.scope === 'member' && draft.members.some(m => !String((m.inputs || {})[inp.key] || '').trim())) errors.push(`Please fill in ${inp.label} for every member.`);
      }
    }
    if (!draft.members || !draft.members.length) errors.push('Please add at least one member.');
    if (errors.length) return { state, errors };
    const g = addGrant(s, {
      kind: draft.kind, typeId: draft.typeId, date: draft.date, templateId: draft.templateId, v: draft.v,
      sigs: { ...draft.sigs }, text: ver.generic ? '' : cleanText(draft.text), inputs: { ...(draft.inputs || {}) },
      members: draft.members.map(m => ({ memberId: m.memberId, name: m.name.trim(), rank: m.rank, inputs: { ...(m.inputs || {}) } })),
      issuedBy: actor, issuedAt: s.today,
    });
    return { state: s, errors: [], grantId: g.id };
  }
  function cleanText(t) {
    // Plain text: BB code is stripped, paragraph breaks kept.
    return String(t || '').replace(/\[\/?[a-z]+(=[^\]]*)?\]/gi, '').replace(/\r/g, '').replace(/\n{3,}/g, '\n\n').trim();
  }

  // A logged correction: changes the grant, bumps its revision, which changes every render URL.
  function correctGrant(state, grantId, changes, reason, actor) {
    const s = clone(state);
    const g = grant(s, grantId);
    const errors = [];
    if (!String(reason || '').trim()) errors.push('Please give a reason for the correction.');
    const what = [];
    if (changes.text != null && cleanText(changes.text) !== g.text) what.push('Citation text');
    if (changes.date && changes.date !== g.date) what.push('Date');
    if (changes.sigs && JSON.stringify(changes.sigs) !== JSON.stringify(g.sigs)) what.push('Signatures');
    if (changes.inputs && JSON.stringify(changes.inputs) !== JSON.stringify(g.inputs)) what.push('Template inputs');
    if (changes.members) {
      for (const cm of changes.members) {
        const gm = g.members.find(m => m.id === cm.id);
        if (!gm) continue;
        const u = member(s, gm.memberId).username;
        if (cm.name != null && cm.name.trim() !== gm.name) what.push(`Name for ${u}`);
        if (cm.rank && cm.rank.rankId !== gm.rank.rankId) what.push(`Rank for ${u}`);
        if (cm.inputs && JSON.stringify(cm.inputs) !== JSON.stringify(gm.inputs)) what.push(`Inputs for ${u}`);
      }
    }
    if (!what.length) errors.push('Nothing has changed.');
    if (errors.length) return { state, errors };
    if (changes.text != null) g.text = cleanText(changes.text);
    if (changes.date) g.date = changes.date;
    if (changes.sigs) g.sigs = { ...changes.sigs };
    if (changes.inputs) g.inputs = { ...changes.inputs };
    for (const cm of changes.members || []) {
      const gm = g.members.find(m => m.id === cm.id);
      if (!gm) continue;
      if (cm.name != null) gm.name = cm.name.trim();
      if (cm.rank) gm.rank = cm.rank;
      if (cm.inputs) gm.inputs = { ...cm.inputs };
    }
    g.revision += 1;
    g.log.push({ revision: g.revision, at: s.today, by: actor, what: what.join(', '), reason: reason.trim() });
    return { state: s, errors: [] };
  }

  // Saving a roster row from the vendor's award or service-record form. `citation` is what
  // the citation part of the form asked for:
  //   { mode: 'keep' }                  leave the row's citation as it is
  //   { mode: 'pick', gmId }            attach a citation from the picker (replaces an image)
  //   { mode: 'remove' }                take the citation or image off the row
  //   { mode: 'upload', dataUrl }       a file, which only Disciplinary rows still take
  function saveRow(state, input) {
    const s = clone(state);
    const errors = [];
    const existing = input.rowId ? row(s, input.rowId) : null;
    if (existing && isPucRow(existing)) errors.push(PUC_LOCKED);
    if (input.kind === 'award' && input.typeId === PUC) errors.push(PUC_LOCKED);
    if (!input.typeId && input.kind === 'award') errors.push('Please choose an award.');
    if (!input.date) errors.push('Please enter a valid date.');
    const c = input.citation || { mode: 'keep' };
    const isDisc = input.kind === 'record' && input.typeId === DISCIPLINARY;
    if (c.mode === 'upload' && !isDisc) errors.push('Citation images can only be uploaded to Disciplinary records.');
    if (c.mode === 'pick' && isDisc) errors.push('Disciplinary records take a file upload.');
    if (c.mode === 'pick') {
      const found = grantMember(s, c.gmId);
      if (!found || found.gm.memberId !== input.memberId) errors.push('That citation was not issued to this member.');
    }
    if (errors.length) return { state, errors };
    const r = existing || { id: ++s.seq, memberId: input.memberId, citation: null };
    r.kind = input.kind; r.typeId = input.typeId; r.date = input.date; r.details = input.details || '';
    if (c.mode === 'pick') {
      const found = grantMember(s, c.gmId);
      r.citation = { type: 'grant', grantId: found.grant.id, gmId: c.gmId };
    } else if (c.mode === 'remove') {
      r.citation = null;
    } else if (c.mode === 'upload') {
      r.citation = { type: 'image', upload: c.dataUrl || null };
    }
    if (!existing) s.rows.push(r);
    return { state: s, errors: [], rowId: r.id };
  }
  const PUC_LOCKED = 'Presidential Unit Citation rows are managed automatically. Every milpac holds one for each of the ' +
    'regiment\'s Presidential Unit Citations, linked to its certificate, so they can\'t be added, edited or deleted here.';

  // --- template manager ------------------------------------------------------------------------

  function saveField(state, tid, v, field, originalKey) {
    const s = clone(state);
    const ver = version(s, tid, v);
    const errors = [];
    if (ver.state !== 'draft') errors.push('Published versions are frozen. Make a new version to change a field.');
    for (const k of ['x', 'y', 'w', 'h']) {
      if (!Number.isFinite(field[k]) || field[k] < 0) errors.push(`Please enter a number for ${ { x: 'X', y: 'Y', w: 'width', h: 'height' }[k] }.`);
    }
    if (field.x + field.w > ver.w || field.y + field.h > ver.h) errors.push(`The box runs off the plate, which is ${ver.w} × ${ver.h}.`);
    if (field.kind === 'text' && !String(field.pattern || '').trim()) errors.push('Please enter a pattern.');
    if (field.kind === 'signature' && !field.billetId) errors.push('Please choose the signing billet for this slot.');
    const clash = ver.fields.find(f => f.key === field.key && f.key !== originalKey);
    if (clash) field.key = field.key + '_' + (++s.seq);
    if (errors.length) return { state, errors };
    const i = ver.fields.findIndex(f => f.key === originalKey);
    if (i >= 0) ver.fields[i] = field; else ver.fields.push(field);
    return { state: s, errors: [] };
  }
  function deleteField(state, tid, v, key) {
    const s = clone(state);
    const ver = version(s, tid, v);
    if (ver.state !== 'draft') return { state, errors: ['Published versions are frozen.'] };
    ver.fields = ver.fields.filter(f => f.key !== key);
    return { state: s, errors: [] };
  }
  function publishVersion(state, tid, v) {
    const s = clone(state);
    const ver = version(s, tid, v);
    const errors = [];
    if (ver.state !== 'draft') errors.push('Only a draft can be published.');
    if (!ver.fields.some(f => f.kind === 'signature')) errors.push('This version has no signature slot.');
    if (errors.length) return { state, errors };
    ver.state = 'published'; ver.publishedAt = s.today;
    return { state: s, errors: [] };
  }
  function retireVersion(state, tid, v) {
    const s = clone(state);
    const ver = version(s, tid, v);
    if (ver.state !== 'published') return { state, errors: ['Only a published version can be retired.'] };
    ver.state = 'retired';
    return { state: s, errors: [] };
  }
  function addSignature(state, input) {
    const s = clone(state);
    const errors = [];
    if (!input.billetId) errors.push('Please choose a signing billet.');
    if (!String(input.lines[0] || '').trim()) errors.push('Please enter the first printed line, the officer\'s name.');
    if (!input.ink) errors.push('Please choose an ink image.');
    if (errors.length) return { state, errors };
    s.signatures.push({ id: ++s.seq, billetId: input.billetId, lines: input.lines.map(l => l.trim()), ink: input.ink, state: 'active', addedAt: s.today });
    return { state: s, errors: [], signatureId: s.seq };
  }
  function setSignatureState(state, sigId, st) {
    const s = clone(state);
    signature(s, sigId).state = st;
    return { state: s, errors: [] };
  }

  return {
    TODAY, PUC, DISCIPLINARY, GRADUATION, PROMOTION, PERSONAS, ROSTER_GROUPS, AWARD_IMAGES, MONTHS,
    MSM_TEXT, MSM_TEXT_WITH_TYPO, PUC_LOCKED,
    seed, rankSnapshot,
    member, memberByUsername, rank, award, recordType, template, version, grant, signature, billet, row,
    typeTitle, grantMember, rowsForMember, rowsOnGrantMember, rowLabel, citationsForMember, isPucRow,
    templatesServing, issuableTypes, signaturesForBillet, signatureUses, versionPins, slots,
    renderValues, formatPattern, ordinal,
    issueGrant, correctGrant, saveRow, saveField, deleteField, publishVersion, retireVersion,
    addSignature, setSignatureState,
  };
})();
