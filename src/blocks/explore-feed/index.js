import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import metadata from './block.json';
import './style.css';

registerBlockType( metadata.name, {
	edit: Edit,
} );
