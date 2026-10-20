/**
 * Browser regression pass for the disposable Studio lock UX fixture.
 * Requires playwright and @axe-core/playwright (local modules or NODE_PATH).
 * See docs/review/settings-lock-ux-2026-10-20.md for the fixture and run commands.
 * No WordPress options are written by this script.
 */
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
const require = createRequire( import.meta.url );
const { chromium } = require( 'playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const args = process.argv.slice( 2 );
const base = args.find( ( value ) => value.startsWith( 'http' ) ) || 'http://localhost:8889';
assert.ok( [ 'localhost', '127.0.0.1' ].includes( new URL( base ).hostname ), 'Disposable local sites only.' );
const out = fs.mkdtempSync( path.join( os.tmpdir(), 'keel-lock-ux-' ) );
const browser = await chromium.launch( {
	executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
} );
try {
	const context = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
	const page = await context.newPage();
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	await page.goto( base + '/studio-auto-login?redirect_to=%2Fwp-admin%2F' );
	await page.waitForURL( '**/wp-admin/**' );
	for ( const [ screen, url, count ] of [
		[ 'site', '/wp-admin/options-general.php?page=keel', 7 ],
		[ 'network', '/wp-admin/network/settings.php?page=keel-network', 3 ],
	] ) {
		await page.goto( base + url );
		await page.waitForSelector( '#wpbody-content .wrap' );
		if ( args.includes( '--unlocked' ) ) {
			assert.equal( await page.locator( '.keel-lock-summary, .keel-lock-marker' ).count(), 0 );
			continue;
		}
		assert.equal( await page.locator( '.keel-lock-marker' ).count(), count );
		assert.equal( await page.locator( '.keel-lock-summary a' ).count(), count );
		for ( const width of [ 1280, 640, 390 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			assert.ok( await page.evaluate( () => document.documentElement.scrollWidth <= innerWidth ) );
			const result = await new AxeBuilder( { page } ).include( '#wpbody-content .wrap' )
				.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice' ] ).analyze();
			assert.deepEqual( result.violations.map( ( violation ) => ( {
				id: violation.id, targets: violation.nodes.map( ( node ) => node.target ),
			} ) ), [] );
			await page.screenshot( { path: path.join( out, `${ screen }-${ width }.png` ), fullPage: true } );
		}
		if ( 'site' === screen ) {
			for ( const key of [ 'xmlrpc-allow-pingbacks', 'remember-me-days' ] ) {
				const target = page.locator( `#keel-setting-${ key }` );
				await page.locator( `.keel-lock-summary a[href="#keel-setting-${ key }"]` ).focus();
				await page.keyboard.press( 'Enter' );
				assert.ok( await target.evaluate( ( element ) => element.closest( '.keel-dep-item, tr' ).getBoundingClientRect().height > 0 ) );
				await page.waitForFunction( () => document.activeElement.hasAttribute( 'data-keel-locked' ) );
				assert.ok( await page.evaluate( () => document.activeElement.getBoundingClientRect().top >= document.getElementById( 'wpadminbar' ).getBoundingClientRect().bottom ), 'The focused control clears the admin bar.' );
				assert.ok( await target.evaluate( ( element ) => {
					const row = element.closest( '.keel-dep-item, tr' );
					return Array.from( document.querySelectorAll( '[aria-controls]' ) )
						.filter( ( control ) => control.getAttribute( 'aria-controls' ).split( ' ' ).includes( row.id ) )
						.every( ( control ) => 'true' === control.getAttribute( 'aria-expanded' ) );
				} ) );
			}
			const slider = page.locator( 'input[type="range"][data-keel-locked]' );
			const value = await slider.inputValue();
			await slider.focus();
			await page.keyboard.press( 'ArrowRight' );
			assert.equal( await slider.inputValue(), value );
		}
	}
	assert.deepEqual( errors, [] );
	console.log( `Lock UX browser checks passed. Screenshots: ${ out }` );
} finally {
	await browser.close();
}
