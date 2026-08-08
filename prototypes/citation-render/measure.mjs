import { chromium } from 'playwright';
const file = 'file://' + process.cwd() + '/citation-prototype.html';
const b = await chromium.launch(); const p = await b.newPage();
for (const v of ['faithful','typeset']) {
  await p.goto(`${file}?variant=${v}`); await p.waitForTimeout(300);
  const r = await p.evaluate(() => {
    const el = document.getElementById('body');
    const cs = getComputedStyle(el);
    const inner = el.firstElementChild;
    const lines = Math.round(inner.getBoundingClientRect().height / parseFloat(cs.lineHeight));
    const chars = document.querySelector('#body').innerText.replace(/\s+/g,' ').length;
    return { fontSize: cs.fontSize, lineHeight: cs.lineHeight, boxW: cs.width,
             lines, charsPerLine: Math.round(chars / lines) };
  });
  console.log(v, JSON.stringify(r));
}
await b.close();
