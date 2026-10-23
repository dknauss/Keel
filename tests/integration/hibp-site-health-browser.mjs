/** Focused HIBP panel verification on explicitly marked disposable Studio labs. */
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
const require = createRequire(import.meta.url);
const { chromium, firefox, webkit } = require('playwright');
const [base, lab] = process.argv.slice(2);
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Local labs only.');
const config = JSON.parse(await fs.readFile(path.join(lab, 'wp-content/keel-hibp-browser.json')));
const multi = config.multisite === true;
const out = process.env.HIBP_BROWSER_OUTPUT || '/tmp/keel-hibp-browser';
await fs.mkdir(out, { recursive: true });
const rows = [];
async function expanded(button, value) {
 await button.page().waitForFunction(({ control, value }) => document.querySelector(`button[aria-controls="${control}"]`)?.getAttribute('aria-expanded') === value, { control: await button.getAttribute('aria-controls'), value });
}
const expected = {
 none: ['no sampled failures', 'does not establish that screening has run', 'No validated live response'],
 exhausted: ['sampled unavailability', 'allowance for fresh lookups was exhausted'],
 busy: ['sampled unavailability', 'lookup slots were busy'],
 transport: ['sampled unavailability', 'HTTP 429'],
 later_success: ['sampled unavailability', 'HTTP 429', 'A later live response does not erase'],
 success: ['no sampled failures', 'historical transport evidence'],
 unavailable: ['evidence is unavailable', 'could not be read', 'does not establish a Have I Been Pwned outage'],
 optout: ['switched off in code', 'deliberate configuration'],
 setting_off: ['screening is switched off', 'Strong passwords are not being required'],
}[config.case];
for (const [engine, type] of Object.entries({ chromium, firefox, webkit })) {
 const browser = await type.launch();
 try {
  for (const role of multi ? ['manager', 'delegated', ...(config.case === 'none' ? ['site', 'anonymous'] : [])] : ['manager', ...(config.case === 'none' ? ['anonymous'] : [])]) {
   const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
   const page = await context.newPage();
   const errors = []; page.on('pageerror', e => errors.push(e.message));
   await context.request.get(base + '/studio-auto-login?redirect_to=%2Fwp-admin%2F');
   if (role !== 'manager') await context.addCookies([{ name: 'keel_hibp_browser_role', value: role, url: base }]);
   const response = await page.goto(base + '/wp-admin/site-health.php');
   if (role === 'anonymous' || role === 'site') {
    assert.equal(await page.locator('[aria-controls="health-check-accordion-block-keel_defaults_breach_screening"]').count(), 0);
    assert.ok(response.status() === 403 || page.url().includes('wp-login.php'), 'Core refuses unauthorized Site Health access.');
    rows.push({ engine, role, case: config.case, refused: true });
    await context.close(); continue;
   }
   const button = page.locator('.health-check-accordion-trigger').filter({ hasText: /Password breach|breach-screening/ }).first();
   await button.waitFor({ state: 'attached' });
   await page.waitForFunction(() => document.querySelector('#a11y-speak-polite')?.textContent.includes('All site health tests have finished running'), undefined, { timeout: 60000 });
   if (!(await button.isVisible())) await page.locator('.site-health-view-passed').press('Enter');
   await button.focus();
   await button.press('Enter');
   await expanded(button, 'true');
   await button.press('Space');
   await expanded(button, 'false');
   await button.press('Enter');
   await expanded(button, 'true');
   await button.focus();
   await page.keyboard.press('Tab');
   await page.keyboard.press('Shift+Tab');
   assert.ok(await button.evaluate(el => document.activeElement === el), 'Native Tab/Shift+Tab returns to the disclosure.');
   const id = await button.getAttribute('aria-controls');
   const panel = page.locator('#' + id);
   const status = ['none', 'success', 'optout', 'setting_off'].includes(config.case) ? 'good' : 'recommended';
   assert.equal(await button.evaluate(el => el.closest('.health-check-accordion').id), 'health-check-site-status-' + status, 'Core renders the finding in the correct status group.');
   const text = (await button.innerText()) + ' ' + (await panel.innerText());
   if (role === 'delegated' && !['optout', 'setting_off'].includes(config.case)) {
    assert.match(text, /coverage is unknown/);
    assert.doesNotMatch(text, /HTTP 429|minutes? ago|allowance.*exhausted|slots were busy|last recorded|later live|sampled unavailability/);
   } else for (const snippet of expected) assert.ok(text.includes(snippet), `${engine}/${role}/${config.case}: ${snippet}`);
   if (['exhausted', 'busy'].includes(config.case)) assert.ok(!text.includes('service could not be reached'));
   const widths = [];
   for (const width of [1280, 782, 390, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    await button.scrollIntoViewIfNeeded();
    const layout = await panel.evaluate(el => ({ viewport: innerWidth, page: document.documentElement.scrollWidth, panel: el.getBoundingClientRect().toJSON(), scroll: el.scrollWidth, client: el.clientWidth }));
    assert.ok(layout.page <= width + 1, `Page overflow ${engine}/${role}/${config.case}/${width}`);
    assert.ok(layout.scroll <= layout.client + 1, `Panel overflow ${engine}/${role}/${config.case}/${width}`);
    widths.push(width);
   }
   await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
   const axe = await page.evaluate(async id => { const r = await window.axe.run({ include: [['#' + id], [`button[aria-controls="${id}"]`]] }, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } }); return r.violations.map(v => ({ id: v.id, impact: v.impact, nodes: v.nodes.length })); }, id);
   assert.deepEqual(axe, []);
   assert.deepEqual(errors, []);
   if (engine === 'chromium' && ['later_success', 'none'].includes(config.case)) {
    for (const width of [320, 1280]) {
     await page.setViewportSize({ width, height: 1000 });
     await button.scrollIntoViewIfNeeded();
     await page.evaluate(() => scrollTo(0, 0));
     await page.screenshot({ path: path.join(out, `${multi ? 'network' : 'single'}-${role}-${config.case}-${width}.png`), fullPage: true });
    }
   }
   let settingLink = false;
   if (config.case === 'setting_off') {
    const link = panel.getByRole('link', { name: 'Password Strength', exact: true });
    const target = new URL(await link.getAttribute('href'), base);
    assert.equal(target.origin, new URL(base).origin);
    await Promise.all([page.waitForURL(url => url.pathname === target.pathname && url.hash === target.hash), link.press('Enter')]);
    await page.locator(`tr:has(${target.hash})`).waitFor({ state: 'visible' });
    assert.ok(await page.locator(`tr:has(${target.hash})`).isVisible(), 'Setting link reveals its target row (the anchor itself has zero height).');
    settingLink = true;
   }
   rows.push({ engine, version: browser.version(), role, case: config.case, widths, settingLink, status, keyboard: 'Enter/Space toggle aria-expanded', axe, errors, text });
   await context.close();
  }
 } finally { await browser.close(); }
}
const records = (await fs.readFile(path.join(lab, 'wp-content/keel-hibp-browser.jsonl'), 'utf8')).trim().split('\n').filter(Boolean).map(s => JSON.parse(s));
assert.ok(records.length > 0, 'Instrumentation observed real WordPress requests.');
assert.ok(records.every(r => r.http === 0 && r.writes === 0 && r.digest === config.digest), 'Viewing must not call HIBP, write its authority, or alter its stored snapshot.');
const report = { site: multi ? 'multisite' : 'standalone', case: config.case, transport: 'synthetic evidence; external HTTP blocked', requests: records.length, http: 0, writes: 0, authorityUnchanged: true, rows };
await fs.writeFile(path.join(out, `${report.site}-${config.case}.json`), JSON.stringify(report, null, 2) + '\n');
console.log(JSON.stringify({ site: report.site, case: report.case, panels: rows.length, requests: records.length, http: 0, writes: 0, authorityUnchanged: true }));
