<?php
/**
 * [mvs_gallery] can ask for a layout it cannot draw itself.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

/**
 * Basecamp 10297764235.
 *
 * The grid path could filter by tag, category, type and author but only ever
 * drew one layout; the platform layouts could draw four but accepted no
 * filter. This pins the seam that joins them: Free offers a slug it does not
 * recognise to `mvs_gallery_layout_html`, and treats markup coming back as
 * "handled".
 *
 * Free deliberately does NOT look up which slugs exist - Pro's MODES map is
 * private - so these assert the ask-and-see behaviour, including that an
 * unknown slug degrades to the grid instead of erroring.
 */
class GalleryLayoutSeamTest extends WP_UnitTestCase {

	/** @var array<int, array{layout: string, args: array}> */
	private array $calls = array();

	private ?string $answer = null;

	public function set_up(): void {
		parent::set_up();

		$this->calls  = array();
		$this->answer = null;

		add_filter(
			'mvs_gallery_layout_html',
			function ( $html, $layout, $args ) {
				$this->calls[] = array(
					'layout' => $layout,
					'args'   => $args,
				);

				return null === $this->answer ? $html : $this->answer;
			},
			10,
			3
		);
	}

	/**
	 * A slug Free draws itself never reaches the seam.
	 */
	public function test_a_core_layout_is_not_offered_to_an_extension(): void {
		foreach ( array( 'grid', 'masonry', 'list', 'square', 'original' ) as $core ) {
			$this->calls = array();

			do_shortcode( '[mvs_gallery layout="' . $core . '"]' );

			$this->assertSame(
				array(),
				$this->calls,
				"'{$core}' is a layout Free renders; it must not be offered out."
			);
		}
	}

	/**
	 * A slug Free does not know IS offered, with the resolved query args.
	 */
	public function test_an_unknown_layout_is_offered_with_the_query_args(): void {
		do_shortcode( '[mvs_gallery layout="flickr" tag="nature" category="travel"]' );

		$this->assertCount( 1, $this->calls, 'The seam was not offered the unknown layout.' );
		$this->assertSame( 'flickr', $this->calls[0]['layout'] );
		$this->assertSame( 'nature', $this->calls[0]['args']['tag'] );
		$this->assertSame( 'travel', $this->calls[0]['args']['category'] );
	}

	/**
	 * Markup returned by an extension short-circuits Free's own grid.
	 */
	public function test_returned_markup_replaces_the_grid(): void {
		$this->answer = '<div id="rendered-by-an-extension"></div>';

		$out = do_shortcode( '[mvs_gallery layout="flickr"]' );

		$this->assertStringContainsString( 'rendered-by-an-extension', $out );
		$this->assertStringNotContainsString( 'mvs-media-grid', $out, "Free's grid rendered as well as the extension's." );
	}

	/**
	 * THE DEGRADE PATH. Nobody answers, so the owner gets the grid - not an
	 * error and not an empty page, which is what a typo should cost.
	 */
	public function test_an_unanswered_layout_falls_back_to_the_grid(): void {
		$this->answer = null;

		$out = do_shortcode( '[mvs_gallery layout="not-a-real-layout"]' );

		$this->assertCount( 1, $this->calls, 'It should still have been offered.' );
		$this->assertStringContainsString( 'mvs-media-grid', $out, 'An unanswered layout did not fall back to the grid.' );
	}

	/**
	 * No layout at all behaves exactly as before this change.
	 */
	public function test_no_layout_attribute_is_unchanged(): void {
		$out = do_shortcode( '[mvs_gallery]' );

		$this->assertSame( array(), $this->calls, 'The seam fired without a layout being asked for.' );
		$this->assertStringContainsString( 'mvs-media-grid', $out );
	}
}
