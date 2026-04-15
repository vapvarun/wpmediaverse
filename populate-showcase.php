<?php
/**
 * WPMediaVerse Showcase Data Population Script.
 *
 * Populates the site with multi-user content, albums, and social interactions
 * to create a realistic showcase/demo of all MediaVerse features.
 *
 * Run via WP-CLI:
 *   wp eval-file wp-content/plugins/wpmediaverse/populate-showcase.php --user=1
 *
 * @package WPMediaVerse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

// ---------------------------------------------------------------------------
// Configuration.
// ---------------------------------------------------------------------------

/**
 * Stock images to download from Picsum Photos.
 * Using seed-based URLs for reproducibility and reliability.
 */
$stock_images = array(
	// Nature / Landscape (8).
	array( 'seed' => 'mvs-alpine-peaks', 'title' => 'Alpine Peaks at Dawn', 'desc' => 'Majestic mountain peaks bathed in early morning light with clouds drifting through valleys.', 'tags' => array( 'nature', 'mountain', 'sunrise', 'landscape' ), 'category' => 'Nature', 'privacy' => 'public', 'user' => 'sarah' ),
	array( 'seed' => 'mvs-emerald-lake', 'title' => 'Emerald Lake Reflection', 'desc' => 'Perfectly still lake reflecting surrounding pine trees and distant mountains.', 'tags' => array( 'nature', 'lake', 'reflection', 'landscape' ), 'category' => 'Nature', 'privacy' => 'public', 'user' => 'sarah' ),
	array( 'seed' => 'mvs-wildflower-meadow', 'title' => 'Wildflower Meadow', 'desc' => 'Colorful wildflowers stretching across a hillside meadow under blue sky.', 'tags' => array( 'nature', 'flowers', 'meadow', 'landscape' ), 'category' => 'Nature', 'privacy' => 'public', 'user' => 'sarah' ),
	array( 'seed' => 'mvs-coastal-cliffs', 'title' => 'Coastal Cliffs at Sunset', 'desc' => 'Dramatic sea cliffs illuminated by warm sunset light with crashing waves below.', 'tags' => array( 'nature', 'ocean', 'sunset', 'landscape' ), 'category' => 'Nature', 'privacy' => 'public', 'user' => 'sarah' ),
	array( 'seed' => 'mvs-autumn-forest', 'title' => 'Autumn Forest Canopy', 'desc' => 'Vibrant red and gold autumn leaves creating a natural canopy over a forest trail.', 'tags' => array( 'nature', 'forest', 'autumn', 'trees' ), 'category' => 'Nature', 'privacy' => 'public', 'user' => 'sarah' ),
	array( 'seed' => 'mvs-desert-dunes', 'title' => 'Desert Sand Dunes', 'desc' => 'Rolling sand dunes with mesmerizing patterns carved by desert winds.', 'tags' => array( 'nature', 'desert', 'sand', 'landscape' ), 'category' => 'Nature', 'privacy' => 'loggedin', 'user' => 'sarah' ),
	array( 'seed' => 'mvs-rainforest-stream', 'title' => 'Rainforest Stream', 'desc' => 'Crystal clear stream winding through dense tropical rainforest.', 'tags' => array( 'nature', 'forest', 'water', 'tropical' ), 'category' => 'Nature', 'privacy' => 'public', 'user' => 'diego' ),
	array( 'seed' => 'mvs-northern-lights', 'title' => 'Northern Lights Display', 'desc' => 'Stunning aurora borealis dancing across the night sky above snowy landscape.', 'tags' => array( 'nature', 'aurora', 'night', 'landscape' ), 'category' => 'Nature', 'privacy' => 'public', 'user' => 'diego' ),

	// Architecture / Urban (7).
	array( 'seed' => 'mvs-glass-tower', 'title' => 'Glass Tower Reflections', 'desc' => 'Modern skyscraper facade reflecting clouds and neighboring buildings.', 'tags' => array( 'architecture', 'building', 'glass', 'modern' ), 'category' => 'Architecture', 'privacy' => 'public', 'user' => 'marcus' ),
	array( 'seed' => 'mvs-historic-cathedral', 'title' => 'Historic Cathedral Interior', 'desc' => 'Ornate cathedral interior with dramatic light streaming through stained glass.', 'tags' => array( 'architecture', 'cathedral', 'interior', 'historic' ), 'category' => 'Architecture', 'privacy' => 'public', 'user' => 'marcus' ),
	array( 'seed' => 'mvs-spiral-staircase', 'title' => 'Spiral Staircase', 'desc' => 'Elegant spiral staircase viewed from above creating a mesmerizing geometric pattern.', 'tags' => array( 'architecture', 'stairs', 'geometric', 'interior' ), 'category' => 'Architecture', 'privacy' => 'public', 'user' => 'alex' ),
	array( 'seed' => 'mvs-urban-alley', 'title' => 'Urban Back Alley', 'desc' => 'Atmospheric urban alley with street art and vintage shop signs.', 'tags' => array( 'city', 'street', 'urban', 'graffiti' ), 'category' => 'Architecture', 'privacy' => 'public', 'user' => 'marcus' ),
	array( 'seed' => 'mvs-brutalist-concrete', 'title' => 'Brutalist Concrete', 'desc' => 'Bold brutalist architecture with raw concrete surfaces and strong geometric forms.', 'tags' => array( 'architecture', 'concrete', 'brutalist', 'modern' ), 'category' => 'Architecture', 'privacy' => 'loggedin', 'user' => 'marcus' ),
	array( 'seed' => 'mvs-neon-tokyo', 'title' => 'Neon Tokyo Streets', 'desc' => 'Busy Tokyo street at night illuminated by colorful neon signs and advertisements.', 'tags' => array( 'city', 'night', 'neon', 'urban' ), 'category' => 'Architecture', 'privacy' => 'public', 'user' => 'marcus' ),
	array( 'seed' => 'mvs-minimalist-facade', 'title' => 'Minimalist Facade', 'desc' => 'Clean minimalist building facade with perfect symmetry and muted tones.', 'tags' => array( 'architecture', 'minimalist', 'facade', 'modern' ), 'category' => 'Architecture', 'privacy' => 'public', 'user' => 'mina' ),

	// Food / Lifestyle (6).
	array( 'seed' => 'mvs-artisan-bread', 'title' => 'Artisan Sourdough Bread', 'desc' => 'Freshly baked artisan sourdough with golden crust and rustic texture.', 'tags' => array( 'food', 'bread', 'baking', 'artisan' ), 'category' => 'Food', 'privacy' => 'public', 'user' => 'emma' ),
	array( 'seed' => 'mvs-sushi-platter', 'title' => 'Japanese Sushi Platter', 'desc' => 'Beautifully arranged sushi platter with fresh nigiri, maki rolls, and sashimi.', 'tags' => array( 'food', 'sushi', 'japanese', 'seafood' ), 'category' => 'Food', 'privacy' => 'public', 'user' => 'emma' ),
	array( 'seed' => 'mvs-latte-art', 'title' => 'Latte Art Rosetta', 'desc' => 'Perfectly poured latte art rosetta in a ceramic cup on a wooden table.', 'tags' => array( 'food', 'coffee', 'latte', 'cafe' ), 'category' => 'Food', 'privacy' => 'public', 'user' => 'emma' ),
	array( 'seed' => 'mvs-farmers-market', 'title' => 'Farmers Market Colors', 'desc' => 'Vibrant display of fresh fruits and vegetables at a local farmers market.', 'tags' => array( 'food', 'market', 'vegetables', 'fresh' ), 'category' => 'Food', 'privacy' => 'public', 'user' => 'emma' ),
	array( 'seed' => 'mvs-pasta-homemade', 'title' => 'Homemade Pasta', 'desc' => 'Fresh homemade pasta with rich tomato sauce and basil garnish.', 'tags' => array( 'food', 'pasta', 'italian', 'homemade' ), 'category' => 'Food', 'privacy' => 'private', 'user' => 'emma' ),
	array( 'seed' => 'mvs-chocolate-cake', 'title' => 'Dark Chocolate Layer Cake', 'desc' => 'Decadent dark chocolate layer cake with ganache drip and berry garnish.', 'tags' => array( 'food', 'dessert', 'chocolate', 'baking' ), 'category' => 'Food', 'privacy' => 'public', 'user' => 'oliver' ),

	// Travel / Adventure (7).
	array( 'seed' => 'mvs-santorini-view', 'title' => 'Santorini Blue Domes', 'desc' => 'Iconic blue-domed churches of Santorini overlooking the Aegean Sea.', 'tags' => array( 'travel', 'greece', 'santorini', 'mediterranean' ), 'category' => 'Travel', 'privacy' => 'public', 'user' => 'priya' ),
	array( 'seed' => 'mvs-kyoto-temple', 'title' => 'Kyoto Temple Garden', 'desc' => 'Serene Japanese temple garden with raked sand and perfectly pruned trees.', 'tags' => array( 'travel', 'japan', 'temple', 'garden' ), 'category' => 'Travel', 'privacy' => 'public', 'user' => 'priya' ),
	array( 'seed' => 'mvs-marrakech-souk', 'title' => 'Marrakech Souk', 'desc' => 'Colorful spice market in the bustling souks of Marrakech, Morocco.', 'tags' => array( 'travel', 'morocco', 'market', 'culture' ), 'category' => 'Travel', 'privacy' => 'public', 'user' => 'priya' ),
	array( 'seed' => 'mvs-venice-canal', 'title' => 'Venice Grand Canal', 'desc' => 'Gondolas on the Grand Canal with historic Venetian palazzos on both sides.', 'tags' => array( 'travel', 'italy', 'venice', 'canal' ), 'category' => 'Travel', 'privacy' => 'public', 'user' => 'priya' ),
	array( 'seed' => 'mvs-swiss-train', 'title' => 'Swiss Mountain Train', 'desc' => 'Red Swiss mountain train crossing a viaduct with alpine scenery.', 'tags' => array( 'travel', 'switzerland', 'train', 'mountain' ), 'category' => 'Travel', 'privacy' => 'loggedin', 'user' => 'priya' ),
	array( 'seed' => 'mvs-bali-rice', 'title' => 'Bali Rice Terraces', 'desc' => 'Lush green rice terraces cascading down a hillside in Bali, Indonesia.', 'tags' => array( 'travel', 'bali', 'rice', 'landscape' ), 'category' => 'Travel', 'privacy' => 'public', 'user' => 'liam' ),
	array( 'seed' => 'mvs-iceland-waterfall', 'title' => 'Iceland Waterfall', 'desc' => 'Powerful waterfall plunging into a misty canyon surrounded by moss-covered cliffs.', 'tags' => array( 'travel', 'iceland', 'waterfall', 'nature' ), 'category' => 'Travel', 'privacy' => 'public', 'user' => 'liam' ),

	// Portraits / People (4).
	array( 'seed' => 'mvs-studio-portrait', 'title' => 'Studio Portrait', 'desc' => 'Professional studio portrait with dramatic Rembrandt lighting.', 'tags' => array( 'portrait', 'studio', 'lighting', 'people' ), 'category' => 'Portraits', 'privacy' => 'public', 'user' => 'zara' ),
	array( 'seed' => 'mvs-street-candid', 'title' => 'Street Candid', 'desc' => 'Candid street photograph capturing a spontaneous moment of joy.', 'tags' => array( 'portrait', 'street', 'candid', 'people' ), 'category' => 'Portraits', 'privacy' => 'private', 'user' => 'zara' ),
	array( 'seed' => 'mvs-golden-hour-portrait', 'title' => 'Golden Hour Portrait', 'desc' => 'Warm golden hour portrait with soft backlighting and lens flare.', 'tags' => array( 'portrait', 'golden-hour', 'outdoor', 'people' ), 'category' => 'Portraits', 'privacy' => 'public', 'user' => 'zara' ),
	array( 'seed' => 'mvs-musician-portrait', 'title' => 'Musician in the Making', 'desc' => 'Environmental portrait of a musician with their instrument in a moody setting.', 'tags' => array( 'portrait', 'music', 'creative', 'people' ), 'category' => 'Portraits', 'privacy' => 'loggedin', 'user' => 'zara' ),

	// Abstract / Art (4).
	array( 'seed' => 'mvs-paint-splash', 'title' => 'Abstract Paint Splash', 'desc' => 'Vivid paint splash creating an energetic abstract composition.', 'tags' => array( 'abstract', 'art', 'color', 'creative' ), 'category' => 'Abstract', 'privacy' => 'public', 'user' => 'alex' ),
	array( 'seed' => 'mvs-geometric-shadows', 'title' => 'Geometric Shadows', 'desc' => 'Intricate shadow patterns cast by geometric structures in afternoon sun.', 'tags' => array( 'abstract', 'geometric', 'shadow', 'pattern' ), 'category' => 'Abstract', 'privacy' => 'public', 'user' => 'alex' ),
	array( 'seed' => 'mvs-light-trails', 'title' => 'Light Trails', 'desc' => 'Long exposure light trails creating flowing abstract patterns in the dark.', 'tags' => array( 'abstract', 'light', 'longexposure', 'night' ), 'category' => 'Abstract', 'privacy' => 'loggedin', 'user' => 'alex' ),
	array( 'seed' => 'mvs-texture-rust', 'title' => 'Rust Texture Study', 'desc' => 'Close-up study of rust patterns and textures on aged metal surface.', 'tags' => array( 'abstract', 'texture', 'rust', 'macro' ), 'category' => 'Abstract', 'privacy' => 'public', 'user' => 'mina' ),

	// Wildlife / Animals (4).
	array( 'seed' => 'mvs-eagle-flight', 'title' => 'Eagle in Flight', 'desc' => 'Majestic eagle soaring against a clear blue sky with wings fully spread.', 'tags' => array( 'wildlife', 'bird', 'eagle', 'nature' ), 'category' => 'Wildlife', 'privacy' => 'public', 'user' => 'diego' ),
	array( 'seed' => 'mvs-fox-snow', 'title' => 'Red Fox in Snow', 'desc' => 'Red fox alert and watchful in a snowy winter landscape.', 'tags' => array( 'wildlife', 'fox', 'snow', 'winter' ), 'category' => 'Wildlife', 'privacy' => 'public', 'user' => 'diego' ),
	array( 'seed' => 'mvs-butterfly-macro', 'title' => 'Butterfly Macro', 'desc' => 'Detailed macro photograph of a monarch butterfly on a purple flower.', 'tags' => array( 'wildlife', 'butterfly', 'macro', 'nature' ), 'category' => 'Wildlife', 'privacy' => 'loggedin', 'user' => 'diego' ),
	array( 'seed' => 'mvs-deer-morning', 'title' => 'Deer at Morning Mist', 'desc' => 'Young deer standing in misty morning light at the edge of a forest clearing.', 'tags' => array( 'wildlife', 'deer', 'morning', 'nature' ), 'category' => 'Wildlife', 'privacy' => 'public', 'user' => 'mina' ),
);

