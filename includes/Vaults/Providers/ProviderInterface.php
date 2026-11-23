<?php
namespace TBSHComplianceAuditLogger\Vaults\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Off-site vault provider.
 *
 * Every method returns array{ ok: bool, message: string, remote_path?: string }.
 * A provider must only report ok after the remote side confirmed success.
 */
interface ProviderInterface {

	/**
	 * Verify credentials by writing and deleting a small probe object.
	 *
	 * @return array
	 */
	public function test();

	/**
	 * Upload a local file.
	 *
	 * @param string $local_path  Local file.
	 * @param string $remote_name Remote file name.
	 * @return array
	 */
	public function upload( $local_path, $remote_name );
}
