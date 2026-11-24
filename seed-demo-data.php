<?php
/**
 * WPMediaVerse Demo Data Seeder.
 *
 * Run via WP-CLI: wp eval-file wp-content/plugins/wpmediaverse/seed-demo-data.php
 * Or use the "Import Demo Data" button on MediaVerse > Overview.
 *
 * Uses bundled stock images from assets/demo-images/ for instant, offline import.
 * The photos are CC0 / public domain; assets/demo-images/CREDITS.json lists the creator and source page of each.
 *
 * Creates 5 demo users, 57 media items, 5 albums, 2 smart collections,
 * and rich social data (reactions, comments, favorites, follows, views).
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

// ---------------------------------------------------------------------------
// Demo Users.
// ---------------------------------------------------------------------------

$demo_users = array(
	array(
		'login'   => 'mina_aoki',
		'display' => 'Mina Aoki',
		'email'   => 'mina@demo.local',
		'bio'     => 'Tokyo-based street and travel photographer.',
	),
	array(
		'login'   => 'oliver_brooks',
		'display' => 'Oliver Brooks',
		'email'   => 'oliver@demo.local',
		'bio'     => 'Landscape and nature photographer from Colorado.',
	),
	array(
		'login'   => 'priya_sharma',
		'display' => 'Priya Sharma',
		'email'   => 'priya@demo.local',
		'bio'     => 'Food and travel photographer exploring cultures through cuisine.',
	),
	array(
		'login'   => 'liam_oconnor',
		'display' => 'Liam O\'Connor',
		'email'   => 'liam@demo.local',
		'bio'     => 'Architecture and urban photography specialist.',
	),
	array(
		'login'   => 'emma_williams',
		'display' => 'Emma Williams',
		'email'   => 'emma@demo.local',
		'bio'     => 'Portrait and fashion photographer based in London.',
	),
);

// ---------------------------------------------------------------------------
// 57 Media Items from 24 bundled photos (assets/demo-images/, credits in CREDITS.json).
// ---------------------------------------------------------------------------

$images = array(
	array(
		'file'        => 'fog-shrouded-mountain-peak.jpg',
		'title'       => 'Mountain Above the Fog',
		'description' => 'A rocky summit rising out of a sea of low cloud under a heavy morning sky.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'mountain', 'fog', 'summit' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'glass-building-wall-in-soho.jpg',
		'title'       => 'Glass Tower and Sky',
		'description' => 'A curved glass facade rising into a sky of streaked cloud, in black and white.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'architecture', 'glass', 'monochrome', 'tower' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'gray-haired-man-portrait.jpg',
		'title'       => 'Studio Portrait',
		'description' => 'A man with grey hair and glasses against a soft grey backdrop.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'studio', 'man', 'glasses' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'a-well-garnished-pizza.jpg',
		'title'       => 'Pizza With Rocket',
		'description' => 'A fresh pizza topped with rocket and olives on a wooden peel.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'pizza', 'italian', 'fresh' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'sunset-from-a-plane.jpg',
		'title'       => 'Wing at Sunset',
		'description' => 'The wing of a plane glows orange above the clouds.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'sunset', 'airplane', 'sky' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'laptop-on-wood.jpg',
		'title'       => 'Laptop on a Wooden Desk',
		'description' => 'A laptop on a warm wooden desk in soft light.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'technology', 'laptop', 'desk', 'work' ),
		'category'    => 'Technology',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'lake-wenatchee.jpg',
		'title'       => 'Lake at Golden Hour',
		'description' => 'Calm water reflects a warm sunset sky above snow-dusted rocks and dark pines.',
		'type'        => 'image',
		'privacy'     => 'loggedin',
		'tags'        => array( 'nature', 'lake', 'sunset', 'reflection' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'beautiful-nyc-skyline.jpg',
		'title'       => 'Manhattan From Above',
		'description' => 'Dense towers stretch to the horizon under a clear blue sky.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'architecture', 'city', 'skyline', 'aerial' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'fashionable-woman-and-bokeh.jpg',
		'title'       => 'City Lights Portrait',
		'description' => 'A black and white portrait of a woman against out-of-focus city lights.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'bokeh', 'monochrome', 'woman' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'margherita-pizza.jpg',
		'title'       => 'Margherita Night',
		'description' => 'A margherita pizza with basil, eaten with a fork and knife.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'margherita', 'basil', 'pizza' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'sunset-at-beach-in-crystal-cove.jpg',
		'title'       => 'Coastline at Dusk',
		'description' => 'A long beach and cliffs under an orange and blue sky.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'beach', 'dusk', 'coast' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'laptop-in-close-up.jpg',
		'title'       => 'Laptop Close-up',
		'description' => 'The edge of a laptop and keyboard on a dark desk.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'technology', 'laptop', 'keyboard', 'closeup' ),
		'category'    => 'Technology',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'misty-coniferous-forest-on-a-slope.jpg',
		'title'       => 'Misty Mountain Forest',
		'description' => 'Low cloud rolls through conifers on a steep slope.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'forest', 'mist', 'mountain' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'golden-city-skyline.jpg',
		'title'       => 'Skyline at Sunrise',
		'description' => 'A hazy golden sun rises behind a distant waterfront skyline.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'architecture', 'skyline', 'sunrise', 'harbor' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'portrait-of-elderly-woman.jpg',
		'title'       => 'Doorway Portrait',
		'description' => 'An elderly woman sits in a turquoise doorway in warm window light.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'documentary', 'doorway', 'elder' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'brunch.jpg',
		'title'       => 'Smoked Salmon Brunch',
		'description' => 'Bagels with cream cheese, salmon and capers on a dark table.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'brunch', 'salmon', 'bagel' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'ol-city-of-korcula.jpg',
		'title'       => 'Old Town on the Water',
		'description' => 'A walled stone town on a peninsula above turquoise sea.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'croatia', 'oldtown', 'sea' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'ruler-and-laptop-on-a-desk.jpg',
		'title'       => 'Minimal Desk Setup',
		'description' => 'A notebook, mug and laptop on a clean white desk.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'technology', 'desk', 'minimal', 'notes' ),
		'category'    => 'Technology',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'steamy-waterfall.jpg',
		'title'       => 'Steaming Waterfall',
		'description' => 'Spray rises from a wide waterfall dropping into a rocky canyon.',
		'type'        => 'image',
		'privacy'     => 'private',
		'tags'        => array( 'nature', 'waterfall', 'spray', 'canyon' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'street-lights-on-a-busy-night-in-midtown.jpg',
		'title'       => 'Midtown After Dark',
		'description' => 'Traffic trails and bright windows along a busy city street.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'city', 'night', 'traffic', 'lights' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'man-in-front-of-brick-wall.jpg',
		'title'       => 'Street Style',
		'description' => 'A man in a rust jacket stands against a white brick wall.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'street', 'style', 'urban' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'coffee-and-friends.jpg',
		'title'       => 'Coffee and Friends',
		'description' => 'Three cups meet in the middle of a wooden table.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'coffee', 'friends', 'latte' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'colorful-asian-street-market.jpg',
		'title'       => 'Night Market',
		'description' => 'Neon signs and stalls fill a narrow market street at night.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'market', 'night', 'asia' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'laptop-on-wood.jpg',
		'title'       => 'Home Office Corner',
		'description' => 'A second laptop blurs into a bright background.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'technology', 'office', 'remote', 'workspace' ),
		'category'    => 'Technology',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'thick-pine-forest.jpg',
		'title'       => 'Pine Forest Light',
		'description' => 'Tall straight trunks and soft green light under a dense canopy.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'forest', 'pine', 'trees' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'glass-building-wall-in-soho.jpg',
		'title'       => 'Reflections Overhead',
		'description' => 'Cloud reflected on a modern glass wall seen from below.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'architecture', 'reflection', 'modern', 'sky' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'gray-haired-man-portrait.jpg',
		'title'       => 'Quiet Smirk',
		'description' => 'A relaxed studio portrait with soft, even light.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'expression', 'studio', 'headshot' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'a-well-garnished-pizza.jpg',
		'title'       => 'From the Wood Oven',
		'description' => 'A blistered crust and bright toppings straight from the oven.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'oven', 'crust', 'dinner' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'sunset-from-a-plane.jpg',
		'title'       => 'Window Seat',
		'description' => 'The sky turns pink and gold outside the cabin window.',
		'type'        => 'image',
		'privacy'     => 'loggedin',
		'tags'        => array( 'travel', 'flight', 'window', 'clouds' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'laptop-in-close-up.jpg',
		'title'       => 'Aluminium and Glass',
		'description' => 'A clean product-style view of a laptop.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'technology', 'hardware', 'design', 'minimal' ),
		'category'    => 'Technology',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'fog-shrouded-mountain-peak.jpg',
		'title'       => 'Peak in the Clouds',
		'description' => 'Cloud drifts across the ridge and leaves only the highest rock in view.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'clouds', 'alpine', 'moody' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'beautiful-nyc-skyline.jpg',
		'title'       => 'Midtown Rooftops',
		'description' => 'A view across the rooftops with long afternoon shadows.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'city', 'urban', 'skyscrapers', 'daylight' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'fashionable-woman-and-bokeh.jpg',
		'title'       => 'Winter Look',
		'description' => 'A woman in a knit scarf in soft evening light.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'fashion', 'winter', 'street' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'margherita-pizza.jpg',
		'title'       => 'Basil on Top',
		'description' => 'Slices of tomato and mozzarella with a fresh basil leaf.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'italian', 'basil', 'lunch' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'sunset-at-beach-in-crystal-cove.jpg',
		'title'       => 'Evening Tide',
		'description' => 'Waves run onto a dark beach as the light fades.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'ocean', 'evening', 'shore' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'ruler-and-laptop-on-a-desk.jpg',
		'title'       => 'Planning Day',
		'description' => 'A spiral notebook and pen beside the laptop.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'technology', 'planning', 'workspace', 'notebook' ),
		'category'    => 'Technology',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'lake-wenatchee.jpg',
		'title'       => 'Still Water Reflections',
		'description' => 'Stones in the foreground lead the eye across a mirror-still winter lake.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'water', 'reflection', 'winter' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'golden-city-skyline.jpg',
		'title'       => 'Golden Haze',
		'description' => 'Warm light washes over a distant city across the water.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'city', 'golden', 'haze', 'waterfront' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'portrait-of-elderly-woman.jpg',
		'title'       => 'Waiting in the Doorway',
		'description' => 'A quiet, colourful moment framed by an old wooden door.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'colour', 'door', 'life' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'brunch.jpg',
		'title'       => 'Weekend Plates',
		'description' => 'A small brunch spread with coffee on the side.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'weekend', 'breakfast', 'table' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'ol-city-of-korcula.jpg',
		'title'       => 'Korcula From the Sea',
		'description' => 'The old town towers seen from a boat on a clear day.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'island', 'boat', 'summer' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'misty-coniferous-forest-on-a-slope.jpg',
		'title'       => 'Forest in the Clouds',
		'description' => 'Dark evergreens fade into pale cloud on the hillside.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'trees', 'cloud', 'hillside' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'street-lights-on-a-busy-night-in-midtown.jpg',
		'title'       => 'Street Lights at Night',
		'description' => 'Colourful light and long exposure on a night street.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'urban', 'nightlife', 'longexposure', 'colour' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'man-in-front-of-brick-wall.jpg',
		'title'       => 'Brick Wall Pose',
		'description' => 'A relaxed pose in front of a large window and painted brick.',
		'type'        => 'image',
		'privacy'     => 'loggedin',
		'tags'        => array( 'portrait', 'fashion', 'brick', 'lifestyle' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'coffee-and-friends.jpg',
		'title'       => 'Latte Art Cheers',
		'description' => 'Two latte-art cups and an iced coffee clink together.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'coffee', 'cheers', 'cafe', 'social' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'colorful-asian-street-market.jpg',
		'title'       => 'Market Street From Above',
		'description' => 'A gate and rows of lit stalls seen from high above.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'street', 'lights', 'city' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'steamy-waterfall.jpg',
		'title'       => 'Canyon Mist',
		'description' => 'Mist drifts over black rock beside fast, pale water.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'river', 'mist', 'rock' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'beautiful-nyc-skyline.jpg',
		'title'       => 'Concrete Grid',
		'description' => 'Streets and towers form a pattern from high above.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'architecture', 'grid', 'urban', 'view' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'gray-haired-man-portrait.jpg',
		'title'       => 'Headshot in Green',
		'description' => 'A clean headshot in a dark green polo shirt.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'headshot', 'professional', 'studio' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'a-well-garnished-pizza.jpg',
		'title'       => 'Garnished to Order',
		'description' => 'Rocket and cherry tomatoes on a golden crust.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'pizza', 'garnish', 'homemade' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'colorful-asian-street-market.jpg',
		'title'       => 'Lights of the Night Market',
		'description' => 'Colourful stalls and signs after dark.',
		'type'        => 'image',
		'privacy'     => 'private',
		'tags'        => array( 'travel', 'nightlife', 'food', 'colour' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'thick-pine-forest.jpg',
		'title'       => 'Into the Woods',
		'description' => 'A quiet stand of pines with no path in sight.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'woods', 'quiet', 'green' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'street-lights-on-a-busy-night-in-midtown.jpg',
		'title'       => 'Night Intersection',
		'description' => 'Bare trees and neon-bright buildings at an intersection.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'city', 'intersection', 'evening', 'urban' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'brunch.jpg',
		'title'       => 'Capers and Cream Cheese',
		'description' => 'Close-up of a bagel plate with a moka pot behind it.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'closeup', 'breakfast', 'coffee' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'fog-shrouded-mountain-peak.jpg',
		'title'       => 'Morning Cloud Sea',
		'description' => 'The valley fills with cloud while the peak stays sharp against the sky.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'landscape', 'morning', 'mist' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'lake-wenatchee.jpg',
		'title'       => 'Winter Shoreline',
		'description' => 'Ice at the edge of the lake and the last light on the far ridge.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'winter', 'shoreline', 'dusk' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'steamy-waterfall.jpg',
		'title'       => 'Waterfall Edge',
		'description' => 'Light catches the spray at the lip of the falls.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'falls', 'light', 'landscape' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
);

// Albums configuration — one per demo user.
$albums_config = array(
	array(
		'title'       => 'Mountain Escapes',
		'description' => 'Majestic peaks, alpine sunrises, and serene lakes from high-altitude adventures.',
		'user'        => 'oliver_brooks',
		'media_tags'  => array( 'nature', 'mountain', 'lake' ),
		'max_items'   => 6,
	),
	array(
		'title'       => 'Travel Notes',
		'description' => 'Night markets, old towns, coastlines and window seats from the road.',
		'user'        => 'mina_aoki',
		'media_tags'  => array( 'travel' ),
		'max_items'   => 5,
	),
	array(
		'title'       => 'Food Stories',
		'description' => 'Culinary journeys captured one dish at a time — from street food to fine dining.',
		'user'        => 'priya_sharma',
		'media_tags'  => array( 'food' ),
		'max_items'   => 5,
	),
	array(
		'title'       => 'Urban Geometry',
		'description' => 'Lines, angles, and reflections that reveal the hidden beauty of modern architecture.',
		'user'        => 'liam_oconnor',
		'media_tags'  => array( 'architecture', 'glass', 'geometry', 'urban' ),
		'max_items'   => 5,
	),
	array(
		'title'       => 'Portrait Series',
		'description' => 'People and personalities — studio sessions, candid moments, and street style.',
		'user'        => 'emma_williams',
		'media_tags'  => array( 'portrait' ),
		'max_items'   => 5,
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
 * Import a local image file into the MVS uploads directory and generate thumbnails.
 *
 * @param string $source_path Full path to source image.
 * @param string $title       Image title.
 * @return array|false Array with file_url, file_path, mime, file_size, thumbs, or false on failure.
 */
