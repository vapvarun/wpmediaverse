<?php
/**
 * WPMediaVerse Demo Data Seeder.
 *
 * Run via WP-CLI: wp eval-file wp-content/plugins/wpmediaverse/seed-demo-data.php
 * Or use the "Import Demo Data" button on MediaVerse > Overview.
 *
 * Uses bundled stock images from assets/demo-images/ for instant, offline import.
 * Images sourced from Unsplash (free license).
 *
 * @package WPMediaVerse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// ---------------------------------------------------------------------------
// Configuration.
// ---------------------------------------------------------------------------

$demo_dir = plugin_dir_path( __FILE__ ) . 'assets/demo-images/';

$images = array(
	// Nature / Landscape.
	array(
		'file'        => 'alpine-mountain-sunrise.jpg',
		'title'       => 'Alpine Mountain Sunrise',
		'description' => 'Golden sunrise over snow-capped alpine peaks with misty valleys below.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'mountain', 'sunrise', 'landscape' ),
		'category'    => 'Nature',
	),
	array(
		'file'        => 'misty-forest-path.jpg',
		'title'       => 'Misty Forest Path',
		'description' => 'A winding path through a lush green forest with morning mist.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'forest', 'path', 'mist' ),
		'category'    => 'Nature',
	),
	array(
		'file'        => 'tropical-beach-paradise.jpg',
		'title'       => 'Tropical Beach Paradise',
		'description' => 'Crystal clear turquoise water meeting white sandy beach under blue sky.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'beach', 'ocean', 'tropical' ),
		'category'    => 'Nature',
	),
	array(
		'file'        => 'sunlight-through-trees.jpg',
		'title'       => 'Sunlight Through Trees',
		'description' => 'Warm sunlight filtering through tall deciduous trees in a dense forest.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'forest', 'sunlight', 'trees' ),
		'category'    => 'Nature',
	),
	// City / Architecture.
	array(
		'file'        => 'city-skyline-dusk.jpg',
		'title'       => 'City Skyline at Dusk',
		'description' => 'Modern city skyline with illuminated skyscrapers reflected in the river at dusk.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'city', 'skyline', 'architecture', 'urban' ),
		'category'    => 'Architecture',
	),
	array(
		'file'        => 'modern-glass-building.jpg',
		'title'       => 'Modern Glass Building',
		'description' => 'Geometric patterns in a modern glass and steel building facade.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'architecture', 'building', 'glass', 'modern' ),
		'category'    => 'Architecture',
	),
	array(
		'file'        => 'urban-street-night.jpg',
		'title'       => 'Urban Street Night',
		'description' => 'Busy urban street at night with neon lights and car trails.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'city', 'night', 'street', 'urban' ),
		'category'    => 'Architecture',
	),
	// People / Portraits.
	array(
		'file'        => 'outdoor-portrait.jpg',
		'title'       => 'Outdoor Portrait',
		'description' => 'Natural light portrait in an outdoor setting with soft bokeh background.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'people', 'outdoor' ),
		'category'    => 'Portraits',
	),
	array(
		'file'        => 'creative-studio-portrait.jpg',
		'title'       => 'Creative Studio Portrait',
		'description' => 'Professional studio portrait with dramatic lighting and clean background.',
		'type'        => 'image',
		'privacy'     => 'loggedin',
		'tags'        => array( 'portrait', 'studio', 'creative' ),
		'category'    => 'Portraits',
	),
	// Food.
	array(
		'file'        => 'gourmet-dish-plating.jpg',
		'title'       => 'Gourmet Dish Plating',
		'description' => 'Beautifully plated gourmet dish with fresh herbs and artistic presentation.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'gourmet', 'plating', 'restaurant' ),
		'category'    => 'Food',
	),
	array(
		'file'        => 'artisan-pizza.jpg',
		'title'       => 'Artisan Pizza',
		'description' => 'Freshly baked artisan pizza with melted cheese and fresh toppings.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'pizza', 'cooking' ),
		'category'    => 'Food',
	),
	// Travel.
	array(
		'file'        => 'lake-reflection.jpg',
		'title'       => 'Lake Reflection',
		'description' => 'Perfect mirror reflection of mountains in a crystal clear lake.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'lake', 'reflection', 'nature' ),
		'category'    => 'Travel',
	),
	array(
		'file'        => 'airport-departure.jpg',
		'title'       => 'Airport Departure',
		'description' => 'Airplane wing visible through the terminal window at golden hour.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'airport', 'airplane', 'journey' ),
		'category'    => 'Travel',
	),
	// Private / Members-only.
	array(
		'file'        => 'tech-workspace.jpg',
		'title'       => 'Tech Workspace',
		'description' => 'Close-up of circuit boards and electronic components.',
		'type'        => 'image',
		'privacy'     => 'loggedin',
		'tags'        => array( 'technology', 'electronics', 'workspace' ),
		'category'    => 'Technology',
	),
	array(
		'file'        => 'premium-wallpaper.jpg',
		'title'       => 'Premium Wallpaper',
		'description' => 'High-resolution abstract tech wallpaper for premium members.',
		'type'        => 'image',
		'privacy'     => 'private',
		'tags'        => array( 'abstract', 'wallpaper', 'premium' ),
		'category'    => 'Technology',
	),
);

// Albums configuration.
$albums_config = array(
	array(
		'title'       => 'Nature Collection',
		'description' => 'Beautiful landscapes and nature photography from around the world.',
		'media_tags'  => array( 'nature' ),
	),
	array(
		'title'       => 'City & Architecture',
		'description' => 'Urban landscapes, modern buildings, and architectural photography.',
		'media_tags'  => array( 'city', 'architecture' ),
	),
	array(
		'title'       => 'Food Photography',
		'description' => 'Delicious dishes and food styling inspiration.',
		'media_tags'  => array( 'food' ),
	),
);

// Smart collections configuration.
$collections_config = array(
	array(
		'title'       => 'Nature Highlights',
		'description' => 'Auto-curated collection of all nature-tagged media.',
		'type'        => 'smart',
		'rules'       => array(
			array(
				'key'   => 'tag',
				'value' => 'nature',
			),
		),
	),
	array(
		'title'       => 'Public Gallery',
		'description' => 'All publicly visible media in one place.',
		'type'        => 'smart',
		'rules'       => array(
			array(
				'key'   => 'privacy',
				'value' => 'public',
			),
		),
	),
);

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

/**
 * Import a local image file into the WP media library.
 *
 * @param string $source_path Full path to source image.
 * @param string $title       Image title.
 * @return int|WP_Error Attachment ID or error.
 */
