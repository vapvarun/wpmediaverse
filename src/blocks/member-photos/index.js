import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';

// Compile the frontend card CSS to build/blocks/member-photos/style-index.css
// (block.json references it). Without this import wp-scripts never emitted the
// file, so the member card shipped completely unstyled.
import './style.css';

registerBlockType( metadata.name, {
	edit: Edit,
} );