function mvs_seed_import_local_image( $source_path, $title, int $author_id, array $args = array() ) {
	// Route demo uploads through the canonical UploadService::handle() so
	// the seeder exercises the SAME pipeline a real user upload does:
	// - thumb_large/medium/thumb meta written (or file_url fallback on failure)
	// - video embedded-poster extraction via getID3
	// - mvs_media_uploaded action fires (BP activity sync, AI queue, etc.)
	// - LoggerService entries on failure
	// Direct MediaRepository::insert in a seeder would skip every one of those.
	$upload = \WPMediaVerse\Core\Plugin::container()->get( 'upload' );
	if ( ! $upload ) {
		return false;
	}

	// Copy source to a temp path so UploadService can move it into uploads/wpmediaverse/.
	$tmp = wp_tempnam( 'mvs-seed-' . basename( $source_path ) );
	if ( ! $tmp || ! copy( $source_path, $tmp ) ) {
		return false;
	}

	$filetype = wp_check_filetype( $source_path );
	$mime     = $filetype['type'] ?: 'image/jpeg';

	$file = array(
		'name'     => sanitize_file_name( basename( $source_path ) ),
		'type'     => $mime,
		'tmp_name' => $tmp,
		'size'     => filesize( $tmp ),
		'error'    => 0,
	);

	$handle_args = array_merge(
		array(
			'privacy'           => 'public',
			'title'             => $title,
			'moderation_status' => 'approved',
		),
		$args
	);

	$media_id = $upload->handle( $file, $author_id, $handle_args );

	if ( is_wp_error( $media_id ) || ! $media_id ) {
		return false;
	}

	// MediaRepository is a container-registered service with instance methods
	// only. Resolve it through the container so the seeder works on fresh
	// installs without depending on internal class shape.
	$row = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_all( (int) $media_id );
	return array(
		'media_id'  => (int) $media_id,
		'file_url'  => $row['file_url'] ?? '',
		'file_path' => $row['file_path'] ?? '',
		'mime'      => $row['file_type'] ?? $mime,
	);
}