function mvs_seed_import_local_image( $source_path, $title ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$upload_dir = wp_upload_dir();
	$filename   = sanitize_file_name( $title ) . '.jpg';
	$dest_path  = $upload_dir['path'] . '/' . wp_unique_filename( $upload_dir['path'], $filename );

	// Copy file to uploads directory.
	if ( ! copy( $source_path, $dest_path ) ) {
		return new WP_Error( 'copy_failed', 'Failed to copy demo image to uploads.' );
	}

	$filetype = wp_check_filetype( $dest_path );

	$attachment_id = wp_insert_attachment(
		array(
			'post_title'     => $title,
			'post_mime_type' => $filetype['type'],
			'post_status'    => 'inherit',
		),
		$dest_path
	);

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $dest_path );
	wp_update_attachment_metadata( $attachment_id, $metadata );

	return $attachment_id;
}

/**
 * Ensure a media category exists.
 *
 * @param string $name Category name.
 * @return int Term ID.
 */
function mvs_seed_ensure_category( $name ) {
	$term = term_exists( $name, 'mvs_category' );
	if ( $term ) {
		return is_array( $term ) ? (int) $term['term_id'] : (int) $term;
	}
	$result = wp_insert_term( $name, 'mvs_category' );
	if ( is_wp_error( $result ) ) {
		return 0;
	}
	return (int) $result['term_id'];
}

/**
 * Ensure media tags exist.
 *
 * @param array $tags Tag names.
 * @return array Term IDs.
 */
