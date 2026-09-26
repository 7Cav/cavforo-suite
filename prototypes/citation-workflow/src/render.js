// PROTOTYPE. The render: draws one grant member's certificate on its template version's plate,
// in the browser's canvas instead of prod's Imagick. It follows the decided fit: single-line
// fields shrink to their box, the citation text takes the largest size that fits with justified
// lines and a centred last line, and a size below the field's legibility threshold is flagged,
// never refused. The plates here are stand-ins built from the BSM design's art layers.

const Render = (() => {
  const IMG = {};
  const plates = {};
  const legacyCache = {};
  const INK = '#1d2a4d';

  async function init(assets) {
    const load = (name, src) => new Promise(res => {
      const im = new Image();
      im.onload = () => { IMG[name] = im; res(); };
      im.onerror = () => res();
      im.src = src;
    });
    await Promise.all(Object.entries(assets).map(([k, v]) => load(k, v)));
    await Promise.all(['400 20px Tinos', '700 20px Tinos', 'italic 400 20px Tinos', '400 15px Roboto', '700 15px Roboto']
      .map(f => document.fonts.load(f).catch(() => null)));
    IMG.inkA = IMG.ink;
    IMG.inkB = makeInk(11); IMG.inkC = makeInk(23); IMG.inkD = makeInk(37);
    IMG.inkE = makeInk(51); IMG.inkF = makeInk(64); IMG.inkG = makeInk(79);
  }

  // A made-up ink signature: a seeded scribble, nobody's hand.
  function makeInk(seed) {
    let x = seed * 9301 + 49297;
    const rnd = () => ((x = (x * 9301 + 49297) % 233280) / 233280);
    const c = document.createElement('canvas');
    c.width = 620; c.height = 150;
    const g = c.getContext('2d');
    g.strokeStyle = INK; g.lineCap = 'round'; g.lineJoin = 'round';
    const pts = [];
    let px = 30, base = 88;
    const letters = 7 + Math.floor(rnd() * 5);
    for (let i = 0; i < letters; i++) {
      const tall = rnd() < 0.3, loop = rnd() < 0.55;
      const w = 34 + rnd() * 30, h = tall ? 60 + rnd() * 18 : 26 + rnd() * 16;
      pts.push([px, base]);
      if (loop) { pts.push([px + w * 0.55, base - h]); pts.push([px + w * 0.15, base - h * 0.55]); pts.push([px + w * 0.8, base + 4]); }
      else { pts.push([px + w * 0.3, base - h]); pts.push([px + w * 0.65, base - 2]); }
      px += w;
      if (px > 540) break;
    }
    pts.push([px + 20, base - 6]);
    g.beginPath(); g.moveTo(pts[0][0], pts[0][1]);
    for (let i = 1; i < pts.length - 1; i++) {
      const mx = (pts[i][0] + pts[i + 1][0]) / 2, my = (pts[i][1] + pts[i + 1][1]) / 2;
      g.quadraticCurveTo(pts[i][0], pts[i][1], mx, my);
    }
    g.lineWidth = 3.4; g.stroke();
    g.beginPath(); g.moveTo(40, 122); g.bezierCurveTo(200, 108 + rnd() * 10, 380, 126, Math.min(px + 30, 600), 104);
    g.lineWidth = 2.6; g.stroke();
    return c;
  }

  // --- plates ------------------------------------------------------------------------------

  function canvas(w, h) { const c = document.createElement('canvas'); c.width = w; c.height = h; return c; }
  function fontSpec(kind, size) {
    return (kind === 'bold' ? '700 ' : kind === 'italic' ? 'italic 400 ' : '400 ') + size + 'px Tinos, "Times New Roman", serif';
  }
  function centre(g, text, y, size, kind = 'regular', color = '#16161a', w = 1275) {
    g.font = fontSpec(kind, size); g.fillStyle = color; g.textAlign = 'center'; g.textBaseline = 'middle';
    g.fillText(text, w / 2, y);
  }
  function drawImg(g, im, x, y, w, h) { if (im) g.drawImage(im, x, y, w, h); }
  function cover(g, im, W, H) {
    if (!im) { g.fillStyle = '#efece2'; g.fillRect(0, 0, W, H); return; }
    const s = Math.max(W / im.width, H / im.height);
    const w = im.width * s, h = im.height * s;
    g.drawImage(im, (W - w) / 2, (H - h) / 2, w, h);
  }

  function plate(key) {
    if (plates[key]) return plates[key];
    let c;
    const P = 'plate-blank-1275';
    const preamble = (g, y1) => {
      centre(g, 'TO ALL WHO SHALL SEE THESE PRESENTS, GREETINGS;', y1, 25);
      centre(g, 'THIS IS TO CERTIFY THAT THE SECRETARY OF THE ARMY HAS AWARDED THE', y1 + 27, 25);
    };
    if (key === 'msm') {
      c = canvas(1275, 1554); const g = c.getContext('2d');
      drawImg(g, IMG[P], 0, 0, 1275, 1554);
      drawImg(g, IMG['msm-bow'], 337, 46, 600, 116);
      centre(g, 'THE UNITED STATES ARMY', 297, 42, 'bold');
      preamble(g, 348);
      centre(g, 'TO', 465, 24);
    } else if (key === 'eib') {
      c = canvas(1275, 1554); const g = c.getContext('2d');
      drawImg(g, IMG[P], 0, 0, 1275, 1554);
      drawImg(g, IMG['award-67'], 442, 60, 390, 180);
      centre(g, 'THE UNITED STATES ARMY', 312, 42, 'bold');
      preamble(g, 362);
      centre(g, 'EXPERT INFANTRY BADGE', 452, 52);
    } else if (key === 'bct') {
      c = canvas(1275, 1554); const g = c.getContext('2d');
      drawImg(g, IMG[P], 0, 0, 1275, 1554);
      drawImg(g, IMG['seal-left'], 527, 40, 220, 220);
      centre(g, '7TH CAVALRY REGIMENT', 330, 42, 'bold');
      centre(g, 'CERTIFICATE OF GRADUATION', 420, 58);
      centre(g, 'THIS IS TO CERTIFY THAT', 560, 26);
      centre(g, 'HAS SUCCESSFULLY COMPLETED', 745, 26);
      centre(g, 'AND IS WELCOMED INTO THE RANKS OF THE 7TH CAVALRY REGIMENT', 880, 26);
    } else if (key === 'bsm-port') {
      c = canvas(1275, 1554); drawImg(c.getContext('2d'), IMG['plate-bsm-portrait-1275'], 0, 0, 1275, 1554);
    } else if (key === 'bsm-land') {
      c = canvas(640, 500); drawImg(c.getContext('2d'), IMG['plate-bsm-landscape-640'], 0, 0, 640, 500);
    } else if (key === 'puc-2011' || key === 'puc-2021') {
      // Stand-ins for the two issued PUC certificates: the real ones become the plates as issued.
      const is21 = key === 'puc-2021';
      c = canvas(1275, 985); const g = c.getContext('2d');
      cover(g, IMG[P], 1275, 985);
      drawImg(g, IMG['award-61'], 487, 50, 300, 126);
      centre(g, 'THE PRESIDENT OF THE UNITED STATES OF AMERICA', 230, 34, 'bold');
      centre(g, 'HAS AWARDED THE', 280, 24);
      centre(g, 'PRESIDENTIAL UNIT CITATION', 338, 54);
      centre(g, 'TO THE', 392, 24);
      centre(g, '7TH CAVALRY REGIMENT, 1ST CAVALRY BRIGADE', 440, 36, 'bold', '#3a3a3a');
      const body = is21
        ? 'For extraordinary heroism and outstanding performance of duty across the spring 2021 campaign. The Regiment held every assigned objective through five weeks of sustained operations, trained and absorbed more new troopers than in any season before it, and did so with a spirit of cooperation that set the standard for the community.'
        : 'For extraordinary heroism and outstanding performance of duty in the Regiment\'s founding campaigns. Through its first seasons of operations the Regiment built the traditions, standards and training that every trooper since has inherited, and its conduct set an example for the whole gaming community.';
      textBlock(g, { x: 170, y: 490, w: 935, h: 250, font: 'regular', size: 27, minLegible: 12, align: 'justify', multiline: true, color: '#16161a' }, body, 1.5);
      const day = is21 ? '16TH DAY OF MAY, 2021' : '4TH DAY OF JULY, 2011';
      centre(g, 'GIVEN UNDER MY HAND ON THIS ' + day, 775, 23);
      drawSignature(g, { x: 472, y: 800, w: 330, h: 170 }, {
        ink: is21 ? 'inkB' : 'inkF',
        lines: is21 ? ['General Hugo Radcliffe', 'Regimental Commander', '7th Cavalry Regiment'] : ['General Ada Pemberton', 'Regimental Commander', '7th Cavalry Regiment'],
      });
    } else {
      c = canvas(1275, 1554); drawImg(c.getContext('2d'), IMG[P], 0, 0, 1275, 1554);
    }
    plates[key] = c;
    return c;
  }

  // --- text ----------------------------------------------------------------------------------

  function layout(g, text, kind, size, maxW) {
    g.font = fontSpec(kind, size);
    const space = g.measureText(' ').width;
    const lines = [];
    for (const para of String(text).split('\n')) {
      const words = para.split(/\s+/).filter(Boolean);
      if (!words.length) { lines.push({ words: [], widths: [], width: 0, last: true }); continue; }
      let cur = [], ws = [], curW = 0;
      for (const w of words) {
        const ww = g.measureText(w).width;
        const next = cur.length ? curW + space + ww : ww;
        if (cur.length && next > maxW) { lines.push({ words: cur, widths: ws, width: curW, last: false }); cur = [w]; ws = [ww]; curW = ww; }
        else { cur.push(w); ws.push(ww); curW = next; }
      }
      lines.push({ words: cur, widths: ws, width: curW, last: true });
    }
    return { lines, space };
  }

  // Sets text in a field's box and returns the size it landed on.
  function textBlock(g, f, text, leading) {
    const lead = f.leading || leading || 1.5;
    g.fillStyle = f.color || '#16161a'; g.textBaseline = 'middle';
    if (!f.multiline) {
      let size = f.size;
      g.font = fontSpec(f.font, size);
      while (size > 4 && g.measureText(text).width > f.w) { size -= 0.25; g.font = fontSpec(f.font, size); }
      g.textAlign = f.align === 'left' ? 'left' : 'center';
      g.fillText(text, f.align === 'left' ? f.x : f.x + f.w / 2, f.y + f.h / 2);
      return size;
    }
    let lo = 4, hi = f.size, best = lo;
    const fits = sz => layout(g, text, f.font, sz, f.w).lines.length * sz * lead <= f.h;
    if (fits(hi)) best = hi;
    else {
      for (let i = 0; i < 22; i++) { const mid = (lo + hi) / 2; if (fits(mid)) { best = mid; lo = mid; } else hi = mid; }
    }
    const { lines, space } = layout(g, text, f.font, best, f.w);
    const lh = best * lead, total = lines.length * lh;
    let y = f.y + Math.max(0, (f.h - total) / 2) + lh / 2;
    g.textAlign = 'left';
    for (const ln of lines) {
      if (ln.words.length) {
        if (f.align === 'justify' && !ln.last && ln.words.length > 1) {
          const gap = (f.w - ln.widths.reduce((a, b) => a + b, 0)) / (ln.words.length - 1);
          let x = f.x;
          ln.words.forEach((w, i) => { g.fillText(w, x, y); x += ln.widths[i] + gap; });
        } else {
          let x = f.align === 'left' ? f.x : f.x + (f.w - ln.width) / 2;
          ln.words.forEach((w, i) => { g.fillText(w, x, y); x += ln.widths[i] + space; });
        }
      }
      y += lh;
    }
    return best;
  }

  function drawSignature(g, f, sig) {
    const ink = sig && (IMG[sig.ink] || sig.inkImage);
    const inkH = f.h * 0.44, inkW = f.w * 0.92;
    if (ink) {
      const s = Math.min(inkW / ink.width, inkH / ink.height);
      const w = ink.width * s, h = ink.height * s;
      g.drawImage(ink, f.x + (f.w - w) / 2, f.y + f.h * 0.47 - h, w, h);
    }
    if (f.rule !== false) {
      g.fillStyle = '#2b2b2b';
      g.fillRect(f.x + f.w * 0.07, f.y + f.h * 0.49, f.w * 0.86, Math.max(1, f.w / 220));
    }
    const lines = (sig && sig.lines) || [];
    const s1 = f.h * 0.095, s2 = f.h * 0.085;
    g.fillStyle = '#16161a'; g.textAlign = 'center'; g.textBaseline = 'middle';
    let y = f.y + f.h * 0.6;
    lines.forEach((ln, i) => {
      if (!ln) return;
      const size = i === 0 ? s1 : s2;
      g.font = fontSpec(i === 0 ? 'bold' : 'regular', size);
      let sz = size;
      while (sz > 4 && g.measureText(ln).width > f.w) { sz -= 0.25; g.font = fontSpec(i === 0 ? 'bold' : 'regular', sz); }
      g.fillText(ln, f.x + f.w / 2, y);
      y += size * 1.25;
    });
  }

  function placeholder(g, f, label) {
    g.save();
    g.strokeStyle = 'rgba(70,90,140,.75)'; g.setLineDash([10, 7]); g.lineWidth = Math.max(1.5, f.w / 250);
    g.strokeRect(f.x, f.y, f.w, f.h);
    g.fillStyle = 'rgba(70,90,140,.9)'; g.font = '400 ' + Math.max(12, Math.min(26, f.h * 0.22)) + 'px Roboto, sans-serif';
    g.textAlign = 'center'; g.textBaseline = 'middle';
    g.fillText(label, f.x + f.w / 2, f.y + f.h / 2);
    g.restore();
  }

  // --- the render ------------------------------------------------------------------------------

  // opts: { grant, gm, ver, preview, boxes, highlight }
  function renderGrant(s, opts) {
    const { grant, gm, ver } = opts;
    const base = plate(ver.plate);
    const c = canvas(base.width, base.height);
    const g = c.getContext('2d');
    g.drawImage(base, 0, 0);
    const vals = Model.renderValues(s, grant, gm, ver);
    const report = [];
    for (const f of ver.fields) {
      if (f.kind === 'text') {
        let text = Model.formatPattern(f.pattern, vals);
        if (f.caps) text = text.toUpperCase();
        if (!text) { if (opts.preview && /\{citation_text\}/.test(f.pattern)) placeholder(g, f, 'Citation text'); continue; }
        const size = textBlock(g, f, text);
        report.push({ key: f.key, size, min: f.minLegible, flagged: f.minLegible && size < f.minLegible - 0.01, pt: toPt(size, ver.w), minPt: toPt(f.minLegible || 0, ver.w), multiline: f.multiline });
      } else if (f.kind === 'signature') {
        const sigId = grant.sigs && grant.sigs[f.key];
        const sig = sigId && Model.signature(s, sigId);
        if (sig) drawSignature(g, f, sig);
        else if (opts.preview) placeholder(g, f, 'Signature: ' + ((Model.billet(s, f.billetId) || {}).title || 'no billet'));
      } else if (f.kind === 'rankArt') {
        if (opts.preview) placeholder(g, f, 'Rank art');
      }
    }
    if (opts.boxes) {
      g.save();
      for (const f of ver.fields) {
        const hot = f.key === opts.highlight;
        g.strokeStyle = hot ? 'rgba(214,120,20,.95)' : 'rgba(40,110,200,.8)';
        g.setLineDash(hot ? [] : [8, 6]); g.lineWidth = hot ? 4 : 2 * ver.w / 1275;
        g.strokeRect(f.x, f.y, f.w, f.h);
        g.fillStyle = hot ? 'rgba(214,120,20,.95)' : 'rgba(40,110,200,.85)';
        const fs = Math.max(11, 20 * ver.w / 1275);
        g.font = '500 ' + fs + 'px Roboto, sans-serif'; g.textAlign = 'left'; g.textBaseline = 'bottom';
        g.fillText(f.key, f.x + 2, f.y - 2);
      }
      g.restore();
    }
    return { canvas: c, report };
  }
  function toPt(px, plateW) { return px * 72 / (plateW / 8.5); }

  // Today's image citations, as stand-ins. Generated from the row, so nobody real appears.
  function legacyImage(s, r) {
    if (legacyCache[r.id]) return legacyCache[r.id];
    const m = Model.member(s, r.memberId);
    let c;
    if (r.kind === 'record' && r.typeId === Model.DISCIPLINARY) {
      c = canvas(850, 1100); const g = c.getContext('2d');
      g.fillStyle = '#fff'; g.fillRect(0, 0, 850, 1100);
      g.fillStyle = '#111'; g.textAlign = 'left'; g.textBaseline = 'alphabetic';
      g.font = '700 26px Tinos, serif'; g.fillText('7TH CAVALRY REGIMENT', 70, 110);
      g.font = '400 18px Tinos, serif'; g.fillText('7CAV-R-043   LETTER OF REPRIMAND', 70, 145);
      g.fillRect(70, 165, 710, 2);
      g.font = '400 17px Tinos, serif';
      const rows = [['MEMBER', m.username], ['DATE', r.date], ['ISSUED BY', 'S1 Command Staff']];
      rows.forEach(([k, v], i) => { g.fillStyle = '#555'; g.fillText(k, 70, 215 + i * 34); g.fillStyle = '#111'; g.fillText(v, 240, 215 + i * 34); });
      g.fillStyle = '#e8e8e8';
      for (let i = 0; i < 16; i++) g.fillRect(70, 360 + i * 34, i % 5 === 4 ? 420 : 710, 12);
      g.fillStyle = '#777'; g.font = 'italic 400 17px Tinos, serif'; g.textAlign = 'center';
      g.fillText('A filed disciplinary form. Its contents are not part of this prototype.', 425, 960);
    } else {
      c = canvas(640, 780); const g = c.getContext('2d');
      drawImg(g, IMG['plate-blank-1275'], 0, 0, 640, 780);
      const W = 640;
      const rk = Model.rank(s, m.rankId);
      const title = Model.typeTitle(s, r.kind, r.typeId);
      if (r.kind === 'award' && IMG['award-' + r.typeId]) {
        const im = IMG['award-' + r.typeId];
        const w = Math.min(220, im.width * 2), h = im.height * (w / im.width);
        drawImg(g, im, (W - w) / 2, 40, w, h);
      } else if (IMG['rank-' + (rk && rk.id)]) {
        const im = IMG['rank-' + rk.id];
        const h = 90, w = im.width * (h / im.height);
        drawImg(g, im, (W - w) / 2, 35, w, h);
      }
      centre(g, 'THE UNITED STATES ARMY', 165, 21, 'bold', '#16161a', W);
      let heading = title.toUpperCase(), line2 = 'TO';
      let body = 'For outstanding service to the 7th Cavalry Regiment. The dedication shown reflects great credit upon the recipient and the Regiment.';
      if (r.kind === 'record' && r.typeId === Model.PROMOTION) {
        heading = 'CERTIFICATE OF PROMOTION'; line2 = '';
        body = String(r.details || '').replace(/^Promoted to /, 'Is hereby promoted to ') + ', effective ' + r.date + '.';
      } else if (r.kind === 'record' && r.typeId === Model.GRADUATION) {
        heading = 'CERTIFICATE OF GRADUATION'; line2 = '';
        body = String(r.details || '').replace(/^Graduated /, 'Has successfully completed ') + '.';
      } else if (r.kind === 'award' && r.typeId === 40) {
        body = 'For successfully completing initial entry training and joining the ranks of the 7th Cavalry Regiment.';
      }
      centre(g, heading, 212, 25, 'regular', '#16161a', W);
      if (line2) centre(g, line2, 240, 12, 'regular', '#16161a', W);
      const who = ((rk ? rk.title + ' ' : '') + m.fullName).toUpperCase();
      textBlock(g, { x: 20, y: 250, w: 600, h: 26, font: 'bold', size: 20, align: 'center', color: '#3a3a3a' }, who);
      textBlock(g, { x: 70, y: 300, w: 500, h: 260, font: 'regular', size: 15, align: 'justify', multiline: true, color: '#16161a' }, body, 1.6);
      const [y, mo, d] = r.date.split('-').map(Number);
      centre(g, `GIVEN UNDER MY HAND ON THIS ${Model.ordinal(d)} DAY OF ${Model.MONTHS[mo - 1]}, ${y}`.toUpperCase(), 610, 12, 'regular', '#16161a', W);
      drawSignature(g, { x: 236, y: 630, w: 169, h: 92 }, { ink: 'inkB', lines: ['General Hugo Radcliffe', 'Regimental Commander', '7th Cavalry Regiment'] });
      drawImg(g, IMG['seal-left'], 42, 650, 70, 70);
      drawImg(g, IMG['seal-right'], 528, 650, 70, 70);
    }
    legacyCache[r.id] = c;
    return c;
  }
  function uploadedImage(dataUrl) {
    return new Promise(res => { const im = new Image(); im.onload = () => { const c = canvas(im.width, im.height); c.getContext('2d').drawImage(im, 0, 0); res(c); }; im.src = dataUrl; });
  }

  // --- names and opening ---------------------------------------------------------------------

  function slug(t) {
    return String(t || '').normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase()
      .replace(/&/g, ' ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }
  function renderFilename(s, grant, gm) {
    const who = gm ? slug(gm.name) || String(gm.id) : '7th-cavalry-regiment';
    return `${who}-${slug(Model.typeTitle(s, grant.kind, grant.typeId))}-${grant.date}.jpg`;
  }
  function renderUrl(s, grant, gm) {
    const key = gm ? `${gm.id}/${gm.token}` : `regiment/${grant.id}/${grant.token}`;
    return `https://7cav.us/citations/render/${key}/${grant.revision}/${renderFilename(s, grant, gm)}`;
  }

  // Opens an image the way the roster's Citation link does: in a new tab, on the browser's
  // own backdrop. Falls back to an in-page viewer if the browser blocks the new tab.
  function openImage(c, filename, fallback) {
    const dataUrl = c.toDataURL('image/jpeg', 0.85);
    const w = window.open('', '_blank');
    if (!w) { fallback && fallback(dataUrl, filename, c); return; }
    const doc = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, minimum-scale=0.1">
<title>${filename} (${c.width}×${c.height})</title>
<style>html,body{margin:0;height:100%;background:#0e0e0e}body{display:flex;align-items:center;justify-content:center}
img{display:block;max-width:100%;max-height:100vh;cursor:zoom-in;user-select:none;-webkit-user-select:none}
body.z{display:block}body.z img{max-width:none;max-height:none;cursor:zoom-out;margin:auto}</style></head>
<body><img src="${dataUrl}" alt="${filename}" onclick="document.body.classList.toggle('z')"></body></html>`;
    w.document.open(); w.document.write(doc); w.document.close();
  }

  return { init, IMG, plate, drawSignature, renderGrant, legacyImage, uploadedImage, renderFilename, renderUrl, openImage, toPt, makeInk };
})();