/**
 * New users to create.
 */
$new_users = array(
	array(
		'login'       => 'diego',
		'email'       => 'diego@demo.local',
		'first_name'  => 'Diego',
		'last_name'   => 'Santos',
		'display_name' => 'Diego Santos',
		'description' => 'Wildlife photographer and nature conservationist. Capturing the beauty of the natural world one frame at a time.',
		'role'        => 'author',
		'avatar_seed' => 'avatar-diego',
	),
	array(
		'login'       => 'mina',
		'email'       => 'mina@demo.local',
		'first_name'  => 'Mina',
		'last_name'   => 'Aoki',
		'display_name' => 'Mina Aoki',
		'description' => 'Minimalist architecture photographer based in Tokyo. Finding beauty in clean lines and empty spaces.',
		'role'        => 'author',
		'avatar_seed' => 'avatar-mina',
	),
	array(
		'login'       => 'oliver',
		'email'       => 'oliver@demo.local',
		'first_name'  => 'Oliver',
		'last_name'   => 'Brooks',
		'display_name' => 'Oliver Brooks',
		'description' => 'Food blogger and culinary photographer. From farm to table, every dish tells a story.',
		'role'        => 'author',
		'avatar_seed' => 'avatar-oliver',
	),
	array(
		'login'       => 'zara',
		'email'       => 'zara@demo.local',
		'first_name'  => 'Zara',
		'last_name'   => 'Okonkwo',
		'display_name' => 'Zara Okonkwo',
		'description' => 'Portrait and fashion photographer. Capturing personality and emotion through the lens.',
		'role'        => 'author',
		'avatar_seed' => 'avatar-zara',
	),
	array(
		'login'       => 'liam',
		'email'       => 'liam@demo.local',
		'first_name'  => 'Liam',
		'last_name'   => "O'Connor",
		'display_name' => "Liam O'Connor",
		'description' => 'Travel and adventure photographer. Currently exploring Europe one rooftop at a time.',
		'role'        => 'author',
		'avatar_seed' => 'avatar-liam',
	),
);