function mvs_seed_ensure_tags( $tags ) {
	$ids = array();
	foreach ( $tags as $tag_name ) {
		$term = term_exists( $tag_name, 'mvs_tag' );
		if ( $term ) {
			$ids[] = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
		} else {
			$result = wp_insert_term( $tag_name, 'mvs_tag' );
			if ( ! is_wp_error( $result ) ) {
				$ids[] = (int) $result['term_id'];
			}
		}
	}
	return $ids;
}

/**
 * Log a message — works in WP-CLI and AJAX contexts.
 *
 * @param string $message Message.
 * @param string $type    Type: log, success, warning.
 * @return void
 */
function mvs_seed_log( $message, $type = 'log' ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		if ( 'success' === $type ) {
			WP_CLI::success( $message );
		} elseif ( 'warning' === $type ) {
			WP_CLI::warning( $message );
		} else {
			WP_CLI::log( $message );
		}
	}
}

// ---------------------------------------------------------------------------
// Check for existing demo data.
// ---------------------------------------------------------------------------

$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'mvs_media',
		'publish'
	)
);

if ( (int) $existing > 0 ) {
	mvs_seed_log( "Found {$existing} existing media items. Skipping demo import to avoid duplicates.", 'warning' );
	mvs_seed_log( 'To reimport, first delete existing mvs_media posts.' );
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		wp_send_json_success( array( 'message' => 'Demo data already exists. Delete existing media first to reimport.' ) );
	}
	return;
}

// ---------------------------------------------------------------------------
// Seed media.
// ---------------------------------------------------------------------------

$user_id = get_current_user_id();
if ( ! $user_id ) {
	mvs_seed_log( 'No logged-in user found. Run with --user=<id> in WP-CLI.', 'warning' );
	return;
}

mvs_seed_log( 'Importing WPMediaVerse demo data...' );
mvs_seed_log( '' );

$created_media = array();

foreach ( $images as $idx => $img ) {
	$num         = $idx + 1;
	$source_path = $demo_dir . $img['file'];

	if ( ! file_exists( $source_path ) ) {
		mvs_seed_log( "[{$num}/" . count( $images ) . "] Missing: {$img['file']}", 'warning' );
		continue;
	}

	mvs_seed_log( "[{$num}/" . count( $images ) . "] Importing: {$img['title']}..." );

	$attachment_id = mvs_seed_import_local_image( $source_path, $img['title'] );

	if ( is_wp_error( $attachment_id ) ) {
		mvs_seed_log( '  Failed: ' . $attachment_id->get_error_message(), 'warning' );
		continue;
	}

	$file_url  = wp_get_attachment_url( $attachment_id );
	$file_path = get_attached_file( $attachment_id );
	$mime_type = get_post_mime_type( $attachment_id );
	$file_size = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : 0;

	// Create mvs_media post as draft first (so publish hooks fire AFTER meta is set).
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'mvs_media',
			'post_title'   => $img['title'],
			'post_content' => $img['description'],
			'post_status'  => 'draft',
			'post_author'  => $user_id,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		mvs_seed_log( '  Failed to create post: ' . $post_id->get_error_message(), 'warning' );
		continue;
	}

	// Get width/height from attachment metadata.
	$attach_meta = wp_get_attachment_metadata( $attachment_id );

	// Set meta BEFORE publishing (so hooks have access to full data).
	\WPMediaVerse\Services\MediaMeta::set_many(
		$post_id,
		array(
			'file_url'       => $file_url,
			'file_type'      => $mime_type,
			'file_size'      => $file_size,
			'media_type'     => $img['type'],
			'privacy'        => $img['privacy'],
			'attachment_id'  => $attachment_id,
			'width'          => $attach_meta['width'] ?? null,
			'height'         => $attach_meta['height'] ?? null,
			'title'          => $img['title'],
		)
	);

	// Set featured image.
	set_post_thumbnail( $post_id, $attachment_id );

	// Now publish (hooks fire with full data available).
	wp_publish_post( $post_id );

	// Set tags.
	if ( ! empty( $img['tags'] ) ) {
		$tag_ids = mvs_seed_ensure_tags( $img['tags'] );
		if ( $tag_ids ) {
			wp_set_object_terms( $post_id, $tag_ids, 'mvs_tag' );
		}
	}

	// Set category.
	if ( ! empty( $img['category'] ) ) {
		$cat_id = mvs_seed_ensure_category( $img['category'] );
		if ( $cat_id ) {
			wp_set_object_terms( $post_id, array( $cat_id ), 'mvs_category' );
		}
	}

	// Create stats row.
	$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_media_stats',
		array(
			'media_id'   => $post_id,
			'views'      => 0,
			'reactions'  => 0,
			'comments'   => 0,
			'downloads'  => 0,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( '%d', '%d', '%d', '%d', '%d', '%s' )
	);

	// Create index row.
	$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_media_index',
		array(
			'media_id'          => $post_id,
			'post_author'       => $user_id,
			'media_type'        => $img['type'],
			'privacy'           => $img['privacy'],
			'moderation_status' => 'approved',
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
		),
		array( '%d', '%d', '%s', '%s', '%s', '%s' )
	);

	$created_media[] = array(
		'post_id' => $post_id,
		'tags'    => $img['tags'],
	);

	mvs_seed_log( "  Created mvs_media #{$post_id}: {$img['title']} ({$img['privacy']})", 'success' );
}

