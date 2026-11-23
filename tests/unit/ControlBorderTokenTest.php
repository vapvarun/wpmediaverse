<?php
/**
 * The control edge token.
 *
 * Controls (fields, selects, outlined buttons) draw their edge with
 * --mvs-border-control, a mix of the text colour that clears WCAG 1.4.11's 3:1
 * on light and dark surfaces. --mvs-border stays the faint frame colour for
 * cards and dividers. These pin that the token exists in every place it has to
 * be declared, and that the controls QA measured at 1.4:1 use it.
 *
 * The full check is the browser crawler, bin/control-contrast-crawler.js.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class ControlBorderTokenTest extends WP_UnitTestCase {

	private function css( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/' . $file );
	}

	/**
	 * Body of the first rule whose selector list is exactly $selector.
	 */
	private function rule( string $css, string $selector ): string {
		// Comments may hold braces ("select { width: 100% }").
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$this->assertSame( 1, preg_match( '/(?:^|\})\s*' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/m', $css, $m ), "rule {$selector} not found" );
		return $m[1];
	}

	public function test_token_is_declared_for_light_dark_and_old_browsers(): void {
		$css = $this->css( 'frontend.css' );

		// Light :root and the dark block both declare it: a dark wrapper below
		// <html> redefines --mvs-text, and the mix must follow it there.
		$this->assertGreaterThanOrEqual( 2, preg_match_all( '/--mvs-border-control:\s*color-mix\(in oklab, var\(--mvs-text\)/', $css ) );
		// A browser without color-mix() gets a hex edge, not an invalid one.
		$this->assertMatchesRegularExpression( '/@supports not \(color: color-mix\([^)]*\)\)\s*\{[^@]*--mvs-border-control:\s*#[0-9a-f]{6}/i', $css );

		$this->assertStringContainsString( '--mvs-border-control: color-mix(', $this->css( 'admin.css' ) );
	}

	public function test_the_controls_qa_measured_use_the_control_edge(): void {
		$css = $this->css( 'frontend.css' );

		foreach ( array( '.mvs-btn.mvs-btn--secondary', '.mvs-panel-toolbar__search', '.mvs-panel-toolbar__select' ) as $selector ) {
			$this->assertStringContainsString( 'var(--mvs-border-control)', $this->rule( $css, $selector ), $selector );
		}
	}

	/**
	 * A label on a plain primary fill is picked by the fill's luminance, not
	 * taken from the theme: white on Twenty Twenty-Five's #1d7cd5 is 4.29:1.
	 */
	public function test_labels_on_a_plain_primary_fill_follow_its_luminance(): void {
		$css = $this->css( 'frontend.css' );

		$this->assertMatchesRegularExpression( '/--mvs-on-primary-solid:\s*color\(from var\(--mvs-primary\) xyz-d65 /', $css );
		foreach ( array( '.mvs-dashboard-tabs .mvs-dashboard-tab.active .mvs-dashboard-tab__count', '.mvs-tag-cloud-item.active', '.mvs-follow-btn' ) as $selector ) {
			$this->assertStringContainsString( 'var(--mvs-on-primary-solid', $this->rule( $css, $selector ), $selector );
		}
	}

	/**
	 * A pressed or focused link button keeps its label: core's a:active
	 * (0,1,1) outranks a one-class colour (#183ad6 on #2271b1, 1.55:1).
	 */
	public function test_admin_link_buttons_pin_their_label_in_every_state(): void {
		$rule = $this->rule( $this->css( 'admin.css' ), "a.mvs-btn--primary:focus,\na.mvs-btn--primary:active,\na.mvs-btn--primary:visited" );
		$this->assertStringContainsString( 'var(--mvs-surface)', $rule );
	}
}