// Tags and categories are now stored as MediaMeta values (no CPT taxonomy).

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

/**
 * Flag taxonomy terms created by the demo seeder so cleanup can identify them.
 *
 * @param string[] $term_names Term names assigned via wp_set_object_terms().
 * @param string   $taxonomy   Taxonomy slug ('mvs_tag' or 'mvs_category').
 */
function mvs_seed_flag_demo_terms( array $term_names, string $taxonomy ): void {
	foreach ( $term_names as $name ) {
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term && ! is_wp_error( $term ) && ! get_term_meta( $term->term_id, '_mvs_demo_term', true ) ) {
			add_term_meta( $term->term_id, '_mvs_demo_term', '1', true );
		}
	}
}

// ---------------------------------------------------------------------------
// Check for existing demo data.
// ---------------------------------------------------------------------------

// Refuse only when demo content is ALREADY seeded — not merely because the
// site has media. Counting mvs_media_index asked the wrong question: a site
// with any real uploads of its own could never import the demo set, and after
// running the cleanup the count often stayed above zero (the owner's own
// files), so "delete existing media first" was advice that could not be
// followed without emptying the library.
//
// `mvs_demo_seeded` is written at the end of this script and removed by
// cleanup-demo-data.php once no demo users remain, so it answers the question
// this guard is actually asking.
if ( get_option( 'mvs_demo_seeded' ) ) {
	mvs_seed_log( 'Demo data is already seeded. Skipping import to avoid duplicates.', 'warning' );
	mvs_seed_log( 'To reimport, run Delete Demo Data first.' );
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		// json_ERROR, not success: this is a refusal. Reporting it as success
		// made the admin JS take its success path — redirect to Explore — so a
		// refused import looked like a completed one that produced nothing.
		wp_send_json_error( array( 'message' => 'Demo data is already imported. Delete it first to re-import.' ) );
	}
	return;
}

