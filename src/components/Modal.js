import { useEffect } from '@wordpress/element';

export default function Modal({ title, isOpen, onClose, children }) {
	// Close on Escape key press.
	useEffect(() => {
		const handleEscape = (e) => {
			if (e.key === 'Escape') {
				onClose();
			}
		};
		if (isOpen) {
			window.addEventListener('keydown', handleEscape);
		}
		return () => {
			window.removeEventListener('keydown', handleEscape);
		};
	}, [isOpen, onClose]);

	if ( ! isOpen ) {
		return null;
	}

	return (
		<div className="tbsh-modal-overlay" onClick={onClose}>
			<div className="tbsh-modal" onClick={(e) => e.stopPropagation()}>
				<div className="tbsh-modal-header">
					<h4 className="tbsh-modal-title">{title}</h4>
					<button className="tbsh-modal-close" onClick={onClose} aria-label="Close modal">&times;</button>
				</div>
				<div className="tbsh-modal-body">
					{children}
				</div>
			</div>
		</div>
	);
}
