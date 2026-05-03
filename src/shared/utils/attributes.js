/**
 * Standard attribute schemas for WPMediaVerse Pro blocks.
 * Import and spread into block attributes for consistency.
 */

export const uniqueIdAttribute = {
	uniqueId: { type: 'string', default: '' },
};

export const spacingAttributes = {
	padding: {
		type: 'object',
		default: { top: 24, right: 24, bottom: 24, left: 24 },
	},
	paddingTablet: { type: 'object', default: undefined },
	paddingMobile: { type: 'object', default: undefined },
	paddingUnit: { type: 'string', default: 'px' },
	margin: {
		type: 'object',
		default: { top: 0, right: 0, bottom: 0, left: 0 },
	},
	marginTablet: { type: 'object', default: undefined },
	marginMobile: { type: 'object', default: undefined },
	marginUnit: { type: 'string', default: 'px' },
};

export const typographyAttributes = {
	fontFamily: { type: 'string', default: '' },
	fontSize: { type: 'number', default: undefined },
	fontSizeTablet: { type: 'number', default: undefined },
	fontSizeMobile: { type: 'number', default: undefined },
	fontSizeUnit: { type: 'string', default: 'px' },
	fontWeight: { type: 'string', default: '' },
	lineHeight: { type: 'number', default: undefined },
	lineHeightUnit: { type: 'string', default: '' },
	letterSpacing: { type: 'number', default: undefined },
	textTransform: { type: 'string', default: '' },
};

export const shadowAttributes = {
	boxShadow: { type: 'boolean', default: false },
	shadowHorizontal: { type: 'number', default: 0 },
	shadowVertical: { type: 'number', default: 4 },
	shadowBlur: { type: 'number', default: 8 },
	shadowSpread: { type: 'number', default: 0 },
	shadowColor: { type: 'string', default: 'rgba(0, 0, 0, 0.12)' },
};

export const borderAttributes = {
	borderRadius: {
		type: 'object',
		default: { top: 0, right: 0, bottom: 0, left: 0 },
	},
	borderRadiusUnit: { type: 'string', default: 'px' },
};

export const visibilityAttributes = {
	hideOnDesktop: { type: 'boolean', default: false },
	hideOnTablet: { type: 'boolean', default: false },
	hideOnMobile: { type: 'boolean', default: false },
};

/**
 * Get all standard attributes combined.
 * Usage: { ...getStandardAttributes() } in block.json attributes.
 */
export function getStandardAttributes() {
	return {
		...uniqueIdAttribute,
		...spacingAttributes,
		...shadowAttributes,
		...borderAttributes,
		...visibilityAttributes,
	};
}
