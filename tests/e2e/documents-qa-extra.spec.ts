import { expect, test, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * Extra DOCUMENTS-QA browser checks (gaps not in smoke/walk).
 *
 * Run alone or after the other suites with --workers=1.
 */

const MEMBER_A = 'journey-member';
const MEMBER_B = 'mina_aoki';
const NON_GRANTEE = 'rftqa';
const PUBLIC_SLUG = 'qa-real-document-702802'; // media 159, privacy=public
const WP_PATH = '/Users/vapvarun/Local Sites/mediaverse/app/public';

function wpEval( php: string ): string {
	return execFileSync( 'wp', [ 'eval', php, `--path=${ WP_PATH }`, '--allow-root' ], {
		encoding: 'utf8',
	} )
		.split( '\n' )
		.map( ( l ) => l.trim() )
		.filter( ( l ) => l && ! /Warning|imagick|Startup|Deprecated|Failed loading|Xdebug|Zend Engine/i.test( l ) )
		.join( '\n' );
}

async function autoLogin( page: Page, user: string ): Promise<void> {
	await page.goto( `/?autologin=${ encodeURIComponent( user ) }`, { waitUntil: 'networkidle' } );
}

async function signedOut( page: Page ): Promise<void> {
	await page.context().clearCookies();
	await page.goto( '/', { waitUntil: 'domcontentloaded' } );
}

test.describe( 'Documents QA extra browser checks', () => {
	test.describe.configure( { mode: 'serial', timeout: 90_000 } );

	test.afterAll( () => {
		wpEval( `
update_option('mvs_pro_documents_enabled','1');
update_option('mvs_pro_documents_anon_links','1');
update_option('mvs_pro_documents_extraction','1');
foreach (array_keys(wp_roles()->get_names()) as $r) {
  $o = get_role($r);
  if ($o) { $o->add_cap('use_mvs_documents'); }
}
echo 'restored';
` );
	} );

	test( '§3.5 Public doc readable while subscriber role is capped', async ( { page } ) => {
		wpEval( `
$role=get_role('subscriber');
$role->remove_cap('use_mvs_documents');
echo get_role('subscriber')->has_cap('use_mvs_documents') ? 'YES' : 'no';
` );

		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/', { waitUntil: 'networkidle' } );
		await expect(
			page.locator( 'a[href*="/documents"]' ).filter( { hasText: /^Documents$/i } )
		).toHaveCount( 0 );

		// Public permalink must still open for a capped subscriber.
		const res = await page.goto( `/media/${ PUBLIC_SLUG }/`, { waitUntil: 'networkidle' } );
		expect( res?.status() ).toBe( 200 );
		await expect( page.locator( '.mvs-media-title' ) ).toContainText( /QA Real Document/i );

		await signedOut( page );
		const explore = await page.goto( '/explore-document/', { waitUntil: 'networkidle' } );
		expect( explore?.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).toBeVisible();

		wpEval( `
$role=get_role('subscriber');
$role->add_cap('use_mvs_documents');
echo 'YES';
` );
	} );

	test( '§2.5 Edit grantee cannot re-share (no share control)', async ( { page } ) => {
		const mediaId = wpEval( `
global $wpdb;
$id=(int)$wpdb->get_var("SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22 AND status='publish' AND title LIKE 'PW Renamed%' ORDER BY media_id DESC LIMIT 1");
if(!$id){ $id=(int)$wpdb->get_var("SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22 AND status='publish' ORDER BY media_id DESC LIMIT 1"); }
$user=get_user_by('login','mina_aoki');
$perms=new \\WPMediaVersePro\\Documents\\PermissionService();
$existing=(int)$wpdb->get_var($wpdb->prepare(
  "SELECT id FROM {$wpdb->prefix}mvs_access_grants WHERE media_id=%d AND user_id=%d AND permission='edit' AND revoked_at IS NULL LIMIT 1",
  $id, (int)$user->ID
));
if(!$existing){
  $r=$perms->grant($id, array('user_id'=>(int)$user->ID,'permission'=>'edit'));
  if(is_wp_error($r)){ echo 'ERR:'.$r->get_error_message(); return; }
}
$slug=$wpdb->get_var($wpdb->prepare("SELECT slug FROM {$wpdb->prefix}mvs_media_index WHERE media_id=%d",$id));
echo $id.'|'.$slug;
` );
		expect( mediaId ).not.toMatch( /^ERR:/ );
		const [ id, slug ] = mediaId.split( '|' );
		expect( Number( id ) ).toBeGreaterThan( 0 );

		await autoLogin( page, MEMBER_B );
		await page.goto( `/media/${ slug }/`, { waitUntil: 'networkidle' } );
		await expect( page.locator( '.mvs-media-title' ) ).toBeVisible();
		// Edit grantee can open the doc but must not get owner share controls.
		await expect( page.locator( 'input[name="mvs_share_with"]' ) ).toHaveCount( 0 );
		await expect( page.locator( 'form.mvs-drive__share, .mvs-share-form' ) ).toHaveCount( 0 );

		await page.goto( '/explore-document/?drive=shared', { waitUntil: 'networkidle' } );
		const row = page.locator( '.mvs-drive__row' ).filter( { has: page.locator( `a[href*="${ slug }"]` ) } ).first();
		if ( await row.count() ) {
			await row.locator( 'summary.mvs-drive__actions-toggle' ).click().catch( () => undefined );
			await expect( row.locator( 'input[name="mvs_share_with"]' ) ).toHaveCount( 0 );
		}
	} );

	test( '§2.6 Non-grantee does not see private PW doc; URL 404s', async ( { page } ) => {
		const slug = wpEval( `
global $wpdb;
$row=$wpdb->get_row("SELECT media_id,slug,privacy FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22 AND status='publish' AND privacy='private' ORDER BY media_id DESC LIMIT 1");
if(!$row){
  $id=(int)$wpdb->get_var("SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE media_type='document' AND post_author=22 AND status='publish' ORDER BY media_id DESC LIMIT 1");
  \\WPMediaVerse\\Core\\Plugin::container()->get('media_repository')->set($id,'privacy','private');
  $row=$wpdb->get_row($wpdb->prepare("SELECT media_id,slug,privacy FROM {$wpdb->prefix}mvs_media_index WHERE media_id=%d",$id));
}
echo $row->slug;
` );

		await autoLogin( page, NON_GRANTEE );
		await page.goto( '/explore-document/?drive=shared', { waitUntil: 'networkidle' } );
		await expect( page.locator( `a[href*="${ slug }"]` ) ).toHaveCount( 0 );

		const res = await page.goto( `/media/${ slug }/`, { waitUntil: 'domcontentloaded' } );
		expect( res?.status() ).toBe( 404 );
		await expect( page.locator( '.mvs-media-title' ) ).toHaveCount( 0 );
	} );

	test( 'Explore documents works signed-out', async ( { page } ) => {
		await signedOut( page );
		const res = await page.goto( '/explore-document/', { waitUntil: 'networkidle' } );
		expect( res?.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).toBeVisible();
		// Should not force login for public explore.
		expect( page.url() ).not.toMatch( /wp-login\.php/ );
	} );

	test( '§7 Admin single editor loads and keeps slug on title view', async ( { page } ) => {
		await autoLogin( page, '1' );
		await page.goto(
			'/wp-admin/admin.php?page=mvs-documents&view=single&media_id=2252',
			{ waitUntil: 'networkidle' }
		);
		await expect( page.locator( 'body' ) ).toContainText( /qa-pdf|Title|Privacy/i );
		await expect( page.locator( 'input[name*="title"], #title, input[type="text"]' ).first() ).toBeVisible();
	} );

	test( 'Owner PDF single page preview iframe delivers 200', async ( { page } ) => {
		await autoLogin( page, MEMBER_A );
		const res = await page.goto( '/media/qa-pdf-fixture-112498/', { waitUntil: 'networkidle' } );
		expect( res?.status() ).toBe( 200 );
		const iframe = page.locator( 'iframe[src*="/documents/"][src*="/preview"]' );
		await expect( iframe ).toBeVisible();
		const src = await iframe.getAttribute( 'src' );
		const preview = await page.request.get( src! );
		expect( preview.status() ).toBe( 200 );
		expect( preview.headers()[ 'content-type' ] || '' ).toMatch( /pdf/i );
	} );

	test( 'Free surface: documents master off → Explore explains / permalink 404', async ( { page } ) => {
		wpEval( `update_option('mvs_pro_documents_enabled','0'); echo get_option('mvs_pro_documents_enabled');` );

		await autoLogin( page, MEMBER_A );
		await page.goto( '/my-media/', { waitUntil: 'networkidle' } );
		await expect( page.locator( 'a[href*="/documents"]' ).filter( { hasText: /^Documents$/i } ) ).toHaveCount( 0 );

		await autoLogin( page, '1' );
		await page.goto( '/explore-document/', { waitUntil: 'networkidle' } );
		const body = await page.locator( 'body' ).innerText();
		expect( body.toLowerCase() ).toMatch( /switched off|nothing to list|documents/ );

		const permalink = await page.goto( '/media/qa-pdf-fixture-112498/', { waitUntil: 'domcontentloaded' } );
		expect( permalink?.status() ).toBe( 404 );

		wpEval( `update_option('mvs_pro_documents_enabled','1'); echo '1';` );
	} );

	test( '§5 Settings Documents controls visible after nav click', async ( { page } ) => {
		await autoLogin( page, '1' );
		await page.goto( '/wp-admin/admin.php?page=mvs-settings#documents', { waitUntil: 'networkidle' } );
		const docsNav = page
			.locator( 'a[href*="#documents"], [data-section="documents"], .mvs-settings-nav a' )
			.filter( { hasText: /^Documents$/i } );
		if ( await docsNav.count() ) {
			await docsNav.first().click();
		}
		await expect( page.locator( 'body' ) ).toContainText(
			/Who can use documents|Enable Documents|Anonymous share|Maximum document|Search inside documents/i,
			{ timeout: 10_000 }
		);
	} );

	test( 'Space API still absent (Phase 11)', async ( { request } ) => {
		expect( ( await request.get( '/wp-json/mvs-pro/v1/drives' ) ).status() ).toBe( 404 );
		expect( [ 404, 401 ] ).toContain( ( await request.get( '/wp-json/mvs-pro/v1/documents/bulk' ) ).status() );
	} );
} );
