<?php
/**
 * Theme copies of our templates are compared by `@version` (Basecamp 10344471983).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\TemplateVersions;
use WPMediaVerse\Services\HealthCheckService;

/**
 * @since 2.6.0
 */
class TemplateVersionsTest extends WP_UnitTestCase {

	/**
	 * Temporary theme override folders created by a test.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	public function tear_down(): void {
		foreach ( $this->dirs as $dir ) {
			$this->remove( $dir );
		}
		$this->dirs = array();
		remove_all_filters( 'mvs_template_roots' );
		parent::tear_down();
	}

	/**
	 * A theme override folder holding the given files.
	 *
	 * @param array<string, string> $files Relative path => file contents.
	 * @return string Folder path.
	 */
	private function theme( array $files ): string {
		$dir = trailingslashit( get_temp_dir() ) . 'mvs-tv-' . wp_generate_password( 8, false ) . '/';
		foreach ( $files as $rel => $contents ) {
			wp_mkdir_p( dirname( $dir . $rel ) );
			file_put_contents( $dir . $rel, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$this->dirs[] = $dir;

		return $dir;
	}

	/**
	 * A template header with an optional version.
	 *
	 * @param string $version Version, or '' for none.
	 * @return string
	 */
	private function header( string $version ): string {
		return "<?php\n/**\n * Theme copy.\n *\n * @package Theme\n" . ( $version ? " * @version {$version}\n" : '' ) . " */\n";
	}

	/**
	 * Delete a folder tree.
	 *
	 * @param string $dir Folder.
	 */
	private function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function test_every_shipped_template_is_versioned(): void {
		$current = TemplateVersions::version_of( MVS_PLUGIN_DIR . 'templates/explore.php' );

		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+/', $current );
		$this->assertSame( '', TemplateVersions::version_of( MVS_PLUGIN_DIR . 'templates/admin/documents.php' ), 'wp-admin screens are not overridable, so not versioned.' );
	}

	public function test_old_and_unversioned_copies_are_outdated_and_current_ones_are_not(): void {
		$current = TemplateVersions::version_of( MVS_PLUGIN_DIR . 'templates/explore.php' );
		$dir     = $this->theme(
			array(
				'explore.php'                    => $this->header( '1.0.0' ),
				'404.php'                        => $this->header( '' ),
				'partials/explore-tag-cloud.php' => $this->header( $current ),
				'my-own-template.php'            => $this->header( '' ),
			)
		);

		$rows = wp_list_pluck( TemplateVersions::outdated( array( $dir ) ), 'theme_version', 'file' );

		$this->assertSame(
			array(
				'404.php'     => '',
				'explore.php' => '1.0.0',
			),
			$this->sorted( $rows ),
			'Only copies older than (or missing) the plugin version are reported; the theme\'s own files are ignored.'
		);
	}

	public function test_a_child_theme_copy_hides_the_parent_copy(): void {
		$current = TemplateVersions::version_of( MVS_PLUGIN_DIR . 'templates/explore.php' );
		$child   = $this->theme( array( 'explore.php' => $this->header( $current ) ) );
		$parent  = $this->theme( array( 'explore.php' => $this->header( '1.0.0' ) ) );

		$this->assertSame( array(), TemplateVersions::outdated( array( $child, $parent ) ), 'Only the child copy loads, and it is current.' );
	}

	public function test_another_plugin_can_register_its_templates(): void {
		$root = $this->theme( array( 'layouts/grid.php' => $this->header( '3.0.0' ) ) );
		add_filter(
			'mvs_template_roots',
			static function ( $roots ) use ( $root ) {
				$roots[ $root ] = 'Add-on';
				return $roots;
			}
		);

		$rows = TemplateVersions::outdated( array( $this->theme( array( 'layouts/grid.php' => $this->header( '2.0.0' ) ) ) ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Add-on', $rows[0]['plugin'] );
		$this->assertSame( '3.0.0', $rows[0]['plugin_version'] );
	}

	public function test_site_health_is_good_without_overrides(): void {
		$result = ( new HealthCheckService() )->test_template_overrides();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'wpmediaverse_template_overrides', $result['test'] );
	}

	/**
	 * Sort by key so the assertion does not depend on directory order.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private function sorted( array $rows ): array {
		ksort( $rows );
		return $rows;
	}
}