/**
 * Existing users to update with proper profile data.
 */
$update_users = array(
	'sarah'  => array( 'first_name' => 'Sarah', 'last_name' => 'Chen', 'description' => 'Landscape photographer and digital artist. Chasing golden light across mountain ranges and coastlines.', 'avatar_seed' => 'avatar-sarah' ),
	'marcus' => array( 'first_name' => 'Marcus', 'last_name' => 'Rivera', 'description' => 'Street photographer capturing urban stories. Finding poetry in concrete jungles and neon lights.', 'avatar_seed' => 'avatar-marcus' ),
	'emma'   => array( 'first_name' => 'Emma', 'last_name' => 'Williams', 'description' => 'Food and lifestyle photographer. Making everyday moments look delicious.', 'avatar_seed' => 'avatar-emma' ),
	'alex'   => array( 'first_name' => 'Alex', 'last_name' => 'Nakamura', 'description' => 'Tech enthusiast and architecture photographer. Exploring where technology meets design.', 'avatar_seed' => 'avatar-alex' ),
	'priya'  => array( 'first_name' => 'Priya', 'last_name' => 'Sharma', 'description' => 'Travel photographer and storyteller. Documenting cultures and connections across Asia and beyond.', 'avatar_seed' => 'avatar-priya' ),
);

