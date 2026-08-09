/**
 * Bulk selection for the status transition matrix.
 *
 * The matrix is five statuses squared minus the diagonal — twenty ordered
 * pairs — multiplied by every eligible post type. Ticking that by hand is the
 * only reason this file exists.
 *
 * The bulk toggles are rendered by PHP with no `name` attribute and a `hidden`
 * wrapper. They therefore submit nothing and stay invisible when this script
 * does not run, so the matrix behaves exactly as it did before with JavaScript
 * off. Nothing here changes what a saved pair means: `sanitize_status_transitions()`
 * still normalizes the submitted matrix server-side, and a pair alone never
 * grants publication.
 *
 * @package IsuDev\WPContentBridge
 */

( function () {
	'use strict';

	/*
	 * Confirming the preset is independent of the matrix wiring below: the
	 * button lives in its own form outside the table, and each half must
	 * still work if the other finds nothing to bind to.
	 */
	Array.prototype.forEach.call(
		document.querySelectorAll( '[data-wpcb-confirm]' ),
		function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( button.getAttribute( 'data-wpcb-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		}
	);

	var root = document.getElementById( 'wpcb-status-transitions' );

	if ( ! root ) {
		return;
	}

	var cells = Array.prototype.slice.call(
		root.querySelectorAll( 'input[type="checkbox"][data-wpcb-row]' )
	);
	var toggles = Array.prototype.slice.call(
		root.querySelectorAll( 'input[type="checkbox"][data-wpcb-scope]' )
	);

	if ( 0 === cells.length || 0 === toggles.length ) {
		return;
	}

	/**
	 * Resolves the cells one toggle governs.
	 *
	 * @param {HTMLInputElement} toggle Bulk toggle.
	 * @return {HTMLInputElement[]} Governed cell checkboxes.
	 */
	function members( toggle ) {
		var scope = toggle.getAttribute( 'data-wpcb-scope' );
		var key = toggle.getAttribute( 'data-wpcb-key' );

		return cells.filter( function ( cell ) {
			if ( 'all' === scope ) {
				return true;
			}

			if ( 'row' === scope ) {
				return cell.getAttribute( 'data-wpcb-row' ) === key;
			}

			return cell.getAttribute( 'data-wpcb-col' ) === key;
		} );
	}

	/**
	 * Repaints every toggle from the cells it governs, so a partially selected
	 * row or column reads as indeterminate rather than falsely unchecked.
	 *
	 * @return {void}
	 */
	function refresh() {
		toggles.forEach( function ( toggle ) {
			var group = members( toggle );
			var selected = group.filter( function ( cell ) {
				return cell.checked;
			} ).length;

			toggle.checked = 0 < group.length && selected === group.length;
			toggle.indeterminate = 0 < selected && selected < group.length;
		} );
	}

	toggles.forEach( function ( toggle ) {
		var wrapper = toggle.closest( '.wpcb-bulk-toggle' );

		if ( wrapper ) {
			wrapper.hidden = false;
		}

		toggle.addEventListener( 'change', function () {
			var next = toggle.checked;

			members( toggle ).forEach( function ( cell ) {
				cell.checked = next;
			} );

			refresh();
		} );
	} );

	cells.forEach( function ( cell ) {
		cell.addEventListener( 'change', refresh );
	} );

	refresh();
}() );
