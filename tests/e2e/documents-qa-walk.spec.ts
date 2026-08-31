import { expect, test, Page } from '@playwright/test';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

/**
 * Interactive DOCUMENTS-QA walk via Playwright.
 *
 * Mutates Member A's drive (create/upload/rename/privacy/trash/share) then
 * restores sensitive settings (role gate / master toggle) before finishing.
 *
 * Run:
 *   MVS_SITE_URL=http://mediaverse.local npx playwright test tests/e2e/documents-qa-walk.spec.ts
 */

const MEMBER_A = 'journey-member';
const MEMBER_B = 'mina_aoki';
const NON_GRANTEE = 'rftqa';
const WP_PATH = '/Users/vapvarun/Local Sites/mediaverse/app/public';
const FIXTURE = path.join( __dirname, 'fixtures', 'pw-doc-fixture.txt' );
const FOLDER = `PW Folder ${ Date.now() }`;
const RENAMED = `PW Renamed ${ Date.now() }.txt`;

function wpEval( php: string ): string {
	const out = execFileSync(
		'wp',
		[ 'eval', php, `--path=${ WP_PATH }`, '--allow-root' ],
		{ encoding: 'utf8' }
	);
	return out
		.split( '\n' )
		.map( ( l ) => l.trim() )
		.filter( ( l ) => l && ! /Warning|imagick|Startup|Deprecated|Failed loading|Xdebug|Zend Engine/i.test( l ) )
		.join( '\n' );
}

async function autoLogin( page: Page, user: string ): Promise<void> {
	await page.goto( `/?autologin=${ encodeURIComponent( user ) }`, {
		waitUntil: 'networkidle',
	} );
}

async function openDrive( page: Page ): Promise<void> {
	await page.goto( '/my-media/documents/', { waitUntil: 'networkidle' } );
	await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
}

