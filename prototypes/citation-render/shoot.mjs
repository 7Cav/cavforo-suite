import { chromium } from 'playwright';
const file = 'file://' + process.cwd() + '/citation-prototype.html';
const b = await chromium.launch();
const p = await b.newPage({ deviceScaleFactor: 1 });
for (const v of ['faithful', 'typeset', 'page']) {
  await p.goto(`${file}?variant=${v}`);
  await p.waitForTimeout(400);
  await p.addStyleTag({ content: '#bar,#state{display:none!important}' });  // prototype chrome
  await (await p.$('.sheet, .v-page')).screenshot({ path: `out-${v}.png` });
  console.log(v, 'ok');
}
await b.close();
