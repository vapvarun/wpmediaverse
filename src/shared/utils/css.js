/**
 * Generate scoped CSS for a WPMediaVerse Pro block instance.
 *
 * @param {string} uniqueId  - Unique block instance ID.
 * @param {Object} attrs     - Block attributes.
 * @return {string} CSS string with desktop, tablet, and mobile rules.
 */
export function generateBlockCSS( uniqueId, attrs ) {
	if ( ! uniqueId ) {
		return '';
	}

	const selector = `.mvs-block-${ uniqueId }`;
	const desktop = [];
	const tablet = [];
	const mobile = [];

	// Padding
	if ( attrs.padding ) {
		const u = attrs.paddingUnit || 'px';
		const p = attrs.padding;
		desktop.push(
			`padding: ${ p.top }${ u } ${ p.right }${ u } ${ p.bottom }${ u } ${ p.left }${ u };`
		);
	}
	if ( attrs.paddingTablet ) {
		const u = attrs.paddingUnit || 'px';
		const p = attrs.paddingTablet;
		tablet.push(
			`padding: ${ p.top }${ u } ${ p.right }${ u } ${ p.bottom }${ u } ${ p.left }${ u };`
		);
	}
	if ( attrs.paddingMobile ) {
		const u = attrs.paddingUnit || 'px';
		const p = attrs.paddingMobile;
		mobile.push(
			`padding: ${ p.top }${ u } ${ p.right }${ u } ${ p.bottom }${ u } ${ p.left }${ u };`
		);
	}

	// Margin
	if ( attrs.margin ) {
		const u = attrs.marginUnit || 'px';
		const m = attrs.margin;
		desktop.push(
			`margin: ${ m.top }${ u } ${ m.right }${ u } ${ m.bottom }${ u } ${ m.left }${ u };`
		);
	}
	if ( attrs.marginTablet ) {
		const u = attrs.marginUnit || 'px';
		const m = attrs.marginTablet;
		tablet.push(
			`margin: ${ m.top }${ u } ${ m.right }${ u } ${ m.bottom }${ u } ${ m.left }${ u };`
		);
	}
	if ( attrs.marginMobile ) {
		const u = attrs.marginUnit || 'px';
		const m = attrs.marginMobile;
		mobile.push(
			`margin: ${ m.top }${ u } ${ m.right }${ u } ${ m.bottom }${ u } ${ m.left }${ u };`
		);
	}

	// Border radius
	if ( attrs.borderRadius ) {
		const u = attrs.borderRadiusUnit || 'px';
		const r = attrs.borderRadius;
		desktop.push(
			`border-radius: ${ r.top }${ u } ${ r.right }${ u } ${ r.bottom }${ u } ${ r.left }${ u };`
		);
	}

	// Box shadow
	if ( attrs.boxShadow ) {
		const h = attrs.shadowHorizontal || 0;
		const v = attrs.shadowVertical || 4;
		const b = attrs.shadowBlur || 8;
		const s = attrs.shadowSpread || 0;
		const c = attrs.shadowColor || 'rgba(0,0,0,0.12)';
		desktop.push( `box-shadow: ${ h }px ${ v }px ${ b }px ${ s }px ${ c };` );
	}

	// Font size (responsive)
	if ( attrs.fontSize !== undefined ) {
		const u = attrs.fontSizeUnit || 'px';
		desktop.push( `font-size: ${ attrs.fontSize }${ u };` );
	}
	if ( attrs.fontSizeTablet !== undefined ) {
		const u = attrs.fontSizeUnit || 'px';
		tablet.push( `font-size: ${ attrs.fontSizeTablet }${ u };` );
	}
	if ( attrs.fontSizeMobile !== undefined ) {
		const u = attrs.fontSizeUnit || 'px';
		mobile.push( `font-size: ${ attrs.fontSizeMobile }${ u };` );
	}

	// Font family
	if ( attrs.fontFamily ) {
		desktop.push( `font-family: ${ attrs.fontFamily };` );
	}

	// Font weight
	if ( attrs.fontWeight ) {
		desktop.push( `font-weight: ${ attrs.fontWeight };` );
	}

	// Line height
	if ( attrs.lineHeight !== undefined ) {
		const u = attrs.lineHeightUnit || '';
		desktop.push( `line-height: ${ attrs.lineHeight }${ u };` );
	}

	// Letter spacing
	if ( attrs.letterSpacing !== undefined ) {
		desktop.push( `letter-spacing: ${ attrs.letterSpacing }px;` );
	}

	// Text transform
	if ( attrs.textTransform ) {
		desktop.push( `text-transform: ${ attrs.textTransform };` );
	}

	// Build CSS string
	let css = '';

	if ( desktop.length ) {
		css += `${ selector } {\n  ${ desktop.join( '\n  ' ) }\n}\n`;
	}

	if ( tablet.length ) {
		css += `@media (max-width: 1024px) {\n  ${ selector } {\n    ${ tablet.join(
			'\n    '
		) }\n  }\n}\n`;
	}

	if ( mobile.length ) {
		css += `@media (max-width: 767px) {\n  ${ selector } {\n    ${ mobile.join(
			'\n    '
		) }\n  }\n}\n`;
	}

	return css;
}

/**
 * Render a <style> tag with block CSS into the editor or frontend.
 *
 * @param {string} uniqueId - Block instance ID.
 * @param {Object} attrs    - Block attributes.
 * @return {string} CSS string (for use in style tag).
 */
export function renderBlockStyle( uniqueId, attrs ) {
	return generateBlockCSS( uniqueId, attrs );
}
