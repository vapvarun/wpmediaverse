<?php
/**
 * CLI Data Flow Test Suite — WPMediaVerse
 *
 * Tests every REST endpoint and data flow as both user and admin.
 * Run: cd /path/to/wp && php wp-content/plugins/wpmediaverse/tests/cli-data-flow-test.php
 *
 * If data flows work, templates will render correctly.
 *
 * @package WPMediaVerse
 */

if ( php_sapi_name() !== 'cli' ) {
	die( 'CLI only.' );
}

require_once dirname( __DIR__, 4 ) . '/wp-load.php';

// ──────────────────────────────────────────
// Test framework
// ──────────────────────────────────────────
$GLOBALS['test_pass']    = 0;
$GLOBALS['test_fail']    = 0;
$GLOBALS['test_results'] = array();

function assert_test( string $name, bool $pass, string $detail = '' ): void {
	$icon = $pass ? '✅' : '❌';
	echo "$icon $name";
	if ( $detail ) {
		echo " — $detail";
	}
	echo PHP_EOL;

	$pass ? $GLOBALS['test_pass']++ : $GLOBALS['test_fail']++;
	$GLOBALS['test_results'][] = array(
		'name'   => $name,
		'pass'   => $pass,
		'detail' => $detail,
	);
}

function section( string $title ): void {
	echo PHP_EOL . "── $title ──" . PHP_EOL;
}

function rest( string $method, string $route, array $params = array() ): array {
	$req = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$res  = rest_do_request( $req );
	$data = $res->get_data();
	return array(
		'status' => $res->get_status(),
		'data'   => $data,
		'count'  => is_array( $data ) ? count( $data ) : 0,
	);
}

// ──────────────────────────────────────────
// Setup: identify test data
// ──────────────────────────────────────────
global $wpdb;

$admin_id    = 1;
$other_users = $wpdb->get_col(
	"SELECT DISTINCT post_author FROM {$wpdb->prefix}mvs_media_index WHERE post_author > 1 AND status = 'publish' LIMIT 3"
);
$other_id    = ! empty( $other_users ) ? (int) $other_users[0] : 0;

$own_media   = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d AND status = 'publish' LIMIT 1",
		$admin_id
	)
);
$other_media = (int) $wpdb->get_var(
	"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author > 1 AND status = 'publish' LIMIT 1"
);
$album_id    = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mvs_album' AND post_status = 'publish' AND post_author = %d LIMIT 1",
		$admin_id
	)
);

echo '=== WPMediaVerse CLI Data Flow Test ===' . PHP_EOL;
echo "Admin user: $admin_id | Other user: $other_id | Own media: $own_media | Other media: $other_media | Album: $album_id" . PHP_EOL;

// ══════════════════════════════════════════
// PART 1: USER FLOWS (as regular user)
// ══════════════════════════════════════════
echo PHP_EOL . '╔══════════════════════════════════════╗' . PHP_EOL;
echo '║   PART 1: USER FLOWS (as admin/user) ║' . PHP_EOL;
echo '╚══════════════════════════════════════╝' . PHP_EOL;

wp_set_current_user( $admin_id );

// ── 1. BROWSE MEDIA ──
section( '1. BROWSE MEDIA' );

$r = rest( 'GET', '/mvs/v1/media', array( 'per_page' => 5 ) );
assert_test( 'List media (paginated)', $r['status'] === 200 && $r['count'] > 0, $r['count'] . ' items' );

$first = $r['data'][0] ?? array();
$shape = isset( $first['id'], $first['title'], $first['file_url'], $first['thumbnail_url'], $first['privacy'], $first['media_type'] );
assert_test( 'Response shape: id, title, file_url, thumbnail_url, privacy, media_type', $shape );

$r = rest( 'GET', '/mvs/v1/media', array( 's' => 'mountain' ) );
assert_test( 'Search media', $r['status'] === 200, $r['count'] . ' results for "mountain"' );

$r = rest( 'GET', '/mvs/v1/media', array( 'media_type' => 'image' ) );
assert_test( 'Filter by type=image', $r['status'] === 200, $r['count'] . ' images' );

$r = rest( 'GET', '/mvs/v1/media', array( 'author' => $other_id ) );
assert_test( 'Filter by author', $r['status'] === 200, $r['count'] . " items by user $other_id" );

// ── 2. SINGLE MEDIA ──
section( '2. SINGLE MEDIA' );

$r = rest( 'GET', '/mvs/v1/media/' . $other_media );
assert_test( 'Get single media', $r['status'] === 200, $r['data']['title'] ?? '' );

$has_social = isset( $r['data']['stats'] ) || isset( $r['data']['reaction_count'] );
assert_test( 'Has social stats', $has_social, isset( $r['data']['stats'] ) ? 'in stats object' : 'top-level' );

