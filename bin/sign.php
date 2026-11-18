<?php
/**
 * Sign release zips for the GitHub updater.
 *
 *   LIVEPULL_SIGNING_KEY=<base64 secret key> php bin/sign.php dist/livepull.zip [more.zip …]
 *
 * Writes <zip>.sig (base64 Ed25519 signature) next to each zip. The key lives only in the
 * repository secret LIVEPULL_SIGNING_KEY; its public half is LivePull_Updater::SIGNING_KEY.
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
$key = base64_decode( (string) getenv( 'LIVEPULL_SIGNING_KEY' ), true );
if ( ! $key || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $key ) ) {
	fwrite( STDERR, "LIVEPULL_SIGNING_KEY is missing or not a signing key, so the release can't be signed.\n" );
	exit( 1 );
}
$src = file_get_contents( __DIR__ . '/../includes/class-updater.php' );
if ( ! preg_match( "/const SIGNING_KEY = '([^']+)'/", $src, $m ) || ! hash_equals( base64_decode( $m[1] ), sodium_crypto_sign_publickey_from_secretkey( $key ) ) ) {
	fwrite( STDERR, "LIVEPULL_SIGNING_KEY doesn't match the public key in includes/class-updater.php.\n" );
	exit( 1 );
}
foreach ( array_slice( $argv, 1 ) as $zip ) {
	$data = file_get_contents( $zip );
	if ( false === $data ) {
		fwrite( STDERR, "Can't read $zip\n" );
		exit( 1 );
	}
	file_put_contents( $zip . '.sig', base64_encode( sodium_crypto_sign_detached( $data, $key ) ) . "\n" );
	echo "Signed $zip\n";
}
