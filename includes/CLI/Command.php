<?php
namespace TBSHComplianceAuditLogger\CLI;

use TBSHComplianceAuditLogger\Integrity\ChainVerifier;
use TBSHComplianceAuditLogger\Vaults\Packager;
use TBSHComplianceAuditLogger\Vaults\VaultManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compliance Audit Trail & Evidence Logger maintenance commands.
 */
class Command {

	/**
	 * Decrypt an off-site vault package (.tbshvault) to a gzip JSON Lines file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the .tbshvault package.
	 *
	 * [--output=<path>]
	 * : Output path. Defaults to <file>.jsonl.gz
	 *
	 * [--passphrase=<passphrase>]
	 * : Vault passphrase. If omitted, read from the TBSH_CAL_VAULT_PASSPHRASE environment variable or prompted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tbsh-cal vault-decrypt evidence-example-com-20261123-020000.tbshvault
	 *
	 * @subcommand vault-decrypt
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function vault_decrypt( $args, $assoc_args ) {
		$file = $args[0];
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( "Cannot read $file" );
		}
		$out  = $assoc_args['output'] ?? $file . '.jsonl.gz';
		$pass = $assoc_args['passphrase'] ?? getenv( 'TBSH_CAL_VAULT_PASSPHRASE' );
		if ( ! $pass ) {
			$pass = \cli\prompt( 'Passphrase', false, ': ', true );
		}

		$result = Packager::decrypt_file( $file, $out, (string) $pass );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( "Decrypted to $out (gzip-compressed JSON Lines)." );
	}

	/**
	 * Run an off-site vault sync now.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<id>]
	 * : Vault ID. Defaults to every active vault.
	 *
	 * @subcommand vault-sync
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function vault_sync( $args, $assoc_args ) {
		$ids = isset( $assoc_args['id'] ) ? array( (int) $assoc_args['id'] ) : array_column( array_filter( VaultManager::list()['items'], function ( $v ) {
			return $v['is_active'];
		} ), 'id' );
		if ( empty( $ids ) ) {
			\WP_CLI::warning( 'No active vaults.' );
			return;
		}
		foreach ( $ids as $id ) {
			$result = VaultManager::sync( $id );
			if ( is_wp_error( $result ) || empty( $result['ok'] ) ) {
				\WP_CLI::warning( "Vault #$id: " . ( is_wp_error( $result ) ? $result->get_error_message() : $result['message'] ) );
			} else {
				\WP_CLI::success( "Vault #$id: " . $result['message'] );
			}
		}
	}

	/**
	 * Verify the audit-log hash chain.
	 *
	 * @subcommand verify-chain
	 */
	public function verify_chain() {
		$result = ChainVerifier::verify_chain();
		if ( 'verified' === $result['status'] ) {
			\WP_CLI::success( sprintf( 'Chain verified (%d entries checked).', $result['details']['total_checked'] ?? 0 ) );
		} else {
			\WP_CLI::error( sprintf( 'Chain %s: %d issue(s).', $result['status'], $result['issues_found'] ), false );
		}
	}
}
