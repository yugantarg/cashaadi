/**
 * UI check (owner, 2026-10-28: "how do I check for UI failures like these
 * across all my screens and all devices?").
 *
 * Opens each page at phone, tablet and laptop sizes in a real browser and
 * reports what a person would notice:
 *   - the page can be dragged sideways;
 *   - something sticks out past the edge of the screen;
 *   - a short button or link label wraps onto two lines, or is cut off;
 *   - two buttons or links overlap;
 *   - a broken image; a JavaScript error; a page or request that fails.
 * Pages with a problem are screenshotted with the culprits outlined in red.
 *
 * READ-ONLY. Every request except GET is blocked (the login form aside), so
 * browsing never likes, passes, views, saves or changes anything, and members
 * get no "someone viewed your profile". Member profile pages are not opened.
 *
 *   node tools/ui-check/ui-check.js [out-dir]
 *
 * Env: CSM_BASE (default https://cashaadi.in); CSM_TEST_EMAIL and
 * CSM_TEST_PASSWORD for the member screens (a test account; never in the
 * repo). Without them only public pages are checked. Needs Playwright.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const crypto = require( 'crypto' );
const { chromium } = require( 'playwright' );

const BASE = ( process.env.CSM_BASE || 'https://cashaadi.in' ).replace( /\/$/, '' );
const OUT = path.resolve( process.argv[2] || 'ui-check-out' );
const EMAIL = process.env.CSM_TEST_EMAIL || '';
const PASS = process.env.CSM_TEST_PASSWORD || '';

const VIEWPORTS = [
	[ 'phone-320', 320, 640 ],
	[ 'phone-360', 360, 780 ],
	[ 'phone-390', 390, 844 ],
	[ 'phone-430', 430, 932 ],
	[ 'tablet-768', 768, 1024 ],
	[ 'laptop-1280', 1280, 800 ],
	[ 'desktop-1536', 1536, 864 ],
];

const PUBLIC = [ '/', '/membership-pricing/', '/register/', '/login/', '/about-us/', '/terms-and-conditions/', '/privacy-policy/', '/refund-policy/' ];
const MEMBER = [ '/discover/', '/requests/', '/profile/', '/profile/edit/', '/profile/preview/', '/settings/visibility/', '/settings/blocked/', '/refer/' ];
// Never opened, even if a menu links to them.
const SKIP = /logout|delete|deactivat|pause|wp-admin|admin-post|admin-ajax|wp-json|\/member\/\d|action=|mailto:|tel:|\.(pdf|jpg|png|zip)$/i;

/* This environment's network proxy re-signs HTTPS; trust only its own CAs. */
function launchOptions() {
	const o = {};
	if ( process.env.HTTPS_PROXY ) {
		o.proxy = { server: process.env.HTTPS_PROXY };
		const bundle = process.env.NODE_EXTRA_CA_CERTS;
		if ( bundle && fs.existsSync( bundle ) ) {
			const pins = ( fs.readFileSync( bundle, 'utf8' ).match( /-----BEGIN CERTIFICATE-----[\s\S]+?-----END CERTIFICATE-----/g ) || [] )
				.map( ( pem ) => { try { return new crypto.X509Certificate( pem ); } catch ( e ) { return null; } } )
				.filter( ( c ) => c && /Anthropic/.test( c.subject ) )
				.map( ( c ) => crypto.createHash( 'sha256' ).update( c.publicKey.export( { type: 'spki', format: 'der' } ) ).digest( 'base64' ) );
			if ( pins.length ) { o.args = [ '--ignore-certificate-errors-spki-list=' + pins.join( ',' ) ]; }
		}
	}
	return o;
}