// ---------------------------------------------------------------------------
// Seed albums.
// ---------------------------------------------------------------------------

mvs_seed_log( '' );
mvs_seed_log( 'Creating albums...' );

foreach ( $albums_config as $album_cfg ) {
	$album_id = wp_insert_post(
		array(
			'post_type'    => 'mvs_album',
			'post_title'   => $album_cfg['title'],
			'post_content' => $album_cfg['description'],
			'post_status'  => 'publish',
			'post_author'  => $user_id,
		),
		true
	);

	if ( is_wp_error( $album_id ) ) {
		mvs_seed_log( 'Failed to create album: ' . $album_id->get_error_message(), 'warning' );
		continue;
	}

	// Add matching media to album.
	$position = 0;
	foreach ( $created_media as $media ) {
		$matches = array_intersect( $media['tags'], $album_cfg['media_tags'] );
		if ( ! empty( $matches ) ) {
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_album_items',
				array(
					'album_id' => $album_id,
					'media_id' => $media['post_id'],
					'position' => $position,
					'added_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%d', '%d', '%d', '%s' )
			);
			++$position;
		}
	}

	// Set first media as cover.
	if ( $position > 0 ) {
		foreach ( $created_media as $media ) {
			$matches = array_intersect( $media['tags'], $album_cfg['media_tags'] );
			if ( ! empty( $matches ) ) {
				$cover_attachment = get_post_thumbnail_id( $media['post_id'] );
				if ( $cover_attachment ) {
					set_post_thumbnail( $album_id, $cover_attachment );
				}
				break;
			}
		}
	}

	mvs_seed_log( "Created album #{$album_id}: {$album_cfg['title']} ({$position} items)", 'success' );
}

// ---------------------------------------------------------------------------
// Seed smart collections.
// ---------------------------------------------------------------------------

mvs_seed_log( '' );
mvs_seed_log( 'Creating smart collections...' );