// ---------------------------------------------------------------------------
// Create demo users (if not exists).
// ---------------------------------------------------------------------------

mvs_seed_log( 'Creating demo users...' );

$demo_user_ids = array();

foreach ( $demo_users as $du ) {
	$existing_user = get_user_by( 'login', $du['login'] );
	if ( $existing_user ) {
		// Only adopt an account this seeder made. A real member who happens to use the
		// same login keeps their account and is never given (or later stripped of) demo media.
		if ( get_user_meta( $existing_user->ID, 'mvs_demo_user', true ) ) {
			$demo_user_ids[ $du['login'] ] = $existing_user->ID;
			mvs_seed_log( "  User already exists: {$du['display']} (#{$existing_user->ID})" );
		} else {
			$demo_user_ids[ $du['login'] ] = get_current_user_id() ? get_current_user_id() : 1;
			mvs_seed_log( "  Login {$du['login']} belongs to a real account (#{$existing_user->ID}); demo items go to the current admin instead.", 'warning' );
		}
		continue;
	}

	$new_user_id = wp_insert_user(
		array(
			'user_login'   => $du['login'],
			'user_email'   => $du['email'],
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => $du['display'],
			'description'  => $du['bio'],
			'role'         => 'author',
		)
	);

	if ( is_wp_error( $new_user_id ) ) {
		mvs_seed_log( "  Failed to create user {$du['login']}: " . $new_user_id->get_error_message(), 'warning' );
		continue;
	}

	update_user_meta( $new_user_id, 'mvs_demo_user', 1 );
	$demo_user_ids[ $du['login'] ] = $new_user_id;
	mvs_seed_log( "  Created user: {$du['display']} (#{$new_user_id})", 'success' );
}

mvs_seed_log( '  Demo users ready: ' . count( $demo_user_ids ) );

// A profile photo for each demo member, so the people side of the demo is not a
// row of grey placeholders. The attachment is authored by the member, so the
// cleanup's wp_delete_user() removes it with them.
$avatar_files = glob( $demo_dir . 'avatars/*.png' ) ?: array();
$avatar_key   = \WPMediaVerse\Services\ProfileService::AVATAR_META_KEY;
foreach ( array_values( $demo_user_ids ) as $i => $duid ) {
	if ( ! get_user_meta( $duid, 'mvs_demo_user', true ) || get_user_meta( $duid, $avatar_key, true ) || empty( $avatar_files ) ) {
		continue;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$tmp = wp_tempnam( 'mvs-seed-avatar' );
	if ( ! $tmp || ! copy( $avatar_files[ $i % count( $avatar_files ) ], $tmp ) ) {
		continue;
	}
	$avatar_id = media_handle_sideload(
		array(
			'name'     => 'demo-avatar-' . $duid . '.png',
			'tmp_name' => $tmp,
		),
		0,
		null,
		array( 'post_author' => $duid )
	);
	if ( is_wp_error( $avatar_id ) ) {
		mvs_seed_log( '  Avatar not set for #' . $duid . ': ' . $avatar_id->get_error_message(), 'warning' );
		continue;
	}
	update_user_meta( $duid, $avatar_key, (int) $avatar_id );
}

// Fallback user for any unmapped items.
$fallback_user_id = get_current_user_id();
if ( ! $fallback_user_id ) {
	$fallback_user_id = 1;
}

// ---------------------------------------------------------------------------
// Seed media.
// ---------------------------------------------------------------------------

mvs_seed_log( '' );
mvs_seed_log( 'Importing WPMediaVerse demo data (50 media items)...' );
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

	// Resolve author.
	$post_author = $fallback_user_id;
	if ( ! empty( $img['user'] ) && isset( $demo_user_ids[ $img['user'] ] ) ) {
		$post_author = $demo_user_ids[ $img['user'] ];
	}

	$imported = mvs_seed_import_local_image(
		$source_path,
		$img['title'],
		$post_author,
		array(
			'description' => $img['description'],
			'privacy'     => $img['privacy'],
		)
	);

	if ( ! $imported || empty( $imported['media_id'] ) ) {
		mvs_seed_log( '  Failed to import image via UploadService.', 'warning' );
		continue;
	}

	$media_id = (int) $imported['media_id'];

	// Store tags as meta AND assign to mvs_tag taxonomy (for tag cloud + filtering).
	if ( ! empty( $img['tags'] ) ) {
		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'tags', wp_json_encode( array_values( $img['tags'] ) ) );
		wp_set_object_terms( $media_id, $img['tags'], 'mvs_tag', true );
		mvs_seed_flag_demo_terms( $img['tags'], 'mvs_tag' );
	}
	if ( ! empty( $img['category'] ) ) {
		$cat_value = is_array( $img['category'] ) ? $img['category'] : array( $img['category'] );
		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'category', wp_json_encode( array_values( $cat_value ) ) );
		wp_set_object_terms( $media_id, $img['category'], 'mvs_category', true );
		mvs_seed_flag_demo_terms( $cat_value, 'mvs_category' );
	}

	// Create stats row with randomized views.
	$views = wp_rand( 10, 500 );
	$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_media_stats',
		array(
			'media_id'   => $media_id,
			'views'      => $views,
			'reactions'  => 0,
			'comments'   => 0,
			'downloads'  => 0,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( '%d', '%d', '%d', '%d', '%d', '%s' )
	);

	$created_media[] = array(
		'media_id' => $media_id,
		'tags'     => $img['tags'],
		'user'     => $img['user'] ?? '',
		'author'   => $post_author,
	);

	mvs_seed_log( "  Created media #{$media_id}: {$img['title']} ({$img['privacy']})", 'success' );
}

