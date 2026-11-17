/**
 * P32 for the #378 Login Answers lab: what assistive technology is given after a
 * failed sign-in, in a real browser. Not a screen reader: it reads the
 * accessibility tree Chromium builds, which is what a screen reader is handed.
 *
 * Usage: node a11y.js <port> <host> <log>... ; prints JSON.
 * Each <log> is typed with password "x-wrong" and submitted with Enter in the
 * password field, so the run also counts submits per Enter (docs/login-screen
 * contract in AX; core behaviour in Keel).
 */
const { chromium } = require( 'playwright' );

( async () => {
	const [ port, host, ...logs ] = process.argv.slice( 2 );
	const browser = await chromium.launch( { args: [ `--host-resolver-rules=MAP ${ host }:80 127.0.0.1:${ port }` ] } );
	const out = {};
	for ( const log of logs ) {
		const page = await browser.newPage();
		let posts = 0;
		page.on( 'request', ( r ) => {
			if ( r.method() === 'POST' && r.url().includes( 'wp-login.php' ) ) {
				posts++;
			}
		} );
		await page.goto( `http://${ host }/wp-login.php` );
		await page.fill( '#user_login', log );
		await page.fill( '#user_pass', 'x-wrong' );
		await Promise.all( [ page.waitForNavigation(), page.press( '#user_pass', 'Enter' ) ] );
		await page.waitForTimeout( 300 );
		const err = page.locator( '#login_error' );
		out[ log ] = {
			posts_per_enter: posts,
			focused_id: await page.evaluate( () => document.activeElement && document.activeElement.id ),
			error_aria: ( await err.count() ) ? await err.ariaSnapshot() : null,
			error_attrs: ( await err.count() ) ? await err.evaluate( ( e ) => ( { role: e.getAttribute( 'role' ), live: e.getAttribute( 'aria-live' ), tag: e.tagName } ) ) : null,
			error_text: ( await err.count() ) ? ( await err.innerText() ).trim() : null,
			form_aria: await page.locator( '#loginform' ).ariaSnapshot(),
		};
		await page.close();
	}
	await browser.close();
	process.stdout.write( JSON.stringify( out ) );
} )().catch( ( e ) => {
	process.stderr.write( String( e && e.stack ) );
	process.exit( 1 );
} );
