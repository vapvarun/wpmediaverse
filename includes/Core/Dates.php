<?php
/**
 * Date/time helpers and the REST response timestamp-normalization seam.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Single source for UTC -> ISO-8601 conversion and the one REST seam that makes
 * every app-facing timestamp unambiguous.
 *
 * Rather than each controller emitting `<field>_gmt` by hand (partial, drift-
 * prone, forgotten on new endpoints), filter_rest_response() runs once at
 * rest_post_dispatch for the mvs namespaces and adds an ISO-8601 `<key>_gmt`
 * sibling for every whitelisted timestamp key it finds in the response — so web
 * and app share one contract and future endpoints inherit it for free.
 */
final class Dates {

	/**
	 * REST namespaces whose responses get timestamp normalization.
	 *
	 * @var string[]
	 */
	private const NAMESPACES = array( '/mvs/v1/', '/mvs-pro/v1/' );

	/**
	 * Convert a stored UTC "Y-m-d H:i:s" timestamp to an ISO-8601 'Z' string.
	 *
	 * Plugin timestamps are written with current_time('mysql', true) (UTC) or
	 * come from *_gmt columns, but are shipped as unmarked MySQL strings, which
	 * is timezone-ambiguous for the app. This yields e.g. 2026-07-18T19:44:27Z.
	 *
	 * @param string $mysql_utc UTC datetime as stored (no timezone marker).
	 * @return string ISO-8601 UTC ('...Z'), or '' when empty/zero/invalid.
	 */
	public static function iso8601( string $mysql_utc ): string {
		$mysql_utc = trim( $mysql_utc );
		if ( '' === $mysql_utc || 0 === strpos( $mysql_utc, '0000-00-00' ) ) {
			return '';
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		return false === $ts ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	/**
	 * Response keys known to hold a UTC datetime.
	 *
	 * A sibling `<key>_gmt` (ISO-Z) is added for each. Every plugin timestamp is
	 * written with current_time('mysql', true) or read from a *_gmt column, so
	 * these keys are UTC by construction — never add a key here that can carry
	 * site-local time.
	 *
	 * @return string[]
	 */
	public static function timestamp_keys(): array {
		/**
		 * Filters the response keys that receive an ISO-8601 `<key>_gmt` sibling.
		 *
		 * Pro / add-ons extend this to cover their own UTC timestamp fields.
		 *
		 * @param string[] $keys Timestamp key names (must hold UTC values).
		 */
		return (array) apply_filters(
			'mvs_rest_timestamp_keys',
			array(
				'created_at',
				'updated_at',
				'date',
				'added_at',
				'last_activity_at',
				'last_read_at',
				'last_message_at',
				'joined_at',
				'reacted_at',
				'muted_until',
				'expires_at',
				'starts_at',
				'ends_at',
			)
		);
	}

	/**
	 * Recursively add `<key>_gmt` ISO-8601 siblings for whitelisted timestamp
	 * keys.
	 *
	 * Idempotent: skips a key that already has a `_gmt` sibling (so a controller
	 * that emits one by hand is not double-processed), and only touches strings
	 * shaped like a MySQL datetime — ints, nulls, and already-ISO values pass
	 * through untouched. Recurses through arrays and stdClass only; WP_Post /
	 * WP_User and other objects are left alone.
	 *
	 * @param mixed    $data Response data (array/object/scalar).
	 * @param string[] $keys Timestamp key whitelist.
	 * @return mixed Data with sibling *_gmt fields added.
	 */
	public static function add_gmt_fields( $data, array $keys ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::add_gmt_fields( $v, $keys );
			}
			foreach ( $keys as $key ) {
				$gmt = $key . '_gmt';
				if ( array_key_exists( $key, $data ) && ! array_key_exists( $gmt, $data ) ) {
					$iso = self::maybe_iso( $data[ $key ] );
					if ( '' !== $iso ) {
						$data[ $gmt ] = $iso;
					}
				}
			}
			return $data;
		}

		if ( $data instanceof \stdClass ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = self::add_gmt_fields( $v, $keys );
			}
			foreach ( $keys as $key ) {
				$gmt = $key . '_gmt';
				if ( isset( $data->$key ) && ! isset( $data->$gmt ) ) {
					$iso = self::maybe_iso( $data->$key );
					if ( '' !== $iso ) {
						$data->$gmt = $iso;
					}
				}
			}
			return $data;
		}

		return $data;
	}

	/**
	 * Convert a value to ISO-8601 only when it is a MySQL-datetime string.
	 *
	 * @param mixed $value Candidate value.
	 * @return string ISO-8601 string, or '' when not a MySQL datetime.
	 */
	private static function maybe_iso( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/', $value ) ) {
			return '';
		}
		return self::iso8601( $value );
	}

	/**
	 * rest_request_after_callbacks callback: add ISO-8601 sibling timestamps to
	 * responses in the mvs namespaces.
	 *
	 * Runs inside WP_REST_Server::dispatch(), so it covers both real HTTP
	 * requests and internal rest_do_request() calls. A WP_Error (failed request)
	 * passes straight through.
	 *
	 * @param mixed $result  Response (WP_REST_Response) or WP_Error.
	 * @param mixed $handler Matched route handler (unused).
	 * @param mixed $request The request (WP_REST_Request expected).
	 * @return mixed
	 */
	public static function filter_rest_response( $result, $handler, $request ) {
		unset( $handler );

		if ( ! $result instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		$route = (string) $request->get_route();
		$owned = false;
		foreach ( self::NAMESPACES as $ns ) {
			if ( 0 === strpos( $route, $ns ) ) {
				$owned = true;
				break;
			}
		}
		if ( ! $owned ) {
			return $result;
		}

		$data = $result->get_data();
		if ( is_array( $data ) || $data instanceof \stdClass ) {
			$result->set_data( self::add_gmt_fields( $data, self::timestamp_keys() ) );
		}

		return $result;
	}
}
