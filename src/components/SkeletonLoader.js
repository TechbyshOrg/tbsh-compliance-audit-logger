import { __ } from '@wordpress/i18n';

/**
 * Placeholder shown while content loads; announced once as "Loading…".
 *
 * @param {Object} props
 * @param {number} props.rows Rows.
 * @param {number} props.cols Columns.
 */
export default function SkeletonLoader( { rows = 5, cols = 4 } ) {
	return (
		<div style={ { padding: '20px', width: '100%' } } role="status" aria-busy="true">
			<span className="tbsh-sr-only">{ __( 'Loading…', 'tbsh-compliance-audit-logger' ) }</span>
			{ Array.from( { length: rows } ).map( ( row, rIdx ) => (
				<div key={ rIdx } style={ { display: 'flex', gap: '16px', marginBottom: '16px' } } aria-hidden="true">
					{ Array.from( { length: cols } ).map( ( cell, cIdx ) => (
						<div
							key={ cIdx }
							className="tbsh-skeleton"
							style={ {
								height: '24px',
								flex: 1,
								borderRadius: '4px',
							} }
						/>
					) ) }
				</div>
			) ) }
		</div>
	);
}