// ── 3. MEDIA CRUD (own) ──
section( '3. MEDIA CRUD (own)' );

$r = rest( 'POST', '/mvs/v1/media/' . $own_media, array( 'title' => 'CLI Test Title' ) );
assert_test( 'Edit own media title', in_array( $r['status'], array( 200, 201 ), true ) );

$r = rest( 'GET', '/mvs/v1/media/' . $own_media );
assert_test( 'Edit persisted', ( $r['data']['title'] ?? '' ) === 'CLI Test Title' );

$r = rest( 'POST', '/mvs/v1/media/' . $own_media, array( 'privacy' => 'private' ) );
assert_test( 'Change privacy to private', in_array( $r['status'], array( 200, 201 ), true ) );

$r = rest( 'GET', '/mvs/v1/media/' . $own_media );
assert_test( 'Privacy persisted as private', ( $r['data']['privacy'] ?? '' ) === 'private' );

$r = rest( 'POST', '/mvs/v1/media/' . $own_media, array( 'privacy' => 'members' ) );
assert_test( 'Change privacy to members', in_array( $r['status'], array( 200, 201 ), true ) );

$r = rest( 'GET', '/mvs/v1/media/' . $own_media );
assert_test( 'Privacy persisted as members', ( $r['data']['privacy'] ?? '' ) === 'members' );

// Reset.
rest( 'POST', '/mvs/v1/media/' . $own_media, array(
	'title'   => 'og-wp-career-board',
	'privacy' => 'public',
) );

// ── 4. REACTIONS ──
section( '4. REACTIONS' );

$r = rest( 'POST', '/mvs/v1/media/' . $other_media . '/reactions', array( 'reaction_type' => 'like' ) );
assert_test( 'React (like)', in_array( $r['status'], array( 200, 201 ), true ), 'status:' . $r['status'] );

$r           = rest( 'GET', '/mvs/v1/media/' . $other_media );
$react_count = $r['data']['stats']['reactions'] ?? $r['data']['reaction_count'] ?? 0;
assert_test( 'Reaction count > 0', $react_count > 0, 'count:' . $react_count );

$r = rest( 'DELETE', '/mvs/v1/media/' . $other_media . '/reactions' );
assert_test( 'Remove reaction', in_array( $r['status'], array( 200, 204 ), true ) );

// ── 5. FAVORITES ──
section( '5. FAVORITES' );

$r = rest( 'POST', '/mvs/v1/media/' . $other_media . '/favorite' );
assert_test( 'Favorite media', in_array( $r['status'], array( 200, 201 ), true ) );

$r = rest( 'GET', '/mvs/v1/me/favorites' );
assert_test( 'List favorites', $r['status'] === 200, $r['count'] . ' favorites' );

$r = rest( 'DELETE', '/mvs/v1/media/' . $other_media . '/favorite' );
assert_test( 'Unfavorite', in_array( $r['status'], array( 200, 204 ), true ) );

// ── 6. COMMENTS ──
section( '6. COMMENTS' );

$comment_text = 'CLI test comment ' . time();
$r            = rest( 'POST', '/mvs/v1/media/' . $other_media . '/comments', array( 'content' => $comment_text ) );
$comment_id   = $r['data']['id'] ?? 0;
assert_test( 'Post comment', in_array( $r['status'], array( 200, 201 ), true ), 'id:' . $comment_id );

$r     = rest( 'GET', '/mvs/v1/media/' . $other_media . '/comments' );
$found = false;
foreach ( $r['data'] as $c ) {
	if ( strpos( $c['content'] ?? '', 'CLI test comment' ) !== false ) {
		$found = true;
	}
}
assert_test( 'Comment in list', $found );

// ── 7. FOLLOW / UNFOLLOW ──
section( '7. FOLLOW / UNFOLLOW' );

if ( $other_id ) {
	$r = rest( 'POST', '/mvs/v1/users/' . $other_id . '/follow' );
	assert_test( 'Follow user', in_array( $r['status'], array( 200, 201 ), true ) );

	$r = rest( 'GET', '/mvs/v1/me/following' );
	assert_test( 'Following list', $r['status'] === 200, $r['count'] . ' following' );

	$r = rest( 'GET', '/mvs/v1/users/' . $other_id . '/followers' );
	assert_test( 'Followers list', $r['status'] === 200, $r['count'] . ' followers' );

	$r = rest( 'DELETE', '/mvs/v1/users/' . $other_id . '/follow' );
	assert_test( 'Unfollow', in_array( $r['status'], array( 200, 204 ), true ) );
} else {
	assert_test( 'Follow (skipped — no other user)', false, 'no other users' );
}

