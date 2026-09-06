// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// Drive the glpipalette command palette: open, search records, scope, jump,
// commands, keyboard navigation, and recents.
const { chromium } = require('playwright');

const BASE = 'http://localhost:8081';
const SHOTS = process.env.SHOT_DIR || '.';

const fail = [];
function check(name, cond, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + String(detail).slice(0, 160) : ''}`);
  if (!cond) fail.push(name);
}

const dump = (page) =>
  page.evaluate(() => {
    const ov = document.querySelector('.glpipalette-overlay');
    if (!ov) return { open: false, groups: [], rows: [] };
    return {
      open: ov.classList.contains('is-open'),
      groups: Array.from(document.querySelectorAll('.glpipalette-group')).map((g) => g.textContent.trim()),
      rows: Array.from(document.querySelectorAll('.glpipalette-row')).map((r) => ({
        title: r.querySelector('.glpipalette-title')?.textContent.trim(),
        sub: r.querySelector('.glpipalette-subtitle')?.textContent.trim() || '',
        active: r.classList.contains('is-active'),
      })),
    };
  });

async function type(page, text) {
  await page.fill('.glpipalette-input', '');
  await page.type('.glpipalette-input', text, { delay: 15 });
  await page.waitForTimeout(1400);
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));

  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', 'glpi');
  await page.fill('input[type=password]', 'glpi');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
  await page.goto(`${BASE}/front/central.php`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);

  // --- 1. Opens on Ctrl+K ----------------------------------------------
  await page.keyboard.press('Control+k');
  await page.waitForTimeout(900);
  let s = await dump(page);
  check('opens with Ctrl+K', s.open, `groups=${s.groups}`);
  check('shows commands when empty', s.groups.includes('Commands'), s.groups.join('/'));

  // --- 2. Record search -------------------------------------------------
  await type(page, 'laptop');
  s = await dump(page);
  const hasRecords = s.groups.some((g) => /ticket/i.test(g));
  check('finds ticket records', hasRecords, s.groups.join(' / '));
  check('record rows have titles', s.rows.some((r) => /laptop/i.test(r.title || '')),
    s.rows.map((r) => r.title).join(' | '));
  await page.screenshot({ path: `${SHOTS}/palette-01-search.png` });

  // --- 3. Commands mode -------------------------------------------------
  await type(page, '>ticket');
  s = await dump(page);
  check('">" filters to commands only', s.groups.length > 0 && !s.groups.some((g) => /^Tickets?$/i.test(g)),
    s.groups.join(' / '));
  check('command matches found', s.rows.length > 0, s.rows.slice(0, 3).map((r) => r.title).join(' | '));
  await page.screenshot({ path: `${SHOTS}/palette-02-commands.png` });

  // --- 4. Type scoping --------------------------------------------------
  // Use a term that matches across several types unscoped, so the check is
  // proving the scope narrowed it rather than that nothing matched at all.
  await type(page, 'laptop');
  const unscoped = (await dump(page)).groups;
  await type(page, 'computer: laptop');
  s = await dump(page);
  check('type scoping narrows to the scoped type',
    s.groups.some((g) => /computer/i.test(g)) && !s.groups.some((g) => /ticket/i.test(g)),
    `unscoped=[${unscoped.join(',')}] scoped=[${s.groups.join(',')}]`);

  // --- 5. Direct id jump ------------------------------------------------
  await type(page, '#47');
  s = await dump(page);
  check('#id offers a jump', s.groups.some((g) => /jump/i.test(g)), s.groups.join(' / '));

  // --- 6. Keyboard navigation ------------------------------------------
  await type(page, 'laptop');
  await page.keyboard.press('ArrowDown');
  await page.waitForTimeout(200);
  s = await dump(page);
  const activeIdx = s.rows.findIndex((r) => r.active);
  check('arrow keys move selection', activeIdx === 1, `active index=${activeIdx}`);

  // --- 7. Enter navigates ----------------------------------------------
  const target = s.rows[activeIdx];
  await page.keyboard.press('Enter');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);
  const url = page.url();
  check('Enter navigates to the item', /form\.php\?id=\d+/.test(url), url);

  // --- 8. Recents -------------------------------------------------------
  await page.keyboard.press('Control+k');
  await page.waitForTimeout(900);
  s = await dump(page);
  check('recent items remembered', s.groups.includes('Recent'),
    `${s.groups.join(' / ')} | picked=${target && target.title}`);
  await page.screenshot({ path: `${SHOTS}/palette-03-recents.png` });

  // --- 9. Escape closes -------------------------------------------------
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  s = await dump(page);
  check('Escape closes', !s.open);

  check('no page errors', errs.length === 0, errs.join(' | '));

  await browser.close();
  console.log(fail.length ? `\n${fail.length} FAILED: ${fail.join(', ')}` : '\nall checks passed');
  process.exit(fail.length ? 1 : 0);
})().catch((e) => { console.error('ERROR:', e.message); process.exit(1); });
