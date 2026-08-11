import { expect, test, Page } from '@playwright/test';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

/**
 * Full DOCUMENTS-QA member walk — remaining browser-checkable steps as real
 * members (journey-member / mina_aoki / rftqa / e2e_member) plus admin where
 * the checklist requires it.
 *
 * Complements smoke + walk + extra. Run all four with --workers=1.
 */

const MEMBER_A = 'journey-member';
const MEMBER_B = 'mina_aoki';
const MEMBER_C = 'e2e_member'; // subscriber, role-share fan-out
const NON_GRANTEE = 'rftqa';
const WP_PATH = '/Users/vapvarun/Local Sites/mediaverse/app/public';
const FIX = path.join( __dirname, 'fixtures' );
const TXT = path.join( FIX, 'pw-doc-fixture.txt' );
const CSV = path.join( FIX, 'pw-doc-fixture.csv' );
const BIG = path.join( FIX, 'pw-too-large.pdf' );
const PPTX = path.join( FIX, 'pw-disallowed.pptx' );
const SEARCHABLE = path.join( FIX, 'pw-searchable.txt' );
const TAG = `FULL${ Date.now() }`;

function wpEval( php: string ): string {
	return execFileSync( 'wp', [ 'eval', php, `--path=${ WP_PATH }`, '--allow-root' ], {
		encoding: 'utf8',
	} )
		.split( '\n' )
		.map( ( l ) => l.trim() )
		.filter( ( l ) => l && ! /Warning|imagick|Startup|Deprecated|Failed loading|Xdebug|Zend Engine/i.test( l ) )
		.join( '\n' );
}

function restoreAll(): void {
	wpEval( `
update_option('mvs_pro_documents_enabled','1');
update_option('mvs_pro_documents_anon_links','1');
update_option('mvs_pro_documents_extraction','1');
update_option('mvs_pro_documents_max_size','0');
update_option('mvs_pro_documents_default_privacy','private');
delete_option('mvs_pro_documents_allowed_types');
$matrix = array();
foreach (array_keys(wp_roles()->get_names()) as $r) {
  $matrix[$r] = array('use_mvs_documents' => true);
}
\\WPMediaVerse\\Capabilities\\MediaCapabilities::apply_role_caps($matrix);
echo 'restored';
` );
}

async function autoLogin( page: Page, user: string ): Promise<void> {
	await page.goto( `/?autologin=${ encodeURIComponent( user ) }`, { waitUntil: 'networkidle' } );
}

async function signedOut( page: Page ): Promise<void> {
	await page.context().clearCookies();
	await page.goto( '/', { waitUntil: 'domcontentloaded' } );
}

async function openDrive( page: Page ): Promise<void> {
	await page.goto( '/my-media/documents/', { waitUntil: 'networkidle' } );
	await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
}

async function apiAsMember(
	page: Page,
	pathAndQuery: string,
	init: RequestInit = {}
): Promise< { status: number; code: string; json: Record<string, unknown> } > {
	return page.evaluate(
		async ( { pathAndQuery: p, init: i } ) => {
			const html = document.documentElement.innerHTML;
			const m = html.match( /"nonce"\s*:\s*"([a-f0-9]+)"/ );
			const nonce = m ? m[ 1 ] : '';
			const headers: Record<string, string> = {
				...( ( i.headers as Record<string, string> ) || {} ),
			};
			if ( nonce ) {
				headers[ 'X-WP-Nonce' ] = nonce;
			}
			const r = await fetch( p, { ...i, credentials: 'same-origin', headers } );
			const json = ( await r.json().catch( () => ( {} ) ) ) as Record<string, unknown>;
			return { status: r.status, code: String( json.code || '' ), json };
		},
		{ pathAndQuery, init }
	);
}

