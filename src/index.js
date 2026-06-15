import { createRoot, render } from '@wordpress/element';
import App from './App';

document.addEventListener( 'DOMContentLoaded', () => {
	const rootEl = document.getElementById( 'tbsh-cal-admin-root' );
	if ( rootEl ) {
		if ( createRoot ) {
			createRoot( rootEl ).render( <App /> );
		} else {
			render( <App />, rootEl );
		}
	}
} );