/**
 * Albums to create — media matched by tags.
 */
$albums_config = array(
	array( 'title' => 'Wilderness Untamed', 'description' => 'Raw beauty of untouched wilderness — mountains, forests, and wide open spaces.', 'user' => 'sarah', 'privacy' => 'public', 'media_tags' => array( 'nature', 'mountain', 'forest', 'landscape' ) ),
	array( 'title' => 'Golden Hour Magic', 'description' => 'That magical time when the world is bathed in warm golden light.', 'user' => 'sarah', 'privacy' => 'public', 'media_tags' => array( 'sunset', 'sunrise', 'golden-hour' ) ),
	array( 'title' => 'Urban Geometry', 'description' => 'The hidden geometry of cities — lines, angles, and patterns in urban architecture.', 'user' => 'marcus', 'privacy' => 'public', 'media_tags' => array( 'architecture', 'building', 'geometric', 'urban' ) ),
	array( 'title' => 'Night City Lights', 'description' => 'Cities come alive after dark — neon, streetlights, and the energy of nightlife.', 'user' => 'marcus', 'privacy' => 'loggedin', 'media_tags' => array( 'night', 'neon', 'city' ) ),
	array( 'title' => 'Taste of the World', 'description' => 'A culinary journey through flavors, textures, and beautiful plating.', 'user' => 'emma', 'privacy' => 'public', 'media_tags' => array( 'food', 'sushi', 'pasta', 'coffee' ) ),
	array( 'title' => 'Sweet Creations', 'description' => 'Desserts, pastries, and sweet treats that are almost too beautiful to eat.', 'user' => 'oliver', 'privacy' => 'public', 'media_tags' => array( 'dessert', 'chocolate', 'baking' ) ),
	array( 'title' => 'Modern Architecture', 'description' => 'Contemporary architectural marvels pushing the boundaries of design.', 'user' => 'alex', 'privacy' => 'public', 'media_tags' => array( 'architecture', 'modern', 'glass', 'minimalist' ) ),
	array( 'title' => 'Wanderlust Asia', 'description' => 'From the temples of Kyoto to the rice terraces of Bali — exploring Asia.', 'user' => 'priya', 'privacy' => 'public', 'media_tags' => array( 'japan', 'bali', 'temple', 'travel' ) ),
	array( 'title' => 'Into the Wild', 'description' => 'Wildlife encounters from forests, mountains, and open plains.', 'user' => 'diego', 'privacy' => 'public', 'media_tags' => array( 'wildlife', 'bird', 'fox', 'deer', 'butterfly' ) ),
	array( 'title' => 'Minimalist Lines', 'description' => 'Less is more — finding beauty in simplicity and clean forms.', 'user' => 'mina', 'privacy' => 'public', 'media_tags' => array( 'minimalist', 'facade', 'texture', 'geometric' ) ),
	array( 'title' => 'Studio Sessions', 'description' => 'Behind the scenes of professional portrait photography in the studio.', 'user' => 'zara', 'privacy' => 'loggedin', 'media_tags' => array( 'portrait', 'studio', 'lighting' ) ),
	array( 'title' => 'European Adventures', 'description' => 'Exploring the history, architecture, and culture of Europe.', 'user' => 'liam', 'privacy' => 'public', 'media_tags' => array( 'greece', 'italy', 'switzerland', 'iceland', 'venice' ) ),
);

/**
 * Comment templates by category.
 */
$comment_templates = array(
	'Nature'       => array(
		'Absolutely breathtaking! The light is incredible here.',
		'Where was this taken? I need to visit!',
		'This makes me want to pack my bags and go hiking.',
		'The colors are so vivid — is this straight out of camera?',
		'Nature at its finest. Well captured!',
		'I can almost feel the fresh mountain air.',
	),
	'Architecture' => array(
		'Love the symmetry and clean lines!',
		'What an incredible building. The geometry is mesmerizing.',
		'Great use of leading lines in this composition.',
		'The reflections add so much depth to this shot.',
		'Brutalist beauty — raw and powerful.',
		'This perspective is everything!',
	),
	'Food'         => array(
		'This looks absolutely delicious!',
		'Recipe please! My mouth is watering.',
		'The plating is art in itself.',
		'I need this in my life right now.',
		'Beautiful food photography — the lighting is spot on.',
		'Making me hungry scrolling through your feed!',
	),
	'Travel'       => array(
		'Adding this to my bucket list immediately!',
		'I was there last year — such a magical place.',
		'The culture and colors are incredible.',
		'What camera did you use for this?',
		'Travel goals right here!',
		'This captures the spirit of the place perfectly.',
	),
	'Portraits'    => array(
		'Incredible lighting and mood.',
		'The expression tells a whole story.',
		'Beautiful composition — very emotive.',
		'The bokeh is so creamy, what lens?',
		'This is portrait photography at its best.',
	),
	'Abstract'     => array(
		'Love the abstract quality of this!',
		'The patterns are hypnotizing.',
		'Great eye for detail.',
		'This would look amazing as a large print.',
	),
	'Wildlife'     => array(
		'What a magnificent creature!',
		'The timing on this shot is perfect.',
		'How long did you wait to get this?',
		'Nature is the best artist.',
		'Incredible wildlife photography!',
	),
);

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

