import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

let nextId = 0;

/**
 * Modal dialog.
 *
 * Moves focus into the dialog when it opens, keeps Tab inside it, closes on Escape (only the
 * top-most dialog), and returns focus to the control that opened it.
 *
 * @param {Object}   props
 * @param {string}   props.title    Title.
 * @param {boolean}  props.isOpen   Visibility.
 * @param {Function} props.onClose  Close handler (Escape, overlay click, close button).
 * @param {string}   props.size     '' (default 600px) or 'lg' (wide).
 * @param {*}        props.children Content.
 */
export default function Modal( { title, isOpen, onClose, size = '', children } ) {
	const dialogRef = useRef( null );
	const closeRef = useRef( onClose );
	const titleId = useRef( `tbsh-modal-title-${ ++nextId }` );
	closeRef.current = onClose;

	useEffect( () => {
		if ( ! isOpen ) {
			return undefined;
		}
		const opener = dialogRef.current ? dialogRef.current.ownerDocument.activeElement : null;
		const dialog = dialogRef.current;

		// Focus the first field or button in the body, otherwise the dialog itself.
		const body = dialog && dialog.querySelector( '.tbsh-modal-body' );
		const first = body && body.querySelector( FOCUSABLE );
		if ( first || dialog ) {
			( first || dialog ).focus();
		}

		const onKeyDown = ( e ) => {
			const dialogs = document.querySelectorAll( '.tbsh-modal[role="dialog"]' );
			if ( dialogs[ dialogs.length - 1 ] !== dialog ) {
				return; // A dialog opened above this one handles the key.
			}
			if ( 'Escape' === e.key ) {
				e.stopPropagation();
				closeRef.current();
				return;
			}
			if ( 'Tab' !== e.key ) {
				return;
			}
			const items = Array.from( dialog.querySelectorAll( FOCUSABLE ) ).filter( ( el ) => ( el.checkVisibility ? el.checkVisibility() : ! el.closest( '[hidden]' ) ) );
			if ( ! items.length ) {
				e.preventDefault();
				dialog.focus();
				return;
			}
			const firstItem = items[ 0 ];
			const lastItem = items[ items.length - 1 ];
			const active = dialog.ownerDocument.activeElement;
			if ( e.shiftKey && ( active === firstItem || active === dialog ) ) {
				e.preventDefault();
				lastItem.focus();
			} else if ( ! e.shiftKey && active === lastItem ) {
				e.preventDefault();
				firstItem.focus();
			}
		};

		document.addEventListener( 'keydown', onKeyDown );
		return () => {
			document.removeEventListener( 'keydown', onKeyDown );
			if ( opener && typeof opener.focus === 'function' && opener.isConnected ) {
				opener.focus();
			}
		};
	}, [ isOpen ] );

	if ( ! isOpen ) {
		return null;
	}

	return (
		// The overlay click is a mouse convenience; keyboard users close with Escape or the close button.
		// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
		<div className="tbsh-modal-overlay" onClick={ onClose }>
			{ /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				ref={ dialogRef }
				className={ `tbsh-modal ${ size === 'lg' ? 'tbsh-modal-lg' : '' }` }
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId.current }
				tabIndex="-1"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<div className="tbsh-modal-header">
					<h2 id={ titleId.current } className="tbsh-modal-title">
						{ title }
					</h2>
					<button
						type="button"
						className="tbsh-modal-close"
						onClick={ onClose }
						aria-label={ __( 'Close dialog', 'tbsh-compliance-audit-logger' ) }
					>
						&times;
					</button>
				</div>
				<div className="tbsh-modal-body">{ children }</div>
			</div>
		</div>
	);
}