foreach ( $collections_config as $col_cfg ) {
	$col_id = wp_insert_post(
		array(
			'post_type'    => 'mvs_collection',
			'post_title'   => $col_cfg['title'],
			'post_content' => $col_cfg['description'],
			'post_status'  => 'publish',
			'post_author'  => $user_id,
		),
		true
	);

	if ( is_wp_error( $col_id ) ) {
		mvs_seed_log( 'Failed to create collection: ' . $col_id->get_error_message(), 'warning' );
		continue;
	}

	// Collections use wp_postmeta (no custom table for this post type).
	update_post_meta( $col_id, '_mvs_collection_type', $col_cfg['type'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value

	// Save rules — resolve tag names to term IDs.
	$resolved_rules = array();
	foreach ( $col_cfg['rules'] as $rule ) {
		$resolved_rule = $rule;
		if ( 'tag' === $rule['key'] ) {
			$term = get_term_by( 'name', $rule['value'], 'mvs_tag' );
			if ( $term ) {
				$resolved_rule['value'] = (string) $term->term_id;
			}
		}
		$resolved_rules[] = $resolved_rule;
	}
	update_post_meta( $col_id, '_mvs_collection_rules', $resolved_rules ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value

	mvs_seed_log( "Created collection #{$col_id}: {$col_cfg['title']} ({$col_cfg['type']})", 'success' );
}

// ---------------------------------------------------------------------------
// Seed social interactions.
// ---------------------------------------------------------------------------

mvs_seed_log( '' );
mvs_seed_log( 'Adding social interactions...' );

$reaction_types = array( 'like', 'love', 'haha', 'wow' );

foreach ( $created_media as $idx => $media ) {
	$media_id = $media['post_id'];

	// Add a reaction.
	$reaction = $reaction_types[ $idx % count( $reaction_types ) ];
	$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_reactions',
		array(
			'media_id'      => $media_id,
			'user_id'       => $user_id,
			'reaction_type' => $reaction,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		),
		array( '%d', '%d', '%s', '%s' )
	);

	// Update stats — reactions + random views.
	$views = wp_rand( 10, 120 );
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_media_stats',
		array(
			'reactions'  => 1,
			'views'      => $views,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'media_id' => $media_id ),
		array( '%d', '%d', '%s' ),
		array( '%d' )
	);
}

// Add comments to first 5 media.
$comments = array(
	'Amazing shot! The composition is perfect.',
	'Love the colors in this one.',
	'This is incredible, where was this taken?',
	'Great work, keep it up!',
	'Stunning photography, well done.',
);

$commenter = get_userdata( $user_id );

for ( $i = 0; $i < min( 5, count( $created_media ) ); $i++ ) {
	$media_id = $created_media[ $i ]['post_id'];

	wp_insert_comment(
		array(
			'comment_post_ID'  => $media_id,
			'comment_author'   => $commenter ? $commenter->display_name : 'Admin',
			'user_id'          => $user_id,
			'comment_content'  => $comments[ $i ],
			'comment_type'     => 'mvs_comment',
			'comment_approved' => 1,
		)
	);

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_media_stats',
		array(
			'comments'   => 1,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'media_id' => $media_id ),
		array( '%d', '%s' ),
		array( '%d' )
	);
}

// Add favorites to first 8 items.
for ( $i = 0; $i < min( 8, count( $created_media ) ); $i++ ) {
	$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_favorites',
		array(
			'media_id'   => $created_media[ $i ]['post_id'],
			'user_id'    => $user_id,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( '%d', '%d', '%s' )
	);
}

// ---------------------------------------------------------------------------
// Competition Demo Data (requires Pro plugin).
// ---------------------------------------------------------------------------

$competition_counts = array(
	'challenges' => 0,
	'battles'    => 0,
);

$created_media_ids = array_map( function( $m ) {
	return $m['post_id'];
}, $created_media );

