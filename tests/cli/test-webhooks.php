<?php
/**
 * CLI Tests: Webhooks — Source code integrity and configuration.
 *
 * Verifies WebhookService source code patterns, event constants,
 * HMAC signing, and option reading. These are structural tests
 * that inspect actual source code rather than making HTTP requests.
 *
 * @package WPMediaVerse
 */

function run_webhooks_tests(): array {
	$p = 0;
	$f = 0;

	$webhook_file = dirname( __DIR__, 2 ) . '/includes/Integrations/WebhookService.php';
	$source       = file_get_contents( $webhook_file );

	// ═══════════════════════════════════
	// SOURCE CODE: SSL VERIFY
	// ═══════════════════════════════════
	section( 'SSL VERIFY HANDLING' );

	// WebhookService must use wp_is_local_environment() for sslverify, not hardcode true.
	$uses_local_env = strpos( $source, 'wp_is_local_environment' ) !== false;
	assert_test(
		'WebhookService uses wp_is_local_environment() for sslverify',
		$uses_local_env,
		$uses_local_env ? 'found wp_is_local_environment()' : 'NOT FOUND - may hardcode sslverify'
	) ? $p++ : $f++;

	// Verify it does NOT have a hardcoded 'sslverify' => true without the environment check.
	$has_hardcoded_ssl = preg_match( "/'sslverify'\s*=>\s*true(?!.*wp_is_local_environment)/s", $source );
	assert_test(
		'No hardcoded sslverify => true (uses filter instead)',
		! $has_hardcoded_ssl,
		$has_hardcoded_ssl ? 'FOUND hardcoded true' : 'OK - uses apply_filters'
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// SOURCE CODE: HOOKS ALWAYS REGISTERED
	// ═══════════════════════════════════
	section( 'HOOK REGISTRATION' );

	// init() must NOT have an early return before add_action calls.
	// Extract the init() method body.
	$init_match = preg_match( '/public\s+function\s+init\s*\(\s*\)\s*:\s*void\s*\{(.*?)(?:public|private|protected|\z)/s', $source, $m );
	$init_body  = $m[1] ?? '';

	// Check that init() adds at least the core hooks.
	$has_uploaded_hook  = strpos( $init_body, 'mvs_media_uploaded' ) !== false;
	$has_deleted_hook   = strpos( $init_body, 'mvs_media_deleted' ) !== false;
	$has_reaction_hook  = strpos( $init_body, 'mvs_reaction_added' ) !== false;
	$has_comment_hook   = strpos( $init_body, 'mvs_comment_created' ) !== false;

	assert_test( 'init() registers mvs_media_uploaded hook', $has_uploaded_hook ) ? $p++ : $f++;
	assert_test( 'init() registers mvs_media_deleted hook', $has_deleted_hook ) ? $p++ : $f++;

	// Verify no early return before the add_action calls.
	// Find position of first add_action and check for return statements before it.
	$first_hook_pos = strpos( $init_body, 'add_action' );
	if ( $first_hook_pos !== false ) {
		$before_hooks     = substr( $init_body, 0, $first_hook_pos );
		$has_early_return = preg_match( '/\breturn\b/', $before_hooks );
		assert_test(
			'init() has no early return before hook registration',
			! $has_early_return,
			$has_early_return ? 'FOUND return before add_action' : 'hooks always registered'
		) ? $p++ : $f++;
	} else {
		assert_test( 'init() has no early return before hook registration', false, 'no add_action found in init()' ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// SOURCE CODE: DISPATCH CHECKS AT SEND TIME
	// ═══════════════════════════════════
	section( 'DISPATCH BEHAVIOR' );

	// dispatch() should call get_webhooks() and iterate — checking at send time, not at init.
	$dispatch_match = preg_match( '/private\s+function\s+dispatch\s*\(.*?\)\s*:\s*void\s*\{(.*?)(?:private|public|protected|\z)/s', $source, $dm );
	$dispatch_body  = $dm[1] ?? '';

	$checks_at_dispatch = strpos( $dispatch_body, 'get_webhooks' ) !== false;
	assert_test(
		'dispatch() calls get_webhooks() at send time (not cached at init)',
		$checks_at_dispatch,
		$checks_at_dispatch ? 'found get_webhooks() in dispatch()' : 'NOT FOUND'
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// EVENTS CONSTANT
	// ═══════════════════════════════════
	section( 'WEBHOOK EVENTS CONSTANT' );

	$expected_events = array(
		'media.uploaded',
		'media.updated',
		'media.deleted',
		'media.moderated',
		'media.reaction',
		'media.comment',
	);

	$actual_events = \WPMediaVerse\Integrations\WebhookService::EVENTS;
	assert_test(
		'EVENTS constant is an array',
		is_array( $actual_events ),
		'type:' . gettype( $actual_events )
	) ? $p++ : $f++;

	$events_match = $actual_events === $expected_events;
	assert_test(
		'EVENTS matches expected list: ' . implode( ', ', $expected_events ),
		$events_match,
		$events_match ? 'exact match' : 'actual: ' . implode( ', ', $actual_events )
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// OPTION READING
	// ═══════════════════════════════════
	section( 'WEBHOOK OPTION READING' );

	// get_webhooks() reads from 'mvs_webhooks' option.
	$reads_option = strpos( $source, "get_option( 'mvs_webhooks'" ) !== false
		|| strpos( $source, "get_option('mvs_webhooks'" ) !== false;
	assert_test(
		'get_webhooks() reads mvs_webhooks option',
		$reads_option,
		$reads_option ? 'found get_option(mvs_webhooks)' : 'NOT FOUND'
	) ? $p++ : $f++;

	// Verify the option can be read without error.
	$webhooks = get_option( 'mvs_webhooks', array() );
	assert_test(
		'get_option(mvs_webhooks) returns array (or empty)',
		is_array( $webhooks ),
		'type:' . gettype( $webhooks ) . ' count:' . ( is_array( $webhooks ) ? count( $webhooks ) : 'n/a' )
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// HMAC SIGNING
	// ═══════════════════════════════════
	section( 'HMAC SIGNATURE' );

	// Verify the source uses hash_hmac with sha256 for signing.
	$uses_hmac_sha256 = strpos( $source, "hash_hmac( 'sha256'" ) !== false
		|| strpos( $source, "hash_hmac('sha256'" ) !== false;
	assert_test(
		'Webhook signing uses HMAC-SHA256',
		$uses_hmac_sha256,
		$uses_hmac_sha256 ? 'found hash_hmac(sha256)' : 'NOT FOUND'
	) ? $p++ : $f++;

	// Verify signature header format is 'sha256=...'.
	$has_sig_header = strpos( $source, 'X-MVS-Signature' ) !== false;
	$has_sha256_prefix = strpos( $source, "'sha256='" ) !== false || strpos( $source, '"sha256="' ) !== false;
	assert_test(
		'Signature sent in X-MVS-Signature header with sha256= prefix',
		$has_sig_header && $has_sha256_prefix,
		'header:' . ( $has_sig_header ? 'yes' : 'no' ) . ' prefix:' . ( $has_sha256_prefix ? 'yes' : 'no' )
	) ? $p++ : $f++;

	return array( $p, $f );
}
