<?php
namespace TBSHComplianceAuditLogger\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads custom SVGs from assets/icons/ for localization.
 */
class SVGHelper {

	/**
	 * Scans the icons directory and returns a registry array of name => SVG markup.
	 *
	 * @return array Icons registry.
	 */
	public static function get_icons() {
		$dir   = TBSH_CAL_PATH . 'assets/icons/';
		$icons = array();

		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '*.svg' );
			foreach ( $files as $file ) {
				$name    = pathinfo( $file, PATHINFO_FILENAME );
				$content = file_get_contents( $file );
				if ( $content ) {
					// Remove <?xml declarations or HTML comments.
					$content = preg_replace( '/<\?xml.*\?>/i', '', $content );
					// Extract inner path XML or clean up wrappers.
					$icons[ $name ] = trim( $content );
				}
			}
		}

		return $icons;
	}
}