// ---------------------------------------------------------------------------
// Seed albums (one per demo user).
// ---------------------------------------------------------------------------

mvs_seed_log( '' );
mvs_seed_log( 'Creating albums...' );

foreach ( $albums_config as $album_cfg ) {
	$album_author = $fallback_user_id;
	if ( ! empty( $album_cfg['user'] ) && isset( $demo_user_ids[ $album_cfg['user'] ] ) ) {
		$album_author = $demo_user_ids[ $album_cfg['user'] ];
	}

	$album_id = wp_insert_post(
		array(
			'post_type'    => 'mvs_album',
			'post_title'   => $album_cfg['title'],
			'post_content' => $album_cfg['description'],
			'post_status'  => 'publish',
			'post_author'  => $album_author,
		),
		true
	);

	if ( is_wp_error( $album_id ) ) {
		mvs_seed_log( 'Failed to create album: ' . $album_id->get_error_message(), 'warning' );
		continue;
	}

	// Add matching media to album (limit to max_items).
	$position  = 0;
	$max_items = isset( $album_cfg['max_items'] ) ? (int) $album_cfg['max_items'] : 10;

	foreach ( $created_media as $media ) {
		if ( $position >= $max_items ) {
			break;
		}
		$matches = array_intersect( $media['tags'], $album_cfg['media_tags'] );
		if ( ! empty( $matches ) ) {
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_album_items',
				array(
					'album_id' => $album_id,
					'media_id' => $media['media_id'],
					'position' => $position,
					'added_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%d', '%d', '%d', '%s' )
			);
			++$position;
		}
	}

	// Set first matching media as album cover via MVS meta.
	if ( $position > 0 ) {
		foreach ( $created_media as $media ) {
			$matches = array_intersect( $media['tags'], $album_cfg['media_tags'] );
			if ( ! empty( $matches ) ) {
				update_post_meta( $album_id, '_mvs_cover_media_id', $media['media_id'] );
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
	// Idempotency guard: re-running the seeder must NOT stack duplicate
	// same-named collections for this author. Without this, each run created a
	// fresh "Public Gallery"/"Nature Highlights", so repeated seeding produced
	// 2x, 3x... copies. Reuse an existing one when present.
	$existing_col = get_posts(
		array(
			'post_type'        => 'mvs_collection',
			'post_status'      => 'any',
			'author'           => $fallback_user_id,
			'title'            => $col_cfg['title'],
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- one-off CLI/dev seeder; bypassing filters keeps demo seeding deterministic.
		)
	);

	if ( ! empty( $existing_col ) ) {
		$col_id = (int) $existing_col[0];
		mvs_seed_log( "  Collection already exists, reusing: {$col_cfg['title']} (#{$col_id})" );
	} else {
		$col_id = wp_insert_post(
			array(
				'post_type'    => 'mvs_collection',
				'post_title'   => $col_cfg['title'],
				'post_content' => $col_cfg['description'],
				'post_status'  => 'publish',
				'post_author'  => $fallback_user_id,
			),
			true
		);

		if ( is_wp_error( $col_id ) ) {
			mvs_seed_log( 'Failed to create collection: ' . $col_id->get_error_message(), 'warning' );
			continue;
		}
	}

	// Collections use wp_postmeta (still a CPT).
	update_post_meta( $col_id, '_mvs_collection_type', $col_cfg['type'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value

	// Save smart rules through the service so tag/category names normalize to term IDs.
	if ( 'smart' === $col_cfg['type'] && ! empty( $col_cfg['rules'] ) ) {
		( new \WPMediaVerse\Services\CollectionService() )->save_rules( $col_id, $col_cfg['rules'] );
	}

	mvs_seed_log( "Created collection #{$col_id}: {$col_cfg['title']} ({$col_cfg['type']})", 'success' );
}

// ---------------------------------------------------------------------------
// Seed social interactions (expanded).
// ---------------------------------------------------------------------------

mvs_seed_log( '' );
mvs_seed_log( 'Adding social interactions...' );

$all_user_ids   = array_values( $demo_user_ids );
$reaction_types = array( 'like', 'love', 'haha', 'wow' );
$reaction_count = 0;
$comment_count  = 0;
$favorite_count = 0;
$follow_count   = 0;

// --- 100+ reactions (randomized, no self-likes) ---
foreach ( $created_media as $media ) {
	$media_id     = $media['media_id'];
	$media_author = $media['author'];

	// Each media gets 2-4 reactions from OTHER users.
	$num_reactions = wp_rand( 2, 4 );
	$eligible      = array_filter(
		$all_user_ids,
		function ( $uid ) use ( $media_author ) {
			return $uid !== $media_author;
		}
	);
	$eligible      = array_values( $eligible );

	if ( empty( $eligible ) ) {
		continue;
	}

	shuffle( $eligible );
	$reactors = array_slice( $eligible, 0, min( $num_reactions, count( $eligible ) ) );

	foreach ( $reactors as $reactor_id ) {
		$reaction = $reaction_types[ wp_rand( 0, count( $reaction_types ) - 1 ) ];
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_reactions',
			array(
				'media_id'      => $media_id,
				'user_id'       => $reactor_id,
				'reaction_type' => $reaction,
				'created_at'    => gmdate( 'Y-m-d H:i:s', time() - wp_rand( 0, 7 * DAY_IN_SECONDS ) ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		++$reaction_count;
	}

	// Update stats — reactions + views already seeded above.
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_media_stats',
		array(
			'reactions'  => count( $reactors ),
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'media_id' => $media_id ),
		array( '%d', '%s' ),
		array( '%d' )
	);
	// The reactions leaderboard sorts on the index column, not the stats table.
	\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'reaction_count', count( $reactors ) );
}

mvs_seed_log( "  Reactions: {$reaction_count}" );

// --- 30 comments (realistic, 20+ chars, from different users) ---
$comment_texts = array(
	'Amazing shot! The composition is absolutely perfect here.',
	'Love the colors and mood in this one. Really well done.',
	'This is incredible, where was this taken? I need to visit.',
	'Great work, the lighting is just stunning. Keep it up!',
	'Stunning photography, the detail is remarkable.',
	'The way you captured the light here is mesmerizing.',
	'I keep coming back to look at this. Truly beautiful work.',
	'This belongs in a gallery. Seriously impressive.',
	'What camera and lens did you use? The sharpness is amazing.',
	'Perfect timing! You really nailed this moment.',
	'The depth and layers in this photo tell such a great story.',
	'Wow, the colors are so vibrant yet natural. Gorgeous.',
	'This makes me want to grab my camera and head outside immediately.',
	'Incredible atmosphere in this shot. You have a great eye.',
	'So moody and cinematic. This is next-level photography.',
	'The textures in this image are absolutely beautiful up close.',
	'I love everything about this composition and color palette.',
	'This shot has such a peaceful and calming energy to it.',
	'Bookmarking this for inspiration on my next trip abroad.',
	'Absolutely breathtaking. One of the best I have seen today.',
	'The bokeh in the background is so smooth and creamy.',
	'Such a unique perspective. I never would have thought to frame it this way.',
	'This photo really captures the essence of the location perfectly.',
	'Masterful use of natural light here. No flash needed at all.',
	'I can almost feel the warmth of the sun in this photo.',
	'The contrast between the foreground and sky is beautiful.',
	'This image has such a timeless quality about it. Well done.',
	'Absolutely love the color grading on this one. So atmospheric.',
	'The symmetry in this photo is so satisfying to look at.',
	'One of my favorites on the platform. Truly outstanding work.',
);

$media_for_comments = $created_media;
shuffle( $media_for_comments );
$media_for_comments = array_slice( $media_for_comments, 0, 30 );

foreach ( $media_for_comments as $ci => $media ) {
	$media_id     = $media['media_id'];
	$media_author = $media['author'];

	// Pick a commenter who is NOT the media author.
	$eligible = array_filter(
		$all_user_ids,
		function ( $uid ) use ( $media_author ) {
			return $uid !== $media_author;
		}
	);
	$eligible = array_values( $eligible );
	if ( empty( $eligible ) ) {
		continue;
	}

	$commenter_id = $eligible[ wp_rand( 0, count( $eligible ) - 1 ) ];

	// Insert comment as activity row (no CPT, so no wp_comments).
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_activity',
		array(
			'user_id'    => $commenter_id,
			'type'       => 'comment',
			'media_id'   => $media_id,
			'content'    => $comment_texts[ $ci % count( $comment_texts ) ],
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - wp_rand( 0, 7 * DAY_IN_SECONDS ) ),
		),
		array( '%d', '%s', '%d', '%s', '%s' )
	);

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}mvs_media_stats SET comments = comments + 1, updated_at = %s WHERE media_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			gmdate( 'Y-m-d H:i:s' ),
			$media_id
		)
	);
	++$comment_count;
}