/**
 * Log a message — works in WP-CLI and generic PHP contexts.
 *
 * @param string $message Message text.
 * @param string $type    log|success|warning|error.
 */
function mvs_showcase_log( $message, $type = 'log' ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		switch ( $type ) {
			case 'success':
				WP_CLI::success( $message );
				break;
			case 'warning':
				WP_CLI::warning( $message );
				break;
			case 'error':
				WP_CLI::error( $message, false );
				break;
			default:
				WP_CLI::log( $message );
		}
	} else {
		echo "[{$type}] {$message}\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Download a stock image from Picsum and import it into the WP media library.
 *
 * @param string $seed   Picsum seed string for reproducible image.
 * @param string $title  Image title.
 * @param int    $width  Image width.
 * @param int    $height Image height.
 * @return int|WP_Error Attachment ID or error.
 */
function mvs_showcase_download_image( $seed, $title, $width = 1200, $height = 800 ) {
	$url = "https://picsum.photos/seed/{$seed}/{$width}/{$height}";

	$tmp_file = download_url( $url, 30 );

	if ( is_wp_error( $tmp_file ) ) {
		return $tmp_file;
	}

	$file_array = array(
		'name'     => sanitize_file_name( $title ) . '.jpg',
		'tmp_name' => $tmp_file,
	);

	$attachment_id = media_handle_sideload( $file_array, 0, $title );

	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $tmp_file );
		return $attachment_id;
	}

	return $attachment_id;
}

/**
 * Ensure a mvs_category term exists.
 *
 * @param string $name Category name.
 * @return int Term ID or 0.
 */
function mvs_showcase_ensure_category( $name ) {
	$term = term_exists( $name, 'mvs_category' );
	if ( $term ) {
		return is_array( $term ) ? (int) $term['term_id'] : (int) $term;
	}
	$result = wp_insert_term( $name, 'mvs_category' );
	return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
}

/**
 * Ensure mvs_tag terms exist.
 *
 * @param array $tags Tag names.
 * @return array Term IDs.
 */
