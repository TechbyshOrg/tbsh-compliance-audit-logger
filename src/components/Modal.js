import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Modal dialog.
 *
 * @param {Object}   props
 * @param {string}   props.title    Title.
 * @param {boolean}  props.isOpen   Visibility.
 * @param {Function} props.onClose  Close handler (Escape, overlay click, close button).
 * @param {string}   props.size     '' (default 600px) or 'lg' (wide).
 * @param {*}        props.children Content.
 */
export default function Modal( { title, isOpen, onClose, size = '', children } ) {
	// Close on Escape key press.
	useEffect( () => {
		const handleEscape = ( e ) => {
			if ( e.key === 'Escape' ) {
				onClose();
			}
		};
		if ( isOpen ) {
			window.addEventListener( 'keydown', handleEscape );
		}
		return () => {
			window.removeEventListener( 'keydown', handleEscape );
		};
	}, [ isOpen, onClose ] );

	if ( ! isOpen ) {
		return null;
	}

	return (
		<div className="tbsh-modal-overlay" onClick={ onClose }>
			<div
				className={ `tbsh-modal ${ size === 'lg' ? 'tbsh-modal-lg' : '' }` }
				role="dialog"
				aria-modal="true"
				aria-label={ title }
				onClick={ ( e ) => e.stopPropagation() }
			>
				<div className="tbsh-modal-header">
					<h4 className="tbsh-modal-title">{ title }</h4>
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