mvs_seed_log( "  Comments: {$comment_count}" );

// --- 40 favorites/bookmarks (spread across media and users, no self-favorites) ---
$media_for_favs = $created_media;
shuffle( $media_for_favs );

foreach ( $media_for_favs as $media ) {
	if ( $favorite_count >= 40 ) {
		break;
	}

	$media_id     = $media['media_id'];
	$media_author = $media['author'];

	$eligible = array_filter(
		$all_user_ids,
		function ( $uid ) use ( $media_author ) {
			return $uid !== $media_author;
		}
	);
	$eligible = array_values( $eligible );
	if ( empty( $eligible ) ) {
		continue;
	}

	$fav_user = $eligible[ wp_rand( 0, count( $eligible ) - 1 ) ];
	$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'mvs_favorites',
		array(
			'media_id'   => $media_id,
			'user_id'    => $fav_user,
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - wp_rand( 0, 14 * DAY_IN_SECONDS ) ),
		),
		array( '%d', '%d', '%s' )
	);
	++$favorite_count;
}

mvs_seed_log( "  Favorites: {$favorite_count}" );

// --- 20 follows (users following each other, no self-follows) ---
$follows_table = $wpdb->prefix . 'mvs_follows';
$follows_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $follows_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( $follows_exist && count( $all_user_ids ) >= 2 ) {
	$follow_pairs = array();
	foreach ( $all_user_ids as $follower ) {
		foreach ( $all_user_ids as $followed ) {
			if ( $follower !== $followed ) {
				$follow_pairs[] = array( $follower, $followed );
			}
		}
	}
	shuffle( $follow_pairs );
	$follow_pairs = array_slice( $follow_pairs, 0, 20 );

	foreach ( $follow_pairs as $pair ) {
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$follows_table,
			array(
				'follower_id'  => $pair[0],
				'following_id' => $pair[1],
				'created_at'   => gmdate( 'Y-m-d H:i:s', time() - wp_rand( 0, 30 * DAY_IN_SECONDS ) ),
			),
			array( '%d', '%d', '%s' )
		);
		++$follow_count;
	}
}

mvs_seed_log( "  Follows: {$follow_count}" );