function mvs_showcase_ensure_tags( $tags ) {
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
 * Get or create a user by login.
 *
 * @param string $login Username.
 * @return int|false User ID or false.
 */
function mvs_showcase_get_user_id( $login ) {
	$user = get_user_by( 'login', $login );
	return $user ? $user->ID : false;
}

/**
 * Generate a random datetime within the last N days.
 *
 * @param int $max_days_ago Maximum days in the past.
 * @return string MySQL datetime string (GMT).
 */
function mvs_showcase_random_date( $max_days_ago = 30 ) {
	$offset = wp_rand( 0, $max_days_ago * DAY_IN_SECONDS );
	return gmdate( 'Y-m-d H:i:s', time() - $offset );
}

// ---------------------------------------------------------------------------
// Guard: check if showcase data already exists.
// ---------------------------------------------------------------------------

$showcase_flag = get_option( 'mvs_showcase_populated', false );
if ( $showcase_flag ) {
	mvs_showcase_log( 'Showcase data already populated. Delete option "mvs_showcase_populated" to re-run.', 'warning' );
	return;
}

$admin_user_id = get_current_user_id();
if ( ! $admin_user_id ) {
	mvs_showcase_log( 'No logged-in user. Run with --user=1 in WP-CLI.', 'error' );
	return;
}

mvs_showcase_log( '========================================' );
mvs_showcase_log( 'WPMediaVerse Showcase Data Population' );
mvs_showcase_log( '========================================' );
mvs_showcase_log( '' );

// ---------------------------------------------------------------------------
// Phase 1: Create / update users.
// ---------------------------------------------------------------------------

mvs_showcase_log( '--- Phase 1: Users ---' );

// Map username => user_id for later use.
$user_map = array();

// Update existing custom demo users.
foreach ( $update_users as $login => $data ) {
	$uid = mvs_showcase_get_user_id( $login );
	if ( ! $uid ) {
		mvs_showcase_log( "User '{$login}' not found, skipping update.", 'warning' );
		continue;
	}

	wp_update_user(
		array(
			'ID'           => $uid,
			'first_name'   => $data['first_name'],
			'last_name'    => $data['last_name'],
			'display_name' => $data['first_name'] . ' ' . $data['last_name'],
			'description'  => $data['description'],
		)
	);

	// Set BuddyPress xProfile Name if available.
	if ( function_exists( 'xprofile_set_field_data' ) ) {
		xprofile_set_field_data( 'Name', $uid, $data['first_name'] . ' ' . $data['last_name'] );
	}

	$user_map[ $login ] = $uid;
	mvs_showcase_log( "  Updated user: {$login} (ID {$uid})", 'success' );
}

// Create new users.
foreach ( $new_users as $u ) {
	$existing = mvs_showcase_get_user_id( $u['login'] );
	if ( $existing ) {
		$user_map[ $u['login'] ] = $existing;
		mvs_showcase_log( "  User '{$u['login']}' already exists (ID {$existing}), skipping." );
		continue;
	}

	$uid = wp_insert_user(
		array(
			'user_login'   => $u['login'],
			'user_email'   => $u['email'],
			'user_pass'    => wp_generate_password( 16 ),
			'first_name'   => $u['first_name'],
			'last_name'    => $u['last_name'],
			'display_name' => $u['display_name'],
			'description'  => $u['description'],
			'role'         => $u['role'],
		)
	);

	if ( is_wp_error( $uid ) ) {
		mvs_showcase_log( "  Failed to create user '{$u['login']}': " . $uid->get_error_message(), 'warning' );
		continue;
	}

	if ( function_exists( 'xprofile_set_field_data' ) ) {
		xprofile_set_field_data( 'Name', $uid, $u['display_name'] );
	}

	$user_map[ $u['login'] ] = $uid;
	mvs_showcase_log( "  Created user: {$u['display_name']} (ID {$uid})", 'success' );
}

// Download and set avatars for all active users.
mvs_showcase_log( '' );
mvs_showcase_log( '  Downloading avatars...' );

$all_avatar_users = array_merge(
	array_map(
		function ( $login ) use ( $update_users ) {
			return array( 'login' => $login, 'seed' => $update_users[ $login ]['avatar_seed'] );
		},
		array_keys( $update_users )
	),
	array_map(
		function ( $u ) {
			return array( 'login' => $u['login'], 'seed' => $u['avatar_seed'] );
		},
		$new_users
	)
);

foreach ( $all_avatar_users as $av ) {
	if ( ! isset( $user_map[ $av['login'] ] ) ) {
		continue;
	}
	$uid = $user_map[ $av['login'] ];

	// Skip if avatar already set.
	$existing_avatar = get_user_meta( $uid, '_mvs_custom_avatar', true );
	if ( $existing_avatar ) {
		mvs_showcase_log( "  Avatar already set for {$av['login']}, skipping." );
		continue;
	}

	$avatar_id = mvs_showcase_download_image( $av['seed'], $av['login'] . '-avatar', 200, 200 );

	if ( is_wp_error( $avatar_id ) ) {
		mvs_showcase_log( "  Avatar download failed for {$av['login']}: " . $avatar_id->get_error_message(), 'warning' );
		continue;
	}

	update_user_meta( $uid, '_mvs_custom_avatar', $avatar_id );
	mvs_showcase_log( "  Avatar set for {$av['login']}", 'success' );

	usleep( 200000 ); // 200ms delay.
}

mvs_showcase_log( '' );
mvs_showcase_log( "  User map: " . count( $user_map ) . " active users ready." );

// ---------------------------------------------------------------------------
// Phase 2: Download stock images and create media.
// ---------------------------------------------------------------------------

mvs_showcase_log( '' );
mvs_showcase_log( '--- Phase 2: Downloading & Creating Media ---' );

$created_media = array(); // Array of [ 'post_id' => int, 'tags' => array, 'category' => string, 'user' => string ].

foreach ( $stock_images as $idx => $img ) {
	$num   = $idx + 1;
	$total = count( $stock_images );

	// Resolve user ID.
	$owner_id = isset( $user_map[ $img['user'] ] ) ? $user_map[ $img['user'] ] : $admin_user_id;

	mvs_showcase_log( "[{$num}/{$total}] Downloading: {$img['title']}..." );

	$attachment_id = mvs_showcase_download_image( $img['seed'], $img['title'] );

	if ( is_wp_error( $attachment_id ) ) {
		mvs_showcase_log( "  Download failed: " . $attachment_id->get_error_message(), 'warning' );
		continue;
	}

	$file_url  = wp_get_attachment_url( $attachment_id );
	$file_path = get_attached_file( $attachment_id );
	$mime_type = get_post_mime_type( $attachment_id );
	$file_size = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : 0;

	// Random date within last 30 days for organic feel.
	$post_date = mvs_showcase_random_date( 30 );

	// Switch user context.
	wp_set_current_user( $owner_id );

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'mvs_media',
			'post_title'   => $img['title'],
			'post_content' => $img['desc'],
			'post_status'  => 'publish',
			'post_author'  => $owner_id,
			'post_date'    => get_date_from_gmt( $post_date ),
			'post_date_gmt' => $post_date,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		mvs_showcase_log( "  Failed to create media post: " . $post_id->get_error_message(), 'warning' );
		continue;
	}

	// Set meta via custom tables (not wp_postmeta).
	\WPMediaVerse\Repository\MediaRepository::set_many(
		$post_id,
		array(
			'file_url'          => $file_url,
			'file_type'         => $mime_type,
			'file_size'         => $file_size,
			'media_type'        => 'image',
			'privacy'           => $img['privacy'],
			'attachment_id'     => $attachment_id,
			'moderation_status' => 'approved',
		)
	);

	set_post_thumbnail( $post_id, $attachment_id );

	// Tags and categories.
	if ( ! empty( $img['tags'] ) ) {
		$tag_ids = mvs_showcase_ensure_tags( $img['tags'] );
		if ( $tag_ids ) {
			wp_set_object_terms( $post_id, $tag_ids, 'mvs_tag' );
			\WPMediaVerse\Repository\MediaRepository::set( $post_id, 'tags', wp_json_encode( array_values( $img['tags'] ) ) );
		}
	}

	if ( ! empty( $img['category'] ) ) {
		$cat_id = mvs_showcase_ensure_category( $img['category'] );
		if ( $cat_id ) {
			wp_set_object_terms( $post_id, array( $cat_id ), 'mvs_category' );
			\WPMediaVerse\Repository\MediaRepository::set( $post_id, 'category', wp_json_encode( array( $img['category'] ) ) );
		}
	}

	// Media stats row.
	$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_media_stats',
		array(
			'media_id'   => $post_id,
			'views'      => 0,
			'reactions'  => 0,
			'comments'   => 0,
			'downloads'  => 0,
			'updated_at' => $post_date,
		),
		array( '%d', '%d', '%d', '%d', '%d', '%s' )
	);

	// Media index row.
	$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_media_index',
		array(
			'media_id'          => $post_id,
			'post_author'       => $owner_id,
			'media_type'        => 'image',
			'privacy'           => $img['privacy'],
			'moderation_status' => 'approved',
			'created_at'        => $post_date,
		),
		array( '%d', '%d', '%s', '%s', '%s', '%s' )
	);

	$created_media[] = array(
		'post_id'  => $post_id,
		'tags'     => $img['tags'],
		'category' => $img['category'],
		'user'     => $img['user'],
		'owner_id' => $owner_id,
		'date'     => $post_date,
	);

	mvs_showcase_log( "  Created mvs_media #{$post_id}: {$img['title']} (by {$img['user']}, {$img['privacy']})", 'success' );

	usleep( 200000 ); // 200ms delay between downloads.
}

