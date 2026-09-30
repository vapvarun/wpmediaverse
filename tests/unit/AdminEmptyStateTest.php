<?php
/**
 * The admin empty-state helper emits the markup admin.css styles.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10350404627. The helper used to emit <p> titles with classes no
 * stylesheet defined, so nothing called it and every screen hand-rolled its own.
 */
class AdminEmptyStateTest extends WP_UnitTestCase {

	public function test_markup_is_the_icon_h3_p_shape_admin_css_styles(): void {
		$html = Plugin::container()->get( 'template_helpers' )->render_admin_empty_state(
			array(
				'icon'    => 'flag',
				'title'   => 'Nothing here',
				'message' => 'No reports with this status.',
			)
		);

		$this->assertStringContainsString( 'class="mvs-empty-state-admin"', $html );
		$this->assertStringContainsString( 'data-lucide="flag"', $html );
		$this->assertStringContainsString( '<h3>Nothing here</h3>', $html );
		$this->assertStringContainsString( '<p>No reports with this status.</p>', $html );
	}
}
