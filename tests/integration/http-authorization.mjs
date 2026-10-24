/**
 * Real-WordPress HTTP authorization pass for disposable localhost labs, run by
 * http-authorization-lab.sh. The owner signs in through wp-login.php with
 * KEEL_HTTP_LAB_PASSWORD, so no runner-specific login route is needed.
 * Tokens remain in memory.
 */
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { createHash } from 'node:crypto';
const { chromium } = createRequire(import.meta.url)('playwright');
const [single, multi, output] = process.argv.slice(2);
assert.ok(output, 'Pass single-site URL, multisite URL and a new output JSON path.');
const bases = [single, multi];
assert.equal(bases.length, 2, 'Pass single-site and multisite localhost URLs.');
for (const base of bases) assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname));
const ownerPassword = process.env.KEEL_HTTP_LAB_PASSWORD || '';
assert.ok(ownerPassword.length >= 24, 'Set KEEL_HTTP_LAB_PASSWORD to the value the setup script used.');
const expectedSourceHash = createHash('sha256').update(await fs.readFile(new URL('../../includes/settings-page.php', import.meta.url))).digest('hex');
// Reserve a new artifact before mutating either lab. Existing evidence is never overwritten.
const artifact = await fs.open(output, 'wx', 0o600);
const browser = await chromium.launch();
const results = [];
const actors = [];
async function info(ctx, base) {
	const response = await ctx.request.get(base + '/wp-admin/admin-ajax.php?action=keel_http_lab');
	const json = await response.json();
	assert.equal(json.success, true, 'Fixture context available to authenticated user.');
	return json.data;
}
try {
	for (const [index, base] of bases.entries()) {
		const root = await browser.newContext();
		const page = await root.newPage();
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		await page.goto(base + '/wp-login.php?redirect_to=' + encodeURIComponent(base + '/wp-admin/'));
		await page.fill('#user_login', 'keel_http_owner');
		await page.fill('#user_pass', ownerPassword);
		await Promise.all([page.waitForURL(url => url.pathname.startsWith('/wp-admin/')), page.click('#wp-submit')]);
		await page.waitForLoadState('load');
		assert.equal(errors.length, 0, 'Admin baseline has no page errors.');
		const owner = await info(root, base);
		assert.equal(owner.state.minor, 'disabled', 'Run setup before the lab so the authorized control must change persisted state.');
		assert.equal(owner.multisite, index === 1);
		assert.equal(owner.source_hash, expectedSourceHash, 'Runtime sanitizer matches this checkout irrespective of folder name.');
		const storage = await root.storageState();
		async function probe(ctx, profile, name, path, form, expected, unchanged = true, method = 'POST') {
			const before = await info(root, base);
			const response = method === 'GET'
				? await ctx.request.get(base + path, { maxRedirects: 0 })
				: await ctx.request.post(base + path, { form, maxRedirects: 0 });
			const body = await response.text();
			assert.ok(expected.includes(response.status()), `${profile}/${name}: unexpected HTTP ${response.status()}`);
			assert.ok(!/(?:Fatal error|Warning:|Uncaught (?:TypeError|Error))/.test(body), `${profile}/${name}: PHP warning or error`);
			const after = await info(root, base);
			if (unchanged) assert.deepEqual(after.state, before.state, `${profile}/${name}: protected state changed`);
			results.push({ lab: index ? 'multisite' : 'single', profile, name, status: response.status(), protectedStateUnchanged: unchanged ? true : null });
			return { response, body, after };
		}
		for (const profile of ['subscriber', 'editor', 'admin', 'delegate', 'owner']) {
			const ctx = await browser.newContext({ storageState: storage });
			if (profile !== 'owner') {
				const response = await ctx.request.post(base + '/wp-admin/admin-ajax.php', { form: { action: 'keel_http_lab', op: 'assume', login: 'keel_http_' + profile, _ajax_nonce: owner.nonces.control } });
				assert.equal((await response.json()).success, true, 'Fixture issues real user session.');
			}
			const user = await info(ctx, base);
			if (profile !== 'owner') assert.ok(user.roles.includes(profile === 'delegate' || profile === 'admin' ? 'administrator' : profile));
			if (index && profile === 'delegate') assert.deepEqual(user.caps, [true, false, true, false], 'Synthetic delegate retains site authority without network authority.');
			actors.push({ lab: index ? 'multisite' : 'single', profile, roles: user.roles, capabilities: user.caps, wordpress: user.wordpress, php: user.php });
			const allowed = user.caps[2] && (!index || user.caps[1]);
			const exportAllowed = user.caps[0] && user.caps[3] && (!index || user.caps[1]);
			await probe(ctx, profile, 'site-settings-bad-nonce', '/wp-admin/options.php', { option_page: 'keel_settings_group', action: 'update', _wpnonce: 'invalid' }, [403]);
			if (!user.caps[0]) await probe(ctx, profile, 'site-settings-valid-nonce-denied', '/wp-admin/options.php', { option_page: 'keel_settings_group', action: 'update', _wpnonce: user.nonces.options }, [403]);
			await probe(ctx, profile, 'export-get', '/wp-admin/admin-post.php?action=keel_posture_export', {}, [405], true, 'GET');
			await probe(ctx, profile, 'export-bad-nonce', '/wp-admin/admin-post.php', { action: 'keel_posture_export', _wpnonce: 'invalid' }, [403]);
			if (profile !== 'owner') await probe(ctx, profile, 'export-other-session-nonce', '/wp-admin/admin-post.php', { action: 'keel_posture_export', _wpnonce: owner.nonces.export }, [403]);
			const exported = await probe(ctx, profile, 'export-valid-nonce', '/wp-admin/admin-post.php', { action: 'keel_posture_export', _wpnonce: user.nonces.export }, exportAllowed ? [200] : [403]);
			if (exportAllowed) {
				const report = JSON.parse(exported.body);
				assert.ok(report.policies);
				assert.match(exported.response.headers()['cache-control'], /no-store/);
				assert.equal(exported.response.headers()['x-content-type-options'], 'nosniff');
			}
			await probe(ctx, profile, 'minor-bad-nonce', '/wp-admin/admin-post.php', { action: 'keel_defaults_enable_minor_updates', _wpnonce: 'invalid' }, [403]);
			if (!allowed) await probe(ctx, profile, 'minor-valid-nonce-denied', '/wp-admin/admin-post.php', { action: 'keel_defaults_enable_minor_updates', _wpnonce: user.nonces.minor }, [403]);
			for (const action of ['health-check-keel-backport', 'health-check-keel-autoload', 'health-check-keel-cron']) {
				await probe(ctx, profile, action + '-bad-nonce', '/wp-admin/admin-ajax.php', { action, _wpnonce: 'invalid' }, [403]);
				if (!user.caps[3]) await probe(ctx, profile, action + '-valid-nonce-denied', '/wp-admin/admin-ajax.php', { action, _wpnonce: user.nonces.health }, [403]);
			}
			if (index) {
				await probe(ctx, profile, 'network-bad-nonce', '/wp-admin/network/settings.php?page=keel-network', { keel_network_nonce: 'invalid' }, [403]);
				if (!user.caps[1]) await probe(ctx, profile, 'network-valid-nonce-denied', '/wp-admin/network/settings.php?page=keel-network', { keel_network_nonce: user.nonces.network }, [403]);
			}
			await ctx.close();
		}
		const anon = await browser.newContext();
		await probe(anon, 'anonymous', 'export-post', '/wp-admin/admin-post.php', { action: 'keel_posture_export', _wpnonce: 'invalid' }, [403]);
		await probe(anon, 'anonymous', 'minor-post', '/wp-admin/admin-post.php', { action: 'keel_defaults_enable_minor_updates' }, [400]);
		await probe(anon, 'anonymous', 'collector-post', '/wp-admin/admin-ajax.php', { action: 'health-check-keel-autoload' }, [400]);
		await anon.close();
		const enabled = await probe(root, 'owner', 'minor-authorized-control', '/wp-admin/admin-post.php', { action: 'keel_defaults_enable_minor_updates', _wpnonce: owner.nonces.minor }, [302], false);
		assert.equal(enabled.after.state.minor, 'enabled', 'Authorized control actually writes the intended option.');
		await page.goto(base + (index ? '/wp-admin/network/settings.php?page=keel-network' : '/wp-admin/options-general.php?page=keel'));
		const formNonce = await page.locator(index ? 'input[name="keel_network_nonce"]' : 'input[name="_wpnonce"]').first().inputValue();
		const group = index ? null : await page.locator('input[name="option_page"]').inputValue();
		const form = index
			? { keel_network_nonce: formNonce, 'keel_network_manage[remember_me_days]': '1', 'keel_network_manage[admin_menu_width]': '1', 'keel_settings[remember_me_days][nested]': 'bad', 'keel_settings[admin_menu_width][nested]': 'bad' }
			: { option_page: group, action: 'update', _wpnonce: formNonce, _wp_http_referer: '/wp-admin/options-general.php?page=keel', 'keel_settings[remember_me_days][nested]': 'bad', 'keel_settings[admin_menu_width][nested]': 'bad', 'keel_settings[password_exempt_roles][0][nested]': 'bad' };
		const saved = await probe(root, 'owner', 'malformed-settings-control', index ? '/wp-admin/network/settings.php?page=keel-network' : '/wp-admin/options.php', form, [302], false);
		const settings = index ? saved.after.state.network : saved.after.state.settings;
		assert.equal(settings.remember_me_days, 14);
		assert.equal(settings.admin_menu_width, 'default');
		if (!index) assert.deepEqual(settings.password_exempt_roles, []);
		await root.close();
	}
	await artifact.writeFile(JSON.stringify({ capabilityOrder: ['manage_options', 'manage_network_options', 'update_core', 'view_site_health_checks'], actors, cases: results }, null, 2) + '\n');
	console.log(JSON.stringify({ cases: results.length, labs: 2, failures: 0 }));
} finally { await browser.close(); await artifact.close(); }
