<?php
namespace TBSHComplianceAuditLogger\Reporting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders report data (meta + sections) as print-ready HTML, CSV or JSON.
 */
class ReportRenderer {

	/**
	 * Render.
	 *
	 * @param string $format html|csv|json.
	 * @param array  $data   Report data.
	 * @return string
	 */
	public static function render( $format, array $data ) {
		switch ( $format ) {
			case 'json':
				return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			case 'csv':
				return self::csv( $data );
			default:
				return self::html( $data );
		}
	}

	/**
	 * CSV: a metadata block, then each section as its own titled table.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	private static function csv( array $data ) {
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$put = function ( array $row ) use ( $fh ) {
			// Neutralise spreadsheet formula injection.
			$row = array_map( function ( $v ) {
				$v = (string) $v;
				return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
			}, $row );
			fputcsv( $fh, $row, ',', '"', '\\' );
		};

		$meta = $data['meta'];
		$put( array( $meta['title'] ) );
		$put( array( __( 'Organisation', 'tbsh-compliance-audit-logger' ), $meta['organisation'] ) );
		$put( array( __( 'Period', 'tbsh-compliance-audit-logger' ), $meta['period'][0] . ' – ' . $meta['period'][1] ) );
		$put( array( __( 'Generated', 'tbsh-compliance-audit-logger' ), $meta['generated_at'] ) );
		$put( array( __( 'Audit log chain head', 'tbsh-compliance-audit-logger' ), $meta['chain_head'] ) );

		foreach ( $data['sections'] as $section ) {
			$put( array() );
			$put( array( $section['title'] ) );
			if ( 'table' === $section['kind'] ) {
				$put( $section['columns'] );
				foreach ( $section['rows'] as $row ) {
					$put( $row );
				}
			} elseif ( 'kv' === $section['kind'] ) {
				foreach ( $section['data'] as $k => $v ) {
					$put( array( $k, is_array( $v ) ? wp_json_encode( $v ) : ( is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v ) ) );
				}
			} else {
				$put( array( $section['text'] ) );
			}
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Self-contained, print-ready HTML document.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	private static function html( array $data ) {
		$meta = $data['meta'];
		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $meta['title'] . ' — ' . $meta['organisation'] ); ?></title>
<style>
	:root { --ink:#0f172a; --muted:#475569; --line:#e2e8f0; --accent:#2563eb; }
	* { box-sizing: border-box; }
	body { font: 13px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: var(--ink); margin: 0; padding: 32px; max-width: 1100px; margin-inline: auto; }
	header { border-bottom: 3px solid var(--accent); padding-bottom: 16px; margin-bottom: 24px; }
	h1 { font-size: 22px; margin: 0 0 4px; }
	h2 { font-size: 15px; margin: 28px 0 8px; padding-bottom: 4px; border-bottom: 1px solid var(--line); }
	.meta { color: var(--muted); display: grid; grid-template-columns: max-content 1fr; gap: 2px 16px; margin-top: 12px; }
	.meta dt { font-weight: 600; }
	.meta dd { margin: 0; word-break: break-all; }
	table { width: 100%; border-collapse: collapse; margin-top: 6px; }
	th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
	th { background: #f8fafc; font-weight: 600; font-size: 12px; }
	td { word-break: break-word; }
	.status-PASS { color: #047857; font-weight: 600; } .status-WARN { color: #b45309; font-weight: 600; } .status-FAIL { color: #b91c1c; font-weight: 600; }
	.empty { color: var(--muted); font-style: italic; }
	.note { margin-top: 32px; padding: 12px 14px; background: #f8fafc; border: 1px solid var(--line); border-radius: 6px; color: var(--muted); font-size: 12px; }
	.print-hint { margin-bottom: 16px; padding: 8px 12px; background: #eff6ff; border-radius: 6px; color: #1e40af; }
	@media print { body { padding: 0; } .print-hint { display: none; } h2 { break-after: avoid; } tr { break-inside: avoid; } }
</style>
</head>
<body>
<p class="print-hint"><?php esc_html_e( 'To save as PDF, use your browser’s Print command and choose “Save as PDF”.', 'tbsh-compliance-audit-logger' ); ?></p>
<header>
	<h1><?php echo esc_html( $meta['title'] ); ?></h1>
	<dl class="meta">
		<dt><?php esc_html_e( 'Organisation', 'tbsh-compliance-audit-logger' ); ?></dt><dd><?php echo esc_html( $meta['organisation'] ); ?></dd>
		<dt><?php esc_html_e( 'Website', 'tbsh-compliance-audit-logger' ); ?></dt><dd><?php echo esc_html( $meta['site'] ); ?></dd>
		<dt><?php esc_html_e( 'Reporting period', 'tbsh-compliance-audit-logger' ); ?></dt><dd><?php echo esc_html( $meta['period'][0] . ' – ' . $meta['period'][1] ); ?></dd>
		<dt><?php esc_html_e( 'Generated', 'tbsh-compliance-audit-logger' ); ?></dt><dd><?php echo esc_html( $meta['generated_at'] . ( $meta['generated_by'] ? ' · ' . $meta['generated_by'] : '' ) ); ?></dd>
	</dl>
</header>
		<?php foreach ( $data['sections'] as $section ) : ?>
	<section>
		<h2><?php echo esc_html( $section['title'] ); ?></h2>
			<?php if ( 'text' === $section['kind'] ) : ?>
		<p><?php echo esc_html( $section['text'] ); ?></p>
			<?php elseif ( 'kv' === $section['kind'] ) : ?>
		<table><tbody>
				<?php foreach ( $section['data'] as $k => $v ) : ?>
			<tr><th style="width:30%"><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $k ) ) ); ?></th><td><?php echo esc_html( is_array( $v ) ? wp_json_encode( $v ) : ( is_bool( $v ) ? ( $v ? __( 'Yes', 'tbsh-compliance-audit-logger' ) : __( 'No', 'tbsh-compliance-audit-logger' ) ) : (string) $v ) ); ?></td></tr>
				<?php endforeach; ?>
		</tbody></table>
			<?php elseif ( empty( $section['rows'] ) ) : ?>
		<p class="empty"><?php esc_html_e( 'No records.', 'tbsh-compliance-audit-logger' ); ?></p>
			<?php else : ?>
		<table>
			<thead><tr><?php foreach ( $section['columns'] as $col ) : ?><th><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $col ) ) ); ?></th><?php endforeach; ?></tr></thead>
			<tbody>
				<?php foreach ( $section['rows'] as $row ) : ?>
				<tr><?php foreach ( $row as $cell ) : ?><td class="status-<?php echo esc_attr( preg_replace( '/[^A-Z]/', '', (string) $cell ) ); ?>"><?php echo esc_html( (string) $cell ); ?></td><?php endforeach; ?></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
			<?php endif; ?>
	</section>
		<?php endforeach; ?>
<p class="note">
		<?php
		printf(
			/* translators: 1: chain head hash, 2: plugin name/version */
			esc_html__( 'Integrity: this file is recorded with a SHA-256 content hash and an HMAC-SHA256 signature computed with a secret key held by the issuing website. Use “Verify” in Compliance Reports to confirm the file is unchanged. At generation time the head of the tamper-evident audit-log chain was %1$s. Generated by %2$s.', 'tbsh-compliance-audit-logger' ),
			'<code>' . esc_html( $meta['chain_head'] ? $meta['chain_head'] : '—' ) . '</code>',
			esc_html( $meta['plugin'] )
		);
		?>
</p>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
