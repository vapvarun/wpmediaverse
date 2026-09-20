<?php
/**
 * Dump the live REST route registry with the authority each route declares.
 *
 * Run with: wp eval-file bin/route-authority-scan.php
 *
 * The point is that a route's audience must be DECLARED and CHECKED, not
 * inferred by a reader from a permission_callback name. This produces the
 * machine-readable half: what is actually registered right now, and what each
 * route's callback resolves to.
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

$mvs_namespaces = array( 'mvs/v1', 'mvs-pro/v1' );
$mvs_out        = array();

foreach ( rest_get_server()->get_routes() as $mvs_route => $mvs_handlers ) {
	$mvs_ns = '';
	foreach ( $mvs_namespaces as $mvs_candidate ) {
		if ( 0 === strpos( ltrim( $mvs_route, '/' ), $mvs_candidate ) ) {
			$mvs_ns = $mvs_candidate;
			break;
		}
	}

	if ( '' === $mvs_ns || rtrim( '/' . $mvs_ns, '/' ) === rtrim( $mvs_route, '/' ) ) {
		continue;
	}

	foreach ( $mvs_handlers as $mvs_handler ) {
		$mvs_methods = array_keys( array_filter( (array) ( $mvs_handler['methods'] ?? array() ) ) );
		$mvs_cb      = $mvs_handler['permission_callback'] ?? null;

		if ( is_array( $mvs_cb ) && isset( $mvs_cb[0], $mvs_cb[1] ) ) {
			$mvs_cb_name = ( is_object( $mvs_cb[0] ) ? get_class( $mvs_cb[0] ) : (string) $mvs_cb[0] ) . '::' . $mvs_cb[1];
		} elseif ( $mvs_cb instanceof Closure ) {
			$mvs_cb_name = 'Closure';
		} elseif ( is_string( $mvs_cb ) ) {
			$mvs_cb_name = $mvs_cb;
		} else {
			$mvs_cb_name = 'NONE';
		}

		$mvs_key = implode( ',', $mvs_methods ) . ' ' . $mvs_route;

		$mvs_out[ $mvs_key ] = array(
			'namespace'           => $mvs_ns,
			'route'               => $mvs_route,
			'methods'             => $mvs_methods,
			'permission_callback' => $mvs_cb_name,
			'takes_object_id'     => (bool) preg_match( '/\(\?P<(id|media_id|album_id|folder_id|document_id|collection_id|conversation_id)>/', $mvs_route ),
			'writes'              => (bool) array_intersect( $mvs_methods, array( 'POST', 'PUT', 'PATCH', 'DELETE' ) ),
		);
	}
}

ksort( $mvs_out );
echo wp_json_encode( $mvs_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
