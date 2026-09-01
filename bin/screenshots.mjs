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
 * screenshot-4 photographs the patch-status panel, which only has anything in
 * it on a site whose core version WordPress.org classifies as insecure. Point
 * --url at a deliberately old install, or that capture throws rather than
 * writing a picture of an empty check.
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
// 900 tall is the listing's frame, not the page's. Every clip below therefore
// passes fullPage: true — without it Playwright bounds a clip to the viewport,
// so a panel sitting below the fold is silently truncated to whatever happens
// to be on screen. That is how screenshot-3 came out 125px tall: the Site
// Health Info panel is 1202px at y=834, and the clip was cut off at 900.
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


// boundingBox() is viewport-relative; a fullPage clip is document-relative.
// Mixing them shifts every frame by however far the page happened to be
// scrolled — clicking an accordion scrolls it into view, so the shift is never
// zero and never constant. This returns document coordinates, which is what
// the clips below are expressed in.
const docBox = async ( selector ) =>
	page.locator( selector ).evaluate( ( el ) => {
		const r = el.getBoundingClientRect();
		return { x: r.x + window.scrollX, y: r.y + window.scrollY, width: r.width, height: r.height };
	} );

const settings = `${ base }/wp-admin/options-general.php?page=keel`;

// A capture of the login page would look like a plugin that renders nothing.
await page.goto( settings, { waitUntil: 'networkidle' } );
if ( ! ( await page.locator( '.keel-page-header' ).count() ) ) {
	throw new Error( 'Not authenticated: the settings screen did not render. Check --url and --wp.' );
}

await tidy();
await page.screenshot( { path: `${ out }/screenshot-1.png`, fullPage: true, clip: { x: 0, y: 0, width: 1280, height: 1000 } } );

await page.goto( settings, { waitUntil: 'networkidle' } );
await page.click( '#contextual-help-link' );
await page.click( 'a[href="#tab-panel-keel-passwords"]' );
await page.waitForTimeout( 400 );
const help = await docBox( '#contextual-help-wrap' );
await page.screenshot( {
	path: `${ out }/screenshot-2.png`,
	fullPage: true,
	clip: { x: help.x, y: help.y, width: help.width, height: Math.min( help.height, 900 ) },
} );

await page.goto( `${ base }/wp-admin/site-health.php?tab=debug`, { waitUntil: 'networkidle' } );
await tidy();

// The Info tab is server-rendered and gives its accordion no trigger id — the
// button is identified by what it controls. This used to click
// '#health-check-accordion-trigger-keel', which matches nothing in current
// core, so the whole run died here before reaching anything else.
const infoTrigger = 'button[aria-controls="health-check-accordion-block-keel"]';
await page.waitForSelector( infoTrigger, { timeout: 15000 } );
await page.click( infoTrigger );

// Measure once the panel is open. Clicking and sleeping measured it while it
// was still collapsed, so the clip came out 125px tall and the listing image
// showed a heading and one sentence instead of the table it exists to show.
await page.waitForSelector( '#health-check-accordion-block-keel', { state: 'visible', timeout: 15000 } );
await page.waitForTimeout( 250 );
const infoHeading = await docBox( infoTrigger );
const panel = await docBox( '#health-check-accordion-block-keel' );

// From the heading, not a fixed offset above the panel: the Info tab stacks a
// dozen collapsed sections above this one, and a 60px margin framed the bottom
// of "Filesystem Permissions" as though it were part of Keel's.
await page.screenshot( {
	path: `${ out }/screenshot-3.png`,
	fullPage: true,
	clip: {
		x: 0,
		y: Math.max( 0, infoHeading.y - 4 ),
		width: 1280,
		height: Math.min( infoHeading.height + panel.height + 40, 1200 ),
	},
} );

// Site Health → Status, with the patch-status result open.
//
// This one needs a site whose core version WordPress.org classifies as
// insecure, or there is nothing to photograph: on a current version the check
// is a one-line "not currently flagged" and the panel this picture exists to
// show — the patched release on the site's own line, the ladder of offers, the
// install button — never renders. Point --url at a deliberately old install.
//
// The Status tab runs its checks over AJAX after load, so the result does not
// exist in the initial HTML. Waiting on the selector rather than a timeout is
// the difference between a reliable capture and one that intermittently
// photographs an empty list.
await page.goto( `${ base }/wp-admin/site-health.php`, { waitUntil: 'networkidle' } );
await tidy();

const backport = '#health-check-accordion-block-keel_backport';
const backportTrigger = 'button[aria-controls="health-check-accordion-block-keel_backport"]';

// Wait on the trigger, not the panel: the panel carries hidden="hidden" until
// it is expanded, so waiting for it to be visible waits forever.
await page.waitForSelector( backportTrigger, { timeout: 30000 } );
await page.click( backportTrigger );
await page.waitForSelector( backport, { state: 'visible', timeout: 15000 } );
await page.waitForTimeout( 250 );

const heading = await docBox( backportTrigger );
const body = await docBox( backport );

if ( ! body || body.height < 100 ) {
	throw new Error(
		'The patch-status panel rendered empty. Point --url at a site whose core version WordPress.org flags as insecure.'
	);
}

await page.screenshot( {
	path: `${ out }/screenshot-4.png`,
	fullPage: true,
	clip: {
		x: 0,
		// Flush to the heading: a margin above it catches the last line of the
		// preceding check, which reads as part of this one.
		y: Math.max( 0, heading.y - 4 ),
		width: 1280,
		height: Math.min( heading.height + body.height + 16, 1200 ),
	},
} );

await browser.close();
console.log( `Wrote screenshot-1..4.png to ${ out }` );
