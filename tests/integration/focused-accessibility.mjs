/**
 * Focused Updates/settings accessibility regression against disposable local labs.
 * See docs/review/focused-accessibility-2026-10-23.md for fixture setup and limits.
 * Requires playwright and axe-core through local modules or NODE_PATH.
 * Arguments: single-site URL, then multisite URL. Does not submit settings.
 */
import { createRequire } from 'node:module';
import os from 'node:os';
import path from 'node:path';
const { chromium, firefox, webkit } = createRequire(import.meta.url)('playwright');
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
const out = await fs.mkdtemp(path.join(os.tmpdir(), 'keel-focused-a11y-'));
const rows = [];
const axePath = createRequire(import.meta.url).resolve('axe-core/axe.min.js');
const bases = process.argv.slice(2).filter((arg) => arg.startsWith('http'));
assert.ok(bases.length, 'Pass disposable single-site and/or multisite lab URLs.');
for (const base of bases)
	assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Local labs only.');
// Wait for native keyboard scrolling to settle before testing another focus move.
async function settleScroll(page) {
	await page.evaluate(
		() =>
			new Promise((resolve) => {
				let last = scrollY,
					stable = 0;
				function frame() {
					const next = scrollY;
					stable = next === last ? stable + 1 : 0;
					last = next;
					if (stable >= 6) resolve();
					else requestAnimationFrame(frame);
				}
				requestAnimationFrame(frame);
			})
	);
}
for (const [engine, type] of Object.entries({ chromium, firefox, webkit })) {
	const browser = await type.launch();
	try {
		for (const base of bases) {
			const port = Number(new URL(base).port);
			const networkSite = base === bases[1];
			const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
			const page = await context.newPage();
			const errors = [];
			page.on('pageerror', (e) => errors.push(e.message));
			await page.goto(base + '/studio-auto-login?redirect_to=%2Fwp-admin%2F');
			for (const locale of ['en_US', 'ar']) {
				await context.addCookies([{ name: 'keel_a11y_locale', value: locale, url: base }]);
				for (const screen of ['updates', 'health', 'settings', ...(networkSite ? ['network'] : [])]) {
					const path =
						screen === 'updates'
							? networkSite
								? '/wp-admin/network/update-core.php'
								: '/wp-admin/update-core.php'
							: screen === 'health'
								? '/wp-admin/site-health.php?keel-update-diagnostics=1'
								: screen === 'network'
									? '/wp-admin/network/settings.php?page=keel-network'
									: '/wp-admin/options-general.php?page=keel';
					const ready = {
						updates: '.keel-update-policy-notice',
						health: '.keel-defaults-update-table-scroll'
					};
					await page.goto(base + path);
					await page.locator(ready[screen] || '.keel-lock-summary').first().waitFor();
					assert.equal(
						await page.locator('html').evaluate((e) => getComputedStyle(e).direction),
						locale === 'ar' ? 'rtl' : 'ltr'
					);
					const row = { engine, version: browser.version(), port, locale, screen, widths: [] };
					assert.equal(
						await page.locator('script[src*="/assets/js/update-evidence.js"]').count(),
						['updates', 'health'].includes(screen) ? 1 : 0,
						'Keyboard helper loads only on Updates and Site Health.'
					);
					if (screen === 'updates') {
						const notice = page.locator('.keel-update-policy-notice');
						assert.ok(
							await notice.evaluate((n) => {
								const heading = document.querySelector('.wp-current-version');
								return (
									!heading ||
									!!(n.compareDocumentPosition(heading) & Node.DOCUMENT_POSITION_FOLLOWING)
								);
							}),
							'Policy notice sits above Current version.'
						);
						assert.equal(await page.locator('.keel-defaults-update-table-scroll').count(), 0);
						row.policyAX = await notice.ariaSnapshot();
					} else if (screen === 'health') {
						const trigger = page.locator(
							'button[aria-controls="health-check-accordion-block-keel_update_diagnostics"]'
						);
						assert.equal(await trigger.getAttribute('aria-expanded'), 'true', 'Deep link opens diagnostics.');
						assert.equal(await trigger.evaluate((t) => document.activeElement === t), true);
						const diag = page.locator('#health-check-accordion-block-keel_update_diagnostics');
						await page.keyboard.press('Tab');
						assert.equal(
							await page.evaluate(() => document.activeElement.className),
							'keel-defaults-update-table-scroll'
						);
						row.diagnosticsAX = await diag.ariaSnapshot();
						for (const region of await page.locator('.keel-defaults-update-table-scroll').all()) {
							assert.ok(
								await region.evaluate((r) => {
									const before = r.scrollLeft;
									const modified = new KeyboardEvent('keydown', {
										key: 'ArrowRight',
										ctrlKey: true,
										bubbles: true,
										cancelable: true
									});
									const child = new KeyboardEvent('keydown', {
										key: 'ArrowRight',
										bubbles: true,
										cancelable: true
									});
									const escape = new KeyboardEvent('keydown', {
										key: 'Escape',
										bubbles: true,
										cancelable: true
									});
									r.dispatchEvent(modified);
									r.querySelector('th').dispatchEvent(child);
									r.dispatchEvent(escape);
									return (
										!modified.defaultPrevented &&
										!child.defaultPrevented &&
										!escape.defaultPrevented &&
										before === r.scrollLeft
									);
								}),
								'Modifier keys, descendants and unrelated keys retain their behavior.'
							);
						}

					} else {
						const form = page.locator('#wpbody-content .wrap');
						row.controlAX = await form.ariaSnapshot();
						for (const control of await page.locator('[data-keel-locked]:visible').all()) {
							const disabled = await control.getAttribute('aria-disabled');
							assert.equal(disabled, 'true');
							const description = await control.getAttribute('aria-describedby');
							assert.ok(description);
							assert.ok(
								await control.evaluate(
									(el, ids) => ids.split(' ').every((id) => document.getElementById(id)),
									description
								)
							);
							const old = await control.evaluate((e) => ({ value: e.value, checked: e.checked }));
							await control.focus();
							for (const key of ['Space', 'ArrowRight', 'ArrowLeft', 'Home', 'End'])
								await page.keyboard.press(key);
							await settleScroll(page);
							assert.deepEqual(await control.evaluate((e) => ({ value: e.value, checked: e.checked })), old);
							await page.keyboard.press('Tab');
							assert.equal(await control.evaluate((e) => document.activeElement === e), false);
						}
						const checkbox = page.locator('input[type=checkbox][data-keel-locked]:visible').first();
						assert.ok(await checkbox.count(), 'Fixture must expose a locked checkbox.');
						await checkbox.focus();
						const beforePageDown = await page.evaluate(() => scrollY);
						await page.keyboard.press('PageDown');
						await page.waitForFunction((before) => scrollY > before, beforePageDown);
						await settleScroll(page);
						assert.equal(
							await checkbox.evaluate((e) =>
								e.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', cancelable: true }))
							),
							true,
							'Escape is not swallowed.'
						);
						if (screen === 'settings') {
							for (const key of ['xmlrpc-allow-pingbacks', 'remember-me-days']) {
								const link = page.locator(`.keel-lock-summary a[href="#keel-setting-${key}"]`);
								if (!(await link.count())) continue;
								await link.focus();
								await page.keyboard.press('Enter');
								await page.waitForFunction(
									(key) =>
										document
											.getElementById('keel-setting-' + key)
											.closest('.keel-dep-item,tr')
											.contains(document.activeElement),
									key
								);
								await page.waitForFunction(
									() =>
										document.activeElement.getBoundingClientRect().top >=
										document.getElementById('wpadminbar').getBoundingClientRect().bottom
								);
								assert.ok(
									await page.evaluate(
										() =>
											document.activeElement.getBoundingClientRect().top >=
											document.getElementById('wpadminbar').getBoundingClientRect().bottom
									),
									JSON.stringify({
										engine,
										locale,
										key,
										geometry: await page.evaluate(() => ({
											control: document.activeElement.getBoundingClientRect().top,
											bar: document.getElementById('wpadminbar').getBoundingClientRect().bottom,
											scroll: scrollY
										}))
									})
								);
							}
							const controller = page.locator('[name="keel_settings[block_xmlrpc_endpoint]"][type=checkbox]');
							await controller.focus();
							await page.keyboard.press('Space');
							assert.equal(await controller.getAttribute('aria-expanded'), 'true');
							for (const id of (await controller.getAttribute('aria-controls')).split(' '))
								assert.ok(await page.locator('#' + id).isVisible());
							await page.keyboard.press('Space');
							assert.equal(await controller.getAttribute('aria-expanded'), 'false');
							for (const id of (await controller.getAttribute('aria-controls')).split(' '))
								assert.equal(await page.locator('#' + id).isVisible(), false);
							const slider = page.locator('#admin_menu_width-range');
							if (!(await slider.getAttribute('data-keel-locked'))) {
								await slider.focus();
								await page.keyboard.press('End');
								assert.equal(
									await slider.getAttribute('aria-valuetext'),
									await page.locator('#admin_menu_width-output').textContent()
								);
							}
						}
					}
					for (const width of [1280, 782, 390, 320]) {
						await page.setViewportSize({ width, height: 900 });
						const geometry = await page.evaluate(() => ({
							width: innerWidth,
							scroll: document.documentElement.scrollWidth,
							direction: getComputedStyle(document.body).direction
						}));
						assert.ok(
							geometry.scroll <= width + 1,
							JSON.stringify({ engine, port, locale, screen, ...geometry })
						);
						if (screen === 'health') {
							for (const region of await page.locator('.keel-defaults-update-table-scroll').all()) {
								await region.focus();
								const initial = await region.evaluate((r) => ({
									left: r.scrollLeft,
									width: r.clientWidth,
									scroll: r.scrollWidth,
									outline: getComputedStyle(r).outline
								}));
								assert.ok(initial.outline.includes('2px'), JSON.stringify(initial));
								await page.keyboard.press(locale === 'ar' ? 'ArrowLeft' : 'ArrowRight');
								await page.waitForTimeout(200);
								if (initial.scroll > initial.width + 1)
									assert.notEqual(
										await region.evaluate((r) => r.scrollLeft),
										initial.left,
										'Overflowed table can be scrolled by keyboard'
									);
							}
						}
						if (engine === 'webkit' && [1280, 320].includes(width))
							await page.screenshot({
								path: `${out}/${engine}-${port}-${locale}-${screen}-${width}.png`,
								fullPage: true
							});
						row.widths.push(geometry);
					}
					await page.setViewportSize({ width: 640, height: 900 });
					assert.ok(
						await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
						'640px reflow overflow'
					);
					await page.addScriptTag({ path: axePath });
					const selector =
						screen === 'updates'
							? '.keel-update-policy-notice'
							: screen === 'health'
								? '#health-check-accordion-block-keel_update_diagnostics'
								: '#wpbody-content .wrap';
					const axeResult = await page.evaluate(
						async (selector) =>
							await axe.run(selector, {
								runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] }
							}),
						selector
					);
					row.violations = axeResult.violations.map((v) => ({
						id: v.id,
						nodes: v.nodes.map((n) => ({ target: n.target, summary: n.failureSummary }))
					}));
					row.incomplete = axeResult.incomplete.map((v) => v.id);
					await fs.writeFile(`${out}/progress.json`, JSON.stringify([...rows, row], null, 2));
					assert.deepEqual(
						row.violations,
						[],
						JSON.stringify({ engine, port, locale, screen, violations: row.violations })
					);
					rows.push(row);
				}
			}
			assert.deepEqual(errors, []);
			await context.close();
		}
	} finally {
		await browser.close();
	}
}
await fs.writeFile(`${out}/results.json`, JSON.stringify(rows, null, 2));
console.log(`PASS: ${rows.length} browser/site/locale/screen combinations; evidence: ${out}`);
