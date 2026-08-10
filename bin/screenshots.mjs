/**
 * Recapture the wordpress.org listing screenshots.
 *
 * The three PNGs in .wordpress-org/ are both the directory listing images and
 * the ones README.md shows, so they go stale the moment the settings screen or
 * the Site Health surface changes — and a stale screenshot is invisible from
 * inside the repository. This existed once as a throwaway script in a temp
 * directory, which meant "retake the screenshots" was a research task rather
 * than a command. Now it is a command.
 *
 *   node bin/screenshots.mjs --url http://localhost:8881 --wp @keel
 *
 * Requires Playwright (`npm i playwright`) and a WordPress install with the
 * current code and a WP-CLI alias that reaches it. It mints its own admin
 * session, so nothing has to be logged in first, and it never writes the cookie
 * anywhere — same posture as the integration probes.
 *
 * Run `composer verify:screenshots` afterwards to confirm they are no longer
 * older than the code they depict.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const args = process.argv.slice( 2 );
const arg = ( name, fallback ) => {
	const i = args.indexOf( `--${ name }` );
	return i === -1 ? fallback : args[ i + 1 ];
};

const base = arg( 'url', 'http://localhost:8881' );
const alias = arg( 'wp', '@keel' );
const out = path.resolve( fileURLToPath( new URL( '../.wordpress-org', import.meta.url ) ) );

// Mint a session through WP-CLI. Two cookies, not one: wp-admin validates the
// auth cookie while REST accepts logged_in alone, and sending only the latter
// silently redirects every capture to wp-login.php.
const wp = ( php ) => {
	const out = execFileSync( 'wp', [ alias, 'eval', php ], { encoding: 'utf8' } )
		.split( '\n' )
		.map( ( l ) => l.trim() )
		// WP-CLI prints PHP deprecation notices on some toolchains; the payload is
		// the last non-empty line that is not one of those.
		.filter( ( l ) => l && ! /^(Deprecated|Warning|Notice):/.test( l ) );

	const payload = out.pop();

	if ( ! payload ) {
		throw new Error( `No output from \`wp ${ alias } eval\`. Is the alias correct and the site reachable?` );
	}

	return payload;
};

// JSON, not a delimited string. A WordPress auth cookie value is itself
// pipe-separated — `admin|1786344862|CUzh...` — so any single-character
// delimiter chosen without looking at the data shreds the value it is meant to
// carry. This is what broke the first version of this script.
const cookieLine = wp( `
$u   = get_users( array( "role" => "administrator", "number" => 1 ) );
$uid = $u ? $u[0]->ID : 1;
$exp = time() + 3600;
$tok = WP_Session_Tokens::get_instance( $uid )->create( $exp );
echo wp_json_encode(
	array(
		AUTH_COOKIE      => wp_generate_auth_cookie( $uid, $exp, "auth", $tok ),
		LOGGED_IN_COOKIE => wp_generate_auth_cookie( $uid, $exp, "logged_in", $tok ),
	)
);
` );

const domain = new URL( base ).hostname;
const cookies = Object.entries( JSON.parse( cookieLine ) ).map(
	( [ name, value ] ) => ( { name, value, domain, path: '/' } )
);

if ( 2 !== cookies.length ) {
	throw new Error( `Expected two cookies, got ${ cookies.length }.` );
}

const browser = await chromium.launch();
const ctx = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
await ctx.addCookies( cookies );
const page = await ctx.newPage();

// Hide the admin chrome. The screenshots are about the plugin, not the sidebar,
// and a full-width frame is what the wordpress.org listing wants.
const tidy = () =>
	page.addStyleTag( {
		content: `
			#adminmenumain, #wpadminbar, #wpfooter, .notice, #screen-meta-links { display: none !important; }
			#wpcontent, #wpbody-content { margin-left: 0 !important; padding-top: 0 !important; }
			html.wp-toolbar, #wpbody { padding-top: 0 !important; }
		`,
	} );

const settings = `${ base }/wp-admin/options-general.php?page=keel`;

// A capture of the login page would look like a plugin that renders nothing.
await page.goto( settings, { waitUntil: 'networkidle' } );
if ( ! ( await page.locator( '.keel-page-header' ).count() ) ) {
	throw new Error( 'Not authenticated: the settings screen did not render. Check --url and --wp.' );
}

await tidy();
await page.screenshot( { path: `${ out }/screenshot-1.png`, clip: { x: 0, y: 0, width: 1280, height: 1000 } } );

await page.goto( settings, { waitUntil: 'networkidle' } );
await page.click( '#contextual-help-link' );
await page.click( 'a[href="#tab-panel-keel-passwords"]' );
await page.waitForTimeout( 400 );
const help = await page.locator( '#contextual-help-wrap' ).boundingBox();
await page.screenshot( {
	path: `${ out }/screenshot-2.png`,
	clip: { x: help.x, y: help.y, width: help.width, height: Math.min( help.height, 900 ) },
} );

await page.goto( `${ base }/wp-admin/site-health.php?tab=debug`, { waitUntil: 'networkidle' } );
await tidy();
await page.click( '#health-check-accordion-trigger-keel, #health-check-section-keel' );
await page.waitForTimeout( 400 );
const panel = await page.locator( '#health-check-accordion-block-keel' ).boundingBox();
await page.screenshot( {
	path: `${ out }/screenshot-3.png`,
	clip: { x: 0, y: Math.max( 0, panel.y - 60 ), width: 1280, height: Math.min( panel.height + 80, 1000 ) },
} );

await browser.close();
console.log( `Wrote screenshot-1..3.png to ${ out }` );
