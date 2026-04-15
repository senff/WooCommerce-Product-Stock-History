( function ( $ ) {
	'use strict';

	function loadVariationHistory( variationId, label, nonce ) {
		var $select    = $( '#psh-variation-select' );
		var $label     = $( '#psh-variation-label' );
		var $container = $( '#psh-history-container' );

		$label.text( label );
		$container.html( '<p class="psh-loading">' + pshI18n.loading + '</p>' );

		// Reset dropdown to placeholder immediately after selection.
		$select.val( '' );

		$.post( ajaxurl, {
			action: 'psh_variation_history',
			variation_id: variationId,
			nonce: nonce
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$container.html( response.data.html );
			} else {
				$container.html( '<p class="psh-error">' + pshI18n.error + '</p>' );
			}
		} )
		.fail( function () {
			$container.html( '<p class="psh-error">' + pshI18n.error + '</p>' );
		} );
	}

	// Clear-history handler.
	$( document ).on( 'click', '.psh-clear-history', function () {
		var $btn       = $( this );
		var productId  = $btn.data( 'product-id' );
		var label      = $btn.data( 'label' ) || '';
		var confirmMsg = pshI18n.confirmClear.replace( '%s', label );
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		$.post( ajaxurl, {
			action:     'psh_clear_history',
			product_id: productId,
			nonce:      pshI18n.clearHistoryNonce,
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$btn.closest( '.psh-history-wrap' ).replaceWith(
					'<p class="psh-no-history">' + pshI18n.noHistory + '</p>'
				);
			} else {
				$btn.prop( 'disabled', false );
			}
		} )
		.fail( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// Load-more handler (works for both simple and variable product history).
	$( document ).on( 'click', '.psh-load-more', function () {
		var $btn       = $( this );
		var productId  = $btn.data( 'product-id' );
		var offset     = $btn.data( 'offset' );

		$btn.prop( 'disabled', true ).text( pshI18n.loading );

		$.post( ajaxurl, {
			action:     'psh_load_more_history',
			product_id: productId,
			offset:     offset,
			nonce:      pshI18n.loadMoreNonce,
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$btn.closest( '.psh-history-wrap' ).find( '.psh-notes' ).append( response.data.html );
				if ( response.data.has_more ) {
					$btn.data( 'offset', response.data.next_offset )
						.prop( 'disabled', false )
						.text( pshI18n.showMore );
				} else {
					$btn.remove();
				}
			} else {
				$btn.prop( 'disabled', false ).text( pshI18n.showMore );
			}
		} )
		.fail( function () {
			$btn.prop( 'disabled', false ).text( pshI18n.showMore );
		} );
	} );

	$( function () {
		var $select      = $( '#psh-variation-select' );
		if ( ! $select.length ) {
			return;
		}

		var nonce        = $select.data( 'nonce' );
		var $activeOption = null;

		function activateOption( $option ) {
			// Restore the previously active option.
			if ( $activeOption ) {
				$activeOption.prop( 'disabled', false ).text( $activeOption.data( 'original-text' ) );
			}
			// Mark the new option as active.
			$activeOption = $option;
			$activeOption.data( 'original-text', $activeOption.text() );
			$activeOption.prop( 'disabled', true ).text( '\u2022 ' + $activeOption.text() );
		}

		$select.on( 'change', function () {
			var $selected = $( this ).find( ':selected' );
			if ( ! $selected.val() ) {
				return;
			}
			activateOption( $selected );
			loadVariationHistory( $selected.val(), $selected.data( 'original-text' ), nonce );
		} );

		// Auto-load parent stock on page load if parent has stock.
		var parentId   = $select.data( 'parent-id' );
		var parentName = $select.data( 'parent-name' );
		if ( parentId ) {
			// Activate the parent option in the dropdown if it exists there.
			var $parentOption = $select.find( 'option[value="' + parentId + '"]' );
			if ( $parentOption.length ) {
				activateOption( $parentOption );
			}
			$( '#psh-variation-label' ).text( parentName );
			loadVariationHistory( parentId, parentName, nonce );
		}
	} );

} )( jQuery );
