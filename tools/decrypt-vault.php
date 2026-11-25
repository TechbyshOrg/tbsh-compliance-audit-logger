<?php
/**
 * Standalone decryptor for Compliance Audit Log vault packages.
 *
 * Restores a .tbshvault file without WordPress:
 *
 *     php decrypt-vault.php <package.tbshvault> [output.jsonl.gz]
 *
 * The passphrase is read from the TBSH_CAL_VAULT_PASSPHRASE environment variable or
 * prompted. Requires PHP 7.2+ with the sodium extension (or paragonie/sodium_compat).
 *
 * File format (version 1):
 *   "TBSHVLT1" (8 bytes) | PBKDF2 iterations (uint32 big-endian) | salt (16 bytes)
 *   | secretstream header (24 bytes)
 *   | repeated: ciphertext length (uint32 big-endian) | ciphertext chunk
 *   key = PBKDF2-HMAC-SHA256(passphrase, salt, iterations, 32 bytes)
 *   cipher = crypto_secretstream_xchacha20poly1305, last chunk tagged FINAL.
 * The decrypted output is gzip-compressed JSON Lines: {"type": ..., "data": {...}} per line.
 *
 * @package TBSHComplianceAuditLogger
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: php decrypt-vault.php <package.tbshvault> [output.jsonl.gz]\n" );
	exit( 2 );
}
if ( ! function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_pull' ) ) {
	fwrite( STDERR, "The PHP sodium extension is required.\n" );
	exit( 2 );
}

$in   = $argv[1];
$out  = $argv[2] ?? $in . '.jsonl.gz';
$pass = getenv( 'TBSH_CAL_VAULT_PASSPHRASE' );
if ( ! $pass ) {
	fwrite( STDOUT, 'Passphrase: ' );
	$pass = rtrim( (string) fgets( STDIN ), "\r\n" );
}

$src  = fopen( $in, 'rb' );
$head = fread( $src, 8 + 4 + 16 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES );
if ( 0 !== strpos( (string) $head, 'TBSHVLT1' ) ) {
	fwrite( STDERR, "Not a vault package.\n" );
	exit( 1 );
}
$iterations = unpack( 'N', substr( $head, 8, 4 ) )[1];
$key        = hash_pbkdf2( 'sha256', $pass, substr( $head, 12, 16 ), $iterations, 32, true );
$state      = sodium_crypto_secretstream_xchacha20poly1305_init_pull( substr( $head, 28 ), $key );
$dst        = fopen( $out, 'wb' );

do {
	$len = fread( $src, 4 );
	if ( false === $len || 4 !== strlen( $len ) ) {
		fwrite( STDERR, "Package is truncated.\n" );
		exit( 1 );
	}
	$res = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, fread( $src, unpack( 'N', $len )[1] ) );
	if ( false === $res ) {
		fwrite( STDERR, "Wrong passphrase or the package was modified.\n" );
		exit( 1 );
	}
	fwrite( $dst, $res[0] );
} while ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL !== $res[1] );

fclose( $src );
fclose( $dst );
fwrite( STDOUT, "Decrypted to $out\n" );