// ---------------------------------------------------------------------------
// Competition Demo Data (requires Pro plugin).
// ---------------------------------------------------------------------------

$competition_counts = array(
	'challenges'  => 0,
	'battles'     => 0,
	'tournaments' => 0,
);

$created_media_ids = array_map(
	function ( $m ) {
		return $m['media_id'];
	},
	$created_media
);

if ( class_exists( '\WPMediaVersePro\Challenges\ChallengeService' ) && count( $created_media_ids ) >= 5 ) {
	mvs_seed_log( '' );
	mvs_seed_log( 'Creating competition demo data...' );

	// Enable all competition features so demo data is visible.
	// The master switch too: without it the seeded competitions exist in the
	// tables but nothing renders them, and demo data you cannot see is worse
	// than none. Competitions are opt-in, and seeding demo data IS opting in.
	update_option( 'mvs_competitions_enabled', '1' );
	update_option( 'mvs_challenges_enabled', '1' );
	update_option( 'mvs_battles_enabled', '1' );
	update_option( 'mvs_tournaments_enabled', '1' );
	update_option( 'mvs_autopilot_enabled', '1' );
	update_option( 'mvs_streaks_enabled', '1' );
	mvs_seed_log( '  Enabled: Challenges, Battles, Tournaments, Autopilot, Streaks' );

	$challenge_service = new \WPMediaVersePro\Challenges\ChallengeService();
	$battle_service    = new \WPMediaVersePro\Battles\BattleService();

	// Get test users (use the authors from created media).
	// array_values(): array_unique() keeps the first key of each author, and the code
	// below addresses the first two authors as [0] and [1].
	$test_users = array_values(
		array_unique(
			array_map(
				function ( $id ) {
					return (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $id );
				},
				$created_media_ids
			)
		)
	);

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
		$ch1_entry_limit = min( 3, count( $created_media_ids ) );
		for ( $i = 0; $i < $ch1_entry_limit; $i++ ) {
			$author = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $created_media_ids[ $i ] );
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
			$user1_media = array_filter(
				$created_media_ids,
				function ( $id ) use ( $test_users ) {
					return (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $id ) === $test_users[0];
				}
			);
			$user2_media = array_filter(
				$created_media_ids,
				function ( $id ) use ( $test_users ) {
					return (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $id ) === $test_users[1];
				}
			);
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

	// --- Tournament: Open for registration ---
	if ( class_exists( '\WPMediaVersePro\Tournaments\TournamentService' ) && count( $test_users ) >= 4 ) {
		$tournament_service = new \WPMediaVersePro\Tournaments\TournamentService();
		$t1                 = $tournament_service->create(
			array(
				'title'                => 'Spring Photography Championship',
				'description'          => 'A 16-bracket single-elimination tournament. Upload your best photo each round and let the community vote!',
				'theme'                => 'Best Shot',
				'bracket_size'         => 16,
				'registration_start'   => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'registration_end'     => gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) ),
				'round_duration_hours' => 48,
			),
			1
		);
		if ( ! is_wp_error( $t1 ) ) {
			++$competition_counts['tournaments'];
			mvs_seed_log( '  Tournament: Spring Photography Championship (registration open)' );
			// Register first 4 demo users.
			foreach ( array_slice( $test_users, 0, 4 ) as $uid ) {
				$tournament_service->register_participant( $t1, $uid );
			}
			mvs_seed_log( '  Registered 4 participants.' );
		}
	}

	// --- Seed autopilot theme pool ---
	if ( class_exists( '\WPMediaVersePro\Challenges\AutopilotService' ) ) {
		$autopilot = new \WPMediaVersePro\Challenges\AutopilotService( $challenge_service );
		$pool      = $autopilot->get_pool();
		if ( empty( $pool ) ) {
			$autopilot->seed_default_pool();
			mvs_seed_log( '  Seeded 52 autopilot themes.' );
		}
	}

	mvs_seed_log( '  Competition demo data created.' );
}

// ---------------------------------------------------------------------------
// Streak Demo Data (requires Pro plugin).
// ---------------------------------------------------------------------------

if ( class_exists( '\WPMediaVersePro\Streaks\StreakService' ) && ! empty( $demo_user_ids ) ) {
	mvs_seed_log( '' );
	mvs_seed_log( 'Setting up demo streaks...' );

	// Give each demo user a varied streak so the badge shows on profiles.
	$streak_data = array(
		0 => array(
			'current' => 23,
			'longest' => 45,
			'freezes' => 2,
		),
		1 => array(
			'current' => 7,
			'longest' => 14,
			'freezes' => 1,
		),
		2 => array(
			'current' => 42,
			'longest' => 42,
			'freezes' => 0,
		),
		3 => array(
			'current' => 3,
			'longest' => 30,
			'freezes' => 2,
		),
		4 => array(
			'current' => 100,
			'longest' => 100,
			'freezes' => 1,
		),
	);

	$today = wp_date( 'Y-m-d' );
	foreach ( $demo_user_ids as $i => $uid ) {
		if ( ! isset( $streak_data[ $i ] ) ) {
			continue;
		}
		$s = $streak_data[ $i ];
		update_user_meta( $uid, '_mvs_current_streak', $s['current'] );
		update_user_meta( $uid, '_mvs_longest_streak', $s['longest'] );
		update_user_meta( $uid, '_mvs_last_upload_date', $today );
		update_user_meta( $uid, '_mvs_streak_freezes', $s['freezes'] );
	}
	mvs_seed_log( '  Streaks set for ' . min( count( $demo_user_ids ), 5 ) . ' demo users.' );
}

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------

// No backfill needed — all fields are set during MediaMeta::insert() above.

// ---------------------------------------------------------------------------
// Seed user reports (requires mvs_reports table).
// ---------------------------------------------------------------------------

$reports_table = $wpdb->prefix . 'mvs_reports';
$reports_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reports_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$report_count  = 0;

