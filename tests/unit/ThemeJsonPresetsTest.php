<?php
/**
 * MediaVerse adds its design tokens to WordPress's defaults, it does not replace them.
 *
 * WP_Theme_JSON_Data::update_with() swaps a preset list for the one it is given.
 * Handing it only the mvs-* entries removed core's colours, font sizes and spacing
 * from every site running the plugin.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_Theme_JSON_Data;
use WP_Theme_JSON_Resolver;
use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

class ThemeJsonPresetsTest extends WP_UnitTestCase {

	/**
	 * Run the plugin's filter over core's own default data and return the result.
	 *
	 * @return array<string,mixed>
	 */
	private function filtered(): array {
		$core = new WP_Theme_JSON_Data( WP_Theme_JSON_Resolver::get_core_data()->get_raw_data(), 'default' );

		return Plugin::register_theme_json( $core )->get_data();
	}

	/**
	 * @param array<string,mixed> $data Filtered theme.json data.
	 * @param string[]            $path Keys under settings.
	 * @return string[]
	 */
	private function slugs( array $data, array $path ): array {
		$node = $data['settings'];
		foreach ( $path as $key ) {
			$node = $node[ $key ] ?? array();
		}
		$list = isset( $node['default'] ) ? $node['default'] : $node;

		return array_column( (array) $list, 'slug' );
	}

	public function test_core_colours_survive_and_the_mvs_colours_are_added(): void {
		$slugs = $this->slugs( $this->filtered(), array( 'color', 'palette' ) );

		foreach ( array( 'black', 'white', 'vivid-red', 'vivid-purple', 'mvs-primary', 'mvs-border' ) as $slug ) {
			$this->assertContains( $slug, $slugs, "The $slug colour must be present." );
		}
	}

	public function test_core_font_sizes_and_spacing_survive(): void {
		$data = $this->filtered();

		$this->assertContains( 'medium', $this->slugs( $data, array( 'typography', 'fontSizes' ) ) );
		$this->assertContains( 'mvs-md', $this->slugs( $data, array( 'typography', 'fontSizes' ) ) );
		$this->assertContains( 'mvs-1', $this->slugs( $data, array( 'spacing', 'spacingSizes' ) ) );
	}

	public function test_an_entry_already_present_is_not_added_twice(): void {
		$slugs = $this->slugs( $this->filtered(), array( 'color', 'palette' ) );

		$this->assertSame( count( $slugs ), count( array_unique( $slugs ) ) );
	}

	public function test_the_plugin_does_not_set_the_sites_content_width(): void {
		$layout = $this->filtered()['settings']['layout'] ?? array();

		$this->assertArrayNotHasKey( 'contentSize', $layout );
	}
}