if ( class_exists( '\WPMediaVersePro\Challenges\ChallengeService' ) && count( $created_media_ids ) >= 5 ) {
	mvs_seed_log( '' );
	mvs_seed_log( 'Creating competition demo data...' );

	$challenge_service = new \WPMediaVersePro\Challenges\ChallengeService();
	$battle_service    = new \WPMediaVersePro\Battles\BattleService();

	// Get test users (use the post authors from created media).
	$test_users = array_unique( array_map( function( $id ) {
		return (int) get_post_field( 'post_author', $id );
	}, $created_media_ids ) );

	// --- Challenge 1: Active challenge (accepting entries) ---
	$ch1 = $challenge_service->create(
		array(
			'title'                => 'Golden Hour Photography',
			'description'          => 'Capture the magic of golden hour — sunrise or sunset. Show us your best warm-light photography.',
			'theme'                => 'Golden Hour',
			'start_date'           => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			'end_date'             => gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) ),
			'voting_end_date'      => gmdate( 'Y-m-d H:i:s', time() + ( 14 * DAY_IN_SECONDS ) ),
			'max_entries_per_user' => 3,
		),
		1
	);
	if ( ! is_wp_error( $ch1 ) ) {
		++$competition_counts['challenges'];
		mvs_seed_log( '  Challenge: Golden Hour Photography (active)' );
		// Submit 3 entries from demo media.
		for ( $i = 0; $i < min( 3, count( $created_media_ids ) ); $i++ ) {
			$author = (int) get_post_field( 'post_author', $created_media_ids[ $i ] );
			if ( $author > 0 ) {
				$challenge_service->submit_entry( $ch1, $author, $created_media_ids[ $i ] );
			}
		}
	}

	// --- Challenge 2: In voting phase ---
	$ch2 = $challenge_service->create(
		array(
			'title'           => 'Street Photography Week',
			'description'     => 'The best of urban life — candid moments, street scenes, city energy.',
			'theme'           => 'Street Life',
			'start_date'      => gmdate( 'Y-m-d H:i:s', time() - ( 14 * DAY_IN_SECONDS ) ),
			'end_date'        => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			'voting_end_date' => gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) ),
		),
		1
	);
	if ( ! is_wp_error( $ch2 ) ) {
		++$competition_counts['challenges'];
		mvs_seed_log( '  Challenge: Street Photography Week (voting)' );
	}

	// --- Battle: Active between first 2 users ---
	if ( count( $test_users ) >= 2 ) {
		$b1 = $battle_service->create( $test_users[0], $test_users[1], 'Landscape vs Portrait' );
		if ( ! is_wp_error( $b1 ) ) {
			$battle_service->accept( $b1, $test_users[1] );
			// Submit media from each user.
			$user1_media = array_filter( $created_media_ids, function( $id ) use ( $test_users ) {
				return (int) get_post_field( 'post_author', $id ) === $test_users[0];
			} );
			$user2_media = array_filter( $created_media_ids, function( $id ) use ( $test_users ) {
				return (int) get_post_field( 'post_author', $id ) === $test_users[1];
			} );
			if ( ! empty( $user1_media ) ) {
				$battle_service->submit_media( $b1, $test_users[0], reset( $user1_media ) );
			}
			if ( ! empty( $user2_media ) ) {
				$battle_service->submit_media( $b1, $test_users[1], reset( $user2_media ) );
			}
			++$competition_counts['battles'];
			mvs_seed_log( '  Battle: Landscape vs Portrait (voting)' );
		}
	}

	mvs_seed_log( '  Competition demo data created.' );
}

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------

mvs_seed_log( '' );
mvs_seed_log( 'Demo data import complete!', 'success' );
mvs_seed_log( '  Media items: ' . count( $created_media ) );
mvs_seed_log( '  Albums: ' . count( $albums_config ) );
mvs_seed_log( '  Collections: ' . count( $collections_config ) );
mvs_seed_log( '  Categories: 6 (Nature, Architecture, Portraits, Food, Travel, Technology)' );
mvs_seed_log( '  Tags: 25+' );
mvs_seed_log( '  Comments: 5' );
mvs_seed_log( '  Favorites: 8' );
mvs_seed_log( '  Reactions: ' . count( $created_media ) );
if ( $competition_counts['challenges'] > 0 || $competition_counts['battles'] > 0 ) {
	mvs_seed_log( '  Challenges: ' . $competition_counts['challenges'] );
	mvs_seed_log( '  Battles: ' . $competition_counts['battles'] );
}

// AJAX response.
if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
	$ajax_msg = sprintf(
		'Imported %d media items, %d albums, %d collections with social interactions.',
		count( $created_media ),
		count( $albums_config ),
		count( $collections_config )
	);
	if ( $competition_counts['challenges'] > 0 || $competition_counts['battles'] > 0 ) {
		$ajax_msg .= sprintf(
			' Plus %d challenges and %d battles.',
			$competition_counts['challenges'],
			$competition_counts['battles']
		);
	}
	wp_send_json_success( array( 'message' => $ajax_msg ) );
}
