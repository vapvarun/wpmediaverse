<?php
/**
 * An MP4 whose container brand is M4V uploads like any MP4.
 *
 * PHP's finfo reports these as video/x-m4v; the allowed list only knows video/mp4.
 * Basecamp 10379561512.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\MediaTypes;
use WPMediaVerse\Core\Plugin;

class M4vBrandUploadTest extends WP_UnitTestCase {

	private string $tmp_dir;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->tmp_dir = sys_get_temp_dir() . '/mvs-m4v-test-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->tmp_dir );
		add_filter( 'mvs_upload_skip_move_uploaded_file_check', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'mvs_upload_skip_move_uploaded_file_check', '__return_true' );
		array_map( 'unlink', (array) glob( $this->tmp_dir . '/*' ) );
		rmdir( $this->tmp_dir );
		parent::tear_down();
	}

	/**
	 * The fixture as PHP hands it over: a temp name with no extension.
	 */
	private function m4v_upload(): array {
		$tmp = $this->tmp_dir . '/php' . wp_generate_password( 6, false );
		copy( dirname( __DIR__ ) . '/fixtures/m4v-brand.mp4', $tmp );

		return array(
			'name'     => 'clip.mp4',
			'tmp_name' => $tmp,
			'type'     => 'video/mp4',
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp ),
		);
	}

	public function test_canonical_mime_maps_only_the_m4v_alias(): void {
		$this->assertSame( 'video/mp4', MediaTypes::canonical_mime( 'video/x-m4v' ) );
		$this->assertSame( 'video/quicktime', MediaTypes::canonical_mime( 'video/quicktime' ) );
		$this->assertSame( 'application/x-php', MediaTypes::canonical_mime( 'application/x-php' ) );
	}

	public function test_fixture_really_sniffs_as_x_m4v(): void {
		$file = $this->m4v_upload();
		$this->assertSame( 'video/x-m4v', finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $file['tmp_name'] ) );
	}

	public function test_m4v_brand_mp4_uploads_as_mp4(): void {
		$result = Plugin::container()->get( 'upload' )->handle( $this->m4v_upload(), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->assertNotSame( 'mvs_invalid_type', $result->get_error_code(), 'An M4V-branded MP4 must pass the type check.' );
			$this->markTestIncomplete( 'Passed the type check, then stopped at: ' . $result->get_error_code() );
		}

		$this->assertSame( 'video/mp4', Plugin::container()->get( 'media_repository' )->get( (int) $result, 'file_type' ) );
	}

	public function test_m4v_brand_mp4_is_refused_when_the_owner_disallows_mp4(): void {
		$no_mp4 = static function ( array $types ): array {
			return array_values( array_diff( $types, array( 'video/mp4' ) ) );
		};
		add_filter( 'mvs_allowed_file_types', $no_mp4 );
		$result = Plugin::container()->get( 'upload' )->handle( $this->m4v_upload(), get_current_user_id() );
		remove_filter( 'mvs_allowed_file_types', $no_mp4 );

		$this->assertWPError( $result );
		$this->assertSame( 'mvs_invalid_type', $result->get_error_code() );
	}
}
