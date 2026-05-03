import { useEffect } from '@wordpress/element';

/**
 * Auto-generate a unique ID for a block instance on first insert.
 * Stores the ID in the block's uniqueId attribute.
 *
 * @param {string}   clientId      - Block client ID from useBlockProps.
 * @param {string}   uniqueId      - Current uniqueId attribute value.
 * @param {Function} setAttributes - Block setAttributes function.
 */
export function useUniqueId( clientId, uniqueId, setAttributes ) {
	useEffect( () => {
		if ( ! uniqueId ) {
			// Generate a short, stable ID from the clientId
			const id = clientId.substring( 0, 8 );
			setAttributes( { uniqueId: id } );
		}
	}, [ clientId, uniqueId, setAttributes ] );
}
