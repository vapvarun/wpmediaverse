<?php
/**
 * Front-end empty states can carry an in-page action button.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10252887530. My Media's "No media yet" box needed an Upload button
 * that opens the file picker in place, which a link action cannot do.
 */
class EmptyStateActionTest extends WP_UnitTestCase {

	public function test_on_click_renders_a_button_bound_to_the_action(): void {
		$html = Plugin::container()->get( 'template_helpers' )->render_block_empty_state(
			array(
				'title'   => 'No media yet',
				'actions' => array(
					array(
						'label'    => 'Upload media',
						'on_click' => 'actions.openFilePicker',
					),
				),
			)
		);

		$this->assertStringContainsString( '<button type="button" class="mvs-btn mvs-btn--primary" data-wp-on--click="actions.openFilePicker">Upload media</button>', $html );
	}

	public function test_url_still_renders_a_link_and_an_empty_action_renders_nothing(): void {
		$html = Plugin::container()->get( 'template_helpers' )->render_block_empty_state(
			array(
				'actions' => array(
					array(
						'label' => 'Create album',
						'url'   => 'https://example.org/albums/new/',
					),
					array( 'label' => 'No target' ),
				),
			)
		);

		$this->assertStringContainsString( '<a href="https://example.org/albums/new/" class="mvs-btn mvs-btn--primary">Create album</a>', $html );
		$this->assertStringNotContainsString( 'No target', $html );
	}
}
