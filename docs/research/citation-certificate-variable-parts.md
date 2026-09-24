# Citation certificates: the parts that vary, and where each comes from

Research note only. No addon code was written. It answers the question in
[#300](https://github.com/7Cav/cavforo-suite/issues/300). Across every design S1 Citations issues,
which parts of a certificate change from one citation to the next, and where does each part's value
come from today?

Three sources were read on 2026-09-24:

- every design file in the Citations Department's Google Drive under `02 - Templates/`, archives
  included, and the folder `04 - Promotion Images/`;
- 365 citation images from the public forum, sampled per award and per row kind;
- the NF/Rosters vendor code and database in the local dev copy, whose data mirrors production up
  to 2026-08-04.

Drive files are named by folder path and file name. Vendor paths are relative to `app/src/addons/`
in the dev copy, and their line numbers drift with vendor upgrades. This repo is public, so the note
carries no member names, record ids or Drive ids, and quotes no prose from a member's certificate.
`<NAME>`, `<RANK>`, `<n>`, `<MONTH>` and `<YYYY>` stand in for real values. Signatories are named by
billet only.

The words **design file**, **plate**, **template**, **grant**, **grant member**, **row kind**,
**written citation**, **generic citation**, **signature slot** and **S1 Citations clerk** carry the
meanings the citation generator's planning map gives them.

---

## In brief

- **Pay grade.** Yes, it prints. The Enlisted, NCO and Officer promotion designs print it after the
  rank, and the bootcamp and NCO Academy certificates print it inside the name line. The Warrant
  design prints the abbreviation instead, as in `(CW2)`. The NF/Rosters rank table has no pay-grade
  column, but 10,611 of 10,727 Promotion records hold the pay grade in their `details` text.
- **Insignia.** Every promotion design composites one insignia per rank from a set of toggled
  raster layers inside the design file. `04 - Promotion Images/` holds 26 older loose images, medal
  boxes and bare insignia, that no current design uses as a layer.
- **Rank.** Almost every design prints the full rank title in capitals, like `STAFF SERGEANT`. The
  Officer promotion's rank line and a few recipient lines use title case. No design prints an
  abbreviation in the name line.
- **Date line.** There are ten shapes, set per design, and 21 of the 66 current designs print no
  date at all. The clerk types the day, the ordinal suffix and the month by hand, and the files keep
  the resulting typos.
- **Repeat awards.** No current per-member design prints the repeat count from `details`. Counts and
  devices do print on generic images made one per level, such as the CIB's `(SECOND AWARD)` and the
  Good Conduct Medal's knot line, and on several archived designs.
- **Unit citations.** MUC, SUA and AVUA print one member's rank and name today, with the unit named
  only inside the citation text. JMU prints the member plus two unit lines. A 2020 SUA and a 2008 MUC
  export print the unit in the recipient line instead, and the PUC always does.
- **No name.** EIB is confirmed. Four sampled rows share one image with no name, although the design
  file still has a name layer. BACC is not, since its design and both sampled exports print a name. The
  nameless course certificate is the Basic Assault Movement Course. In all, 51 of the 132 awards
  print no recipient on their newest sampled export, the CIB, the Good Conduct Medal, the service
  ribbons and most weapon-qualification badges among them.
- **Beyond the known fields**, certificates vary in pay grade, rank insignia, a rank numeral, unit
  lines, a unit as recipient, award variants picked by layer, tier and count text, repeat counts,
  class numbers and honor-graduate status. Section 6 lists them.

---

## 1. Reading the tables

Each design row gives its fields as four codes, then the parts that vary beyond them, then where
each value comes from.

**Recipient line**

| Code | Printed shape |
|---|---|
| N1 | `<RANK> <NAME>` on one line in capitals, rank as the full title, e.g. `STAFF SERGEANT <NAME>` |
| N2 | the same in title case, `Staff Sergeant <Name>` |
| N3 | `<RANK> (<PAY GRADE>) <NAME>` in capitals, e.g. `PRIVATE (E-2) <NAME>` |
| N4 | `<NAME>` alone in capitals; the rank prints on another line or not at all |
| +U | two more lines under the name, `<COMPANY>, <BATTALION>` and `7TH CAVALRY REGIMENT`, in the name's case |
| N0 | no recipient line |

**Date line**, as the design's text layer holds it. `TH` stands for whatever suffix the clerk types.

| Code | Printed shape |
|---|---|
| D1 | `GIVEN UNDER MY HAND ON THIS <n>TH DAY OF <MONTH>, <YYYY> AT FORT HOOD, TEXAS` |
| D2 | `GIVEN UNDER MY HAND IN THE CITY OF WASHINGTON, D.C.` over `ON THIS THE <n>TH DAY OF <MONTH> <YYYY>`, no comma before the year |
| D3 | `GIVEN UNDER MY HAND AT 7TH CAVALRY HEADQUARTERS` over `ON THIS THE <n>TH DAY OF <MONTH>, <YYYY>` |
| D4 | `GIVEN UNDER MY HAND THIS <n>TH DAY OF <MONTH>, <YYYY>` |
| D5 | `Given on this <n>th day of <Month>, <YYYY>` in blackletter |
| D6 | `On this <n>th day of <Month> <YYYY>` in blackletter |
| D7 | `GIVEN BY MY HAND` over `ON THIS THE <n>TH DAY OF <MONTH> <YYYY>` |
| D8 | `ON THIS THE <n>TH OF <MONTH> <YYYY>`, with no `DAY` |
| D9 | `ON THIS <n>TH DAY OF <MONTH>, <YYYY>` under the plate's `GIVEN UNDER MY HAND IN THE CITY OF WASHINGTON, DC` |
| D0 | no date; the row quotes the fixed closing line |

**Text.** W is a written citation text box the clerk fills per grant. F is fixed text that reads
the same on every certificate from the design, in a text layer or in the plate.

**Signatures.** S1 is one slot, the Commander. S2 is two slots, the Executive Officer on the left and
the Commander on the right. S0 shows none. Every slot is a raster layer holding the ink signature
with the printed name and billet lines under it. Other officers' signatures sit beside it as hidden
layers, so a change of command is a layer toggle.

**Where values come from**

- **typed.** The S1 Citations clerk types it into the design's text layer. The member comes from
  the recommendation ticket, whose roster profile links lead to the milpac's Full name and rank, and
  the date is the approval or post date ([#275 resolution](https://github.com/7Cav/cavforo-suite/issues/275)).
- **pasted.** The clerk pastes the proofread paragraph from the recommendation ticket.
- **toggled.** The clerk shows one prepared layer and hides its siblings.
- **plate.** Fixed in the design.

Name and date layers are set in Sitka Banner or Times New Roman unless section 7 lists the design.

---

## 2. Current designs

Folders are under `02 - Templates/`. Sizes are in bytes. "Modified" is the Drive modifiedTime.

### 1. Course Badges

| Design file | Size, modified | Serves | Fields | Other parts that vary | Values come from |
|---|---|---|---|---|---|
| `EOD-YYMMDD.xcf` | 2,410,404 · 2026-09-14 | award rows Basic, Senior and Master Explosive Ordnance Disposal Badge | N1 · D4 · F · S1 | badge level: raster layers `BASIC EOD BADGE`, `SENIOR EOD` (shown) and ` MASTER EOD BADGE`, each holding the title, badge art and course text | typed: name, rank, date. toggled: level, signature |
| `AVIATOR-YYMMDD.xcf` | 2,360,033 · 2026-04-27 | Army Aviator Badge | N1 · D0 `GIVEN UNDER MY HAND` · F · S1 | course text: text layers `ARMA FIXED-WING AVIATOR`, `ARMA ROTARY-WING AVIATOR` (shown), `SQUAD ROTARY AVIATOR` | typed: name, rank. toggled: course text |
| `SENIORAVIATOR-YYMMDD.xcf` | 0 · 2026-06-08 | Senior Army Aviator Badge | empty file | see the archived copy in section 3 | none |
| `MASTERGUNNER-YYMMDD.xcf` | 2,300,500 · 2025-11-30 | Master Gunner Badge | N1 · D0 `GIVEN UNDER MY HAND` · F · S1 | none | typed: name, rank |
| `EXPERTMEDIC-YYMMDD.xcf` | 2,305,864 · 2025-01-05 | Expert Field Medical Badge | N1 · D4 · F · S1 | none | typed: name, rank, date |
| `DCSAVIATOR-YYMMDD.xcf` | 2,350,991 · 2025-01-05 | Army Aviator Badge, DCS World version | N1 · D0 `GIVEN UNDER MY HAND` · F · S1 | course text: the three AVIATOR layers, the shown one rewritten for DCS | typed: name, rank. toggled: course text |
| `FAC-YYMMDD.xcf` | 2,306,332 · 2025-01-05 | Forward Air Controller Badge | N1 · D0 `GIVEN UNDER MY HAND` · F · S1 | none; no Master FAC variant | typed: name, rank |
| `HALO-YYMMDD.xcf` | 2,288,264 · 2025-01-05 | HALO Freefall Badge | N1 · D4 · F · S1 | none | typed: name, rank, date |
| `COMBATMEDIC-YYMMDD.xcf` | 2,315,135 · 2025-01-05 | Combat Field Medical Badge | N1 · D4 · F · S1 | none | typed: name, rank, date |
| `AIRASSAULT-YYMMDD.xcf` | 2,301,805 · 2025-01-05 | Air Assault Badge | N1 · `GIVEN UNDER MY HAND` over `ON THIS <n>TH DAY OF <MONTH> <YYYY>` · F · S1 | none | typed: name, rank, date |
| `SRPARACHUTIST-YYMMDD.xcf` | 2,316,541 · 2025-01-05 | Army Senior Parachutist Badge | N1 · D4 · F · S1 | none | typed: name, rank, date |
| `MASTERMISSIONCON-YYMMDD.xcf` | 2,418,877 · 2025-01-05 | Master Mission Controller Badge | N1 · D0 `GIVEN UNDER MY HAND` · F · S1 | none | typed: name, rank |
| `FLIGHTMEDIC-YYMMDD.xcf` | 2,288,676 · 2025-01-05 | Flight Medic Badge | N1 · D4 without the comma · F · S1 | none | typed: name, rank, date |
| `AIRCREW-YYMMDD.xcf` | 2,395,592 · 2025-01-05 | Aircraft Crewman, Senior Crewman and Master Crewman Badge | N1 · D4 · F · S1 | badge level: raster `AIRCREW BADGE`, raster `SENIOR AIRCREW BADGE` (shown), text layer `MASTER AIRCREW BADGE` | typed: name, rank, date. toggled: level |
| `SRMISSIONCON-YYMMDD.xcf` | 2,422,205 · 2025-01-05 | Senior Mission Controller Badge | N1 · D0 `GIVEN UNDER MY HAND` · F · S1 | title: `SENIOR` shown, a `MASTER` title layer hidden. A second current copy sits in `3. Individual Achievements`, section 7 | typed: name, rank. toggled: title |
| `MASTERAVIATOR-YYMMDD.xcf` | 2,426,405 · 2025-01-05 | Master Army Aviator Badge | N1, placeholder `RANK FIRSTNAME LASTNAME` · D0 · F · S1 | platform and airframe: raster layers `ACV MASTER ROTARY-WING AVIATOR`, `ACV MASTER FIXED-WING AVIATOR`, `ARMA 3 MASTER ROTARY-WING AVIATOR`, `ARMA 3 MASTER FIXED-WING AVIATOR`, all four hidden | typed: name, rank. toggled: variant |
| `PARACHUTIST-YYMMDD.xcf` | 2,303,731 · 2025-01-05 | Army Parachutist Badge | N1, placeholder · D0 · F · S1 | none | typed: name, rank |
| `MSTRPARACHUTIST-YYMMDD.xcf` | 2,304,092 · 2025-01-05 | Army Master Parachutist Badge | N1, placeholder · D0 · F · S1 | none | typed: name, rank |
| `MSTRHALO-YYMMDD.xcf` | 2,292,091 · 2025-01-05 | Master HALO Freefall Badge | N1, placeholder · D0 · F · S1 | none | typed: name, rank |

### 2. Course Certificates

| Design file | Size, modified | Serves | Fields | Other parts that vary | Values come from |
|---|---|---|---|---|---|
| `NCOA-YYMMDD.xcf` | 2,333,285 · 2025-04-13 | Graduation records for NCO Academy courses, seen on a 2026 export | N1 in the file, N3 in the 2026 export · D0, plate `Given at Fort Hood, Texas` · F · S2 | pay grade in the name line when the clerk adds it | typed: name, rank, pay grade |
| `RANGER-YYMMDD.xcf` | 1,250,795 · 2025-01-05 | award rows Ranger Tab | N1 · D6 · F in plate · S1 | class number: a hidden raster line `<Rank> <Name> Class 005/02/19` | typed: name, rank, date, class |
| `BACC-YYMMDD.xcf` | 1,016,044 · 2019-09-01 | Graduation records for the Basic Armor Crewman Course; exports seen from 2009 and 2011 | N2+U · D8 · F in plate · S0, its `Signatures` group hidden | the 2009 and 2011 exports add a class number such as `Class 08-09` | typed: name, rank, unit, date |

### 3. Individual Achievements

| Design file | Size, modified | Serves | Fields | Other parts that vary | Values come from |
|---|---|---|---|---|---|
| `MSM-YYMMDD.xcf` | 0 · 2026-08-29 | Meritorious Service Medal | empty file | see the archived copy in section 3 | none |
| `PH-YYMMDD.xcf` | 1,597,469 · 2026-07-23 | Purple Heart | N1 · D1 · W · S1 | none | typed: name, rank, date. pasted: text |
| `SRMISSIONCON-YYMMDD.xcf` | 2,420,939 · 2026-03-07 | Senior Mission Controller Badge | N1 · D0 `GIVEN UNDER MY HAND` · F · S1 | as the `1. Course Badges` copy | see section 7 |
| `DDSM-YYMMDD.xcf` | 1,819,725 · 2025-12-05 | Defense Distinguished Service Medal | N1 · D1 · W · S1 | none | typed, pasted |
| `SERVICE TEMPLATE.xcf` | 2,872,806 · 2026-04-27 | Battlefield 6 Service Ribbon; the Foxhole ribbon export has the same layout | N0 · no closing line · F `DIRECT PARTICIPATION IN 5 COMBAT OPERATIONS IN THE BATTLEFIELD 6 AREA OF OPERATIONS` · S1 | game name, ribbon image and operation count, edited once per ribbon | plate |
| `7CAVLDA-YYMMDD.xcf` | 3,102,337 · 2025-06-27 | 7th Cavalry Lifetime Dedication Award | N1 under `has been awarded to` · D4 · W · S1 | none | typed, pasted |
| `SNIPER-YYMMDD.xcf` | 1,573,497 · 2025-04-25 | Sniper Ribbon, titled `SNIPER MEDAL` | N1 · D1 · F · S1 | none | typed: name, rank, date |
| `DSM-YYMMDD.xcf` | 1,031,874 · 2025-04-13 | Army Distinguished Service Medal | N1 · D2 · W · S2 | none | typed, pasted |
| `DSC-YYMMDD.xcf` | 2,425,691 · 2025-04-13 | Army Distinguished Service Cross | N1 · D2 · W · S2 | three older signatures and a Chief of Staff layer hidden | typed, pasted |
| `AFEM-YYMMDD.xcf` | 1,036,421 · 2025-04-13 | Armed Forces Expeditionary Medal | N2+U · D2 · F · S2 | unit lines | typed: name, rank, unit, date |
| `AFGHAN-YYMMDD.xcf` | 2,989,363 · 2025-04-13 | Afghanistan Campaign Medal | N1 · D0 `GIVEN BY MY HAND AT FORT HOOD, TEXAS` · F · S2 | a hidden title layer reads `CAVALRY CENTURION MEDAL` | typed: name, rank |
| `DSSM-YYMMDD.xcf` | 1,669,576 · 2025-04-13 | Defense Superior Service Medal | N1 · `GIVEN UNDER MY HAND, THIS <n>TH DAY OF <MONTH>, <YYYY>` · W · S2 | none | typed, pasted |
| `JSAM-YYMMDD.xcf` | 1,725,374 · 2025-04-13 | Joint Service Achievement Medal | N1, then a `Unit` layer `<UNIT> / 7th Cavalry Regiment` in title case · D1 · W · S2 | unit lines | typed: name, rank, unit, date. pasted: text |
| `NCOA-HG-YYMMDD.xcf` | 2,827,084 · 2025-04-13 | NCO Academy honor graduates, seen on a 2023 Graduation record | N3 · D0, no closing line · F · S2 | none | typed: name, rank, pay grade |
| `SS-YYMMDD.xcf` | 1,067,084 · 2025-04-13 | Silver Star | N1 · D2 opening `GIVEN BY MY HAND` · W · S2 | none | typed, pasted |
| `ARCOM-YYMMDD.xcf` | 1,643,765 · 2025-02-19 | Army Commendation Medal, and with Valor Device | N1 · D1 · W · S1 | title: text layers `ARMY COMMENDATION MEDAL` (shown) and `ARMY COMMENDATION MEDAL WITH VALOR DEVICE` | typed, pasted. toggled: title |
| `NDSM-YYMMDD.xcf` | 1,598,266 · 2025-01-05 | National Defense Service Medal | N1 · D0 `GIVEN UNDER MY HAND  AT FORT HOOD, TEXAS` · F · S1 | none | typed: name, rank |
| `BSM-YYMMDD.xcf` | 2,385,819 · 2025-01-05 | Bronze Star, and with Valor Device | N1 · D1 · W · S1 | title: raster layers `BRONZE STAR VALOR` (shown) and `BRONZE STAR` | typed, pasted. toggled: title |
| `DMSM-YYMMDD.xcf` | 1,686,886 · 2025-01-05 | Defense Meritorious Service Medal | N1 · D1 · W · S1 | none | typed, pasted |
| `LoM-YYMMDD.xcf` | 1,628,826 · 2025-01-05 | Legion of Merit | N1 · D1 · W · S1 | none | typed, pasted |
| `DFC-YYMMDD.xcf` | 1,656,286 · 2025-01-05 | Distinguished Flying Cross | N1 · D1 · W · S1 | none | typed, pasted |
| `OVSM-YYMMDD.xcf` | 1,655,683 · 2025-01-05 | Outstanding Volunteer Service Medal | N1 · D1 without the comma · W · S1 | none | typed, pasted |
| `JSCM-YYMMDD.xcf` | 706,848 · 2025-01-05 | Joint Service Commendation Medal | N1 · D2 · W · S1 | none | typed, pasted |
| `SERVERUP-YYMMDD.xcf` | 3,268,012 · 2025-01-05 | 7th Cavalry Server Upgrade Award | N1 · D4 · F · S1 | attachment: groups `Server with Silver star` (shown) and `Server with Gold star`, each with its own ribbon image, a `With Silver Star Attachment` or `With Gold Star Attachment` line and its own text; a hidden `Normal Medal text` for the plain award | typed: name, rank, date. toggled: attachment |
| `PW-YYMMDD.xcf` | 1,566,209 · 2025-01-05 | Prisoner of War Medal | N1, placeholder `FIRSTNAME LASTNAME` · D1, placeholder `XXTH DAY OF MONTH, 2018` · F · S1 | none | typed: name, rank, date |
| `STACKUP-YYMMDD.xcf` | 3,507,167 · 2025-01-05 | StackUp Donation Medal | N1 · D4 · F · S1 | donation tier: groups `20-49USD`, `50-99USD` (shown), `100-199USD`, `200+USD`, each with a knot ribbon image, a title such as `STACKUP DONATION MEDAL / with Bronze Knot` and the amount band; the text names the 2019 drive | typed: name, rank, date. toggled: tier |
| `GWOTEM-YYMMDD.xcf` | 1,651,454 · 2025-01-05 | Global War on Terrorism Expeditionary Medal | N1 · D0 `GIVEN UNDER MY HAND AT FORT HOOD, TEXAS` · F · S1 | none | typed: name, rank |
| `IRAQ-YYMMDD.xcf` | 1,836,341 · 2025-01-05 | Iraq Campaign Medal | N1 · D0 `GIVEN UNDER MY HAND AT FORT HOOD, TEXAS` · F · S1 | none | typed: name, rank |
| `SOLDIER-YYMMDD.xcf` | 1,594,461 · 2025-01-05 | Soldiers Medal | N1 · D1 · W · S1 | none | typed, pasted |
| `HSM-YYMMDD.xcf` | 1,600,796 · 2025-01-05 | Humanitarian Service Medal | N2 · D1 · W · S1 | none | typed, pasted |
| `MASTERMISSIONCON-YYMMDD.xcf` | 2,412,251 · 2024-04-18 | Master Mission Controller Badge | byte-identical to the `1. Course Badges/archive/` copy | section 7 | none |
| `AAM-YYMMDD.xcf` | 1,697,366 · 2024-03-06 | Army Achievement Medal | N1 · D1 · W · S1 | none | typed, pasted |
| `COMBATMEDIC-YYMMDD.xcf` | 2,308,872 · 2020-09-02 | Combat Field Medical Badge | byte-identical to the `1. Course Badges/archive/` copy | section 7 | none |
| `AM-YYMMDD.xcf` | 1,029,134 · 2020-03-17 | Air Medal | N1 · D2 · W · S2, both slots as plain raster layers | none | typed, pasted |
| `EIB-YYMMDD.xcf` | 3,402,365 · 2018-07-19 | Expert Infantry Badge | N4, a name with no rank · D0 `Given under my hand at Fort Hood, Texas` · F · S1 | none; the exports print no name at all, section 5 | plate; the name layer goes unused |
| `ASR-YYMMDD.xcf` | 999,240 · 2017-02-25 | Army Service Ribbon; exports have used BOOTCAMP since | N2 · `GIVEN AT THE 7TH CAVALRY TRAINING CENTER` over `ON THIS THE <n>TH DAY OF <MONTH> <YYYY>` · F · S1 | none | typed: name, rank, date |

### 4. Promotions

| Design file | Size, modified | Serves | Fields | Other parts that vary | Values come from |
|---|---|---|---|---|---|
| `BOOTCAMP-YYMMDD.xcf` | 2,232,351 · 2025-11-06 | records `Graduated Boot Camp, Promoted to Private (E-2)` and its honor version, filed as Graduation or Promotion; the same image also sits on Army Service Ribbon and Honor Graduate Ribbon award rows | N3 · D0, plate `Given at Fort Hood, Texas` · F · S2 | honor graduate: layers `BASIC GRADUATE` (shown) and `HONOR GRADUATE`; the honor layer adds `WITH HONORS` and the Honor Graduate Ribbon, and the name line becomes `PRIVATE FIRST CLASS (E-3) <NAME>` | typed: name, rank, pay grade. toggled: honor |
| `Enlisted Promotion .xcf` | 1,031,200 · 2025-01-05 | Promotion records to PFC, SPC and CPL | N4 · D2 with a comma and two spaces, `ON THIS THE  <n>TH DAY OF <MONTH>, <YYYY>` · F · S1 | new rank: raster layers `PFC`, `SPC`, `CPL` in group `RANKS`, each holding the insignia and a baked line `PRIVATE FIRST CLASS E-3`, `SPECIALIST E-4` or `CORPORAL E-4` | typed: name, date. toggled: rank, pay grade and insignia together |
| `NCO Promotion.xcf` | 981,380 · 2025-01-05 | Promotion records to SGT through CSM | N4 · D3 · F · S1 | new rank: text layer `RANK (TEXT)`, e.g. `SERGEANT (E-5)`, plus insignia layers `SGT (E-5)` to `CSM (E-9)` and `WOC (DISCONTINUED)` in `RANKS (PICTURE)` | typed: name, rank with pay grade, date. toggled: insignia |
| `Officer Promotion.xcf` | 1,141,209 · 2025-01-05 | Promotion records to 2LT through General of the Army | N4 in bold · D3 · F · S1 | new rank: `RANK (TEXT)` in title case, e.g. `Second Lieutenant (O-1)`, plus insignia layers `2LT` to `GEN` and `GOA` | typed: name, rank with pay grade, date. toggled: insignia |
| `Warrant Promotion.xcf` | 1,058,404 · 2025-01-05 | Promotion records to WOC, WO1 and CW2 to CW5 | N4 · D5 · F in plate · S1 | new rank: raster layers `WOC`, `WO1`, `CW2` to `CW5` in `RANKS`, each holding the insignia and a baked line such as `WARRANT OFFICER (WO1)`, `CHIEF WARRANT OFFICER 2 (CW2)` or `WARRANT OFFICER CANDIDATE` | typed: name, date. toggled: rank and insignia together |
| `NCO-RNK-YYMMDD.xcf` | 1,544,908 · 2022-01-02 | award rows NCO Professional Development Ribbon | N1 · D9 · F in plate · S2 | ribbon numeral: hidden layers `NUMERAL 1 - SSG` to `NUMERAL 6 - CSM`, one per rank from SSG up; none for SGT | typed: name, rank, date. toggled: numeral |

`1 - File Naming Help.txt` (1,077 bytes, 2020-01-23) sits in the same folder. It is the rank-to-code
table behind export names like `E4-CPL-240331.png`, section 5.

### 5. Qualification Badges

No current design file. `NOW GENERIC CITATIONS.txt` (110 bytes, 2020-03-14) says every design in the
folder became a generic citation. The seven old designs are in section 3.

### 6. Unit Achievements

| Design file | Size, modified | Serves | Fields | Other parts that vary | Values come from |
|---|---|---|---|---|---|
| `MUC-YYMMDD.xcf` | 2,990,915 · 2025-01-05 | Army Meritorious Unit Citation | N1 for one member, layer `TROOPER NAME` · D4 · W · S1 | the unit appears only inside the text | typed, pasted |
| `SUA-YYMMDD.xcf` | 2,997,013 · 2025-01-05 | Army Superior Unit Citation | N1 for one member · D4 · W · S1 | a hidden `AVUA` ribbon layer | typed, pasted |
| `AVUA-YYMMDD.xcf` | 3,013,745 · 2025-01-05 | Army Valorous Unit Citation | N1 for one member · D4 · W · S1 | none | typed, pasted |
| `JMU-YYMMDD.xcf` | 1,045,767 · 2025-01-05 | Joint Meritorious Unit Citation | N1+U · D7 · W · S1 | unit lines. The file's text layer holds boilerplate ending in an operation name and date; the three 2025 and 2026 exports sampled carry written paragraphs | typed: name, rank, unit, date. pasted: text |

### 7. Citation Clerk Training

Three shortcuts to Google Docs training documents. No design files. Not opened.

---

## 3. Archived and superseded designs

Every row here is archived. "As current" means the same layers and fields as the current design of
the same name. Copies marked "not downloaded" share name, size and modifiedTime with a copy that was
parsed.

| Archived file | Folder under `02 - Templates/` | Size, modified | Serves | Fields and how it differs |
|---|---|---|---|---|
| `MASTERGUNNER-YYMMDD.xcf` | `1. Course Badges/archive` | 2,300,500 · 2025-11-30 | Master Gunner Badge | byte-identical to current |
| `EOD-YYMMDD.xcf` | `1. Course Badges/archive` | 2,417,668 · 2025-08-05 | EOD badges | as current; variant rasters ` MASTER EOD BADGE` and `SENIOR EOD - ARMA 3` hidden |
| `AIRASSAULT-YYMMDD.xcf` | `1. Course Badges/archive` | 2,295,542 · 2024-10-06 | Air Assault Badge | as current |
| `SRMISSIONCON-YYMMDD.xcf` | `1. Course Badges/archive` | 2,415,556 · 2024-08-04 | Senior Mission Controller Badge | as the `1. Course Badges` copy |
| `MASTERMISSIONCON-YYMMDD.xcf` | `1. Course Badges/archive` | 2,412,251 · 2024-04-18 | Master Mission Controller Badge | byte-identical to the `3. Individual Achievements` copy |
| `FAC-YYMMDD.xcf` | `1. Course Badges/archive` | 2,299,766 · 2023-07-08 | Forward Air Controller Badge | as current |
| `DCSAVIATOR-YYMMDD.xcf` | `1. Course Badges/archive` | 2,343,730 · 2023-06-04 | Army Aviator Badge | as current |
| `AVIATOR-YYMMDD.xcf` | `1. Course Badges/archive` | 2,350,180 · 2022-11-22 | Army Aviator Badge | as current, fixed-wing text shown |
| `EXPERTMEDIC-YYMMDD.xcf` | `1. Course Badges/archive` | 2,299,606 · 2020-11-08 | Expert Field Medical Badge | as current |
| `COMBATMEDIC-YYMMDD.xcf` | `1. Course Badges/archive` | 2,308,872 · 2020-09-02 | Combat Field Medical Badge | as current |
| `SENIORAVIATOR-YYMMDD.xcf` | `1. Course Badges/archive` | 2,403,130 · 2020-08-16 | Senior Army Aviator Badge | the only non-empty Senior Aviator design. N1 · D4 · F · S1; variant rasters `ACV SENIOR ROTARY-WING AVIATOR`, `ACV SENIOR FIXED-WING AVIATOR`, `ARMA 3 SENIOR ROTARY-WING AVIATOR` hidden |
| `CAVSCOUT-YYMMDD.xcf` | `1. Course Badges/archive` | 2,795,367 · 2020-03-30 | Cavalry Spurs | N1 under `are presented to` · `Given under my hand on this <n>th Day of <Month>, <YYYY>` in blackletter · F |
| `HALO-YYMMDD.xcf` | `1. Course Badges/archive` | 2,282,085 · 2020-02-12 | HALO Freefall Badge | as current |
| `SRPARACHUTIST-YYMMDD.xcf` | `1. Course Badges/archive` | 2,310,367 · 2019-10-26 | Army Senior Parachutist Badge | as current |
| `AIRCREW-YYMMDD.xcf` | `1. Course Badges/archive` | 2,389,026 · 2019-10-03 | aircrew badges | as current |
| `FLIGHTMEDIC-YYMMDD.xcf` | `1. Course Badges/archive` | 2,282,534 · 2019-09-22 | Flight Medic Badge | as current |
| `SENIOREOD-YYMMDD.xcf` | `1. Course Badges/archive` | 2,318,661 · 2019-09-01 | Senior EOD Badge | a single-level design; the current EOD file holds all three levels. N1 · D4 · S1 |
| `MASTERAVIATOR-YYMMDD.xcf` | `1. Course Badges/archive` | 2,473,523 · 2018-07-06 | Master Army Aviator Badge | as current |
| `MASTEREOD-YYMMDD.xcf` | `1. Course Badges/archive` | 2,348,621 · 2018-07-06 | Master EOD Badge | placeholder name · D0 `GIVEN UNDER MY HAND` |
| `PARACHUTIST-YYMMDD.xcf` | `1. Course Badges/archive` | 2,341,718 · 2018-07-06 | Army Parachutist Badge | as current |
| `MSTRHALO-YYMMDD.xcf` | `1. Course Badges/archive` | 2,329,996 · 2018-07-06 | Master HALO Badge | as current |
| `MSTRPARACHUTIST-YYMMDD.xcf` | `1. Course Badges/archive` | 2,342,507 · 2018-07-06 | Army Master Parachutist Badge | as current |
| `PATHFINDER-YYMMDD.xcf` | `1. Course Badges/archive` | 2,821,271 · 2018-07-03 | Pathfinder Badge, which has no citations on the roster | placeholder name under `is presented to` · `given under my hand` in blackletter, no date · F |
| `BESTRANGER-YYMMDD.xcf` | `1. Course Badges/archive` | 1,031,286 · 2017-08-02 | Best Ranger | N2 · `ON THIS THE <n>ST DAY OF <MONTH> <YYYY>` · F |
| `BAMC-YYMMDD.xcf` | `2. Course Certificates/archive` | 785,964 · 2026-04-27 | Basic Assault Course Ribbon | `TROOPER`, `DATE` and three platform text layers `CITATION ARMA`, `CITATION HLL`, `CITATION SQUAD` all hidden, so it prints no name, no date and fixed text. Matches the 2026 exports, section 5 |
| `NCOA-YYMMDD.xcf` | `2. Course Certificates/archive` | 2,369,239 · 2018-08-23 | NCO Academy | N1 · F · no date layer |
| `RANGER-YYMMDD.xcf` | `2. Course Certificates/archive` | 1,244,723 · 2020-03-19 | Ranger Tab | as current, with the hidden class line |
| `LRRP-YYMMDD.xcf` | `2. Course Certificates/archive` | 1,135,483 · 2018-06-14 | LRRP Tab | N2 · `GIVEN AT THE 7TH CAVALRY TRAINING GROUNDS` over `ON THIS THE <n>ND DAY OF <MONTH> <YYYY>` · F; a hidden S3 officer-in-charge signature |
| `SQUADSERVICE-YYMMDD.xcf` | `3. Individual Achievements/archive` | 2,948,217 · 2026-04-27 | Squad Service Ribbon | N1 · fixed `GIVEN THIS DAY UNDER MY HAND`, no date · F |
| `DDSM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,819,725 · 2025-12-05 | DDSM | byte-identical to current |
| `SNIPER-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,573,497 · 2025-04-25 | Sniper Ribbon | byte-identical to current |
| `ARCOM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,639,489 · 2025-01-05 | ARCOM | as current |
| `BSM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 2,379,369 · 2024-12-05 | Bronze Star | as current, valor title shown |
| `DSC-YYMMDD.xcf` | `3. Individual Achievements/archive` | 2,415,079 · 2024-11-30 | DSC | as current |
| `OVSM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,651,544 · 2024-08-25 | OVSM | as current |
| `MSM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 824,694 · 2024-07-16 | Meritorious Service Medal | the last non-empty MSM design. N1 · D2 · W · S2 |
| `Blank-HSM.jpg` | `3. Individual Achievements/archive` | 197,509 · 2022-09-28 | HSM | a flattened image, not a design file; not downloaded |
| `DSM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,024,294 · 2021-12-10 | DSM | as current |
| `DONATION-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,835,539 · 2021-09-23 | Donation Ribbon | N1 · a visible count layer `37TH AWARD` · `GIVEN UNDER MY HAND AT FORT HOOD, TEXAS THIS <n>TH DAY OF <MONTH>, <YYYY>` · F; hidden ribbon rasters `AFSM` and `GCM-RIBBON` |
| `NDSM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,594,185 · 2021-06-17 | NDSM | as current |
| `JSAM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,714,317 · 2021-04-27 | JSAM | as current, `Unit` layer included |
| `DMSM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,682,794 · 2020-10-05 | DMSM | as current |
| `PH-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,607,576 · 2020-08-16 | Purple Heart | as current |
| `CENT-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,638,250 · 2020-07-01 | Cavalry Centurion Medal | N1 · no date layer; title layer hidden |
| `NCOA-HG-YYMMDD.xcf` | `3. Individual Achievements/archive` | 2,814,785 · 2020-03-23 | NCO Academy honor graduates | as current, N3 |
| `LoM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,628,159 · 2020-03-21 | Legion of Merit | as current |
| `DFC-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,655,598 · 2020-03-17 | DFC | as current |
| `SS-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,065,643 · 2020-03-17 | Silver Star | as current |
| `SERVERUP-YYMMDD.xcf` | `3. Individual Achievements/archive` | 3,263,371 · 2020-02-21 | Server Upgrade Award | as current |
| `SOLDIER-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,593,606 · 2020-02-18 | Soldiers Medal | as current |
| `HLLSERVICE-YYMMDD.xcf` | `3. Individual Achievements/archive` | 2,971,234 · 2020-02-15 | Hell Let Loose Service Ribbon | N1 · fixed `GIVEN THIS DAY UNDER MY HAND` · F with a 5-operation threshold |
| `STACKUP-YYMMDD.xcf` | `3. Individual Achievements/archive` | 3,498,585 · 2020-01-18 | StackUp Donation Medal | as current |
| `AFGHAN-YYMMDD.xcf` | `3. Individual Achievements/archive` | 3,083,740 · 2019-10-14 | Afghanistan Campaign Medal | as current |
| `GWOTEM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,692,146 · 2019-10-12 | GWOTEM | as current |
| `HSM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,645,933 · 2019-10-11 | HSM | as current |
| `IRAQ-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,879,101 · 2019-10-10 | Iraq Campaign Medal | as current |
| `JSCM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 703,462 · 2019-08-18 | JSCM | as current |
| `ARMA3SERVICE-YYMMDD.xcf` | `3. Individual Achievements/archive` | 2,961,392 · 2019-07-13 | Overseas Service Ribbon | N1 · fixed `GIVEN THIS DAY UNDER MY HAND` · F |
| `WW2SERVICE-YYMMDD.xcf` | `3. Individual Achievements/archive` | 2,971,554 · 2019-05-10 | WWII Service Ribbon | N1 · fixed closing line · F with a 5-operation threshold |
| `AFEM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,045,925 · 2019-04-14 | AFEM | as current |
| `RECRUITING-YYMMDD.xcf` | `3. Individual Achievements/archive` | 3,039,362 · 2019-03-12 | Recruiting Ribbon | N1 · D4 · F |
| `SAPPER-YYMMDD.xcf` | `3. Individual Achievements/archive` | 3,059,662 · 2019-03-04 | Sapper Tab | N1 · D4 · F |
| `DSSM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,737,623 · 2018-12-30 | DSSM | as current |
| `PW-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,606,569 · 2018-10-28 | POW Medal | as current |
| `GCM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,879,960 · 2018-07-19 | Army Good Conduct Medal | N1; everything else in plate pixels; no date layer |
| `REENLIST-YYMMDD.xcf` | `3. Individual Achievements/archive` | 844,009 · 2018-07-17 | Reenlistment Ribbon | N1 · `<Month> <n>th, <YYYY>` in mixed case |
| `UNMEDAL-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,027,528 · 2017-10-27 | United Nations Service Medal | N2 over `7th Cavalry Regiment` · `GIVEN ON THIS THE <n>TH DAY OF <MONTH> <YYYY>` · F naming a campaign |
| `COA-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,056,535 · 2017-08-22 | no award title in the file | N2 · D2 opening `GIVEN BY MY HAND` · placeholder text |
| `ADM-YYMMDD.xcf` | `3. Individual Achievements/archive` | 1,502,886 · 2017-02-12 | American Defense Medal | a layer named `Name / Award Repeition` holds `<RANK> <NAME>` over `2ND AWARD` · fixed `GIVEN BY MY HAND AT 7TH CAVALRY HEADQUARTERS` over typed `ON THIS THE <n>TH DAY OF <MONTH> <YYYY>` · F |
| `BOOTCAMP-YYMMDD.xcf`, two copies | `4. Promotions/archive` | 2,220,249 · 2025-02-11 and 2025-02-12 | boot camp | byte-identical to each other; same layers as current |
| `Officer Promotion.xcf` | `4. Promotions/archive` | 1,140,192 · 2022-01-02 | Promotion | as current |
| `NCO Promotion.xcf` | `4. Promotions/archive` | 980,375 · 2022-01-02 | Promotion | as current |
| `Enlisted Promotion.xcf` | `4. Promotions/archive` | 1,029,399 · 2022-01-02 | Promotion | as current |
| `Warrant Promotion.xcf` | `4. Promotions/archive` | 1,058,443 · 2022-01-02 | Promotion | as current |
| `RIFLEQUAL-YYMMDD.xcf` | `5. Qualification Badges/archive` | 1,099,046 · 2019-11-26 | rifle qualification badges | N1 · `ON THIS THE <n>TH DAY OF <MONTH> <YYYY>` · F; groups `PROFICIENCY` (`EXPERT`, `SHARPSHOOTER`) and `WEAPONS` (`M16A3`) |
| `RIFLEQUAL-YYMMDD_2.xcf` | `5. Qualification Badges/archive` | 1,213,499 · 2018-06-25 | rifle qualification badges | N2 · same date shape; weapons `M16A3`, `MX Rifle`; proficiency adds `MARKSMAN` |
| `GRENADEQUAL-YYMMDD.xcf` | `5. Qualification Badges/archive` | 1,127,163 · 2019-11-01 | grenade badges | N1 · same date shape; a weapon text line `USING THE M-67 FRAGMENTATION HAND GRENADE` |
| `MGQUAL-YYMMDD.xcf` | `5. Qualification Badges/archive` | 1,130,686 · 2019-11-26 | machine gun badges | N1 · same date shape; weapon line for the M249 |
| `MAAWSQUAL-YYMMDD.xcf` | `5. Qualification Badges/archive` | 1,090,494 · 2019-11-26 | recoilless rifle badges | N1 · same date shape; weapon raster `M72 LAW` |
| `PISTOLQUAL-YYMMDD.xcf` | `5. Qualification Badges/archive` | 1,100,948 · 2019-11-01 | pistol badges | N1 · same date shape |
| `M203QUAL-YYMMDD.xcf` | `5. Qualification Badges/archive` | 1,193,167 · 2019-11-01 | M-203 badges | N1 · same date shape; groups `M320 (ARMA 3/ACV)` and `M203 (SQUAD)` pick the weapon per game |
| `PUC-NEW.xcf` | `6. Unit Achievements/archive` | 2,909,379 · 2021-05-18 | Presidential Unit Citation | recipient is the unit, `TO THE / 7th CAVALRY GAMING REGIMENT / 1ST CAVALRY BRIGADE` · `... ON THIS THE <n>TH DAY OF <MONTH>, IN THE YEAR OF OUR LORD <YYYY>` · W |
| `SUA-YYMMDD.xcf` | `6. Unit Achievements/archive` | 2,990,475 · 2020-09-17 | SUA | as current |
| `PUC-DRAFT.png` | `6. Unit Achievements/archive` | 784,620 · 2019-05-27 | PUC | a flattened image; not downloaded |
| `AVUA-YYMMDD.xcf` | `6. Unit Achievements/archive` | 3,007,324 · 2020-03-09 | AVUA | as current |
| `JMU-YYMMDD.xcf` | `6. Unit Achievements/archive` | 1,045,396 · 2020-03-02 | JMU | as current |
| `MUC-YYMMDD.xcf` | `6. Unit Achievements/archive` | 2,984,493 · 2020-01-26 | MUC | as current |
| `VUC-YYMMDD.xcf` | `6. Unit Achievements/archive` | 2,822,094 · 2019-05-28 | Army Valorous Unit Citation, earlier layout | N2+U · `GIVEN BY MY HAND AT 7TH CAVALRY REGIMENT HEADQUARTERS` over `ON THIS THE <n>rd DAY OF <MONTH> <YYYY>` · W |
| `PUC-DRAFT.xcf` | `6. Unit Achievements/archive` | 2,977,444 · 2019-05-27 | PUC, all dates on one sheet | unit recipient; title `PRESIDENTIAL UNIT CITATION` over `(5TH AWARD)`; five dated lines, one per time the unit earned it; `GIVEN UNDER MY HAND THIS DAY AT FORT HOOD, TEXAS` |
| `Ranger-yymmdd.psd` | `8. Archive` | 2,217,041 · 2019-01-29 | Ranger Tab | the Photoshop predecessor of `RANGER-YYMMDD.xcf`: name line, a class line such as `Class 005/02/19`, D6 |
| `RR-YYMMDD.psd` | `8. Archive` | 2,067,974 · 2018-04-25 | Regimental Recruiting Ribbon | N2, a count line `1st Award`, `Given on this the <n>th day of <Month> <YYYY>` |
| `Large citation folder.pspimage`, `Distinguished Service Cross.pspimage` | `8. Archive` | 3,946,348 · 2020-08-29; 1,287,891 · 2019-07-13 | unknown | Paint Shop Pro files; no parser, not read |
| four `.url` files | `8. Archive` | 186 to 217 bytes, 2017 | none | links, not followed |
| `DONATION-Generic.xcf` | `8. Archive/7. GENERIC + DISCONTINUED` | 1,833,298 · 2025-02-19 | Donation Ribbon | the archived DONATION with the name and `37TH AWARD` layers hidden and the date line cut to `GIVEN UNDER MY HAND AT FORT HOOD, TEXAS`. Matches the 2026 exports |
| `GRENADEQUAL-WWII.xcf`, `RIFLEQUAL-WWII.xcf` | `8. Archive/7. GENERIC + DISCONTINUED` | 2,469,303 and 2,482,992 · 2020-09-28 | grenade and rifle badges, generic | N0 · `DATE` hidden · proficiency titles `... EXPERT`, `... SHARPSHOOTER`, `... MARKSMAN` toggled |
| `SQUADSERVICE-YYMMDD.xcf` | `8. Archive/7. GENERIC + DISCONTINUED` | 2,941,599 · 2020-03-08 | Squad Service Ribbon | as the category-archive copy, different bytes |
| nine copies: `ARMA3SERVICE`, `UNMEDAL`, `EIB`, `HLLSERVICE`, `PUC-DRAFT.xcf`, `WW2SERVICE`, `AFEM`, `RECRUITING`, `GCM` | `8. Archive/7. GENERIC + DISCONTINUED` | same as the copies above | as above | not downloaded |
| seven copies: `RANGER`, `BESTRANGER`, `ASR`, `REENLIST`, `ADM`, `PATHFINDER`, `LRRP` | `8. Archive/7. GENERIC + DISCONTINUED/DISCONTINUED` | same as the copies above | as above | not downloaded |

---

## 4. Certificates with no current design file

These awards and records have citations on the roster but no design file in a current category
folder. Some have an archived design in section 3, which in most cases no longer matches what the
exports print. What follows comes from the sampled exports.

| Award or record | Rows sampled | What the exports print |
|---|---|---|
| Army & Air Force Presidential Unit Citation | 2 | the unit, regiment and above, not a member; written text; D2. One image per date the unit earned it. `Cav7/EnlistmentDefaults` ships six of them in `src/addons/Cav7/EnlistmentDefaults/assets/puc-citations/` |
| Combat Infantry Badge, and 2nd, 3rd and 4th Award | 10 | N0 · no date. Each award is one generic image; 4 of 4 and 2 of 2 per level are byte-identical. Levels 2 to 4 print `(SECOND AWARD)`, `(THIRD AWARD)`, `(FOURTH AWARD)` and a rising operation count, `FIVE` up to `TWENTY` |
| Army Good Conduct Medal | 2 | N0 · no date · a knot line that changes per level, e.g. `FIRST AWARD FOR ONE YEAR OF SERVICE` and `SECOND SILVER KNOT FOR SIX YEARS OF SERVICE` |
| United Nations Service Medal; Armed Forces Service Medal | 2 each | N0 · no date; each pair byte-identical |
| Donation Ribbon | 3 | 2026: N0, no date, matching `8. Archive/7. GENERIC + DISCONTINUED/DONATION-Generic.xcf`. The 2009 image names the member |
| Recruiting Ribbon | 4 | 2026: three byte-identical images, N0, no date. The 2010 image names the member |
| 7th Cavalry Black Ops Unit Citation | 5 | 2026: four byte-identical images, N0, no date. The 2010 image names a member over `7TH CAVALRY REGIMENT` |
| Squad, Hell Let Loose, HLL Console, Overseas, DCS World, Ready or Not and Foxhole service ribbons | 14 | 2026: N0, no date, a layout like `SERVICE TEMPLATE.xcf`. The text carries an operation threshold; 5, 50, 100, 200 and 250 were seen, so each threshold is its own image |
| D-Day Commemorative Medal | 2 | N0 · text names the anniversary and year, `76TH ... JUNE 2020` and `82ND ... JUNE 2026` |
| D Day Participation Ribbon | 2 | a 2010 image with N1 and a count line `2nd Award` over `Given on this the <n>th Day of <Month> <YYYY>`; a 2019 row carrying the D-Day Commemorative Medal's nameless certificate for the 75th anniversary |
| Senior and Master Instructor, Gold, Senior and Master Recruiter badges | 10 | N0 · D0 `GIVEN UNDER MY HAND` · the threshold in the text, 15 or 25 courses taught, 20 to 100 members recruited |
| Weapon qualification badges: Rifle, Grenade, Pistol, Aeroweapons, M-203, Machine Gun, Recoilless Rifle, Hydra-70, Tank Weapons | 27 | 25: N0 · D0 `GIVEN UNDER MY HAND` · proficiency and weapon in the title, e.g. `RIFLE EXPERT`. The newest M-203 Expert and Machine Gun Expert samples are 2012 and 2016 images that name the member |
| the two named Lifetime Achievement Medals | 4 | three name the member, with written text and D2, in 2012 and 2023 images; the fourth is section 7's mismatch |
| Special Forces Tab, Ranger Selection Ribbon, Cadre Course Ribbon, WAC Service Medal, European-African-Middle Eastern Campaign Medal | 2 each | 2008 to 2017 layouts that name the member; closing lines such as `GIVEN UNDER MY HAND AT FORT BRAGG, NC` over `On this <n>th day of <Month> <YYYY>` |
| Promotion records `Named Enlisted Trooper of the Month` and `Named Non-Commissioned Officer of the Month` | 3 | a monthly-honor certificate filed under the Promotion record type; names the month in the text |
| Graduation records for other courses | 24 | 2008 to 2018 school diplomas: Drill Instructor, Special Forces Qualification, HALO and Air Assault schools, `HONOR GRADUATE MEDAL`. Several print a class number, e.g. `Class 021/05/17` |

---

## 5. The known leads

### Pay grade

A promotion certificate prints the pay grade on three of the four tiers.

- Enlisted: baked into each toggled insignia layer with no brackets, as `PRIVATE FIRST CLASS E-3`,
  `SPECIALIST E-4`, `CORPORAL E-4` (`4. Promotions/Enlisted Promotion .xcf`).
- NCO: typed into the `RANK (TEXT)` layer, as `SERGEANT (E-5)` (`4. Promotions/NCO Promotion.xcf`).
- Officer: typed in title case, as `Second Lieutenant (O-1)` (`4. Promotions/Officer Promotion.xcf`).
- Warrant: the insignia layer prints the abbreviation instead, as `WARRANT OFFICER (WO1)` and
  `CHIEF WARRANT OFFICER 2 (CW2)` (`4. Promotions/Warrant Promotion.xcf`). One 2020 export prints
  `WARRANT OFFICER (W-1)`.

The boot camp and NCO Academy certificates also carry it, inside the name line, as
`PRIVATE (E-2) <NAME>` and `CORPORAL (E-4) <NAME>` (`4. Promotions/BOOTCAMP-YYMMDD.xcf`,
`3. Individual Achievements/NCOA-HG-YYMMDD.xcf`). Of 38 sampled Promotion exports printed in 2019 or
later, OCR read a pay grade on 25 and a warrant abbreviation on 5. OCR could not read the rank line
on the other 8, and all 8 print it when read by eye, e.g. `STAFF SERGEANT (E-6)`,
`General of the Army (O-10)` and `CHIEF WARRANT OFFICER 5 (CW5)`.

The value comes from nowhere structured. The clerk types it, or picks it with the insignia layer.
`4. Promotions/1 - File Naming Help.txt` maps ranks to codes for export file names, `E1-RCT` up to
`O10-GEN` and `O10-GOA`, with a note that officer codes use the letter O. It has two typos, a
`Staff Serveant` line and a `Chief Warrant Officer 6` line for `W5-CW5`.

The NF/Rosters rank table stores no pay grade and no abbreviation. Its columns are `rank_id`,
`title`, `rank_image`, `display_order` and `extra_group_ids` (vendor
`NF/Rosters/Entity/Rank.php:57-64`), and the dev database's table matches. The 30 titles are full
titles such as `Private First Class` and `Chief Warrant Officer 2`, plus `Recruit`, `Reservist` and
`Tester`. The pay grade does sit in Promotion `details` text: 10,611 of 10,727 Promotion records
contain a bracketed grade such as `(E-4)`, and 69 use `(WO1)` or `(CW2)` style instead.

### Insignia

Every promotion design composites one insignia per rank from toggled raster layers inside the design
file:

- Enlisted `RANKS`: `PFC`, `SPC`, `CPL`, with the rank text baked in.
- NCO `RANKS (PICTURE)`: `SGT (E-5)` to `CSM (E-9)` and `WOC (DISCONTINUED)`.
- Warrant `RANKS`: `WOC`, `WO1`, `CW2` to `CW5`, with the rank text baked in.
- Officer `RANKS (PICTURE)`: `2LT` to `GEN`, and `GOA`.

Rendering the Warrant and Enlisted layers one by one shows each carries its own rank line above the
insignia. `NCO-RNK-YYMMDD.xcf` works the same way for the NCO Professional Development Ribbon, with
a numeral layer per rank from SSG to CSM in place of an insignia.

`04 - Promotion Images/` holds 26 loose images, modified between 2018 and 2020. Ten are named for a
rank plus `Promo`, such as `PFCPROMO.png` and `SSGPromo-no-nco-ribbon.png`; the one opened,
`PFCPROMO.png`, is a 200x225 medal-box picture with a PFC chevron beside a cased medal. Sixteen are
bare insignia named for a rank, such as `CPT.png` at 101x96 and `Colonel.jpg`. No current design
uses them as layers; the design's own `CPT` layer is 56x52. Three of the 26 were downloaded; the rest
are known by name and size.

NF/Rosters does keep one image per rank. `rank_image` flags it and the vendor serves it from
`data/roster_ranks/<group>/<rank_id>.jpg` (vendor `NF/Rosters/Entity/Rank.php:19-44,61`). The dev
copy's 29 rank images are icons 9 to 75 pixels wide, far below the certificate insignia.

### How the rank prints

As a full title, almost always in capitals:

- N1 `<RANK> <NAME>` with the full title is the recipient line on 54 of the 66 current designs,
  JSAM and JMU with unit lines added, e.g. `STAFF SERGEANT <NAME>`,
  `CHIEF WARRANT OFFICER 2 <NAME>`.
- Title case appears on the Officer promotion's rank line, on the HSM, AFEM, BACC and 2017 ASR
  recipient lines, and on JSAM's unit line.
- Full title plus pay grade appears on promotions, boot camp and NCO Academy, above.
- The only abbreviation printed is the Warrant design's bracketed `(WO1)` and `(CW2)`, after the
  full title. No design prints `SSG <NAME>` style. Short forms like `CPL.<Surname>.<Initial>` do turn
  up inside citation text, which the clerk pastes as written.
- Promotion designs print the rank on its own line or inside the insignia layer, and the name alone
  on the `TROOPER` line.
- The EIB design's name layer has no rank. So does the export of every nameless certificate in
  section 4.

The clerk types the rank, so it follows whatever the milpac's title is, `Reservist` included: a 2026
StackUp export prints `RESERVIST <NAME>`. Typing errors reach the files; the current OVSM design's
name layer reads `LIEUTENSNT COLOANEL`.

### The date line

The ten shapes in section 1 are fixed per design, and the clerk types the day, the suffix and the
month into them. Findings:

- 21 of the 66 current designs print no date. Ten course badges and both mission controller copies
  in `3. Individual Achievements` end on `GIVEN UNDER MY HAND`; GWOTEM, IRAQ, NDSM, AFGHAN and
  EIB end on a Fort Hood line; BOOTCAMP and NCOA carry `Given at Fort Hood, Texas` in the plate; and
  NCOA-HG and SERVICE TEMPLATE have no closing line. Every generic image in section 4 prints no date
  either.
- The suffix is typed in capitals, `ST`, `ND`, `RD`, `TH`, on every capitals design. The blackletter
  designs use lower case, `21st`, `11th`. The JMU 2026 export mixes them, `ON THIS THE 4th DAY OF
  MARCH 2026`, and the archived VUC reads `23rd`.
- The month is spelled out, in capitals on capitals designs and in title case on blackletter ones.
- The comma before the year depends on the design: D1, D3, D4 and D5 have it, D2, D6, D7 and D8 do
  not, and OVSM and FLIGHTMEDIC drop it from shapes that otherwise have it.
- The place is fixed per design: Fort Hood, the City of Washington, D.C., or 7th Cavalry
  Headquarters. Older exports also show Fort Bragg and Fort Benning.
- Hand typing leaves errors in the files: `21TH` in LoM, `31TH` in the archived SENIOREOD,
  `DECMBER` in DSM, `DECEMEBER` in ARCOM, `FEBURARY` in AFEM, `FEBRAURY` in SOLDIER. A 2020 Warrant
  export spells the day out, `Given on this Fifth day of September, 2020`.

The shape asked about, `ON THIS <n>TH DAY OF <MONTH> <YYYY>`, occurs as written only on Air Assault
and, with a comma, on NCO-RNK. Everywhere else `THE`, a comma, the place or the whole
`GIVEN UNDER MY HAND` phrase differs.

### Repeat awards

No current per-member design prints the repeat count that award rows carry in `details`.

- 8,166 of 92,729 award rows have `<n>st/nd/rd/th Award` wording in `details`. The rows with a count
  of 2 or more sit mostly on Donation Ribbon (4,347), Recruiting Ribbon (1,233), Humanitarian Service
  Medal (659), Black Ops Unit Citation (85) and OVSM (12).
- Donation, Recruiting and Black Ops certificates are now one generic image with no name and no
  count: 2 of 2, 3 of 3 and 4 of 4 sampled 2026 rows are byte-identical.
- HSM names the member but not the count; two sampled rows marked 8th and 2nd award show none.
- One member's OVSM rows marked 2nd and 3rd award carry byte-identical certificates.

Counts and devices do print in other places:

- As separate awards with their own generic image: CIB 2nd, 3rd and 4th Award print
  `(SECOND AWARD)` and so on; valor devices are separate award rows whose design toggles the title,
  `BRONZE STAR MEDAL WITH VALOR DEVICE` and `ARMY COMMENDATION MEDAL WITH VALOR DEVICE`.
- As a level line on a generic image: the Good Conduct Medal prints its knot. `details` records it,
  in wording such as `1st Bronze Knot` on 660 rows and `2nd Silver Knot` on 127; at least 1,963 of
  the 3,284 Good Conduct rows carry knot wording.
- As a device chosen by layer: SERVERUP's silver and gold star attachments, whose ribbon layers are
  named `UN 6th Award` and `UN 11th Award`, and STACKUP's bronze, silver and gold knots by amount.
- On archived designs: DONATION's `37TH AWARD` layer, ADM's second line `2ND AWARD`, PUC-DRAFT's
  `(5TH AWARD)` and the 2018 `RR-YYMMDD.psd` line `1st Award`.
- On older exports: American Defense Medal `1ST AWARD` in 2010 and 2012 images, D Day Participation
  Ribbon `2nd Award` in 2010.

No design layer and no sampled export mentions an oak leaf cluster.

### Unit citations

- MUC, SUA and AVUA print one member's `<RANK> <NAME>` in the `TROOPER NAME` layer. The unit is
  named only inside the citation text (`6. Unit Achievements/MUC-YYMMDD.xcf`, `SUA-YYMMDD.xcf`,
  `AVUA-YYMMDD.xcf`). All nine sampled 2026 exports of the three, three each, print a member.
- JMU prints the member and two unit lines, `<COMPANY>, <BATTALION>` or `<BATTALION>` over
  `7TH CAVALRY REGIMENT` (`6. Unit Achievements/JMU-YYMMDD.xcf`). All four sampled exports print a
  member, and the three read by eye carry the unit lines.
- The recipient line has held a unit instead. The earliest sampled SUA export, dated 2020, prints
  `1ST PLATOON, CHARLIE COMPANY, 1ST BATTALION` there, and a 2008 MUC export prints a company,
  battalion and regiment block. The archived VUC and the 2019 AVUA export print member plus unit.
- The PUC prints only the unit, regiment and above, in both usable sampled rows and in both archived
  PUC designs.
- The Black Ops Unit Citation prints neither since 2026.

### No name

- **EIB: confirmed.** 4 of 4 sampled Expert Infantry Badge rows, citation dates 2021-02 to 2026-07,
  share one byte-identical image with no recipient line and no date. The design file disagrees: it
  has a visible text layer holding a name without a rank (`3. Individual Achievements/EIB-YYMMDD.xcf`).
- **BACC: not confirmed.** The design prints `<Rank> <Name>` and two unit lines
  (`2. Course Certificates/BACC-YYMMDD.xcf`), and both sampled BACC exports, 2009 and 2011 images on
  Graduation records, print a rank, a name and a class number. No award row with a BACC certificate
  turned up. The nameless course certificate is the Basic Assault Movement Course on Basic Assault
  Course Ribbon rows: 3 of 3 sampled 2026 rows are byte-identical with no name and no date, and its
  archived design has the name and date layers hidden (`2. Course Certificates/archive/BAMC-YYMMDD.xcf`).
  The sampled 2021 row of that award still named the member.
- **Others with no name.** 51 of the 132 awards print no recipient on their newest sampled export:
  EIB, the Basic Assault Course Ribbon, CIB and its three repeat awards, Army Good Conduct Medal, UN
  Service Medal, Armed Forces Service Medal, D-Day Commemorative Medal, Donation Ribbon, Recruiting
  Ribbon, Black Ops Unit Citation, eight service ribbons, the five instructor and recruiter badges,
  and 25 of the 27 weapon-qualification badges. The PUC names a unit, not a member. Donation,
  Recruiting, Black Ops, several service ribbons and the Good Conduct Medal named members in their
  older images and archived designs.

### Other varying parts found

- **Signature blocks.** One slot on 53 current designs, two on 12, none on BACC. Each slot is a
  raster with the ink signature and printed name and billet lines, swapped by toggling hidden
  alternatives: up to five sit in one file (`3. Individual Achievements/DSC-YYMMDD.xcf`). Billets
  seen are Commander and Executive Officer on current designs, plus Chief of Staff and S3 officer in
  charge on hidden layers and older exports, and NCO in charge of the NCO Academy and Commander of
  Recruit Training on 2010 and 2011 exports.
- **Citation text.** 23 current designs take a written paragraph. The rest carry fixed text. Some
  fixed text changes per variant, e.g. the aviator course layers.
- **Course and platform variants.** AVIATOR and DCSAVIATOR switch course text per game, the
  archived BAMC switches its citation between ArmA, Hell Let Loose and Squad, and MASTERAVIATOR and
  the archived SENIORAVIATOR switch between ACV and ArmA 3 airframes.
- **Class numbers** on course certificates: RANGER's hidden class line, the Ranger PSD, and 2009,
  2010, 2011 and 2017 exports. Graduation `details` also carry them, e.g. `Graduated ARMA3 Ranger
  Class <nnn-nn>`.
- **Event text** on generic images: the D-Day Commemorative Medal's anniversary number and year, and
  STACKUP's drive year.
- **Leftover recipient data.** 54 of the 66 current design files still hold the last recipient's
  name, and the written-award files also hold that member's citation text. 11 hold placeholders such
  as `JOHN DOE` and `RANK FIRSTNAME LASTNAME`, and SERVICE TEMPLATE has no name layer. S1 works in
  the design file itself and saves over it.

---

## 6. Parts that vary beyond name, rank, date, citation text and signatures

These parts change between certificates of one design, or pick between designs, and are not name,
rank, date, citation text or signatures. For each: where it prints, whether it follows the member or
the grant on the certificates seen, and what records it today.

1. **Pay grade.** Enlisted, NCO and Officer promotions, boot camp, NCO Academy. Follows the new or
   current rank. Recorded only in Promotion `details` text and the naming table; the rank table has
   no column for it.
2. **Rank insignia.** All four promotion tiers. Follows the new rank. Recorded as design layers; the
   roster's `rank_image` is an icon.
3. **Rank numeral.** NCO Professional Development Ribbon, one numeral per rank from SSG to CSM.
   Follows the rank.
4. **Unit lines** under the name. AFEM, JSAM, JMU, BACC, and the archived UNMEDAL and VUC. Printed
   per member, naming that member's own company or battalion. Typed by the clerk. The nearest
   structured source is the milpac's position (vendor `NF/Rosters/Entity/RosterUser.php:227`,
   position title and group at `NF/Rosters/Entity/Position.php:83-93`); whether positions spell
   company and battalion the way certificates print them was not checked.
5. **A unit as the recipient.** PUC always; SUA and MUC in older exports. Per grant.
6. **Award variant picked by layer.** Valor title on BSM and ARCOM; badge level on EOD, AIRCREW and
   the mission controller badges; course or platform on the aviator badges and BAMC; weapon and
   proficiency on the qualification designs; honor graduate on BOOTCAMP. Each variant matches a
   separate award row or record wording, except the course and platform text, which no roster field
   records.
7. **Tier or count text on generic images.** Service ribbon operation thresholds, Good Conduct Medal
   knots, CIB award level and operation count, instructor and recruiter thresholds, StackUp amount
   bands, Server Upgrade star attachments. One image per tier, so today the tier is the choice of
   image. The Good Conduct Medal's knot is in `details`; the others are in no field.
8. **Repeat count.** Printed on archived Donation, American Defense Medal, PUC and Recruiting designs
   and on 2010 to 2012 exports. Per member. Recorded in award `details` as `<n>th Award`.
9. **Class number.** Ranger, BACC, Air Assault and NCO course certificates. Per grant, since a class
   graduates together. Recorded in some Graduation `details`.
10. **Honor-graduate status.** Boot camp and NCO Academy. Per member. Recorded as a separate Honor
    Graduate Ribbon award row and in record wording.
11. **Event name and year** on generic event certificates. Per grant or per event.

The place in the closing line, the recipient line's case and layout, the font, and whether a date
prints at all are set per design and do not vary between certificates of one design.

---

## 7. Damage and anomalies

**Empty design files.** `1. Course Badges/SENIORAVIATOR-YYMMDD.xcf` is 0 bytes, modified 2026-06-08,
and `3. Individual Achievements/MSM-YYMMDD.xcf` is 0 bytes, modified 2026-08-29. The newest non-empty
copies are `1. Course Badges/archive/SENIORAVIATOR-YYMMDD.xcf` from 2020-08-16 and
`3. Individual Achievements/archive/MSM-YYMMDD.xcf` from 2024-07-16.

**Two current SRMISSIONCON files.** `1. Course Badges/SRMISSIONCON-YYMMDD.xcf` (2,422,205 bytes,
2025-01-05, GIMP 2.10 format) and `3. Individual Achievements/SRMISSIONCON-YYMMDD.xcf` (2,420,939
bytes, 2026-03-07, re-saved in GIMP 3). They differ in the name font, Garamond Premier Pro
Semi-Bold against Sitka Banner Bold, and in the title layer, which the GIMP 3 copy widens to 521
pixels in a different face so that it renders clipped as `SENIOR MISSION CONTROLLER BAD`. The newest
sampled Senior Mission Controller Badge export, from 2026, shows that clipped title, so current
exports come from the GIMP 3 copy. A third copy sits in `1. Course Badges/archive/`, dated
2024-08-04.

**Other duplicates.** `3. Individual Achievements/MASTERMISSIONCON-YYMMDD.xcf` and
`3. Individual Achievements/COMBATMEDIC-YYMMDD.xcf` are byte-identical to the `1. Course
Badges/archive/` copies of the same names, while newer versions sit in `1. Course Badges/`.
MASTERGUNNER, DDSM and SNIPER each have a byte-identical copy in their category archive. The two
`4. Promotions/archive/BOOTCAMP-YYMMDD.xcf` files are byte-identical. 16 files under
`8. Archive/7. GENERIC + DISCONTINUED/` repeat copies held elsewhere. `BAMC-YYMMDD.xcf` sits in an
archive folder, yet it was modified in 2026 and matches current exports.

**Designs whose layers disagree with their exports.**

- EIB: the design shows a name; exports since 2021 show none.
- BSM ships with `BRONZE STAR VALOR` shown and `BRONZE STAR` hidden. One of the two sampled Bronze
  Star rows, from 2026-07, prints `BRONZE STAR MEDAL WITH VALOR DEVICE`.
- MASTERAVIATOR has all four variant layers hidden, so the plate carries no badge title or art until
  the clerk shows one.
- NCOA's name layer has no pay grade; its 2026 export prints one.
- The NCO promotion keeps its rank text and insignia in separate layers, and they can drift apart.
  A 2021 Promotion record to Command Sergeant Major prints the design's default rank text
  `SERGEANT (E-5)` above a CSM insignia.
- The archived Donation, Recruiting, Good Conduct and UN Service Medal designs name a member;
  current exports do not. For Donation, `DONATION-Generic.xcf` matches the exports.
- Stored text and pixels disagree in several layers, because someone edited the rendered layer
  rather than its text. `BOOTCAMP`'s `BASIC GRADUATE` and `HONOR GRADUATE` layers store the text
  `THE ARMY SERVICE RIBBON` while showing the full graduation wording. `PARACHUTIST`'s title layer
  stores `AIR ASSAULT BADGE`, `MSTRPARACHUTIST`'s stores `SENIOR PARACHUTIST BADGE`, and
  SRMISSIONCON's hidden `MASTER` title stores `SENIOR MISSION CONTROLLER BADGE`. Reading text out of
  the XCF alone gives the wrong answer for these.

**Fonts outside Sitka Banner and Times New Roman** in current designs:

- Garamond Premier Pro: Enlisted and Warrant promotions, DSM, AFEM, SS, AM, ASR, BACC, both mission
  controller badges.
- Old English Text MT: Warrant date line, RANGER date line.
- Olde English: EIB.
- Bookman Old Style: BACC, JSCM, the SS and AM date lines.
- Arno Pro: JMU.
- The GIMP 3 copy of SRMISSIONCON records one text span only as `font236`.

Archived designs add Adobe Caslon Pro and Olde English in BAMC, Centaur and Tahoma in LRRP, Adobe
Garamond Pro in UNMEDAL, and Book Antiqua and a face named `Diploma` in the WWII qualification pair.
Titles like `Certificate of Promotion` are pixels in the plate, so their faces are not recorded.

**Other oddities in the files.**

- The OVSM title layer, current and archived, puts a U+2060 word joiner between every letter of
  `OUTSTANDING VOLUNTEER SERVICE MEDAL`.
- Fixed text carries typos, e.g. `NON COMISSIONED` in the NCO promotion and `PRSENT` in the JSCM
  heading.
- The Enlisted promotion date line has two spaces after `THE`.
- BACC's signature group is hidden, so it prints no signature.
- Two current designs are GIMP 3 files, XCF version 19: PH and the `3. Individual Achievements`
  SRMISSIONCON. The rest are GIMP 2 files, versions 0, 3 and 11.

**Anomalies in the corpus.**

- The newest Master Gunner Badge citation is PNG data under a `.jpg` name and ends early. The server
  sends its full declared length of 352,936 bytes, so the stored file is cut short.
- One row of a named Lifetime Achievement Medal, citation dated 2026-07, shows the generic
  Donation Ribbon image. The row may have been edited on production after the mirror.
- A 2019 D Day Participation Ribbon row carries a D-Day Commemorative Medal certificate.
- Army Service Ribbon and Honor Graduate Ribbon rows carry the boot camp graduation certificate, the
  same image as the Graduation record.
- 249 of the 365 sampled files hold PNG data, although the vendor stores and serves every citation
  under a `.jpg` name.
- The Promotion record type also holds monthly-honor certificates, `Named Enlisted Trooper of the
  Month` and its NCO twin.

---

## 8. Method

### Drive

The Drive MCP connector's `search_files` walked `02 - Templates/` by `parentId`: all eight category
folders, each `archive/` subfolder, and `8. Archive`'s own subfolders `7. GENERIC + DISCONTINUED`,
`DISCONTINUED`, `7. SIGNATURES` and `8. PythonProject`. It also listed `04 - Promotion Images/`. The
`01 - Personnel` folder and its shortcut inside `02 - Templates/` were not opened. `7. SIGNATURES`
was listed but its images were not opened.

`download_file_content` returned each file as base64, which the harness saved to a local file. Each
was decoded in a scratch directory and its size checked against Drive's `fileSize`; every one
matched. The Drive copy of `BSM-YYMMDD.xcf` matched the known-good local copy byte for byte. That
covered 157 XCF files and 2 PSD files: every design file except the 16 exact duplicates, the two
Paint Shop Pro files, two flattened images and four `.url` files. Three of the 26 promotion images
were downloaded. The two text files were read in full.

A short Python parser, run under `uv run`, read each XCF's header, layer tree, visibility, offsets
and sizes, and the `gimp-text-layer` parasite for text, font, size, colour and justification. GIMP 3
files store the face as `GimpFont` with a `fullname`, which the parser resolved. ImageMagick flattened
every design to check what shows by default, and rendered single layers to read toggled variants
and the text baked into insignia layers.

### Vendor code and dev database

Read `NF/Rosters/Entity/Rank.php`, `Award.php`, `RosterUserAward.php`, `ServiceRecord.php`,
`RecordType.php`, `RosterUser.php` and `Position.php`.

- Award row citations are served from `data/roster_award_citations/<floor(record_id/1000)>/<record_id>.jpg`
  with the upload time as a cache buster (vendor `NF/Rosters/Entity/RosterUserAward.php:25-50`), and
  service-record citations from `data/roster_record_citations/` the same way
  (`NF/Rosters/Entity/ServiceRecord.php:24-49`).
- Award columns are id, title, image, group and order, with no count or device column
  (`NF/Rosters/Entity/Award.php:50-57`). The count lives in the award row's `details`
  (`NF/Rosters/Entity/RosterUserAward.php:73`).
- Record types are id, title and order (`NF/Rosters/Entity/RecordType.php:36-41`).

The dev stack's database, a production mirror to 2026-08-04, supplied the sample frame and the
counts in this note. Only the database and PHP containers were started, and both were stopped again.

### Public corpus

365 citation images were fetched by plain HTTP GET from the public data URLs, at most 4 at a time,
plus one test fetch and one re-fetch of the broken Master Gunner file. The sample covered the
generator's three row kinds and nothing else:

- 264 award rows covering all 132 awards that have a citation, mostly each award's earliest and
  latest by `citation_date`, plus extra rows for repeat awards, the unit citations, EIB, CIB and the
  Basic Assault Course Ribbon;
- 62 Promotion records spread over every new-rank wording in `details`, all four tiers included;
- 39 Graduation records, including boot camp, its honor version, NCO Academy and BACC.

Disciplinary and every other record type were excluded. `citation_date` records the upload, and it
starts in 2021-02 for every row because of a migration, so the dates printed on sampled images run
from 2003 to 2026. Two sampled URLs belonged to rows the dev copy created itself after the mirror;
production holds different rows at those ids, so both were dropped. Every image went through
Tesseract OCR, and about 120 were read by eye on contact sheets. Byte-identical images were found by
hashing.

### Not reached

- The last good contents of the two empty design files. S1 holds backups.
- The Drive's revision history, which the connector cannot list.
- The three training documents in `7. Citation Clerk Training`, which are shortcuts to Google Docs.
- The two Paint Shop Pro files, for want of a parser.
- The faces of text that is baked into plates as pixels.
- Whether milpac positions spell company and battalion the way certificates print them.
- What production holds for rows created after 2026-08-04.
