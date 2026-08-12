<?php
/**
 * The one toolbar every list surface uses.
 *
 * Explore Media, Explore Documents and the document drive each grew their own
 * row of controls: Explore Media had a search box and nothing else, Explore
 * Documents had search and type chips, the drive had search, type, sort,
 * direction and an Apply button. Same job, three answers, three visual
 * languages — and the count, which every one of them already computed, was
 * displayed on exactly one.
 *
 * ORDER IS FIXED, left to right: filters (supplied by the caller, before this
 * partial), then sort field, then direction, then the count end-aligned. Same
 * order everywhere so the eye learns it once.
 *
 * THE COUNT IS NOT `count( $rows )`. Every caller passes a total from a
 * dedicated `COUNT(*)`, because the line states the size of the SET and the set
 * is bigger than the page. A count that silently means "this page" is worse
 * than no count: it looks authoritative and is wrong from row 26 onward.
 *
 * Applies on change. `Apply` is rendered for callers without JavaScript and
 * hidden by `list-toolbar.js` the moment it runs, so the no-JS path submits a
 * plain form and everybody else never sees a button.
 *
 * Expects:
 *
 * @var string $mvs_toolbar_action  Form action URL.
 * @var array  $mvs_toolbar_hidden  name => value pairs to carry through the form.
 * @var array  $mvs_toolbar_sorts   value => label. Empty to omit the sort control.
 * @var string $mvs_toolbar_sort    Current sort value.
 * @var string $mvs_toolbar_order   Current direction, ASC or DESC.
 * @var string $mvs_toolbar_count   Rendered count line, already translated. '' to omit.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

defined( 'ABSPATH' ) || exit;

$mvs_toolbar_action = isset( $mvs_toolbar_action ) ? (string) $mvs_toolbar_action : '';
$mvs_toolbar_hidden = isset( $mvs_toolbar_hidden ) && is_array( $mvs_toolbar_hidden ) ? $mvs_toolbar_hidden : array();
$mvs_toolbar_sorts  = isset( $mvs_toolbar_sorts ) && is_array( $mvs_toolbar_sorts ) ? $mvs_toolbar_sorts : array();
$mvs_toolbar_sort   = isset( $mvs_toolbar_sort ) ? (string) $mvs_toolbar_sort : '';
$mvs_toolbar_order  = isset( $mvs_toolbar_order ) && 'ASC' === strtoupper( (string) $mvs_toolbar_order ) ? 'ASC' : 'DESC';
$mvs_toolbar_count  = isset( $mvs_toolbar_count ) ? (string) $mvs_toolbar_count : '';

if ( ! $mvs_toolbar_sorts && '' === $mvs_toolbar_count ) {
	return;
}
?>
<div class="mvs-list-toolbar">
	<?php if ( $mvs_toolbar_sorts ) : ?>
		<form class="mvs-list-toolbar__form" method="get" action="<?php echo esc_url( $mvs_toolbar_action ); ?>">
			<?php foreach ( $mvs_toolbar_hidden as $mvs_toolbar_key => $mvs_toolbar_value ) : ?>
				<?php if ( '' === (string) $mvs_toolbar_value ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<input type="hidden" name="<?php echo esc_attr( $mvs_toolbar_key ); ?>" value="<?php echo esc_attr( (string) $mvs_toolbar_value ); ?>" />
			<?php endforeach; ?>

			<label class="screen-reader-text" for="mvs-list-sort"><?php esc_html_e( 'Sort by', 'wpmediaverse' ); ?></label>
			<select class="mvs-list-toolbar__select" id="mvs-list-sort" name="sort">
				<?php foreach ( $mvs_toolbar_sorts as $mvs_toolbar_value => $mvs_toolbar_label ) : ?>
					<option value="<?php echo esc_attr( (string) $mvs_toolbar_value ); ?>" <?php selected( $mvs_toolbar_sort, (string) $mvs_toolbar_value ); ?>>
						<?php echo esc_html( (string) $mvs_toolbar_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="mvs-list-order"><?php esc_html_e( 'Direction', 'wpmediaverse' ); ?></label>
			<select class="mvs-list-toolbar__select" id="mvs-list-order" name="order">
				<option value="desc" <?php selected( $mvs_toolbar_order, 'DESC' ); ?>><?php esc_html_e( 'Newest first', 'wpmediaverse' ); ?></option>
				<option value="asc" <?php selected( $mvs_toolbar_order, 'ASC' ); ?>><?php esc_html_e( 'Oldest first', 'wpmediaverse' ); ?></option>
			</select>

			<?php
			// For callers without JavaScript. `list-toolbar.js` removes it, so a
			// member with JS applies on change and never sees a button they had
			// to remember to press.
			?>
			<button type="submit" class="mvs-list-toolbar__apply"><?php esc_html_e( 'Apply', 'wpmediaverse' ); ?></button>
		</form>
	<?php endif; ?>

	<?php if ( '' !== $mvs_toolbar_count ) : ?>
		<p class="mvs-list-toolbar__count"><?php echo esc_html( $mvs_toolbar_count ); ?></p>
	<?php endif; ?>
</div>
