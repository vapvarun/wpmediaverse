import { expect, test, Page } from '@playwright/test';

/**
 * Documents library smoke — post-F1–F4 recheck (2026-08-11).
 *
 * Walks the surfaces DOCUMENTS-QA.md cares about that a browser can assert
 * without mutating settings. Uses existing QA fixtures on mediaverse.local:
 *   - journey-member (uid 22) — Member A
 *   - mina_aoki (uid 8) — Member B with a grant on qa-pdf-fixture
 *   - rftqa — non-grantee
 *   - media 2253 private text doc for 404-vs-403
 *
 * Run:
 *   MVS_SITE_URL=http://mediaverse.local npx playwright test tests/e2e/documents-qa-smoke.spec.ts
 */

const MEMBER_A = 'journey-member';
const MEMBER_B = 'mina_aoki';
const NON_GRANTEE = 'rftqa';
const SHARED_PDF_SLUG = 'qa-pdf-fixture-112498';
const PRIVATE_TEXT_SLUG = 'qa-text-fixture-331197'; // media 2253, privacy=private

async function autoLogin( page: Page, user: string ): Promise<void> {
	await page.goto( `/?autologin=${ encodeURIComponent( user ) }`, {
		waitUntil: 'networkidle',
	} );
}

async function signedOut( page: Page ): Promise<void> {
	await page.context().clearCookies();
	await page.goto( '/', { waitUntil: 'domcontentloaded' } );
}

