export default function SkeletonLoader({ rows = 5, cols = 4 }) {
	return (
		<div style={{ padding: '20px', width: '100%' }}>
			{Array.from({ length: rows }).map((_, rIdx) => (
				<div key={rIdx} style={{ display: 'flex', gap: '16px', marginBottom: '16px' }}>
					{Array.from({ length: cols }).map((_, cIdx) => (
						<div 
							key={cIdx} 
							className="tbsh-skeleton" 
							style={{ 
								height: '24px', 
								flex: 1,
								borderRadius: '4px'
							}} 
						/>
					))}
				</div>
			))}
		</div>
	);
}