/* Runs in the page: what is visibly wrong. */
function inspect() {
	const W = document.documentElement.clientWidth;
	const out = [];
	let n = 0;
	const mark = ( el ) => { el.setAttribute( 'data-uicheck', ++n ); return n; };
	const shown = ( el ) => {
		const r = el.getBoundingClientRect();
		if ( r.width < 1 || r.height < 1 ) { return false; }
		for ( let e = el; e && e !== document.body; e = e.parentElement ) {
			const s = getComputedStyle( e );
			if ( s.display === 'none' || s.visibility === 'hidden' || +s.opacity === 0 || e.hidden || e.getAttribute( 'aria-hidden' ) === 'true' ) { return false; }
		}
		return true;
	};
	const clipped = ( el ) => {
		for ( let e = el.parentElement; e && e !== document.body && e !== document.documentElement; e = e.parentElement ) {
			const s = getComputedStyle( e );
			if ( /auto|scroll|hidden|clip/.test( s.overflowX ) ) { return true; }
			if ( s.position === 'fixed' ) { return false; }
		}
		return false;
	};
	const fixedUp = ( el ) => { for ( let e = el; e && e !== document.body; e = e.parentElement ) { const p = getComputedStyle( e ).position; if ( p === 'fixed' || p === 'sticky' ) { return true; } } return false; };
	const label = ( el ) => ( el.innerText || el.value || el.getAttribute( 'aria-label' ) || el.alt || el.tagName ).replace( /\s+/g, ' ' ).trim().slice( 0, 60 );
	const where = ( el ) => el.tagName.toLowerCase() + ( el.id ? '#' + el.id : '' ) + ( typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split( /\s+/ ).slice( 0, 2 ).join( '.' ) : '' );

	if ( document.documentElement.scrollWidth > W + 1 ) {
		out.push( { kind: 'sideways-scroll', what: 'Page is ' + document.documentElement.scrollWidth + 'px wide on a ' + W + 'px screen' } );
	}

	// Past the edge (outermost culprit only).
	const past = new Set();
	document.querySelectorAll( 'body *' ).forEach( ( el ) => {
		const r = el.getBoundingClientRect();
		if ( ( r.right > W + 2 || r.left < -2 ) && r.width > 0 && shown( el ) && ! clipped( el ) ) {
			for ( let p = el.parentElement; p; p = p.parentElement ) { if ( past.has( p ) ) { return; } }
			past.add( el );
		}
	} );
	[ ...past ].slice( 0, 8 ).forEach( ( el ) => out.push( { kind: 'past-edge', id: mark( el ), what: where( el ) + ' "' + label( el ) + '"', detail: Math.round( el.getBoundingClientRect().left ) + '→' + Math.round( el.getBoundingClientRect().right ) + 'px' } ) );

	// Short labels that wrap or are cut off.
	const controls = [ ...document.querySelectorAll( 'a, button, [role=button], input[type=submit], input[type=button], .csm-btn, .button' ) ].filter( shown );
	controls.forEach( ( el ) => {
		const t = label( el );
		if ( ! t || t.length > 32 || t.split( ' ' ).length > 4 || el.querySelector( 'img, p, div, li' ) ) { return; }
		const range = document.createRange();
		range.selectNodeContents( el );
		const tops = new Set( [ ...range.getClientRects() ].filter( ( r ) => r.height > 4 && r.width > 1 ).map( ( r ) => Math.round( r.top / 4 ) ) );
		if ( tops.size > 1 ) {
			out.push( { kind: 'label-wraps', id: mark( el ), what: where( el ) + ' "' + t + '"', detail: tops.size + ' lines' } );
		} else if ( el.scrollWidth > el.clientWidth + 2 && /hidden|clip/.test( getComputedStyle( el ).overflow ) ) {
			out.push( { kind: 'label-cut', id: mark( el ), what: where( el ) + ' "' + t + '"' } );
		}
	} );

	// Buttons or links on top of each other (not inside fixed bars).
	const flat = controls.filter( ( el ) => ! fixedUp( el ) ).slice( 0, 400 );
	const rects = flat.map( ( el ) => el.getBoundingClientRect() );
	for ( let i = 0; i < flat.length; i++ ) {
		for ( let j = i + 1; j < flat.length; j++ ) {
			if ( flat[ i ].contains( flat[ j ] ) || flat[ j ].contains( flat[ i ] ) ) { continue; }
			const a = rects[ i ], b = rects[ j ];
			const x = Math.max( 0, Math.min( a.right, b.right ) - Math.max( a.left, b.left ) );
			const y = Math.max( 0, Math.min( a.bottom, b.bottom ) - Math.max( a.top, b.top ) );
			if ( x * y > 0.25 * Math.min( a.width * a.height, b.width * b.height ) ) {
				out.push( { kind: 'overlap', id: mark( flat[ i ] ), what: where( flat[ i ] ) + ' "' + label( flat[ i ] ) + '" over ' + where( flat[ j ] ) + ' "' + label( flat[ j ] ) + '"' } );
				mark( flat[ j ] );
			}
		}
	}

	document.querySelectorAll( 'img' ).forEach( ( img ) => {
		if ( img.complete && img.getAttribute( 'src' ) && img.naturalWidth === 0 && shown( img ) ) {
			out.push( { kind: 'broken-image', id: mark( img ), what: ( img.currentSrc || img.src ).slice( 0, 120 ) } );
		}
	} );
	return out;
}