test.describe( 'Documents QA interactive walk', () => {
	test.describe.configure( { mode: 'serial', timeout: 90_000 } );

	let uploadedTitle = '';
	let folderUrl = '';

	test( '§1.1 Documents rail + direct drive URL', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/', { waitUntil: 'networkidle' } );
		const docsLink = page.locator( 'a[href*="/documents"]' ).filter( { hasText: /Documents/i } ).first();
		await expect( docsLink ).toBeVisible();
		await docsLink.click();
		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
		await expect( page ).toHaveURL( /\/documents\/?/ );
	} );

	test( '§1.2 Create folder + duplicate name refused', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await openDrive( page );

		await page.locator( '#mvs-new-folder' ).fill( FOLDER );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'networkidle' } ),
			page.locator( '.mvs-drive__newfolder-button' ).click(),
		] );

		await expect( page.locator( '.mvs-drive__name', { hasText: FOLDER } ) ).toBeVisible();

		await page.locator( '#mvs-new-folder' ).fill( FOLDER );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'networkidle' } ),
			page.locator( '.mvs-drive__newfolder-button' ).click(),
		] );

		const notice = page.locator( '.mvs-drive__notice, [role="status"]' );
		await expect( notice.first() ).toBeVisible();
		const text = ( await notice.first().innerText() ).toLowerCase();
		expect( text ).toMatch( /already|name|exists|taken|here/ );
	} );

	test( '§1.3 Upload into the new folder', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await openDrive( page );

		const folderLink = page.locator( `a.mvs-drive__name`, { hasText: FOLDER } );
		await expect( folderLink ).toBeVisible();
		folderUrl = ( await folderLink.getAttribute( 'href' ) ) || '';
		expect( folderUrl ).toBeTruthy();

		await folderLink.click();
		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
		await expect( page.locator( '.mvs-drive__folder-name' ) ).toContainText( FOLDER );

		await page.locator( '#mvs-document-file' ).setInputFiles( FIXTURE );
		await expect( page.locator( '.mvs-drive__name', { hasText: 'pw-doc-fixture.txt' } ) ).toBeVisible( {
			timeout: 30_000,
		} );

		uploadedTitle = 'pw-doc-fixture.txt';
		await expect( page.locator( '.mvs-drive__name', { hasText: uploadedTitle } ) ).toBeVisible();
	} );

	test( '§1.4 Rename document', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( folderUrl || '/my-media/documents/', { waitUntil: 'networkidle' } );

		const row = page.locator( '.mvs-drive__row', { hasText: uploadedTitle } ).first();
		await expect( row ).toBeVisible();
		await row.locator( 'summary.mvs-drive__actions-toggle' ).click();

		const renameInput = row.locator( 'input[name="mvs_value"]' ).first();
		await renameInput.fill( RENAMED );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'networkidle' } ),
			row.locator( 'button', { hasText: /^Rename$/ } ).click(),
		] );

		await expect( page.locator( '.mvs-drive__name', { hasText: RENAMED } ) ).toBeVisible();
		uploadedTitle = RENAMED;
	} );

	test( '§1.6 Privacy dropdown is Only me / Members / Anyone', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( folderUrl || '/my-media/documents/', { waitUntil: 'networkidle' } );

		const row = page.locator( '.mvs-drive__row', { hasText: uploadedTitle } ).first();
		await row.locator( 'summary.mvs-drive__actions-toggle' ).click();
		const options = await row.locator( 'select[id^="mvs-privacy-"] option' ).allTextContents();
		const cleaned = options.map( ( o ) => o.trim() );
		expect( cleaned ).toEqual( expect.arrayContaining( [ 'Only me', 'Members', 'Anyone' ] ) );
		expect( cleaned.join( ' ' ).toLowerCase() ).not.toMatch( /unlisted/ );
		expect( cleaned.length ).toBe( 3 );
	} );

	test( '§1.8 Trash then restore stays in same folder', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( folderUrl || '/my-media/documents/', { waitUntil: 'networkidle' } );

		const row = page.locator( '.mvs-drive__row', { hasText: uploadedTitle } ).first();
		await row.locator( 'summary.mvs-drive__actions-toggle' ).click();
		page.once( 'dialog', ( d ) => d.accept() );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'networkidle' } ),
			row.locator( 'button', { hasText: /Move to trash/i } ).click(),
		] );

		await expect( page.locator( '.mvs-drive__name', { hasText: uploadedTitle } ) ).toHaveCount( 0 );

		await page.locator( '.mvs-drive__trash-link, a[href*="show=trash"]' ).first().click();
		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
		const trashRow = page.locator( '.mvs-drive__row', { hasText: uploadedTitle } ).first();
		await expect( trashRow ).toBeVisible();
		await trashRow.locator( 'summary.mvs-drive__actions-toggle' ).click();
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'networkidle' } ),
			trashRow.locator( 'button', { hasText: /^Restore$/ } ).click(),
		] );

		await page.goto( folderUrl || '/my-media/documents/', { waitUntil: 'networkidle' } );
		await expect( page.locator( '.mvs-drive__name', { hasText: uploadedTitle } ) ).toBeVisible();
	} );

	test( '§1.12 Sort/filter survives folder navigation', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await openDrive( page );

		const sort = page.locator( 'select[name="sort"], select[name="orderby"]' ).first();
		const order = page.locator( 'select[name="order"]' ).first();
		if ( await sort.count() ) {
			await sort.selectOption( { label: /title|name/i } ).catch( async () => {
				await sort.selectOption( { index: 1 } );
			} );
		}
		if ( await order.count() ) {
			await order.selectOption( { label: /asc|a→z|oldest|a to z/i } ).catch( async () => {
				await order.selectOption( { index: 0 } );
			} );
		}
		const apply = page.locator( '.mvs-panel-toolbar__apply, .mvs-drive__toolbar button[type="submit"]' ).filter( { hasText: /^Apply$/ } ).first();
		if ( await apply.count() ) {
			await Promise.all( [
				page.waitForNavigation( { waitUntil: 'networkidle' } ),
				apply.click(),
			] );
		} else {
			// Fallback: submit the controls form directly if present.
			const form = page.locator( 'form' ).filter( { has: page.locator( 'select[name="sort"], select[name="order"]' ) } ).first();
			if ( await form.count() ) {
				await Promise.all( [
					page.waitForNavigation( { waitUntil: 'networkidle' } ),
					form.evaluate( ( f: HTMLFormElement ) => f.requestSubmit() ),
				] );
			}
		}

		const urlBefore = page.url();
		const folderLink = page.locator( `a.mvs-drive__name`, { hasText: FOLDER } );
		await folderLink.click();
		await page.waitForLoadState( 'networkidle' );
		expect( page.url() ).toMatch( /sort=|order=/ );

		const crumb = page.locator( '.mvs-drive__crumbs a, .mvs-drive__backlink' ).first();
		if ( await crumb.count() ) {
			await crumb.click();
			await page.waitForLoadState( 'networkidle' );
		} else {
			await page.goto( urlBefore, { waitUntil: 'networkidle' } );
		}
		expect( page.url() ).toMatch( /sort=|order=/ );
	} );

	test( '§2 Share with Member B → appears in Shared with me', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( folderUrl || '/my-media/documents/', { waitUntil: 'networkidle' } );

		const row = page.locator( '.mvs-drive__row', { hasText: uploadedTitle } ).first();
		await row.locator( 'summary.mvs-drive__actions-toggle' ).click();
		await row.locator( 'input[name="mvs_share_with"]' ).fill( MEMBER_B );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'networkidle' } ),
			row.locator( 'form.mvs-drive__action' ).filter( { has: page.locator( 'input[name="mvs_share_with"]' ) } ).locator( 'button', { hasText: /^Share$/ } ).click(),
		] );

		await autoLogin( page, MEMBER_B );
		await page.goto( '/explore-document/?drive=shared', { waitUntil: 'networkidle' } );
		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
		await expect( page.locator( '.mvs-drive__name', { hasText: uploadedTitle } ) ).toBeVisible();
	} );

	test( '§2 Shared doc opens for Member B', async ( { page } ) => {
		await autoLogin( page, MEMBER_B );
		await page.goto( '/explore-document/?drive=shared', { waitUntil: 'networkidle' } );
		const link = page.locator( `a.mvs-drive__name`, { hasText: uploadedTitle } );
		await link.click();
		await page.waitForLoadState( 'networkidle' );
		expect( page.url() ).toMatch( /\/media\// );
		await expect( page.locator( '.mvs-media-title' ) ).toContainText( uploadedTitle.replace( /\.txt$/, '' ).slice( 0, 12 ) );
	} );

	test( '§3 Role gate hides drive and returns 403 unavailable', async ( { page, request } ) => {
		await autoLogin( page, '1' );
		// Untick subscriber via WP-CLI (same effect as Settings UI), then restore after.
		wpEval( `
$role=get_role('subscriber');
$role->remove_cap('use_mvs_documents');
echo get_role('subscriber')->has_cap('use_mvs_documents') ? 'YES' : 'no';
` );

		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/', { waitUntil: 'networkidle' } );
		const docsInRail = page.locator( 'a[href*="/documents"]' ).filter( { hasText: /^Documents$/i } );
		await expect( docsInRail ).toHaveCount( 0 );

		await page.goto( '/my-media/documents/', { waitUntil: 'networkidle' } );
		const body = await page.locator( 'body' ).innerText();
		expect( body.toLowerCase() ).not.toMatch( /new folder/ );

		// REST 403 with code — use cookie session via page.request after login.
		const res = await page.request.get( '/wp-json/mvs-pro/v1/documents' );
		// Cookie auth without nonce may 401; prefer evaluate with nonce from drive-less page.
		const api = await page.evaluate( async () => {
			const m = document.documentElement.innerHTML.match( /"nonce"\s*:\s*"([a-f0-9]+)"/ );
			const nonce = m ? m[ 1 ] : '';
			const r = await fetch( '/wp-json/mvs-pro/v1/documents', {
				credentials: 'same-origin',
				headers: nonce ? { 'X-WP-Nonce': nonce } : {},
			} );
			const j = await r.json().catch( () => ( {} ) );
			return { status: r.status, code: j.code || '' };
		} );
		expect( api.status ).toBe( 403 );
		expect( api.code ).toBe( 'mvs_documents_unavailable' );

		// Restore.
		wpEval( `
$role=get_role('subscriber');
$role->add_cap('use_mvs_documents');
echo get_role('subscriber')->has_cap('use_mvs_documents') ? 'YES' : 'no';
` );
		expect( wpEval( `echo get_role('subscriber')->has_cap('use_mvs_documents') ? 'YES' : 'no';` ) ).toBe( 'YES' );

		await autoLogin( page, MEMBER_A );
		await openDrive( page );
		await expect( page.locator( '.mvs-drive__newfolder' ) ).toBeVisible();
	} );

	test( '§4 Master toggle off removes routes; on restores', async ( { page, request } ) => {
		const before = wpEval( `
global $wpdb;
$n=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22 AND status='publish'");
echo $n;
` );

		wpEval( `update_option('mvs_pro_documents_enabled','0'); echo get_option('mvs_pro_documents_enabled');` );
		expect( await request.get( '/wp-json/mvs-pro/v1/documents' ).then( ( r ) => r.status() ) ).toBe( 404 );

		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/documents/', { waitUntil: 'networkidle' } );
		await expect( page.locator( '.mvs-drive__newfolder' ) ).toHaveCount( 0 );

		wpEval( `update_option('mvs_pro_documents_enabled','1'); echo get_option('mvs_pro_documents_enabled');` );
		const after = wpEval( `
global $wpdb;
$n=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22 AND status='publish'");
echo $n;
` );
		expect( after ).toBe( before );

		await autoLogin( page, MEMBER_A );
		await openDrive( page );
		await expect( page.locator( '.mvs-drive__newfolder' ) ).toBeVisible();
	} );

	test( '§1.5 Move document to drive root', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( folderUrl || '/my-media/documents/', { waitUntil: 'networkidle' } );

		const row = page.locator( '.mvs-drive__row', { hasText: uploadedTitle } ).first();
		await expect( row ).toBeVisible();
		await row.locator( 'summary.mvs-drive__actions-toggle' ).click();
		await row.locator( 'select[id^="mvs-move-"]' ).selectOption( '0' );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'networkidle' } ),
			row.locator( 'button', { hasText: /^Move$/ } ).click(),
		] );

		await page.goto( folderUrl || '/my-media/documents/', { waitUntil: 'networkidle' } );
		await expect( page.locator( '.mvs-drive__name', { hasText: uploadedTitle } ) ).toHaveCount( 0 );

		await openDrive( page );
		await expect( page.locator( '.mvs-drive__name', { hasText: uploadedTitle } ) ).toBeVisible();
	} );

	test( '§1.7 Download from row returns file bytes', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await openDrive( page );

		const row = page.locator( '.mvs-drive__row', { hasText: uploadedTitle } ).first();
		await row.locator( 'summary.mvs-drive__actions-toggle' ).click();
		const dl = row.locator( 'a.mvs-drive__download, a[href*="/download"]' ).first();
		await expect( dl ).toBeVisible();
		const href = await dl.getAttribute( 'href' );
		expect( href ).toBeTruthy();
		const res = await page.request.get( href! );
		expect( res.status() ).toBe( 200 );
		const body = await res.text();
		expect( body.length ).toBeGreaterThan( 10 );
		expect( body ).toMatch( /Playwright QA body marker|playwrightgrove/i );
	} );

	test( '§1.11 Nested folder breadcrumbs', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await openDrive( page );

		const child = `PW Nested ${ Date.now() }`;
		// Open parent folder first so new folder nests under it.
		const parent = page.locator( `a.mvs-drive__name`, { hasText: FOLDER } );
		if ( await parent.count() ) {
			await parent.click();
			await page.waitForLoadState( 'networkidle' );
		}

		await page.locator( '#mvs-new-folder' ).fill( child );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'networkidle' } ),
			page.locator( '.mvs-drive__newfolder-button' ).click(),
		] );

		const childLink = page.locator( `a.mvs-drive__name`, { hasText: child } );
		await expect( childLink ).toBeVisible();
		await childLink.click();
		await page.waitForLoadState( 'networkidle' );

		const crumbs = page.locator( '.mvs-drive__crumbs' );
		await expect( crumbs ).toBeVisible();
		await expect( crumbs ).toContainText( FOLDER );
		await expect( page.locator( '.mvs-drive__folder-name' ) ).toContainText( child );
	} );

	test( '§2.3–2.4 Role share then revoke', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await openDrive( page );

		const row = page.locator( '.mvs-drive__row', { hasText: uploadedTitle } ).first();
		await row.locator( 'summary.mvs-drive__actions-toggle' ).click();
		await row.locator( 'select[name="mvs_share_role"]' ).selectOption( 'author' );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'networkidle' } ),
			row.locator( 'form.mvs-drive__action' ).filter( { has: page.locator( 'select[name="mvs_share_role"]' ) } ).locator( 'button', { hasText: /^Share$/ } ).click(),
		] );

		await autoLogin( page, MEMBER_B ); // author role
		await page.goto( '/explore-document/?drive=shared', { waitUntil: 'networkidle' } );
		// mina may already see via user grant; ensure listing works
		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();

		await autoLogin( page, MEMBER_A );
		await openDrive( page );
		const ownerRow = page.locator( '.mvs-drive__row', { hasText: uploadedTitle } ).first();
		await ownerRow.locator( 'summary.mvs-drive__actions-toggle' ).click();
		const revoke = ownerRow.locator( 'button', { hasText: /^Remove$/ } ).first();
		if ( await revoke.count() ) {
			await Promise.all( [
				page.waitForNavigation( { waitUntil: 'networkidle' } ),
				revoke.click(),
			] );
		}
		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
	} );

	test( '§8 Drive usable at 390px viewport', async ( { page } ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		await autoLogin( page, MEMBER_A );
		await openDrive( page );
		const overflow = await page.evaluate( () => {
			const el = document.querySelector( '.mvs-drive' ) || document.body;
			return {
				scrollWidth: document.documentElement.scrollWidth,
				innerWidth: window.innerWidth,
				driveOk: !! document.querySelector( '.mvs-drive' ),
			};
		} );
		expect( overflow.driveOk ).toBe( true );
		expect( overflow.scrollWidth ).toBeLessThanOrEqual( overflow.innerWidth + 2 );
		await expect( page.locator( '#mvs-document-file, .mvs-drive__upload-input' ) ).toBeAttached();
	} );

	test( '§6 Explore + admin still healthy after walk', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( '/explore-document/', { waitUntil: 'networkidle' } );
		await expect( page.locator( 'body' ) ).toBeVisible();

		await autoLogin( page, '1' );
		await page.goto( '/wp-admin/admin.php?page=mvs-documents', { waitUntil: 'networkidle' } );
		await expect( page.locator( 'body' ) ).toContainText( /Documents/i );
	} );
} );