// Restore admin user context.
wp_set_current_user( $admin_user_id );

mvs_showcase_log( '' );
mvs_showcase_log( "  Total new media created: " . count( $created_media ) );

// ---------------------------------------------------------------------------
// Phase 3: Create albums.
// ---------------------------------------------------------------------------

mvs_showcase_log( '' );
mvs_showcase_log( '--- Phase 3: Creating Albums ---' );

$created_albums = array();

foreach ( $albums_config as $album_cfg ) {
	$owner_login = $album_cfg['user'];
	$owner_id    = isset( $user_map[ $owner_login ] ) ? $user_map[ $owner_login ] : $admin_user_id;

	$album_id = wp_insert_post(
		array(
			'post_type'    => 'mvs_album',
			'post_title'   => $album_cfg['title'],
			'post_content' => $album_cfg['description'],
			'post_status'  => 'publish',
			'post_author'  => $owner_id,
		),
		true
	);

	if ( is_wp_error( $album_id ) ) {
		mvs_showcase_log( "  Failed to create album: " . $album_id->get_error_message(), 'warning' );
		continue;
	}

	// Albums use wp_postmeta (no custom table for this post type).
	update_post_meta( $album_id, '_mvs_privacy', $album_cfg['privacy'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	update_post_meta( $album_id, '_mvs_album_type', 'default' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value

	// Match media to album by tags.
	$position   = 0;
	$album_items = array();
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
			\WPMediaVerse\Repository\MediaRepository::set( $media['post_id'], 'album_id', $album_id );
			$album_items[] = $media['post_id'];
			++$position;
		}
	}

	// Set cover from first item.
	if ( ! empty( $album_items ) ) {
		$cover_attachment = get_post_thumbnail_id( $album_items[0] );
		if ( $cover_attachment ) {
			set_post_thumbnail( $album_id, $cover_attachment );
		}
	}

	$created_albums[] = array(
		'album_id' => $album_id,
		'title'    => $album_cfg['title'],
		'items'    => count( $album_items ),
	);

	mvs_showcase_log( "  Created album #{$album_id}: {$album_cfg['title']} ({$position} items, by {$owner_login})", 'success' );
}

// ---------------------------------------------------------------------------
// Phase 4: Social interactions.
// ---------------------------------------------------------------------------

mvs_showcase_log( '' );
mvs_showcase_log( '--- Phase 4: Social Interactions ---' );

// Build a pool of all user IDs for social interactions (BP default users + active users).
$all_user_ids = array();
for ( $i = 2; $i <= 26; $i++ ) {
	$all_user_ids[] = $i; // BP Default Data users.
}
foreach ( $user_map as $uid ) {
	$all_user_ids[] = $uid;
}
$all_user_ids = array_unique( $all_user_ids );

// 4A: Reactions (~100-120).
mvs_showcase_log( '  Adding reactions...' );

$reaction_types    = array( 'like', 'like', 'like', 'like', 'love', 'love', 'love', 'wow', 'wow', 'haha', 'sad', 'angry' );
$total_reactions   = 0;

foreach ( $created_media as $media ) {
	$num_reactions = wp_rand( 3, 12 );
	$reactors      = (array) array_rand( array_flip( $all_user_ids ), min( $num_reactions, count( $all_user_ids ) ) );
	shuffle( $reactors );

	foreach ( array_slice( $reactors, 0, $num_reactions ) as $reactor_id ) {
		$reaction = $reaction_types[ array_rand( $reaction_types ) ];
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_reactions',
			array(
				'media_id'      => $media['post_id'],
				'user_id'       => $reactor_id,
				'reaction_type' => $reaction,
				'created_at'    => mvs_showcase_random_date( 25 ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		++$total_reactions;
	}

	// Update stats.
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_media_stats',
		array(
			'reactions'  => $num_reactions,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'media_id' => $media['post_id'] ),
		array( '%d', '%s' ),
		array( '%d' )
	);
}

mvs_showcase_log( "  Added {$total_reactions} reactions across " . count( $created_media ) . ' media items.', 'success' );

// 4B: Comments (~40-60).
mvs_showcase_log( '  Adding comments...' );

$total_comments = 0;

foreach ( $created_media as $media ) {
	$category = $media['category'];
	$templates = isset( $comment_templates[ $category ] ) ? $comment_templates[ $category ] : $comment_templates['Nature'];

	$num_comments = wp_rand( 1, 3 );
	$commenters   = (array) array_rand( array_flip( $all_user_ids ), min( $num_comments + 1, count( $all_user_ids ) ) );
	shuffle( $commenters );

	foreach ( array_slice( $commenters, 0, $num_comments ) as $commenter_id ) {
		$commenter = get_userdata( $commenter_id );
		if ( ! $commenter ) {
			continue;
		}

		$comment_text = $templates[ array_rand( $templates ) ];

		wp_insert_comment(
			array(
				'comment_post_ID'  => $media['post_id'],
				'comment_author'   => $commenter->display_name,
				'comment_author_email' => $commenter->user_email,
				'user_id'          => $commenter_id,
				'comment_content'  => $comment_text,
				'comment_type'     => 'mvs_comment',
				'comment_approved' => 1,
				'comment_date'     => get_date_from_gmt( mvs_showcase_random_date( 20 ) ),
				'comment_date_gmt' => mvs_showcase_random_date( 20 ),
			)
		);
		++$total_comments;
	}

	// Update stats.
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_media_stats',
		array(
			'comments'   => $num_comments,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'media_id' => $media['post_id'] ),
		array( '%d', '%s' ),
		array( '%d' )
	);
}

mvs_showcase_log( "  Added {$total_comments} comments.", 'success' );

// 4C: Favorites (~60-80).
mvs_showcase_log( '  Adding favorites...' );

$total_favorites = 0;

foreach ( $created_media as $media ) {
	$num_favs  = wp_rand( 1, 5 );
	$favoriters = (array) array_rand( array_flip( $all_user_ids ), min( $num_favs, count( $all_user_ids ) ) );

	foreach ( (array) $favoriters as $fav_uid ) {
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_favorites',
			array(
				'media_id'   => $media['post_id'],
				'user_id'    => $fav_uid,
				'created_at' => mvs_showcase_random_date( 25 ),
			),
			array( '%d', '%d', '%s' )
		);
		++$total_favorites;
	}
}

