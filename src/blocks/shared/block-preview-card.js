/**
 * Editor preview card for Pro blocks.
 *
 * Replaces `<ServerSideRender>` in the editor for blocks whose runtime
 * markup depends on the Interactivity API store (which doesn't run in
 * the editor's static SSR iframe — every conditional state would
 * render stacked). This card gives content-creators a clean
 * representation of what the block IS, with configuration status, so
 * they can place + configure it confidently.
 *
 * The frontend path (render.php → renderer) is unchanged: visitors
 * still see the live, store-driven UI.
 *
 * Usage:
 *
 *   <BlockPreviewCard
 *     icon="awards"
 *     title={ __( 'Tournament', 'wpmediaverse' ) }
 *     description={ __( 'Embed a single tournament bracket.', '...' ) }
 *     status={ tournamentId > 0
 *         ? sprintf( __( 'Tournament #%d', '...' ), tournamentId )
 *         : __( 'Pick a tournament in the sidebar →', '...' ) }
 *     statusType={ tournamentId > 0 ? 'configured' : 'unconfigured' }
 *   />
 */

import { Icon } from '@wordpress/components';

export default function BlockPreviewCard( {
	icon,
	title,
	description,
	status,
	statusType = 'configured',
} ) {
	return (
		<div className="mvs-block-preview-card">
			<div className="mvs-block-preview-card__icon">
				<Icon icon={ icon } size={ 36 } />
			</div>
			<div className="mvs-block-preview-card__body">
				<h3 className="mvs-block-preview-card__title">{ title }</h3>
				{ description && (
					<p className="mvs-block-preview-card__desc">{ description }</p>
				) }
				{ status && (
					<p
						className={ `mvs-block-preview-card__status mvs-block-preview-card__status--${ statusType }` }
					>
						{ status }
					</p>
				) }
			</div>
		</div>
	);
}