async function settle( page ) {
	await page.waitForTimeout( 900 );
	// Scroll through once so scroll-in content and lazy images appear.
	await page.evaluate( async () => {
		for ( let y = 0; y < document.body.scrollHeight; y += Math.max( 300, innerHeight * 0.8 ) ) { scrollTo( 0, y ); await new Promise( ( r ) => setTimeout( r, 120 ) ); }
		scrollTo( 0, 0 );
	} );
	await page.waitForTimeout( 600 );
}

async function login( ctx ) {
	const page = await ctx.newPage();
	await page.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 45000 } );
	const user = page.locator( '#user_login, input[name=log], input[type=email]' ).first();
	await user.fill( EMAIL );
	await page.locator( '#user_pass, input[name=pwd], input[type=password]' ).first().fill( PASS );
	await Promise.all( [ page.waitForLoadState( 'domcontentloaded' ).catch( () => {} ), page.locator( '#wp-submit, button[type=submit], input[type=submit]' ).first().click() ] );
	await page.waitForTimeout( 2500 );
	const ok = ( await ctx.cookies() ).some( ( c ) => /^wordpress_logged_in_/.test( c.name ) );
	// Pages members reach from the Profile tab, so new screens are checked too.
	let found = [];
	if ( ok ) {
		await page.goto( BASE + '/profile/', { waitUntil: 'networkidle', timeout: 45000 } ).catch( () => {} );
		found = await page.evaluate( ( base ) => [ ...document.querySelectorAll( 'a[href]' ) ].map( ( a ) => a.href ).filter( ( h ) => h.indexOf( base ) === 0 ).map( ( h ) => h.slice( base.length ).split( '#' )[0] ), BASE ).catch( () => [] );
	}
	await page.close();
	return { ok, found };
}