mvs_showcase_log( "  Added {$total_favorites} favorites.", 'success' );

// 4D: Follows (~30-40).
mvs_showcase_log( '  Adding follows...' );

$active_user_ids = array_values( $user_map );
$total_follows   = 0;

// Each BP default user follows 1-3 active creators.
foreach ( range( 2, 26 ) as $follower_id ) {
	$num_follows = wp_rand( 1, 3 );
	$targets     = (array) array_rand( array_flip( $active_user_ids ), min( $num_follows, count( $active_user_ids ) ) );

	foreach ( (array) $targets as $following_id ) {
		if ( $follower_id === $following_id ) {
			continue;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_follows',
			array(
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
				'status'       => 'active',
				'created_at'   => mvs_showcase_random_date( 30 ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		++$total_follows;
	}
}

// Active users follow each other.
foreach ( $active_user_ids as $follower_id ) {
	$other_active = array_diff( $active_user_ids, array( $follower_id ) );
	$num_follows  = wp_rand( 2, min( 5, count( $other_active ) ) );
	$targets      = (array) array_rand( array_flip( $other_active ), $num_follows );

	foreach ( (array) $targets as $following_id ) {
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_follows',
			array(
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
				'status'       => 'active',
				'created_at'   => mvs_showcase_random_date( 30 ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		++$total_follows;
	}
}

mvs_showcase_log( "  Added {$total_follows} follows.", 'success' );

// 4E: View counts.
mvs_showcase_log( '  Updating view counts...' );

foreach ( $created_media as $media ) {
	$views = wp_rand( 30, 500 );
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_media_stats',
		array(
			'views'      => $views,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'media_id' => $media['post_id'] ),
		array( '%d', '%s' ),
		array( '%d' )
	);
}

mvs_showcase_log( '  View counts updated.', 'success' );

// 4F: BuddyPress activity entries.
if ( function_exists( 'bp_activity_add' ) ) {
	mvs_showcase_log( '  Creating BuddyPress activity entries...' );

	$bp_activities = 0;
	foreach ( $created_media as $media ) {
		$thumb_url = \WPMediaVerse\Core\TemplateHelpers::get_thumb_url( (int) $media['post_id'], 'medium' );
		$content   = $thumb_url ? '<div class="mvs-activity-media"><img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( \WPMediaVerse\Repository\MediaRepository::get( (int) $media['post_id'], 'title' ) ?: '' ) . '"></div>' : '';

		bp_activity_add(
			array(
				'user_id'       => $media['owner_id'],
				'component'     => 'wpmediaverse',
				'type'          => 'mvs_media_upload',
				'item_id'       => $media['post_id'],
				'content'       => $content,
				'primary_link'  => get_permalink( $media['post_id'] ),
				'date_recorded' => $media['date'],
				'hide_sitewide' => ( 'public' !== \WPMediaVerse\Repository\MediaRepository::get( $media['post_id'], 'privacy' ) ),
			)
		);
		++$bp_activities;
	}

	mvs_showcase_log( "  Created {$bp_activities} BuddyPress activity entries.", 'success' );
}

// ---------------------------------------------------------------------------
// Phase 5: Mark complete and summarize.
// ---------------------------------------------------------------------------

update_option( 'mvs_showcase_populated', true );

mvs_showcase_log( '' );
mvs_showcase_log( '========================================' );
mvs_showcase_log( 'Showcase data population complete!', 'success' );
mvs_showcase_log( '========================================' );
mvs_showcase_log( '' );
mvs_showcase_log( "  Users updated/created: " . count( $user_map ) );
mvs_showcase_log( "  Media items: " . count( $created_media ) );
mvs_showcase_log( "  Albums: " . count( $created_albums ) );
mvs_showcase_log( "  Reactions: {$total_reactions}" );
mvs_showcase_log( "  Comments: {$total_comments}" );
mvs_showcase_log( "  Favorites: {$total_favorites}" );
mvs_showcase_log( "  Follows: {$total_follows}" );
mvs_showcase_log( '' );
mvs_showcase_log( 'Next steps:' );
mvs_showcase_log( '  wp mvs reindex    — Rebuild media search index' );
mvs_showcase_log( '  wp cache flush    — Clear caches' );
