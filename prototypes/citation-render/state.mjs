import { chromium } from 'playwright';
const file = 'file://' + process.cwd() + '/citation-prototype.html';
const b = await chromium.launch(); const p = await b.newPage();
for (const v of ['faithful','typeset','page']) {
  await p.goto(`${file}?variant=${v}`); await p.waitForTimeout(300);
  console.log((await p.$eval('#state', e => e.textContent)).replace(/\n/g,' | '));
}
await b.close();
