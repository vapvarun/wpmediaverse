<?php
/**
 * Site Health integration.
 *
 * Registers custom health checks for WPMediaVerse.
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

class HealthCheckService {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
	}

	/**
	 * Register health check tests.
	 *
	 * @param array $tests Existing tests.
	 * @return array
	 */
	public function register_tests( array $tests ): array {
		$tests['direct']['wpmediaverse_tables'] = array(
			'label' => __( 'WPMediaVerse Database Tables', 'wpmediaverse' ),
			'test'  => array( $this, 'test_tables' ),
		);

		$tests['direct']['wpmediaverse_uploads'] = array(
			'label' => __( 'WPMediaVerse Upload Directory', 'wpmediaverse' ),
			'test'  => array( $this, 'test_uploads' ),
		);

		$tests['direct']['wpmediaverse_pages'] = array(
			'label' => __( 'WPMediaVerse Required Pages', 'wpmediaverse' ),
			'test'  => array( $this, 'test_pages' ),
		);

		return $tests;
	}

	/**
	 * Test that all required DB tables exist.
	 *
	 * @return array
	 */
	public function test_tables(): array {
		global $wpdb;

		$required_tables = array(
			'mvs_reactions',
			'mvs_favorites',
			'mvs_media_views',
			'mvs_media_stats',
			'mvs_access_rules',
			'mvs_access_grants',
			'mvs_mentions',
			'mvs_album_items',
			'mvs_media_index',
			'mvs_follows',
			'mvs_notifications',
			'mvs_reports',
			'mvs_blocks',
			'mvs_activity',
			'mvs_error_log',
		);

		$missing = array();
		foreach ( $required_tables as $table ) {
			$full_table = $wpdb->prefix . $table;
			$exists     = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table )
			);
			if ( ! $exists ) {
				$missing[] = $table;
			}
		}

		if ( empty( $missing ) ) {
			return array(
				'label'       => __( 'WPMediaVerse database tables are present', 'wpmediaverse' ),
				'status'      => 'good',
				'badge'       => array( 'label' => 'WPMediaVerse', 'color' => 'blue' ),
				'description' => sprintf( '<p>%s</p>', __( 'All required database tables exist.', 'wpmediaverse' ) ),
				'test'        => 'wpmediaverse_tables',
			);
		}

		return array(
			'label'       => __( 'WPMediaVerse database tables are missing', 'wpmediaverse' ),
			'status'      => 'critical',
			'badge'       => array( 'label' => 'WPMediaVerse', 'color' => 'red' ),
			'description' => sprintf(
				'<p>%s: %s</p><p>%s</p>',
				__( 'Missing tables', 'wpmediaverse' ),
				esc_html( implode( ', ', $missing ) ),
				__( 'Try deactivating and reactivating the plugin to recreate tables.', 'wpmediaverse' )
			),
			'actions'     => '',
			'test'        => 'wpmediaverse_tables',
		);
	}

	/**
	 * Test that the upload directory is writable.
	 *
	 * @return array
	 */
	public function test_uploads(): array {
		$upload_dir = wp_upload_dir();

		if ( $upload_dir['error'] ) {
			return array(
				'label'       => __( 'Upload directory is not writable', 'wpmediaverse' ),
				'status'      => 'critical',
				'badge'       => array( 'label' => 'WPMediaVerse', 'color' => 'red' ),
				'description' => sprintf( '<p>%s</p>', esc_html( $upload_dir['error'] ) ),
				'test'        => 'wpmediaverse_uploads',
			);
		}

		$mvs_dir = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse';
		if ( is_dir( $mvs_dir ) && ! wp_is_writable( $mvs_dir ) ) {
			return array(
				'label'       => __( 'WPMediaVerse upload directory is not writable', 'wpmediaverse' ),
				'status'      => 'critical',
				'badge'       => array( 'label' => 'WPMediaVerse', 'color' => 'red' ),
				'description' => sprintf( '<p>%s: %s</p>', __( 'Directory not writable', 'wpmediaverse' ), esc_html( $mvs_dir ) ),
				'test'        => 'wpmediaverse_uploads',
			);
		}

		return array(
			'label'       => __( 'WPMediaVerse upload directory is writable', 'wpmediaverse' ),
			'status'      => 'good',
			'badge'       => array( 'label' => 'WPMediaVerse', 'color' => 'blue' ),
			'description' => sprintf( '<p>%s</p>', __( 'Media files can be uploaded successfully.', 'wpmediaverse' ) ),
			'test'        => 'wpmediaverse_uploads',
		);
	}

	/**
	 * Test that auto-created pages exist.
	 *
	 * @return array
	 */
	public function test_pages(): array {
		$pages = array(
			'mvs_page_explore'   => __( 'Explore Media', 'wpmediaverse' ),
			'mvs_page_dashboard' => __( 'My Media', 'wpmediaverse' ),
			'mvs_page_upload'    => __( 'Upload Media', 'wpmediaverse' ),
		);

		$missing = array();
		foreach ( $pages as $option => $label ) {
			$page_id = (int) get_option( $option, 0 );
			if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
				$missing[] = $label;
			}
		}

		if ( empty( $missing ) ) {
			return array(
				'label'       => __( 'WPMediaVerse pages are set up', 'wpmediaverse' ),
				'status'      => 'good',
				'badge'       => array( 'label' => 'WPMediaVerse', 'color' => 'blue' ),
				'description' => sprintf( '<p>%s</p>', __( 'All required pages exist and are published.', 'wpmediaverse' ) ),
				'test'        => 'wpmediaverse_pages',
			);
		}

		return array(
			'label'       => __( 'WPMediaVerse pages are missing', 'wpmediaverse' ),
			'status'      => 'recommended',
			'badge'       => array( 'label' => 'WPMediaVerse', 'color' => 'orange' ),
			'description' => sprintf(
				'<p>%s: %s</p><p>%s</p>',
				__( 'Missing pages', 'wpmediaverse' ),
				esc_html( implode( ', ', $missing ) ),
				__( 'Try deactivating and reactivating the plugin to recreate pages.', 'wpmediaverse' )
			),
			'test'        => 'wpmediaverse_pages',
		);
	}
}
