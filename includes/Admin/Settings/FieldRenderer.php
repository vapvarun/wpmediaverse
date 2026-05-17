<?php
/**
 * Settings field render callbacks.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless utility class — all render_*_field() methods used as add_settings_field callbacks.
 */
class FieldRenderer {

	/**
	 * Render a number input field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_number_field( array $args ): void {
		$value = get_option( $args['option'], '' );
		printf(
			'<input type="number" name="%s" value="%s" class="regular-text" min="0" />',
			esc_attr( $args['option'] ),
			esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses(
					$args['description'],
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
						'a'      => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				)
			);
		}
	}

	/**
	 * Render a size field with MB suffix and server limit hint.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_size_field( array $args ): void {
		$bytes      = (int) get_option( $args['option'], 104857600 );
		$mb_value   = round( $bytes / MB_IN_BYTES );
		$server_max = wp_max_upload_size();
		$server_mb  = round( $server_max / MB_IN_BYTES );

		printf(
			'<input type="number" name="%s" value="%s" class="small-text" min="1" max="%s" step="1" /> <strong>MB</strong>',
			esc_attr( $args['option'] ),
			esc_attr( $mb_value ),
			esc_attr( $server_mb )
		);
		printf(
			'<p class="description">%s</p>',
			sprintf(
				/* translators: %s: server upload limit in MB */
				esc_html__( 'Maximum file size per upload. Server limit: %s MB.', 'wpmediaverse' ),
				esc_html( $server_mb )
			)
		);
	}

	/**
	 * Render the file types checkbox grid.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_file_types_field( array $args ): void {
		// Pass null so WordPress returns the registered default when the option
		// is absent on fresh installs. Previous empty-string fallback short-circuited
		// register_setting()'s default, leaving every checkbox unchecked.
		$current = get_option( $args['option'], null );
		if ( null === $current || '' === $current ) {
			$current = SettingsRegistrar::DEFAULT_ALLOWED_FILE_TYPES;
		}
		$selected = array_map( 'trim', explode( ',', (string) $current ) );

		$groups = array(
			__( 'Images', 'wpmediaverse' )    => array(
				'image/jpeg' => 'JPEG',
				'image/png'  => 'PNG',
				'image/gif'  => 'GIF',
				'image/webp' => 'WebP',
			),
			__( 'Video', 'wpmediaverse' )     => array(
				'video/mp4'  => 'MP4',
				'video/webm' => 'WebM',
			),
			__( 'Audio', 'wpmediaverse' )     => array(
				'audio/mpeg' => 'MP3',
				'audio/ogg'  => 'OGG',
			),
			// Documents group (PDF) removed from the picker in 1.2.3 — see
			// SettingsRegistrar::DEFAULT_ALLOWED_FILE_TYPES note. Sites that
			// previously stored application/pdf still see it in the
			// "Custom types" row below; the option is still respected end-to-end.
		);

		$known_mimes = array();
		foreach ( $groups as $mime_map ) {
			$known_mimes = array_merge( $known_mimes, array_keys( $mime_map ) );
		}
		$custom_types = array_diff( $selected, $known_mimes, array( '' ) );

		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description mvs-desc-mb">%s</p>',
				wp_kses(
					$args['description'],
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
						'a'      => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				)
			);
		}
		echo '<div class="mvs-file-types-grid">';
		foreach ( $groups as $group_label => $mimes ) {
			printf( '<div class="mvs-file-types-group"><strong>%s</strong>', esc_html( $group_label ) );
			foreach ( $mimes as $mime => $label ) {
				$checked = in_array( $mime, $selected, true ) ? ' checked' : '';
				printf(
					'<label><input type="checkbox" name="%s[]" value="%s"%s /> %s</label>',
					esc_attr( $args['option'] ),
					esc_attr( $mime ),
					esc_attr( $checked ),
					esc_html( $label )
				);
			}
			echo '</div>';
		}
		echo '</div>';

		printf(
			'<details class="mvs-custom-types"><summary>%s</summary>',
			esc_html__( 'Custom MIME types', 'wpmediaverse' )
		);
		printf(
			'<textarea name="%s_custom" rows="2" class="large-text" placeholder="%s">%s</textarea>',
			esc_attr( $args['option'] ),
			esc_attr__( 'e.g. image/svg+xml,video/quicktime', 'wpmediaverse' ),
			esc_textarea( implode( ',', $custom_types ) )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Additional comma-separated MIME types for advanced users.', 'wpmediaverse' )
		);
		echo '</details>';
	}

	/**
	 * Render a textarea field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_textarea_field( array $args ): void {
		$value = get_option( $args['option'], '' );
		printf(
			'<textarea name="%s" rows="3" class="large-text">%s</textarea>',
			esc_attr( $args['option'] ),
			esc_textarea( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses(
					$args['description'],
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
						'a'      => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				)
			);
		}
	}

	/**
	 * Render a select field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_select_field( array $args ): void {
		$value   = get_option( $args['option'], '' );
		$choices = $args['choices'] ?? array();

		printf( '<select name="%s">', esc_attr( $args['option'] ) );
		foreach ( $choices as $key => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses(
					$args['description'],
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
						'a'      => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				)
			);
		}
	}

	/**
	 * Render a password input field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_password_field( array $args ): void {
		$value   = get_option( $args['option'], '' );
		$display = '';
		if ( $value ) {
			$display = str_repeat( '*', max( 0, strlen( $value ) - 4 ) ) . substr( $value, -4 );
		}

		printf(
			'<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off" placeholder="%s" />',
			esc_attr( $args['option'] ),
			'',
			esc_attr( $display ? sprintf( 'Current: %s', $display ) : '' )
		);
		if ( $value ) {
			echo '<p class="description">' . esc_html__( 'Leave empty to keep the current key.', 'wpmediaverse' ) . '</p>';
		}
		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses(
					$args['description'],
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
						'a'      => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				)
			);
		}
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_checkbox_field( array $args ): void {
		// Honor the `default` from `register_setting` instead of hardcoding
		// false. Without this, settings declared as `'default' => true`
		// (e.g. `mvs_optimize_originals`, `mvs_generate_webp`) appeared
		// unchecked on first visit; the first admin Save would then persist
		// them as false and silently disable the feature. 1.3.0 fix.
		$registered = get_registered_settings();
		$default    = isset( $registered[ $args['option'] ]['default'] )
			? $registered[ $args['option'] ]['default']
			: false;
		$value      = get_option( $args['option'], $default );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( $args['option'] ),
			checked( $value, true, false ),
			esc_html( $args['label'] ?? '' )
		);
		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses(
					$args['description'],
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
						'a'      => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				)
			);
		}
	}

	/**
	 * Render a text input field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_text_field( array $args ): void {
		$value = get_option( $args['option'], '' );
		printf(
			'<input type="text" name="%s" value="%s" class="regular-text" />',
			esc_attr( $args['option'] ),
			esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses(
					$args['description'],
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
						'a'      => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				)
			);
		}
	}

	/**
	 * Render a color picker field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_color_field( array $args ): void {
		$value = get_option( $args['option'], '#ffffff' );
		printf(
			'<input type="color" name="%s" value="%s" />',
			esc_attr( $args['option'] ),
			esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses(
					$args['description'],
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
						'a'      => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				)
			);
		}
	}

	/**
	 * Render a page dropdown field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_page_dropdown_field( array $args ): void {
		$selected = (int) get_option( $args['option'], 0 );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages escapes internally.
		wp_dropdown_pages(
			array(
				'name'              => $args['option'],
				'selected'          => $selected,
				'show_option_none'  => __( 'Select a page', 'wpmediaverse' ),
				'option_none_value' => 0,
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $selected > 0 && 'publish' === get_post_status( $selected ) ) {
			printf(
				' <a href="%s" target="_blank" class="mvs-btn mvs-btn--sm mvs-status-inline"><i data-lucide="external-link" class="mvs-icon--sm"></i> %s</a>',
				esc_url( get_permalink( $selected ) ),
				esc_html__( 'View Page', 'wpmediaverse' )
			);
		}
	}

	/**
	 * Render the webhook configuration field.
	 */
	public static function render_webhook_field(): void {
		$webhooks = get_option( 'mvs_webhooks', array() );
		$webhook  = ! empty( $webhooks[0] ) ? $webhooks[0] : array(
			'url'    => '',
			'secret' => '',
			'events' => array( '*' ),
		);

		$all_events = \WPMediaVerse\Integrations\WebhookService::EVENTS;
		?>
		<fieldset>
			<p>
				<label><?php esc_html_e( 'URL:', 'wpmediaverse' ); ?></label><br />
				<input type="url" name="mvs_webhooks[0][url]" class="regular-text"
					value="<?php echo esc_attr( $webhook['url'] ?? '' ); ?>"
					placeholder="https://example.com/webhook"
				/>
			</p>
			<p>
				<label><?php esc_html_e( 'Secret:', 'wpmediaverse' ); ?></label><br />
				<?php
				$wh_secret  = $webhook['secret'] ?? '';
				$wh_display = $wh_secret ? str_repeat( '*', max( 0, strlen( $wh_secret ) - 4 ) ) . substr( $wh_secret, -4 ) : '';
				?>
				<input type="password" name="mvs_webhooks[0][secret]" class="regular-text" autocomplete="off"
					value=""
					placeholder="<?php echo esc_attr( $wh_display ? sprintf( 'Current: %s', $wh_display ) : esc_attr__( 'Shared secret for HMAC signing', 'wpmediaverse' ) ); ?>"
				/>
				<?php if ( $wh_secret ) : ?>
					<span class="description"><?php esc_html_e( 'Leave empty to keep the current secret.', 'wpmediaverse' ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<label><?php esc_html_e( 'Events:', 'wpmediaverse' ); ?></label><br />
				<?php $selected_events = $webhook['events'] ?? array( '*' ); ?>
				<label>
					<input type="checkbox" name="mvs_webhooks[0][events][]" value="*"
						<?php checked( in_array( '*', $selected_events, true ) ); ?>
					/> <?php esc_html_e( 'All events', 'wpmediaverse' ); ?>
				</label><br />
				<?php foreach ( $all_events as $event ) : ?>
					<label>
						<input type="checkbox" name="mvs_webhooks[0][events][]" value="<?php echo esc_attr( $event ); ?>"
							<?php checked( in_array( $event, $selected_events, true ) ); ?>
						/> <code><?php echo esc_html( $event ); ?></code>
					</label><br />
				<?php endforeach; ?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Render a select field with Pro-locked options shown as disabled.
	 *
	 * @param array $args Field arguments: 'current' (active label), 'pro' (locked labels).
	 */
	public static function render_pro_select_field( array $args ): void {
		$current_label = $args['current'] ?? '';
		$pro_options   = $args['pro'] ?? array();

		echo '<select disabled>';
		printf( '<option selected>%s</option>', esc_html( $current_label ) );
		foreach ( $pro_options as $label ) {
			printf( '<option disabled>%s</option>', esc_html( $label ) );
		}
		echo '</select>';
		echo '<span class="mvs-pro-badge">' . esc_html__( 'Pro', 'wpmediaverse' ) . '</span>';
	}

	/**
	 * Render a disabled Pro-locked checkbox field.
	 *
	 * @param array $args Field arguments: 'label'.
	 */
	public static function render_pro_checkbox_field( array $args ): void {
		printf(
			'<div class="mvs-pro-field"><label><input type="checkbox" disabled /> %s</label></div>',
			esc_html( $args['label'] ?? '' )
		);
		echo '<span class="mvs-pro-badge">' . esc_html__( 'Pro', 'wpmediaverse' ) . '</span>';
	}
}