test.describe( 'Documents QA smoke (combo)', () => {
	test.describe.configure( { mode: 'serial', timeout: 60_000 } );

	test( '§1 Member A drive loads with toolbar', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/documents/', { waitUntil: 'networkidle' } );

		const drive = page.locator( '.mvs-drive' );
		await expect( drive ).toBeVisible();
		await expect( drive.locator( '#mvs-document-file, .mvs-drive__upload-input' ) ).toBeAttached();
		await expect( drive.locator( '.mvs-drive__newfolder' ) ).toBeVisible();
		await expect( drive.locator( '.mvs-drive__trash-link, a[href*="show=trash"]' ).first() ).toBeVisible();
		await expect( drive.locator( '.mvs-drive__row' ).first() ).toBeVisible( { timeout: 15_000 } );
	} );

	test( '§1 Trash view opens', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/documents/?show=trash', { waitUntil: 'networkidle' } );

		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
		await expect( page.getByText( /Trash|Restore|empty/i ).first() ).toBeVisible();
	} );

	test( '§1 In-drive search finds indexed body text', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/documents/?q=zephyrgrove19', { waitUntil: 'networkidle' } );

		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
		const rows = page.locator( '.mvs-drive__row' );
		await expect( rows.first() ).toBeVisible();
		expect( await rows.count() ).toBeGreaterThanOrEqual( 1 );
	} );

	test( '§2 F1 Shared-with-me lists granted PDF only', async ( { page } ) => {
		await autoLogin( page, MEMBER_B );
		await page.goto( '/explore-document/?drive=shared', { waitUntil: 'networkidle' } );

		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
		await expect( page.getByText( /Shared with me/i ).first() ).toBeVisible();
		await expect( page.locator( `a[href*="${ SHARED_PDF_SLUG }"]` ).first() ).toBeVisible();

		// Must NOT surface the unrelated public fixtures that F1 used to leak.
		const body = await page.locator( 'body' ).innerText();
		expect( body ).not.toMatch( /media\/158|media_id=158\b/ );
	} );

	test( '§2 Granted PDF opens for Member B', async ( { page } ) => {
		await autoLogin( page, MEMBER_B );
		const response = await page.goto( `/media/${ SHARED_PDF_SLUG }/`, {
			waitUntil: 'networkidle',
		} );
		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( '.mvs-media-title' ) ).toContainText( /qa-pdf/i );

		// Preview iframe must point at a deliverable URL (blank frame was a prior defect class).
		const iframe = page.locator( 'iframe[src*="/documents/"][src*="/preview"]' );
		await expect( iframe ).toBeVisible();
		const src = await iframe.getAttribute( 'src' );
		expect( src ).toBeTruthy();
		const preview = await page.request.get( src! );
		expect( preview.status(), `preview via ${ src }` ).toBe( 200 );
	} );

	test( '§2/§6 F2 Private doc 404s for non-grantee (never 403)', async ( { page } ) => {
		await autoLogin( page, NON_GRANTEE );
		const response = await page.goto( `/media/${ PRIVATE_TEXT_SLUG }/`, {
			waitUntil: 'domcontentloaded',
		} );
		expect( response?.status() ).toBe( 404 );
		// Branded 404 copy — must not confirm the document exists (403 / login prompt).
		await expect( page.locator( 'body' ) ).toContainText( /couldn'?t find that media|doesn'?t exist|has been removed|set to private/i );
		await expect( page.locator( '.mvs-media-title' ) ).toHaveCount( 0 );
		await expect( page.locator( 'body' ) ).not.toContainText( /log in to view/i );
	} );

	test( '§5 F3 Anon share link delivers preview + download', async ( {
		page,
		request,
	} ) => {
		// Mint via WP-CLI so we don't fight cookie nonce plumbing in wp-admin.
		const { execFileSync } = await import( 'node:child_process' );
		const token = execFileSync(
			'wp',
			[
				'eval',
				`update_option('mvs_pro_documents_anon_links','1');
global $wpdb;
$table=$wpdb->prefix.'mvs_access_grants';
$token=wp_generate_password(40,false,false);
$hash=hash('sha256',$token);
$ok=$wpdb->insert($table,array(
  'media_id'=>2252,
  'user_id'=>0,
  'granted_at'=>current_time('mysql',true),
  'expires_at'=>null,
  'source'=>'link',
  'target_type'=>'media',
  'grantee_type'=>'link',
  'grantee_role'=>'',
  'permission'=>'view',
  'token_hash'=>$hash,
));
echo $ok ? $token : ('FAIL:'.$wpdb->last_error);`,
				`--path=/Users/vapvarun/Local Sites/mediaverse/app/public`,
				'--allow-root',
			],
			{ encoding: 'utf8' }
		)
			.split( '\n' )
			.map( ( l ) => l.trim() )
			.filter( ( l ) => l && ! /Warning|imagick|Startup|Deprecated/i.test( l ) )
			.pop();

		expect( token, `token mint: ${ token }` ).toMatch( /^[A-Za-z0-9]{20,}$/ );

		await signedOut( page );
		const wrapper = await page.goto(
			`/media/${ SHARED_PDF_SLUG }/?mvs_doc_token=${ encodeURIComponent( token! ) }`,
			{ waitUntil: 'networkidle' }
		);
		expect( wrapper?.status() ).toBe( 200 );
		await expect( page.locator( '.mvs-media-title' ) ).toContainText( /qa-pdf/i );

		const preview = page.locator(
			`iframe[src*="/documents/2252/preview"], a[href*="/documents/2252/preview"]`
		).first();
		await expect( preview ).toBeVisible();
		const previewUrl = ( await preview.getAttribute( 'src' ) ) || ( await preview.getAttribute( 'href' ) );
		expect( previewUrl ).toContain( 'mvs_doc_token=' );

		const previewRes = await request.get( previewUrl! );
		expect( previewRes.status() ).toBe( 200 );
		expect( previewRes.headers()[ 'content-type' ] || '' ).toMatch( /pdf/i );

		const downloadRes = await request.get(
			`/wp-json/mvs-pro/v1/documents/2252/download?mvs_doc_token=${ encodeURIComponent( token! ) }`
		);
		expect( downloadRes.status() ).toBe( 200 );
	} );

	test( '§6 Explore documents page renders', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( '/explore-document/', { waitUntil: 'networkidle' } );
		await expect( page.locator( '.mvs-explore, body' ) ).toBeVisible();
		const html = await page.content();
		expect( html.length ).toBeGreaterThan( 1000 );
	} );

	test( '§7 Admin Documents list + single editor', async ( { page } ) => {
		await autoLogin( page, '1' );
		await page.goto( '/wp-admin/admin.php?page=mvs-documents', {
			waitUntil: 'networkidle',
		} );
		await expect( page.locator( 'body' ) ).toContainText( /Documents/i );
		await expect( page.locator( 'a[href*="view=single"], a[href*="media_id="]' ).first() ).toBeVisible();

		await page.goto(
			'/wp-admin/admin.php?page=mvs-documents&view=single&media_id=2252',
			{ waitUntil: 'networkidle' }
		);
		await expect( page.locator( 'body' ) ).toContainText( /qa-pdf|Title|Privacy/i );
	} );

	test( '§5 Settings Documents section present', async ( { page } ) => {
		await autoLogin( page, '1' );
		await page.goto( '/wp-admin/admin.php?page=mvs-settings#documents', {
			waitUntil: 'networkidle',
		} );
		// Settings is a client-side section switcher — click the Documents nav if hash alone is not enough.
		const docsNav = page.locator( 'a[href*="#documents"], [data-section="documents"], .mvs-settings-nav a' ).filter( { hasText: /^Documents$/i } );
		if ( await docsNav.count() ) {
			await docsNav.first().click();
		}
		await expect( page.locator( 'body' ) ).toContainText( /Who can use documents|Enable Documents|Anonymous share|Search inside documents|Maximum document/i, {
			timeout: 10_000,
		} );
	} );

	test( 'Planned Space routes still absent', async ( { request } ) => {
		const drives = await request.get( '/wp-json/mvs-pro/v1/drives' );
		expect( drives.status() ).toBe( 404 );

		const bulk = await request.get( '/wp-json/mvs-pro/v1/documents/bulk' );
		expect( [ 404, 401 ] ).toContain( bulk.status() );
	} );
} );