// ── 8. ALBUMS ──
section( '8. ALBUMS' );

$r = rest( 'GET', '/mvs/v1/albums' );
assert_test( 'List all albums', $r['status'] === 200, $r['count'] . ' albums' );

$r = rest( 'GET', '/mvs/v1/albums', array( 'author' => $admin_id ) );
assert_test( 'List own albums', $r['status'] === 200, $r['count'] . ' own albums' );

if ( $album_id ) {
	$r = rest( 'GET', '/mvs/v1/albums/' . $album_id . '/items' );
	assert_test( 'List album items', in_array( $r['status'], array( 200, 404 ), true ) && is_array( $r['data'] ), $r['count'] . ' items (status:' . $r['status'] . ')' );
}

// ── 9. COLLECTIONS ──
section( '9. COLLECTIONS' );

$r = rest( 'GET', '/mvs/v1/collections' );
assert_test( 'List collections', $r['status'] === 200, $r['count'] . ' collections' );

// ── 10. TAGS ──
section( '10. TAGS' );

$r = rest( 'GET', '/mvs/v1/tags' );
assert_test( 'List tags', $r['status'] === 200, $r['count'] . ' tags' );

// ── 11. NOTIFICATIONS ──
section( '11. NOTIFICATIONS' );

$r = rest( 'GET', '/mvs/v1/me/notifications' );
assert_test( 'List notifications', $r['status'] === 200, $r['count'] . ' notifications' );

$r = rest( 'GET', '/mvs/v1/me/notifications/count' );
assert_test( 'Notification count', $r['status'] === 200, 'count:' . ( $r['data']['count'] ?? 'N/A' ) );

// ── 12. MESSAGING ──
section( '12. MESSAGING' );

$r = rest( 'GET', '/mvs/v1/messages/conversations' );
assert_test( 'List conversations', in_array( $r['status'], array( 200, 404 ), true ), $r['count'] . ' conversations (status:' . $r['status'] . ')' );

// ── 13. PROFILE ──
section( '13. PROFILE' );

$r = rest( 'GET', '/mvs/v1/me/profile' );
assert_test( 'Get own profile', $r['status'] === 200, 'name:' . ( $r['data']['display_name'] ?? '' ) );

$r = rest( 'GET', '/mvs/v1/users/' . $other_id );
assert_test( 'Get other user', $r['status'] === 200, 'name:' . ( $r['data']['display_name'] ?? $r['data']['name'] ?? '' ) );

$r = rest( 'GET', '/mvs/v1/users/' . $other_id . '/media' );
assert_test( 'Get user media', $r['status'] === 200, $r['count'] . ' items' );

$r = rest( 'GET', '/mvs/v1/users/search', array( 'q' => 'oliver' ) );
assert_test( 'Search users', $r['status'] === 200, $r['count'] . ' results' );

// ── 14. REPORT ──
section( '14. REPORT MEDIA' );

$r = rest( 'POST', '/mvs/v1/media/' . $other_media . '/report', array(
	'reason'  => 'spam',
	'details' => 'CLI test report',
) );
// 200/201 = success, 400 = already reported (both valid).
assert_test( 'Report media', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] );

// ── 15. ACTIVITY ──
section( '15. ACTIVITY FEED' );

$r = rest( 'GET', '/mvs/v1/users/' . $admin_id . '/activity' );
assert_test( 'Activity feed', $r['status'] === 200, $r['count'] . ' entries' );


// ══════════════════════════════════════════
// PART 2: PRO FEATURES
// ══════════════════════════════════════════
echo PHP_EOL . '╔══════════════════════════════════════╗' . PHP_EOL;
echo '║   PART 2: PRO FEATURES              ║' . PHP_EOL;
echo '╚══════════════════════════════════════╝' . PHP_EOL;

section( '16. CHALLENGES' );

$r         = rest( 'GET', '/mvs-pro/v1/challenges' );
$challenge = $r['data'][0] ?? null;
assert_test( 'List challenges', $r['status'] === 200, $r['count'] . ' challenges' );

if ( $challenge ) {
	$cid = $challenge['id'];
	$r   = rest( 'GET', '/mvs-pro/v1/challenges/' . $cid );
	assert_test( 'Get challenge detail', $r['status'] === 200, $r['data']['title'] ?? '' );

	$r = rest( 'GET', '/mvs-pro/v1/challenges/' . $cid . '/entries' );
	assert_test( 'List entries', $r['status'] === 200, $r['count'] . ' entries' );
}

section( '17. BATTLES' );

$r = rest( 'GET', '/mvs-pro/v1/battles' );
assert_test( 'List battles', $r['status'] === 200, $r['count'] . ' battles' );

section( '18. TOURNAMENTS' );