if ( $reports_exist && count( $created_media ) >= 5 && count( $all_user_ids ) >= 2 ) {
	mvs_seed_log( '' );
	mvs_seed_log( 'Creating sample reports...' );

	$report_samples = array(
		array(
			'reason'  => 'spam',
			'details' => 'This image appears to be AI-generated and misleading.',
			'status'  => 'pending',
		),
		array(
			'reason'  => 'copyright',
			'details' => 'Contains watermarked stock photo used without permission.',
			'status'  => 'pending',
		),
		array(
			'reason'  => 'inappropriate',
			'details' => 'Potentially offensive content that may not meet community guidelines.',
			'status'  => 'resolved',
		),
	);

	foreach ( $report_samples as $rs ) {
		$target_media = $created_media[ wp_rand( 0, count( $created_media ) - 1 ) ];
		$media_author = $target_media['author'];

		$eligible_reporters = array_values(
			array_filter(
				$all_user_ids,
				function ( $uid ) use ( $media_author ) {
					return $uid !== $media_author;
				}
			)
		);

		if ( empty( $eligible_reporters ) ) {
			continue;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$reports_table,
			array(
				'reporter_id' => $eligible_reporters[ wp_rand( 0, count( $eligible_reporters ) - 1 ) ],
				'target_type' => 'media',
				'target_id'   => $target_media['media_id'],
				'reason'      => $rs['reason'],
				'details'     => $rs['details'],
				'status'      => $rs['status'],
				'created_at'  => gmdate( 'Y-m-d H:i:s', time() - wp_rand( HOUR_IN_SECONDS, 7 * DAY_IN_SECONDS ) ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		++$report_count;
	}

	mvs_seed_log( "  Reports: {$report_count} (2 pending, 1 resolved)", 'success' );
}

// ---------------------------------------------------------------------------
// Seed play events / analytics (requires Pro mvs_play_events table).
// ---------------------------------------------------------------------------

$play_table     = $wpdb->prefix . 'mvs_play_events';
$play_tbl_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $play_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$play_count     = 0;

if ( $play_tbl_exist && count( $created_media ) >= 5 ) {
	mvs_seed_log( '' );
	mvs_seed_log( 'Creating analytics play events...' );

	$event_types    = array( 'play', 'pause', 'seek', 'complete' );
	$analytics_pool = array_slice( $created_media, 0, min( 10, count( $created_media ) ) );

	foreach ( $analytics_pool as $media ) {
		$mid        = $media['media_id'];
		$num_events = wp_rand( 3, 8 );
		$duration   = (float) wp_rand( 30, 300 );

		for ( $ei = 0; $ei < $num_events; $ei++ ) {
			$viewer = ! empty( $all_user_ids ) ? $all_user_ids[ wp_rand( 0, count( $all_user_ids ) - 1 ) ] : 0;
			$etype  = $event_types[ $ei % count( $event_types ) ];

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$play_table,
				array(
					'media_id'         => $mid,
					'user_id'          => $viewer,
					'session_id'       => wp_generate_uuid4(),
					'event_type'       => $etype,
					'position_seconds' => (float) wp_rand( 0, (int) $duration ),
					'duration_seconds' => 'complete' === $etype ? $duration : null,
					'created_at'       => gmdate( 'Y-m-d H:i:s', time() - wp_rand( 0, 30 * DAY_IN_SECONDS ) ),
				),
				array( '%d', '%d', '%s', '%s', '%f', '%s', '%s' )
			);
			++$play_count;
		}
	}

	mvs_seed_log( "  Play events: {$play_count}", 'success' );
}

mvs_seed_log( '' );
mvs_seed_log( 'Demo data import complete!', 'success' );
mvs_seed_log( '  Demo users: ' . count( $demo_user_ids ) );
mvs_seed_log( '  Media items: ' . count( $created_media ) );
mvs_seed_log( '  Albums: ' . count( $albums_config ) );
mvs_seed_log( '  Collections: ' . count( $collections_config ) );
mvs_seed_log( '  Categories: 6 (Nature, Architecture, Portraits, Food, Travel, Technology)' );
mvs_seed_log( '  Tags: 50+' );
mvs_seed_log( '  Comments: ' . $comment_count );
mvs_seed_log( '  Favorites: ' . $favorite_count );
mvs_seed_log( '  Reactions: ' . $reaction_count );
mvs_seed_log( '  Follows: ' . $follow_count );
if ( $competition_counts['challenges'] > 0 || $competition_counts['battles'] > 0 || $competition_counts['tournaments'] > 0 ) {
	mvs_seed_log( '  Challenges: ' . $competition_counts['challenges'] );
	mvs_seed_log( '  Battles: ' . $competition_counts['battles'] );
	mvs_seed_log( '  Tournaments: ' . $competition_counts['tournaments'] );
}
if ( $report_count > 0 ) {
	mvs_seed_log( '  Reports: ' . $report_count );
}
if ( $play_count > 0 ) {
	mvs_seed_log( '  Play events: ' . $play_count );
}

// Record that demo data has been seeded so the cleanup script and UI can
// detect the presence of demo content without relying solely on the
// `mvs_demo_user` user meta.
update_option( 'mvs_demo_seeded', time(), false );

// Invalidate Overview page stat caches so the toggle between "Import Demo
// Data" and "Delete Demo Data" reflects the new total_media immediately
// after the AJAX reload. OverviewPage::get_stats() / get_recent_uploads()
// cache these keys for five minutes otherwise.
wp_cache_delete( 'mvs_overview_stats', 'wpmediaverse' );
wp_cache_delete( 'mvs_overview_recent', 'wpmediaverse' );

// AJAX response.
if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
	$ajax_msg = sprintf(
		'Imported %d media items, %d albums, %d collections, %d reactions, %d comments, %d favorites, %d follows.',
		count( $created_media ),
		count( $albums_config ),
		count( $collections_config ),
		$reaction_count,
		$comment_count,
		$favorite_count,
		$follow_count
	);
	if ( $competition_counts['challenges'] > 0 || $competition_counts['battles'] > 0 || $competition_counts['tournaments'] > 0 ) {
		$ajax_msg .= sprintf(
			' Plus %d challenges, %d battles, %d tournaments.',
			$competition_counts['challenges'],
			$competition_counts['battles'],
			$competition_counts['tournaments']
		);
	}
	wp_send_json_success( array( 'message' => $ajax_msg ) );
}