( async () => {
	fs.mkdirSync( OUT, { recursive: true } );
	const browser = await chromium.launch( launchOptions() );
	const ctx = await browser.newContext();
	let loggingIn = false;
	await ctx.route( '**/*', ( route ) => {
		const r = route.request();
		if ( r.method() === 'GET' || ( loggingIn && /wp-login\.php|\/login\//.test( r.url() ) ) ) { return route.continue(); }
		return route.abort();
	} );

	let pages = PUBLIC.map( ( p ) => [ 'public', p ] );
	let member = 'not checked (no CSM_TEST_EMAIL / CSM_TEST_PASSWORD)';
	if ( EMAIL && PASS ) {
		loggingIn = true;
		const res = await login( ctx ).catch( ( e ) => ( { ok: false, err: e.message } ) );
		loggingIn = false;
		if ( res.ok ) {
			const list = [ ...new Set( MEMBER.concat( res.found ) ) ].filter( ( p ) => p && p.startsWith( '/' ) && ! SKIP.test( p ) ).slice( 0, 30 );
			member = list.length + ' pages';
			// Public pages are seen logged-out; open a fresh context for them.
			pages = list.map( ( p ) => [ 'member', p ] );
		} else {
			member = 'LOGIN FAILED' + ( res.err ? ': ' + res.err : '' );
		}
	}

	const anon = await browser.newContext();
	await anon.route( '**/*', ( route ) => ( route.request().method() === 'GET' ? route.continue() : route.abort() ) );
	const jobs = PUBLIC.map( ( p ) => [ 'public', p, anon ] ).concat( pages.filter( ( p ) => p[0] === 'member' ).map( ( p ) => [ 'member', p[1], ctx ] ) );

	const report = [];
	for ( const [ who, p, c ] of jobs ) {
		for ( const [ vname, w, h ] of VIEWPORTS ) {
			const page = await c.newPage( { viewport: { width: w, height: h } } );
			const errors = [];
			page.on( 'pageerror', ( e ) => errors.push( { kind: 'js-error', what: e.message.slice( 0, 160 ) } ) );
			page.on( 'response', ( r ) => {
				if ( r.status() >= 400 && r.url().indexOf( BASE ) === 0 && r.request().method() === 'GET' && ! /favicon/.test( r.url() ) ) {
					errors.push( { kind: 'failed-request', what: r.status() + ' ' + r.url().slice( BASE.length, BASE.length + 100 ) } );
				}
			} );
			let issues = [];
			try {
				const res = await page.goto( BASE + p, { waitUntil: 'load', timeout: 30000 } );
				await page.waitForLoadState( 'networkidle', { timeout: 4000 } ).catch( () => {} );
				if ( res && res.url().indexOf( '/wp-login' ) !== -1 && who === 'member' ) { issues.push( { kind: 'logged-out', what: 'sent to login' } ); }
				await settle( page );
				issues = issues.concat( await page.evaluate( inspect ) );
			} catch ( e ) {
				issues.push( { kind: 'load-failed', what: e.message.split( '\n' )[0].slice( 0, 160 ) } );
			}
			issues = issues.concat( errors );
			let shot = '';
			if ( issues.length ) {
				await page.addStyleTag( { content: '[data-uicheck]{outline:3px solid #e11 !important;outline-offset:1px}' } ).catch( () => {} );
				shot = ( who + p ).replace( /[^a-z0-9]+/gi, '-' ).replace( /-+$/, '' ) + '_' + vname + '.png';
				await page.screenshot( { path: path.join( OUT, shot ), fullPage: true } ).catch( () => { shot = ''; } );
			}
			report.push( { who, page: p, viewport: vname, issues, shot } );
			process.stdout.write( ( issues.length ? 'x' : '.' ) );
			await page.close();
		}
	}
	await browser.close();

	// Summary: group the same problem across sizes.
	const bad = report.filter( ( r ) => r.issues.length );
	const lines = [ '# UI check ' + new Date().toISOString().slice( 0, 16 ).replace( 'T', ' ' ) + ' UTC', '', BASE + ' · ' + jobs.length + ' pages × ' + VIEWPORTS.length + ' sizes · member pages: ' + member, '', bad.length ? bad.length + ' page/size combinations with problems.' : 'No problems found.', '' ];
	const byPage = {};
	bad.forEach( ( r ) => { ( byPage[ r.page ] = byPage[ r.page ] || [] ).push( r ); } );
	Object.keys( byPage ).forEach( ( p ) => {
		lines.push( '## ' + p );
		const seen = {};
		byPage[ p ].forEach( ( r ) => r.issues.forEach( ( i ) => {
			const k = i.kind + ' ' + i.what;
			( seen[ k ] = seen[ k ] || { i, sizes: [], shot: r.shot } ).sizes.push( r.viewport );
		} ) );
		Object.values( seen ).forEach( ( s ) => lines.push( '- **' + s.i.kind + '** ' + s.i.what + ( s.i.detail ? ' (' + s.i.detail + ')' : '' ) + ' — ' + s.sizes.join( ', ' ) + ( s.shot ? ' — ' + s.shot : '' ) ) );
		lines.push( '' );
	} );
	fs.writeFileSync( path.join( OUT, 'report.md' ), lines.join( '\n' ) );
	fs.writeFileSync( path.join( OUT, 'report.json' ), JSON.stringify( report, null, 1 ) );
	console.log( '\n' + lines.slice( 0, 6 ).join( '\n' ) + '\nReport: ' + path.join( OUT, 'report.md' ) );
} )();
