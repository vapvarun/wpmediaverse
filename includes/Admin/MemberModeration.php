<?php
/**
 * Suspend a member from the WordPress admin.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The trigger for the suspension gate.
 *
 * RestGuards::deny_if_suspended() enforces `mvs_suspended` on every write, which
 * is the only thing that can stop a member holding a valid Application Password
 * (core skips the `authenticate` filter chain for those, so a login gate cannot
 * touch them). But enforcement without a trigger is a half-feature: before this,
 * nothing in the admin could set the flag, so a moderator could read a report,
 * resolve it, and still have no way to act on the person who caused it.
 *
 * Two surfaces, because moderators work in two places:
 *  - the Users list (see who is suspended, suspend/restore in one click)
 *  - the user's own profile screen (with the context to make the call)
 *
 * Suspension is reversible and deliberately not a ban: the member keeps their
 * account and their content, and can still read. They just cannot post, comment,
 * react, or message while suspended.
 *
 * @since 2.1.0
 */
class MemberModeration {

	/**
	 * Query arg used by the row action.
	 */
	const ACTION = 'mvs_suspend';

	/**
	 * Hook the admin surfaces.
	 */
	public function __construct() {
		add_action( 'show_user_profile', array( $this, 'render_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_field' ) );

		add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
		add_filter( 'user_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'handle_row_action' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Whether the current user may suspend, and whether this target may be suspended.
	 *
	 * An administrator must never be suspendable from this screen — a moderator
	 * could otherwise lock the site owner out of their own community, and a
	 * suspended admin cannot un-suspend themselves.
	 *
	 * @param int $user_id Target member.
	 * @return bool
	 */
	private function can_suspend( int $user_id ): bool {
		if ( ! current_user_can( 'edit_users' ) ) {
			return false;
		}

		if ( get_current_user_id() === $user_id ) {
			return false;
		}

		return ! user_can( $user_id, 'manage_options' );
	}

	/**
	 * Whether a member is suspended.
	 *
	 * @param int $user_id Member.
	 * @return bool
	 */
	private function is_suspended( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, 'mvs_suspended', true );
	}

	/**
	 * Render the suspend checkbox on the profile screen.
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public function render_field( $user ): void {
		if ( ! $this->can_suspend( (int) $user->ID ) ) {
			return;
		}

		$suspended = $this->is_suspended( (int) $user->ID );
		?>
		<h2><?php esc_html_e( 'MediaVerse Moderation', 'wpmediaverse' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Suspend member', 'wpmediaverse' ); ?></th>
				<td>
					<?php wp_nonce_field( 'mvs_suspend_user_' . (int) $user->ID, 'mvs_suspend_nonce' ); ?>
					<label>
						<input type="checkbox" name="mvs_suspended" value="1" <?php checked( $suspended ); ?> />
						<?php esc_html_e( 'Suspended', 'wpmediaverse' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'A suspended member cannot upload, comment, react, or send messages, on the website or in the app. They keep their account and content, and can still browse. This is reversible.', 'wpmediaverse' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the suspend checkbox.
	 *
	 * @param int $user_id Member being saved.
	 */
	public function save_field( $user_id ): void {
		$user_id = (int) $user_id;

		if ( ! $this->can_suspend( $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['mvs_suspend_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['mvs_suspend_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'mvs_suspend_user_' . $user_id ) ) {
			return;
		}

		$this->set_suspended( $user_id, isset( $_POST['mvs_suspended'] ) );
	}

	/**
	 * Flip the flag and announce it.
	 *
	 * @param int  $user_id   Member.
	 * @param bool $suspended Whether to suspend.
	 */
	private function set_suspended( int $user_id, bool $suspended ): void {
		if ( $suspended ) {
			update_user_meta( $user_id, 'mvs_suspended', 1 );
		} else {
			delete_user_meta( $user_id, 'mvs_suspended' );
		}

		/**
		 * Fires when a member's suspension state changes.
		 *
		 * @since 2.1.0
		 *
		 * @param int  $user_id   Member.
		 * @param bool $suspended Whether they are now suspended.
		 */
		do_action( 'mvs_member_suspension_changed', $user_id, $suspended );
	}

	/**
	 * Add a Status column to the Users list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ): array {
		$columns = (array) $columns;

		$columns['mvs_status'] = __( 'MediaVerse', 'wpmediaverse' );

		return $columns;
	}

	/**
	 * Render the Status column.
	 *
	 * @param string $output      Existing output.
	 * @param string $column_name Column key.
	 * @param int    $user_id     Member.
	 * @return string
	 */
	public function render_column( $output, $column_name, $user_id ): string {
		if ( 'mvs_status' !== $column_name ) {
			return (string) $output;
		}

		// This renders inside WordPress's core Users list table, where the
		// plugin's admin stylesheet (and its design tokens) is not enqueued, so we
		// lean on core-native semantics instead of inline colour: plain text for
		// Active, bold for the state that matters. The red destructive cue lives on
		// the row action below via core's own `submitdelete` class.
		if ( ! $this->is_suspended( (int) $user_id ) ) {
			return esc_html__( 'Active', 'wpmediaverse' );
		}

		return '<strong>' . esc_html__( 'Suspended', 'wpmediaverse' ) . '</strong>';
	}

	/**
	 * Add a suspend/restore row action.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_User $user    The row's user.
	 * @return array
	 */
	public function add_row_action( $actions, $user ): array {
		$actions = (array) $actions;
		$user_id = (int) $user->ID;

		if ( ! $this->can_suspend( $user_id ) ) {
			return $actions;
		}

		$suspended = $this->is_suspended( $user_id );

		$url = wp_nonce_url(
			add_query_arg(
				array(
					self::ACTION => $suspended ? 'restore' : 'suspend',
					'user_id'    => $user_id,
				),
				admin_url( 'users.php' )
			),
			'mvs_suspend_user_' . $user_id
		);

		// Suspend is destructive → core's `submitdelete` class (renders red, the
		// WP convention for a row's destructive action). Restore is a plain link
		// (default admin-link blue). No inline colour, no hardcoded hex.
		$actions['mvs_suspend'] = sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( $url ),
			$suspended ? '' : ' class="submitdelete"',
			$suspended ? esc_html__( 'Restore', 'wpmediaverse' ) : esc_html__( 'Suspend', 'wpmediaverse' )
		);

		return $actions;
	}

	/**
	 * Handle the suspend/restore row action.
	 */
	public function handle_row_action(): void {
		if ( ! isset( $_GET[ self::ACTION ], $_GET['user_id'] ) ) {
			return;
		}

		$user_id = absint( $_GET['user_id'] );

		check_admin_referer( 'mvs_suspend_user_' . $user_id );

		if ( ! $this->can_suspend( $user_id ) ) {
			wp_die( esc_html__( 'You are not allowed to suspend this member.', 'wpmediaverse' ), 403 );
		}

		$suspend = 'suspend' === sanitize_key( wp_unslash( $_GET[ self::ACTION ] ) );

		$this->set_suspended( $user_id, $suspend );

		wp_safe_redirect(
			add_query_arg(
				'mvs_suspended_notice',
				$suspend ? 'suspended' : 'restored',
				admin_url( 'users.php' )
			)
		);
		exit;
	}

	/**
	 * Confirm the action to the moderator.
	 */
	public function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only, no state change.
		if ( ! isset( $_GET['mvs_suspended_notice'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only, no state change.
		$notice = sanitize_key( wp_unslash( $_GET['mvs_suspended_notice'] ) );

		$message = 'suspended' === $notice
			? __( 'Member suspended. They can still browse, but cannot post, comment, react, or message — on the website or in the app.', 'wpmediaverse' )
			: __( 'Member restored. They can post again.', 'wpmediaverse' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
