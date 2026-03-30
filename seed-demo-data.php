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
 * Creates 5 demo users, 50 media items, 5 albums, 2 smart collections,
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
// 50 Media Items (stacked from 15 base images).
// ---------------------------------------------------------------------------

$images = array(
	// ── Nature (10 items) ───────────────────────────────────────────────────
	array(
		'file'        => 'alpine-mountain-sunrise.jpg',
		'title'       => 'Alpine Mountain Sunrise',
		'description' => 'Golden sunrise over snow-capped alpine peaks with misty valleys below. The warm light paints the ridgeline in amber and rose.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'mountain', 'sunrise', 'landscape' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'alpine-mountain-sunrise.jpg',
		'title'       => 'Mountain Peak at Dawn',
		'description' => 'First light breaking over jagged mountain peaks as the valley below remains blanketed in a thin layer of morning fog.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'mountain', 'dawn', 'alpine' ),
		'category'    => 'Nature',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'lake-reflection.jpg',
		'title'       => 'Lake Reflection',
		'description' => 'Perfect mirror reflection of mountains in a crystal clear lake surrounded by dense conifer forest and wildflowers.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'lake', 'reflection', 'landscape' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'lake-reflection.jpg',
		'title'       => 'Still Water Morning',
		'description' => 'Glassy lake surface at dawn reflecting the surrounding peaks and treeline with perfect symmetry and stillness.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'lake', 'morning', 'calm' ),
		'category'    => 'Nature',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'misty-forest-path.jpg',
		'title'       => 'Misty Forest Path',
		'description' => 'A winding path through a lush green forest with morning mist clinging to the branches and ferns along the trail.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'forest', 'path', 'mist' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'misty-forest-path.jpg',
		'title'       => 'Enchanted Woodland Trail',
		'description' => 'Soft diffused light filters through towering trees along a moss-covered trail deep in the old-growth forest.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'forest', 'trail', 'woodland' ),
		'category'    => 'Nature',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'sunlight-through-trees.jpg',
		'title'       => 'Sunlight Through Trees',
		'description' => 'Warm sunlight filtering through tall deciduous trees in a dense forest creating dramatic rays of golden light.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'forest', 'sunlight', 'trees' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'sunlight-through-trees.jpg',
		'title'       => 'Forest Cathedral Light',
		'description' => 'Beams of sunlight stream through the canopy like spotlights on a stage, illuminating floating dust particles.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'trees', 'light', 'golden' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'tropical-beach-paradise.jpg',
		'title'       => 'Tropical Beach Paradise',
		'description' => 'Crystal clear turquoise water meeting white sandy beach under a brilliant blue sky dotted with cotton-like clouds.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'beach', 'ocean', 'tropical' ),
		'category'    => 'Nature',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'tropical-beach-paradise.jpg',
		'title'       => 'Island Shoreline',
		'description' => 'Gentle waves lapping against pristine white sand with palm trees swaying in the warm tropical breeze at midday.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'nature', 'island', 'shore', 'paradise' ),
		'category'    => 'Nature',
		'user'        => 'oliver_brooks',
	),

	// ── Architecture (8 items) ──────────────────────────────────────────────
	array(
		'file'        => 'modern-glass-building.jpg',
		'title'       => 'Modern Glass Building',
		'description' => 'Geometric patterns in a modern glass and steel building facade reflecting the sky and surrounding cityscape.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'architecture', 'building', 'glass', 'modern' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'modern-glass-building.jpg',
		'title'       => 'Glass Facade Geometry',
		'description' => 'Abstract lines and reflections on a contemporary glass tower creating a mesmerizing pattern of light and shadow.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'architecture', 'glass', 'abstract', 'geometry' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'modern-glass-building.jpg',
		'title'       => 'Reflections in Steel',
		'description' => 'Cloud reflections distorted across the curved glass panels of a skyscraper, blending nature with urban engineering.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'architecture', 'steel', 'reflections', 'urban' ),
		'category'    => 'Architecture',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'city-skyline-dusk.jpg',
		'title'       => 'City Skyline at Dusk',
		'description' => 'Modern city skyline with illuminated skyscrapers reflected in the river at dusk as the last light fades.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'city', 'skyline', 'architecture', 'dusk' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'city-skyline-dusk.jpg',
		'title'       => 'Downtown Twilight',
		'description' => 'The downtown financial district comes alive with amber and blue lights as twilight settles over the waterfront.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'city', 'twilight', 'downtown', 'lights' ),
		'category'    => 'Architecture',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'urban-street-night.jpg',
		'title'       => 'Urban Street at Night',
		'description' => 'Busy urban street at night with neon lights and car trails streaking through the frame in vivid color.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'city', 'night', 'street', 'urban' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'urban-street-night.jpg',
		'title'       => 'Neon City Nightlife',
		'description' => 'Glowing neon signs and wet pavement reflections transform an ordinary city block into a cinematic scene after dark.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'city', 'neon', 'nightlife', 'urban' ),
		'category'    => 'Architecture',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'urban-street-night.jpg',
		'title'       => 'After Hours Downtown',
		'description' => 'Long exposure captures the flow of traffic and pedestrians through downtown streets well past midnight.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'architecture', 'night', 'long-exposure', 'downtown' ),
		'category'    => 'Architecture',
		'user'        => 'oliver_brooks',
	),

	// ── Portraits (8 items) ─────────────────────────────────────────────────
	array(
		'file'        => 'creative-studio-portrait.jpg',
		'title'       => 'Creative Studio Portrait',
		'description' => 'Professional studio portrait with dramatic lighting and clean background highlighting the subject\'s expression.',
		'type'        => 'image',
		'privacy'     => 'loggedin',
		'tags'        => array( 'portrait', 'studio', 'creative', 'dramatic' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'creative-studio-portrait.jpg',
		'title'       => 'Dramatic Lighting Study',
		'description' => 'A single key light sculpts the contours of the face, creating deep shadows and a moody, introspective atmosphere.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'lighting', 'moody', 'studio' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'creative-studio-portrait.jpg',
		'title'       => 'Fashion Editorial Close-up',
		'description' => 'Bold fashion editorial shot with high contrast and saturated tones, styled for a contemporary magazine spread.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'fashion', 'editorial', 'close-up' ),
		'category'    => 'Portraits',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'creative-studio-portrait.jpg',
		'title'       => 'Artist in the Studio',
		'description' => 'Behind-the-scenes portrait of a creative professional at work, surrounded by the tools of their craft.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'artist', 'creative', 'behind-the-scenes' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'outdoor-portrait.jpg',
		'title'       => 'Outdoor Portrait',
		'description' => 'Natural light portrait in an outdoor setting with soft bokeh background and warm golden tones throughout.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'people', 'outdoor', 'natural-light' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'outdoor-portrait.jpg',
		'title'       => 'Golden Hour Portrait',
		'description' => 'Warm golden hour light wraps around the subject as the sun dips low, creating a naturally flattering glow.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'golden-hour', 'outdoor', 'warm' ),
		'category'    => 'Portraits',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'outdoor-portrait.jpg',
		'title'       => 'Street Style Portrait',
		'description' => 'Candid street style portrait capturing personality and attitude against an urban backdrop of textured walls.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'street-style', 'candid', 'urban' ),
		'category'    => 'Portraits',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'outdoor-portrait.jpg',
		'title'       => 'Park Bench Portrait',
		'description' => 'Relaxed environmental portrait taken on a park bench with dappled shade and soft green background.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'portrait', 'park', 'relaxed', 'environmental' ),
		'category'    => 'Portraits',
		'user'        => 'priya_sharma',
	),

	// ── Food (8 items) ──────────────────────────────────────────────────────
	array(
		'file'        => 'artisan-pizza.jpg',
		'title'       => 'Artisan Pizza',
		'description' => 'Freshly baked artisan pizza with melted cheese, basil, and fresh toppings straight from a wood-fired oven.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'pizza', 'cooking', 'artisan' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'artisan-pizza.jpg',
		'title'       => 'Wood-Fired Margherita',
		'description' => 'Classic margherita pizza with San Marzano tomatoes, fresh mozzarella, and fragrant basil leaves on a charred crust.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'pizza', 'italian', 'margherita' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'artisan-pizza.jpg',
		'title'       => 'Pizza Night at Home',
		'description' => 'Homemade pizza night with a variety of toppings laid out on a rustic wooden board ready to share.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'pizza', 'homemade', 'rustic' ),
		'category'    => 'Food',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'artisan-pizza.jpg',
		'title'       => 'Neapolitan Craft',
		'description' => 'Perfectly leopard-spotted Neapolitan crust topped with bubbling cheese and fresh herbs, captured from above.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'pizza', 'neapolitan', 'flatlay' ),
		'category'    => 'Food',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'gourmet-dish-plating.jpg',
		'title'       => 'Gourmet Dish Plating',
		'description' => 'Beautifully plated gourmet dish with fresh herbs and artistic presentation worthy of a Michelin-starred kitchen.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'gourmet', 'plating', 'restaurant' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'gourmet-dish-plating.jpg',
		'title'       => 'Chef\'s Table Creation',
		'description' => 'An intricate multi-course dish combining textures and colors into edible art, served at the chef\'s private table.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'chef', 'fine-dining', 'culinary' ),
		'category'    => 'Food',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'gourmet-dish-plating.jpg',
		'title'       => 'Modern Gastronomy',
		'description' => 'Deconstructed dessert plate featuring molecular gastronomy techniques with foams, gels, and micro herbs.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'gastronomy', 'modern', 'dessert' ),
		'category'    => 'Food',
		'user'        => 'emma_williams',
	),
	array(
		'file'        => 'gourmet-dish-plating.jpg',
		'title'       => 'Seasonal Tasting Menu',
		'description' => 'A seasonal tasting menu course featuring locally sourced ingredients arranged with precision and care.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'food', 'seasonal', 'tasting', 'local' ),
		'category'    => 'Food',
		'user'        => 'emma_williams',
	),

	// ── Travel (8 items) ────────────────────────────────────────────────────
	array(
		'file'        => 'airport-departure.jpg',
		'title'       => 'Airport Departure',
		'description' => 'Airplane wing visible through the terminal window at golden hour, evoking the excitement of new journeys.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'airport', 'airplane', 'journey' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'airport-departure.jpg',
		'title'       => 'Terminal Window View',
		'description' => 'Silhouette of a traveler gazing out the terminal window as planes taxi on the runway in the fading daylight.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'airport', 'silhouette', 'wanderlust' ),
		'category'    => 'Travel',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'airport-departure.jpg',
		'title'       => 'Boarding Time',
		'description' => 'The anticipation of boarding as the gate display flashes the destination city name and passengers line up.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'airport', 'boarding', 'adventure' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'tropical-beach-paradise.jpg',
		'title'       => 'Beachside Escape',
		'description' => 'A secluded stretch of tropical beach with turquoise water and swaying palms, the ultimate travel destination.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'beach', 'escape', 'tropical' ),
		'category'    => 'Travel',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'tropical-beach-paradise.jpg',
		'title'       => 'Coastline Discovery',
		'description' => 'Walking along an untouched coastline where the jungle meets the sea and every turn reveals a new cove.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'coastline', 'discovery', 'explore' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'urban-street-night.jpg',
		'title'       => 'Tokyo After Dark',
		'description' => 'The electric energy of Tokyo at night, with glowing signage, crowded crosswalks, and reflections on rain-slicked streets.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'tokyo', 'night', 'street' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),
	array(
		'file'        => 'urban-street-night.jpg',
		'title'       => 'Night Market Wander',
		'description' => 'Exploring a bustling night market where food stalls, lanterns, and music blend into a sensory overload.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'night-market', 'culture', 'street' ),
		'category'    => 'Travel',
		'user'        => 'priya_sharma',
	),
	array(
		'file'        => 'urban-street-night.jpg',
		'title'       => 'Backstreet Adventures',
		'description' => 'Wandering through narrow backstreets of a foreign city at dusk, discovering hidden cafes and local life.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'travel', 'backstreet', 'adventure', 'dusk' ),
		'category'    => 'Travel',
		'user'        => 'mina_aoki',
	),

	// ── Technology (8 items) ────────────────────────────────────────────────
	array(
		'file'        => 'tech-workspace.jpg',
		'title'       => 'Tech Workspace',
		'description' => 'Close-up of circuit boards and electronic components on a clean, organized workspace with soldering tools.',
		'type'        => 'image',
		'privacy'     => 'loggedin',
		'tags'        => array( 'technology', 'electronics', 'workspace', 'hardware' ),
		'category'    => 'Technology',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'tech-workspace.jpg',
		'title'       => 'Developer Desk Setup',
		'description' => 'A meticulously arranged developer workspace with dual monitors, mechanical keyboard, and ambient LED lighting.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'technology', 'workspace', 'developer', 'setup' ),
		'category'    => 'Technology',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'tech-workspace.jpg',
		'title'       => 'Hardware Lab',
		'description' => 'Prototype boards and oscilloscope traces fill a hardware engineering lab where the next innovation takes shape.',
		'type'        => 'image',
		'privacy'     => 'loggedin',
		'tags'        => array( 'technology', 'hardware', 'lab', 'engineering' ),
		'category'    => 'Technology',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'tech-workspace.jpg',
		'title'       => 'Circuit Board Macro',
		'description' => 'Extreme macro photography of a circuit board reveals the intricate pathways and solder joints at component level.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'technology', 'macro', 'circuit', 'electronics' ),
		'category'    => 'Technology',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'premium-wallpaper.jpg',
		'title'       => 'Premium Wallpaper',
		'description' => 'High-resolution abstract tech wallpaper with flowing gradients and geometric shapes for premium members.',
		'type'        => 'image',
		'privacy'     => 'private',
		'tags'        => array( 'abstract', 'wallpaper', 'premium', 'digital' ),
		'category'    => 'Technology',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'premium-wallpaper.jpg',
		'title'       => 'Abstract Data Flow',
		'description' => 'Visualization of data streams rendered as flowing particles and light trails against a dark background.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'abstract', 'data', 'visualization', 'digital' ),
		'category'    => 'Technology',
		'user'        => 'liam_oconnor',
	),
	array(
		'file'        => 'premium-wallpaper.jpg',
		'title'       => 'Neon Grid Horizon',
		'description' => 'Retro-futuristic neon grid stretching to the horizon with glowing wireframe mountains and a synthetic sunset.',
		'type'        => 'image',
		'privacy'     => 'public',
		'tags'        => array( 'abstract', 'neon', 'retro', 'synthwave' ),
		'category'    => 'Technology',
		'user'        => 'oliver_brooks',
	),
	array(
		'file'        => 'premium-wallpaper.jpg',
		'title'       => 'Digital Texture Art',
		'description' => 'Generative art piece combining algorithmic patterns with hand-tuned color palettes for a unique digital texture.',
		'type'        => 'image',
		'privacy'     => 'private',
		'tags'        => array( 'abstract', 'generative', 'texture', 'art' ),
		'category'    => 'Technology',
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
		'title'       => 'Tokyo Streets',
		'description' => 'Neon lights, night markets, and the electric pulse of Tokyo after dark.',
		'user'        => 'mina_aoki',
		'media_tags'  => array( 'travel', 'tokyo', 'night-market', 'street', 'backstreet' ),
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
function mvs_seed_import_local_image( $source_path, $title ) {
	$upload_dir = wp_upload_dir();
	$dest_dir   = $upload_dir['basedir'] . '/wpmediaverse/' . gmdate( 'Y/m' );
	wp_mkdir_p( $dest_dir );

	$filename  = wp_unique_filename( $dest_dir, basename( $source_path ) );
	$dest_path = $dest_dir . '/' . $filename;

	if ( ! copy( $source_path, $dest_path ) ) {
		return false;
	}

	$rel_dir  = 'wpmediaverse/' . gmdate( 'Y/m' );
	$file_url = $upload_dir['baseurl'] . '/' . $rel_dir . '/' . $filename;
	$filetype = wp_check_filetype( $dest_path );
	$mime     = $filetype['type'] ?: 'image/jpeg';

	// Generate thumbnails.
	$thumbs = array();
	if ( str_starts_with( $mime, 'image/' ) ) {
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$editor = wp_get_image_editor( $dest_path );
		if ( ! is_wp_error( $editor ) ) {
			$sizes = array(
				'large'  => array( 'width' => 1024, 'height' => 1024, 'crop' => false ),
				'medium' => array( 'width' => 300,  'height' => 300,  'crop' => false ),
				'thumb'  => array( 'width' => 150,  'height' => 150,  'crop' => true ),
			);
			$generated = $editor->multi_resize( $sizes );
			$base_url  = $upload_dir['baseurl'] . '/' . $rel_dir;
			foreach ( $generated as $size_name => $data ) {
				$thumbs[ 'thumb_' . $size_name ] = $base_url . '/' . $data['file'];
			}
			$img_size = $editor->get_size();
			$thumbs['width']  = $img_size['width'] ?? null;
			$thumbs['height'] = $img_size['height'] ?? null;
		}
	}

	return array(
		'file_url'  => $file_url,
		'file_path' => $dest_path,
		'mime'      => $mime,
		'file_size' => filesize( $dest_path ),
		'thumbs'    => $thumbs,
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

// ---------------------------------------------------------------------------
// Check for existing demo data.
// ---------------------------------------------------------------------------

$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'publish'
	)
);

if ( (int) $existing > 0 ) {
	mvs_seed_log( "Found {$existing} existing media items. Skipping demo import to avoid duplicates.", 'warning' );
	mvs_seed_log( 'To reimport, first run the cleanup script.' );
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		wp_send_json_success( array( 'message' => 'Demo data already exists. Delete existing media first to reimport.' ) );
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
		$demo_user_ids[ $du['login'] ] = $existing_user->ID;
		mvs_seed_log( "  User already exists: {$du['display']} (#{$existing_user->ID})" );
		continue;
	}

	$new_user_id = wp_insert_user(
		array(
			'user_login'   => $du['login'],
			'user_email'   => $du['email'],
			'user_pass'    => 'demo1234',
			'display_name' => $du['display'],
			'description'  => $du['bio'],
			'role'         => 'author',
		)
	);

	if ( is_wp_error( $new_user_id ) ) {
		mvs_seed_log( "  Failed to create user {$du['login']}: " . $new_user_id->get_error_message(), 'warning' );
		continue;
	}

	$demo_user_ids[ $du['login'] ] = $new_user_id;
	mvs_seed_log( "  Created user: {$du['display']} (#{$new_user_id})", 'success' );
}

mvs_seed_log( '  Demo users ready: ' . count( $demo_user_ids ) );

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

	$imported = mvs_seed_import_local_image( $source_path, $img['title'] );

	if ( ! $imported ) {
		mvs_seed_log( '  Failed to import image.', 'warning' );
		continue;
	}

	// Resolve author.
	$post_author = $fallback_user_id;
	if ( ! empty( $img['user'] ) && isset( $demo_user_ids[ $img['user'] ] ) ) {
		$post_author = $demo_user_ids[ $img['user'] ];
	}

	// Insert into mvs_media_index (returns auto-generated media_id).
	$media_id = \WPMediaVerse\Services\MediaMeta::insert(
		array(
			'title'             => $img['title'],
			'description'       => $img['description'],
			'post_author'       => $post_author,
			'status'            => 'publish',
			'media_type'        => $img['type'],
			'privacy'           => $img['privacy'],
			'moderation_status' => 'approved',
			'file_url'          => $imported['file_url'],
			'file_path'         => $imported['file_path'],
			'file_type'         => $imported['mime'],
			'file_size'         => $imported['file_size'],
			'width'             => $imported['thumbs']['width'] ?? null,
			'height'            => $imported['thumbs']['height'] ?? null,
		)
	);

	if ( ! $media_id ) {
		mvs_seed_log( '  Failed to insert media into index table.', 'warning' );
		continue;
	}

	// Store thumbnails.
	foreach ( $imported['thumbs'] as $key => $value ) {
		if ( str_starts_with( $key, 'thumb_' ) ) {
			\WPMediaVerse\Services\MediaMeta::set( $media_id, $key, $value );
		}
	}

	// Store tags and category as meta (no CPT taxonomy support).
	if ( ! empty( $img['tags'] ) ) {
		\WPMediaVerse\Services\MediaMeta::set( $media_id, 'tags', $img['tags'] );
	}
	if ( ! empty( $img['category'] ) ) {
		\WPMediaVerse\Services\MediaMeta::set( $media_id, 'category', $img['category'] );
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

	// Collections use wp_postmeta (still a CPT).
	update_post_meta( $col_id, '_mvs_collection_type', $col_cfg['type'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value

	// Save rules — tag values stored as plain strings now (no taxonomy term IDs).
	update_post_meta( $col_id, '_mvs_collection_rules', $col_cfg['rules'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value

	mvs_seed_log( "Created collection #{$col_id}: {$col_cfg['title']} ({$col_cfg['type']})", 'success' );
}

// ---------------------------------------------------------------------------
// Seed social interactions (expanded).
// ---------------------------------------------------------------------------

mvs_seed_log( '' );
mvs_seed_log( 'Adding social interactions...' );

$all_user_ids    = array_values( $demo_user_ids );
$reaction_types  = array( 'like', 'love', 'haha', 'wow' );
$reaction_count  = 0;
$comment_count   = 0;
$favorite_count  = 0;
$follow_count    = 0;

// --- 100+ reactions (randomized, no self-likes) ---
foreach ( $created_media as $media ) {
	$media_id     = $media['media_id'];
	$media_author = $media['author'];

	// Each media gets 2-4 reactions from OTHER users.
	$num_reactions = wp_rand( 2, 4 );
	$eligible      = array_filter( $all_user_ids, function ( $uid ) use ( $media_author ) {
		return $uid !== $media_author;
	} );
	$eligible = array_values( $eligible );

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
	$eligible = array_filter( $all_user_ids, function ( $uid ) use ( $media_author ) {
		return $uid !== $media_author;
	} );
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

	$eligible = array_filter( $all_user_ids, function ( $uid ) use ( $media_author ) {
		return $uid !== $media_author;
	} );
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
				'follower_id' => $pair[0],
				'followed_id' => $pair[1],
				'created_at'  => gmdate( 'Y-m-d H:i:s', time() - wp_rand( 0, 30 * DAY_IN_SECONDS ) ),
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

$created_media_ids = array_map( function( $m ) {
	return $m['media_id'];
}, $created_media );

if ( class_exists( '\WPMediaVersePro\Challenges\ChallengeService' ) && count( $created_media_ids ) >= 5 ) {
	mvs_seed_log( '' );
	mvs_seed_log( 'Creating competition demo data...' );

	$challenge_service = new \WPMediaVersePro\Challenges\ChallengeService();
	$battle_service    = new \WPMediaVersePro\Battles\BattleService();

	// Get test users (use the authors from created media).
	$test_users = array_unique( array_map( function( $id ) {
		return (int) \WPMediaVerse\Services\MediaMeta::get_author( $id );
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
			$author = (int) \WPMediaVerse\Services\MediaMeta::get_author( $created_media_ids[ $i ] );
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
				return (int) \WPMediaVerse\Services\MediaMeta::get_author( $id ) === $test_users[0];
			} );
			$user2_media = array_filter( $created_media_ids, function( $id ) use ( $test_users ) {
				return (int) \WPMediaVerse\Services\MediaMeta::get_author( $id ) === $test_users[1];
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

	// --- Tournament: Open for registration ---
	if ( class_exists( '\WPMediaVersePro\Tournaments\TournamentService' ) && count( $test_users ) >= 4 ) {
		$tournament_service = new \WPMediaVersePro\Tournaments\TournamentService();
		$t1 = $tournament_service->create(
			array(
				'title'              => 'Spring Photography Championship',
				'description'        => 'A 16-bracket single-elimination tournament. Upload your best photo each round and let the community vote!',
				'theme'              => 'Best Shot',
				'bracket_size'       => 16,
				'registration_start' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'registration_end'   => gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) ),
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
		$pool = $autopilot->get_pool();
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
		0 => array( 'current' => 23, 'longest' => 45, 'freezes' => 2 ),
		1 => array( 'current' => 7,  'longest' => 14, 'freezes' => 1 ),
		2 => array( 'current' => 42, 'longest' => 42, 'freezes' => 0 ),
		3 => array( 'current' => 3,  'longest' => 30, 'freezes' => 2 ),
		4 => array( 'current' => 100, 'longest' => 100, 'freezes' => 1 ),
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