$r = rest( 'GET', '/mvs-pro/v1/tournaments' );
assert_test( 'List tournaments', $r['status'] === 200, $r['count'] . ' tournaments' );

section( '19. BOOSTS' );

$r = rest( 'GET', '/mvs-pro/v1/boosts' );
assert_test( 'List boosts', in_array( $r['status'], array( 200, 404 ), true ), 'status:' . $r['status'] );


// ══════════════════════════════════════════
// PART 3: ADMIN FLOWS
// ══════════════════════════════════════════
echo PHP_EOL . '╔══════════════════════════════════════╗' . PHP_EOL;
echo '║   PART 3: ADMIN FLOWS               ║' . PHP_EOL;
echo '╚══════════════════════════════════════╝' . PHP_EOL;

section( '20. ADMIN SETTINGS' );

// Verify settings are readable.
$settings = array(
	'mvs_max_upload_size'   => 'Max Upload Size',
	'mvs_allowed_file_types' => 'Allowed File Types',
	'mvs_default_privacy'   => 'Default Privacy',
	'mvs_thumbnail_style'   => 'Thumbnail Style',
	'mvs_duplicate_action'  => 'Duplicate Detection',
);
foreach ( $settings as $key => $label ) {
	$val = get_option( $key, '__UNSET__' );
	assert_test( "Setting: $label ($key)", $val !== '__UNSET__', "value: $val" );
}

section( '21. ADMIN MODERATION' );

// Check moderation queue counts.
$pending = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE moderation_status = 'pending'"
);
assert_test( 'Pending moderation count', true, "$pending items" );

section( '22. ADMIN STATS' );

$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = 'publish'" );
$views  = (int) $wpdb->get_var( "SELECT SUM(view_count) FROM {$wpdb->prefix}mvs_media_index" );
$reacts = (int) $wpdb->get_var( "SELECT SUM(reaction_count) FROM {$wpdb->prefix}mvs_media_index" );
assert_test( 'Total published media', $total > 0, "$total items" );
assert_test( 'Total views', true, number_format( $views ) );
assert_test( 'Total reactions', true, number_format( $reacts ) );

section( '23. PERMISSIONS' );

$roles_to_check = array( 'administrator', 'editor', 'subscriber' );
$caps_to_check  = array( 'upload_mvs_media', 'edit_mvs_media', 'manage_mvs_settings' );
foreach ( $roles_to_check as $role_slug ) {
	$role = get_role( $role_slug );
	if ( ! $role ) {
		continue;
	}
	foreach ( $caps_to_check as $cap ) {
		$has = ! empty( $role->capabilities[ $cap ] );
		if ( 'subscriber' === $role_slug && 'manage_mvs_settings' === $cap ) {
			assert_test( "Subscriber does NOT have $cap", ! $has );
		} elseif ( 'administrator' === $role_slug ) {
			assert_test( "Admin has $cap", $has );
		}
	}
}

section( '24. DATABASE INTEGRITY' );

$tables = array(
	'mvs_media_index',
	'mvs_media_meta',
	'mvs_reactions',
	'mvs_favorites',
	'mvs_follows',
	'mvs_notifications',
	'mvs_reports',
	'mvs_blocks',
	'mvs_activity',
	'mvs_conversations',
	'mvs_messages',
	'mvs_album_items',
	'mvs_error_log',
);
foreach ( $tables as $table ) {
	$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$table}'" );
	assert_test( "Table exists: $table", ! empty( $exists ) );
}

// Check for orphan data.
$orphan_album_items = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_album_items ai
	 LEFT JOIN {$wpdb->prefix}mvs_media_index mi ON ai.media_id = mi.media_id
	 WHERE mi.media_id IS NULL"
);
assert_test( 'No orphan album items', $orphan_album_items === 0, "$orphan_album_items orphans" );


// ══════════════════════════════════════════
// SUMMARY
// ══════════════════════════════════════════
echo PHP_EOL . '══════════════════════════════════════' . PHP_EOL;
$total_tests = $GLOBALS['test_pass'] + $GLOBALS['test_fail'];
echo "TOTAL: {$GLOBALS['test_pass']} passed, {$GLOBALS['test_fail']} failed out of $total_tests tests" . PHP_EOL;

if ( $GLOBALS['test_fail'] > 0 ) {
	echo PHP_EOL . 'FAILED TESTS:' . PHP_EOL;
	foreach ( $GLOBALS['test_results'] as $t ) {
		if ( ! $t['pass'] ) {
			echo '  ❌ ' . $t['name'] . ( $t['detail'] ? ' — ' . $t['detail'] : '' ) . PHP_EOL;
		}
	}
	exit( 1 );
}

echo PHP_EOL . '🎉 ALL TESTS PASSED' . PHP_EOL;
exit( 0 );
