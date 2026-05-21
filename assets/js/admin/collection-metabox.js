/**
 * Collection metabox: toggles the smart-rules builder vs manual hint by
 * collection type, and adds/removes/repurposes rule rows. Replaces the former
 * inline <script>. The starting rule index comes from window.mvsCollectionMetabox
 * (wp_localize_script).
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var ruleIndex = parseInt( ( window.mvsCollectionMetabox || {} ).ruleCount, 10 ) || 0;
	var typeRadios = document.querySelectorAll( 'input[name="mvs_collection_type"]' );
	var rulesWrap = document.getElementById( 'mvs-rules-wrap' );
	var manualHint = document.getElementById( 'mvs-manual-hint' );
	var rulesList = document.getElementById( 'mvs-rules-list' );
	var addBtn = document.getElementById( 'mvs-add-rule' );
	var templateWrap = document.getElementById( 'mvs-rule-template-wrap' );
	if ( ! rulesList || ! addBtn || ! templateWrap ) {
		return;
	}

	typeRadios.forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			if ( this.value === 'smart' ) {
				rulesWrap.style.display = '';
				manualHint.style.display = 'none';
			} else {
				rulesWrap.style.display = 'none';
				manualHint.style.display = '';
			}
		} );
	} );

	addBtn.addEventListener( 'click', function () {
		var templateRow = templateWrap.querySelector( '.mvs-metabox-rule-row' );
		var clone = templateRow.cloneNode( true );
		clone.querySelectorAll( '[name]' ).forEach( function ( el ) {
			el.name = el.name.replace( /__INDEX__/g, ruleIndex );
		} );
		rulesList.appendChild( clone );
		ruleIndex++;
		bindRuleRow( clone );
	} );

	rulesList.querySelectorAll( '.mvs-metabox-rule-row' ).forEach( bindRuleRow );

	function bindRuleRow( row ) {
		var keySelect = row.querySelector( '.mvs-metabox-rule-key' );
		var removeBtn = row.querySelector( '.mvs-metabox-remove-rule' );

		if ( keySelect ) {
			keySelect.addEventListener( 'change', function () {
				updateValueField( row, this.value );
			} );
			updateValueField( row, keySelect.value );
		}

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				row.remove();
			} );
		}
	}

	function updateValueField( row, key ) {
		var allValueFields = row.querySelectorAll( '.mvs-metabox-rule-value, .mvs-metabox-rule-value-date, .mvs-metabox-rule-value-number' );
		allValueFields.forEach( function ( el ) {
			el.style.display = 'none';
			el.disabled = true;
		} );

		var target = row.querySelector( '[data-for-key="' + key + '"]' );
		if ( target ) {
			target.style.display = '';
			target.disabled = false;
		} else if ( key === 'date_after' || key === 'date_before' ) {
			var dateInput = row.querySelector( '.mvs-metabox-rule-value-date' );
			if ( dateInput ) {
				dateInput.style.display = '';
				dateInput.disabled = false;
			}
		} else if ( key === 'author' ) {
			var numInput = row.querySelector( '.mvs-metabox-rule-value-number' );
			if ( numInput ) {
				numInput.style.display = '';
				numInput.disabled = false;
			}
		}
	}
} )();