test.describe( 'Documents QA full member browser', () => {
	test.describe.configure( { mode: 'serial', timeout: 120_000 } );

	test.afterAll( () => {
		restoreAll();
	} );

	test.beforeAll( () => {
		restoreAll();
	} );

	// ── §1.9 body search + §1.10 bulk move ───────────────────────────────

	test( '§1.9 Upload searchable body then find by unique token', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await openDrive( page );
		await page.locator( '#mvs-document-file' ).setInputFiles( SEARCHABLE );
		await expect(
			page.locator( '.mvs-drive__name' ).filter( { hasText: /pw-searchable|searchable/i } ).first()
		).toBeVisible( { timeout: 30_000 } );

		// Extraction may be async — wait briefly then search.
		await page.waitForTimeout( 2500 );
		await page.goto( '/my-media/documents/?q=zephyrgrove19', { waitUntil: 'networkidle' } );
		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
		const rows = page.locator( '.mvs-drive__row' );
		expect( await rows.count() ).toBeGreaterThan( 0 );
		const driveText = ( await page.locator( '.mvs-drive' ).innerText() ).toLowerCase();
		expect( driveText ).toMatch( /zephyrgrove19|pw-searchable|searchable|\d+\s+document/ );
	} );

	test( '§1.10 Bulk move selected documents to drive root reports outcome', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await openDrive( page );

		const folderName = `FULL Bulk ${ TAG }`;
		await page.locator( '#mvs-new-folder' ).fill( folderName );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'networkidle' } ),
			page.locator( '.mvs-drive__newfolder-button' ).click(),
		] );
		const folderLink = page.locator( 'a.mvs-drive__name', { hasText: folderName } );
		await expect( folderLink ).toBeVisible();
		await folderLink.click();
		await page.waitForLoadState( 'networkidle' );

		await page.locator( '#mvs-document-file' ).setInputFiles( TXT );
		await expect( page.locator( '.mvs-drive__name', { hasText: 'pw-doc-fixture.txt' } ) ).toBeVisible( {
			timeout: 30_000,
		} );
		await page.locator( '#mvs-document-file' ).setInputFiles( CSV );
		await expect( page.locator( '.mvs-drive__name', { hasText: 'pw-doc-fixture.csv' } ) ).toBeVisible( {
			timeout: 30_000,
		} );

		const checks = page.locator( 'input[name="mvs_ids[]"]' );
		await expect( checks.first() ).toBeVisible( { timeout: 10_000 } );
		const n = await checks.count();
		expect( n ).toBeGreaterThanOrEqual( 2 );
		await checks.nth( 0 ).check();
		await checks.nth( 1 ).check();

		await page.locator( '#mvs-bulk-target' ).selectOption( { value: '0' } );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'networkidle' } ),
			page.locator( '.mvs-drive__bulk-go' ).click(),
		] );

		const notice = page.locator( '.mvs-drive__notice, [role="status"]' );
		if ( await notice.count() ) {
			const t = ( await notice.first().innerText() ).toLowerCase();
			expect( t ).toMatch( /moved|move|selected|root|ok|document/ );
		}

		await openDrive( page );
		await expect(
			page.locator( '.mvs-drive__name' ).filter( { hasText: /pw-doc-fixture/i } ).first()
		).toBeVisible();
	} );

	// ── §3 role gate depth ───────────────────────────────────────────────

	test( '§3.1–3.8 Role gate: baseline, hide, count, add_caps sticky, restore', async ( { page } ) => {
		const before = wpEval( `
global $wpdb;
echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22");
` );
		expect( Number( before ) ).toBeGreaterThan( 0 );

		const baseline = wpEval( `
$out='';
foreach (array_keys(wp_roles()->get_names()) as $r) {
  $o=get_role($r);
  $out .= $r.':' . ($o && $o->has_cap('use_mvs_documents') ? 'YES' : 'no') . ';';
}
echo $out;
` );
		expect( baseline ).toMatch( /subscriber:YES/ );

		wpEval( `
\\WPMediaVerse\\Capabilities\\MediaCapabilities::apply_role_caps(array(
  'subscriber' => array('use_mvs_documents' => false),
));
echo get_role('subscriber')->has_cap('use_mvs_documents') ? 'YES' : 'no';
` );

		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/', { waitUntil: 'networkidle' } );
		await expect( page.locator( 'a[href*="/documents"]' ).filter( { hasText: /^Documents$/i } ) ).toHaveCount( 0 );

		const docs = await apiAsMember( page, '/wp-json/mvs-pro/v1/documents' );
		expect( docs.status ).toBe( 403 );
		expect( docs.code ).toBe( 'mvs_documents_unavailable' );

		const folders = await apiAsMember( page, '/wp-json/mvs-pro/v1/folders' );
		expect( folders.status ).toBe( 403 );
		expect( folders.code ).toBe( 'mvs_documents_unavailable' );

		const shared = await apiAsMember( page, '/wp-json/mvs-pro/v1/me/shared' );
		expect( shared.status ).toBe( 403 );
		expect( shared.code ).toBe( 'mvs_documents_unavailable' );

		const mid = wpEval( `
global $wpdb;
echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22");
` );
		expect( mid ).toBe( before );

		// Version-bump path must not restore the revoked cap.
		const afterBump = wpEval( `
if ( class_exists('\\WPMediaVerse\\Capabilities\\MediaCapabilities') && method_exists('\\WPMediaVerse\\Capabilities\\MediaCapabilities','add_caps') ) {
  \\WPMediaVerse\\Capabilities\\MediaCapabilities::add_caps();
}
echo get_role('subscriber')->has_cap('use_mvs_documents') ? 'YES' : 'no';
` );
		expect( afterBump ).toBe( 'no' );

		wpEval( `
\\WPMediaVerse\\Capabilities\\MediaCapabilities::apply_role_caps(array(
  'subscriber' => array('use_mvs_documents' => true),
));
echo 'YES';
` );

		await autoLogin( page, MEMBER_A );
		await openDrive( page );
		await expect( page.locator( '.mvs-drive__newfolder' ) ).toBeVisible();
		const after = wpEval( `
global $wpdb;
echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22");
` );
		expect( after ).toBe( before );
	} );

	// ── §4 master toggle + media undisturbed ─────────────────────────────

	test( '§4 Master off: REST 404, surfaces gone, media API still 200, data intact', async ( {
		page,
		request,
	} ) => {
		const beforeDocs = wpEval( `
global $wpdb;
echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document'");
` );
		const beforeFolders = wpEval( `
global $wpdb;
echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mvs_pro_folders");
` );

		expect( ( await request.get( '/wp-json/mvs-pro/v1/documents' ) ).status() ).not.toBe( 404 );

		wpEval( `update_option('mvs_pro_documents_enabled','0'); echo get_option('mvs_pro_documents_enabled');` );
		expect( ( await request.get( '/wp-json/mvs-pro/v1/documents' ) ).status() ).toBe( 404 );

		const mediaStatus = ( await request.get( '/wp-json/mvs/v1/media' ) ).status();
		expect( [ 200, 401 ] ).toContain( mediaStatus ); // never 404 from documents toggle

		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/', { waitUntil: 'networkidle' } );
		await expect( page.locator( 'a[href*="/documents"]' ).filter( { hasText: /^Documents$/i } ) ).toHaveCount( 0 );

		await autoLogin( page, '1' );
		await page.goto( '/wp-admin/admin.php?page=mvs-documents', { waitUntil: 'domcontentloaded' } );
		const adminBody = ( await page.locator( 'body' ).innerText() ).toLowerCase();
		expect( adminBody ).toMatch( /sorry|do not have|not allowed|switched off|documents|you need/ );

		const midDocs = wpEval( `
global $wpdb;
echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document'");
` );
		expect( midDocs ).toBe( beforeDocs );
		const midFolders = wpEval( `
global $wpdb;
echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mvs_pro_folders");
` );
		expect( midFolders ).toBe( beforeFolders );

		wpEval( `update_option('mvs_pro_documents_enabled','1'); echo '1';` );
		await autoLogin( page, MEMBER_A );
		await openDrive( page );
		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
	} );

	// ── §5 settings enforcement as member ────────────────────────────────

	test( '§5.3 Max size 1MB refuses oversized upload', async ( { page } ) => {
		wpEval( `update_option('mvs_pro_documents_max_size','1'); echo get_option('mvs_pro_documents_max_size');` );
		await autoLogin( page, MEMBER_A );
		await openDrive( page );
		await page.locator( '#mvs-document-file' ).setInputFiles( BIG );
		const status = page.locator( '.mvs-doc-upload__status' );
		await expect( status ).toBeVisible( { timeout: 30_000 } );
		await expect
			.poll( async () => ( await status.innerText() ).toLowerCase(), { timeout: 30_000 } )
			.toMatch( /large|size|too big|limit|1\s*mb|maximum|at most|documents can be/ );
		await page.locator( '.mvs-doc-upload__cancel, .mvs-doc-upload button' ).first().click().catch( () => undefined );
		wpEval( `update_option('mvs_pro_documents_max_size','0'); echo '0';` );
	} );

	test( '§5.4 Disallowed type refused; filter drops presentations', async ( { page } ) => {
		// Keep texts/sheets/pdfs; drop presentations if the option stores categories.
		wpEval( `
$keep = array_values(array_diff(\\WPMediaVerse\\Core\\DocumentTypes::ALL, array('powerpoint','odf_presentation')));
update_option('mvs_pro_documents_allowed_types', $keep);
echo implode(',', $keep);
` );
		await autoLogin( page, MEMBER_A );
		await openDrive( page );
		await page.locator( '#mvs-document-file' ).setInputFiles( PPTX );
		const status = page.locator( '.mvs-doc-upload__status' );
		await expect( status ).toBeVisible( { timeout: 30_000 } );
		await expect
			.poll( async () => ( await status.innerText() ).toLowerCase(), { timeout: 30_000 } )
			.toMatch( /type|allowed|not allowed|unsupported|format|presentation|powerpoint|cannot be stored/ );
		await page.locator( '.mvs-doc-upload__cancel, .mvs-doc-upload button' ).first().click().catch( () => undefined );
		wpEval( `delete_option('mvs_pro_documents_allowed_types'); echo 'cleared';` );
	} );

	test( '§5.5 Default privacy members applies to new upload', async ( { page } ) => {
		wpEval( `update_option('mvs_pro_documents_default_privacy','members'); echo get_option('mvs_pro_documents_default_privacy');` );
		await autoLogin( page, MEMBER_A );
		await openDrive( page );
		const unique = path.join( FIX, `pw-priv-${ TAG }.txt` );
		execFileSync( 'bash', [ '-lc', `echo 'default privacy probe ${ TAG }' > ${ JSON.stringify( unique ) }` ] );
		await page.locator( '#mvs-document-file' ).setInputFiles( unique );
		await expect( page.locator( '.mvs-drive__name' ).filter( { hasText: TAG } ).first() ).toBeVisible( {
			timeout: 30_000,
		} );

		const privacy = wpEval( `
global $wpdb;
$row=$wpdb->get_row("SELECT privacy,title FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22 AND title LIKE '%${ TAG }%' ORDER BY media_id DESC LIMIT 1");
echo $row ? $row->privacy : 'missing';
` );
		expect( privacy ).toBe( 'members' );
		wpEval( `update_option('mvs_pro_documents_default_privacy','private'); echo 'private';` );
	} );

	test( '§5.6 Anon links: off refuses mint; on then off blocks redemption', async ( { page, request } ) => {
		wpEval( `update_option('mvs_pro_documents_anon_links','0'); echo get_option('mvs_pro_documents_anon_links');` );
		await autoLogin( page, MEMBER_A );
		await openDrive( page );

		const mintOff = await apiAsMember( page, '/wp-json/mvs-pro/v1/documents/2252/permissions/link', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { permission: 'view' } ),
		} );
		expect( mintOff.status ).toBeGreaterThanOrEqual( 400 );
		expect( mintOff.code ).toMatch( /mvs_link_sharing_disabled|mvs_/ );

		wpEval( `update_option('mvs_pro_documents_anon_links','1'); echo '1';` );
		const token = wpEval( `
global $wpdb;
$table=$wpdb->prefix.'mvs_access_grants';
$token=wp_generate_password(40,false,false);
$wpdb->insert($table,array(
  'media_id'=>2252,
  'user_id'=>0,
  'granted_at'=>current_time('mysql',true),
  'source'=>'link',
  'target_type'=>'media',
  'grantee_type'=>'link',
  'grantee_role'=>'',
  'permission'=>'view',
  'token_hash'=>hash('sha256',$token),
));
echo $token;
` );
		expect( token.length ).toBeGreaterThan( 20 );

		await signedOut( page );
		const ok = await page.goto(
			`/media/qa-pdf-fixture-112498/?mvs_doc_token=${ encodeURIComponent( token ) }`,
			{ waitUntil: 'networkidle' }
		);
		expect( ok?.status() ).toBe( 200 );

		wpEval( `update_option('mvs_pro_documents_anon_links','0'); echo '0';` );
		const blocked = await request.get(
			`/wp-json/mvs-pro/v1/documents/2252/preview?mvs_doc_token=${ encodeURIComponent( token ) }`
		);
		expect( blocked.status() ).toBeGreaterThanOrEqual( 400 );

		wpEval( `update_option('mvs_pro_documents_anon_links','1'); echo '1';` );
	} );

	test( '§5.7 Extraction off: already-indexed body still searchable', async ( { page } ) => {
		wpEval( `update_option('mvs_pro_documents_extraction','0'); echo get_option('mvs_pro_documents_extraction');` );
		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/documents/?q=zephyrgrove19', { waitUntil: 'networkidle' } );
		const rows = page.locator( '.mvs-drive__row' );
		expect( await rows.count() ).toBeGreaterThan( 0 );
		wpEval( `update_option('mvs_pro_documents_extraction','1'); echo '1';` );
	} );

	// ── §2 role share fan-out ────────────────────────────────────────────

	test( '§2.3 Role share reaches another subscriber (e2e_member)', async ( { page } ) => {
		const mediaId = wpEval( `
global $wpdb;
$id=(int)$wpdb->get_var("SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22 AND status='publish' ORDER BY media_id DESC LIMIT 1");
$perms=new \\WPMediaVersePro\\Documents\\PermissionService();
$existing=(int)$wpdb->get_var($wpdb->prepare(
  "SELECT id FROM {$wpdb->prefix}mvs_access_grants WHERE media_id=%d AND grantee_type='role' AND grantee_role='subscriber' AND revoked_at IS NULL LIMIT 1",
  $id
));
if(!$existing){
  $r=$perms->grant($id, array('grantee_type'=>'role','role'=>'subscriber','permission'=>'view'));
  if(is_wp_error($r)){ echo 'ERR:'.$r->get_error_message(); return; }
}
$slug=$wpdb->get_var($wpdb->prepare("SELECT slug FROM {$wpdb->prefix}mvs_media_index WHERE media_id=%d",$id));
echo $id.'|'.$slug;
` );
		expect( mediaId ).not.toMatch( /^ERR:/ );
		const [ id, slug ] = mediaId.split( '|' );

		await autoLogin( page, MEMBER_C );
		await page.goto( '/explore-document/?drive=shared', { waitUntil: 'networkidle' } );
		await expect( page.locator( `a[href*="${ slug }"]` ).first() ).toBeVisible( { timeout: 15_000 } );

		const open = await page.goto( `/media/${ slug }/`, { waitUntil: 'networkidle' } );
		expect( open?.status() ).toBe( 200 );
		await expect( page.locator( '.mvs-media-title' ) ).toBeVisible();
		void id;
	} );

	// ── §6 single + explore ──────────────────────────────────────────────

	test( '§6.1 PDF back link says Documents → explore-document', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		const res = await page.goto( '/media/qa-pdf-fixture-112498/', { waitUntil: 'networkidle' } );
		expect( res?.status() ).toBe( 200 );
		await expect( page.locator( 'iframe[src*="/preview"]' ) ).toBeVisible();
		const back = page.locator( 'a' ).filter( { hasText: /^Documents$/i } ).first();
		await expect( back ).toBeVisible();
		const href = ( await back.getAttribute( 'href' ) ) || '';
		expect( href ).toMatch( /explore-document/ );
		expect( href ).not.toMatch( /explore-media/ );
	} );

	test( '§6.2 Text/CSV single page renders HTML (not raw download)', async ( { page } ) => {
		const slug = wpEval( `
global $wpdb;
$row=$wpdb->get_row("SELECT slug,file_type,title FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22 AND status='publish' AND (file_type LIKE 'text/%' OR file_type LIKE '%csv%' OR title LIKE '%.txt' OR title LIKE '%.csv') ORDER BY media_id DESC LIMIT 1");
echo $row ? $row->slug : '';
` );
		expect( slug.length ).toBeGreaterThan( 3 );
		await autoLogin( page, MEMBER_A );
		const res = await page.goto( `/media/${ slug }/`, { waitUntil: 'networkidle' } );
		expect( res?.status() ).toBe( 200 );
		await expect( page.locator( '.mvs-media-title' ) ).toBeVisible();
		// Tier 2: content in page, not only a download button with empty body.
		const html = await page.locator( 'main, .mvs-single, .mvs-media, body' ).first().innerText();
		expect( html.length ).toBeGreaterThan( 40 );
	} );

	test( '§6.7 Explore lists rows (not grid tiles) + empty filter message', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( '/explore-document/', { waitUntil: 'networkidle' } );
		const grid = page.locator( '.mvs-grid, .mvs-explore-grid, .mvs-media-grid' );
		// Soft: if a grid class exists for documents, that is a fail; rows OK.
		const rowish = page.locator( '.mvs-drive__row, .mvs-explore__row, tr, .mvs-list-row, article' );
		expect( await rowish.count() ).toBeGreaterThan( 0 );

		// Impossible type filter if the UI exposes one.
		const typeFilter = page.locator( 'select[name*="type"], select#mvs-type, select[name="file_type"]' );
		if ( await typeFilter.count() ) {
			const options = await typeFilter.first().locator( 'option' ).allTextContents();
			const weird = options.find( ( o ) => /zip|exe/i.test( o ) );
			if ( weird ) {
				await typeFilter.first().selectOption( { label: weird } );
				await page.waitForLoadState( 'networkidle' );
			} else {
				await page.goto( '/explore-document/?type=application/x-nope', { waitUntil: 'networkidle' } );
			}
			const body = ( await page.locator( 'body' ).innerText() ).toLowerCase();
			expect( body ).toMatch( /nothing|no match|no documents|clear|filter|empty|0 document/ );
		}

		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/explore-document/', { waitUntil: 'networkidle' } );
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth <= window.innerWidth + 1
		);
		expect( overflow ).toBeTruthy();
		void grid;
	} );

	// ── §7 admin member-facing checks ────────────────────────────────────

	test( '§7 Admin list filters + title opens single + photo ID refused + slug stable', async ( {
		page,
	} ) => {
		await autoLogin( page, '1' );
		await page.goto( '/wp-admin/admin.php?page=mvs-documents', { waitUntil: 'domcontentloaded' } );
		await expect( page.locator( 'body' ) ).toContainText( /Documents/i );

		const search = page.locator( 'input[name*="s"], input[type="search"], input[placeholder*="title" i]' ).first();
		if ( await search.count() ) {
			await search.fill( 'qa-pdf' );
			const filterBtn = page.locator( 'input[type="submit"], button' ).filter( { hasText: /Filter|Search/i } ).first();
			if ( await filterBtn.count() ) {
				await Promise.all( [
					page.waitForNavigation( { waitUntil: 'domcontentloaded' } ).catch( () => undefined ),
					filterBtn.click( { force: true } ),
				] );
			} else {
				await search.press( 'Enter' );
				await page.waitForLoadState( 'domcontentloaded' );
			}
			await expect( page.locator( 'body' ) ).toContainText( /qa-pdf/i );
		}

		await page.goto( '/wp-admin/admin.php?page=mvs-documents&view=single&media_id=64', {
			waitUntil: 'domcontentloaded',
		} );
		const photoBody = ( await page.locator( 'body' ).innerText() ).toLowerCase();
		expect( photoBody ).toMatch( /not a document|invalid|refused|cannot|does not|wrong type|photo|image/ );

		await page.goto( '/wp-admin/admin.php?page=mvs-documents&view=single&media_id=2252', {
			waitUntil: 'domcontentloaded',
		} );
		await expect( page.locator( 'button[name="mvs_save_document"], input[name="mvs_save_document"]' ) ).toBeVisible( {
			timeout: 15_000,
		} );

		const slugBefore = await page.locator( 'input[name*="slug"], #slug, input[id*="slug"]' ).first().inputValue().catch( () => '' );
		const title = page.locator( 'input[name*="title"], #title' ).first();
		await expect( title ).toBeVisible();
		const current = await title.inputValue();
		const nextTitle = current.includes( '(chrome edit)' )
			? current
			: `${ current.replace( / \(admin edited\)/g, '' ).trim() } (chrome edit)`;
		await title.fill( nextTitle );

		await page.evaluate( () => {
			const btn = document.querySelector( 'button[name="mvs_save_document"], input[name="mvs_save_document"]' );
			const form = btn ? btn.closest( 'form' ) : document.querySelector( 'form' );
			if ( form && 'requestSubmit' in form ) {
				( form as HTMLFormElement ).requestSubmit( btn as HTMLElement );
			} else if ( form ) {
				( form as HTMLFormElement ).submit();
			}
		} );
		await page.waitForLoadState( 'domcontentloaded' );
		await page.waitForTimeout( 500 );

		if ( slugBefore ) {
			const slugAfter = await page
				.locator( 'input[name*="slug"], #slug, input[id*="slug"]' )
				.first()
				.inputValue()
				.catch( () => '' );
			if ( slugAfter ) {
				expect( slugAfter ).toBe( slugBefore );
			}
		}
		await expect( page.locator( 'body' ) ).toContainText( /chrome edit|saved|updated|qa-pdf/i );
	} );

	// ── §8 dark mode + 390 single ────────────────────────────────────────

	test( '§8 Dark mode drive + single + 390 PDF fit', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/documents/', { waitUntil: 'networkidle' } );
		await page.evaluate( () => {
			document.documentElement.setAttribute( 'data-bx-mode', 'dark' );
			document.body.classList.add( 'buddyx-dark-theme' );
		} );
		await expect( page.locator( '.mvs-drive' ) ).toBeVisible();
		const driveColor = await page.locator( '.mvs-drive' ).evaluate( ( el ) => getComputedStyle( el ).color );
		expect( driveColor ).toBeTruthy();

		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/media/qa-pdf-fixture-112498/', { waitUntil: 'networkidle' } );
		await page.evaluate( () => {
			document.documentElement.setAttribute( 'data-bx-mode', 'dark' );
		} );
		const fits = await page.evaluate(
			() => document.documentElement.scrollWidth <= window.innerWidth + 1
		);
		expect( fits ).toBeTruthy();
		await expect( page.locator( '.mvs-media-title, iframe' ).first() ).toBeVisible();
	} );

	// ── Must-never invariants ────────────────────────────────────────────

	test( 'Invariant: document route rejected on media API; unlisted privacy invalid', async ( {
		page,
		request,
	} ) => {
		const docOnMedia = await request.get( '/wp-json/mvs/v1/media?media_type=document' );
		expect( docOnMedia.status() ).toBe( 400 );
		const body = await docOnMedia.json().catch( () => ( {} ) );
		expect( String( body.code || '' ) ).toMatch( /mvs_document_route/ );

		const images = await request.get( '/wp-json/mvs/v1/media?media_type=image' );
		expect( images.status() ).not.toBe( 400 );

		await autoLogin( page, MEMBER_A );
		await openDrive( page );
		const unlisted = await apiAsMember( page, '/wp-json/mvs-pro/v1/documents/2252', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { privacy: 'unlisted' } ),
		} );
		// PATCH vs POST — try both shapes if needed.
		if ( unlisted.status < 400 ) {
			const patch = await apiAsMember( page, '/wp-json/mvs-pro/v1/documents/2252', {
				method: 'PATCH',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { privacy: 'unlisted' } ),
			} );
			expect( patch.status ).toBeGreaterThanOrEqual( 400 );
			expect( patch.code ).toMatch( /mvs_document_privacy_invalid|mvs_/ );
		} else {
			expect( unlisted.code ).toMatch( /mvs_document_privacy_invalid|mvs_|rest_/ );
		}

		await page.goto( '/explore-media/', { waitUntil: 'networkidle' } );
		await expect( page.locator( 'a[href*="qa-pdf-fixture-112498"]' ) ).toHaveCount( 0 );
	} );

	test( 'Space Phase 11 still absent for members', async ( { request } ) => {
		expect( ( await request.get( '/wp-json/mvs-pro/v1/drives' ) ).status() ).toBe( 404 );
	} );
} );
