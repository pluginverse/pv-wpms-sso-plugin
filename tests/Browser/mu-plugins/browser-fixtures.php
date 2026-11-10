<?php

/** Test-only MU plugin. Never loaded by the normal dev environment or a build. */
if ( ! defined( 'PV_WPMS_SSO_BROWSER_TESTS' ) || ! PV_WPMS_SSO_BROWSER_TESTS || 'local' !== wp_get_environment_type() ) {
	return;
}
if ( 'https' === ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) {
	$_SERVER['HTTPS'] = 'on';
	$_SERVER['SERVER_PORT'] = '9443';
}

add_action( 'login_form', static function (): void {
	echo '<p><label for="browser-factor">Second factor</label><input id="browser-factor" name="browser_factor" type="text"></p>';
} );
add_filter( 'authenticate', static function ( $user ) {
	if ( $user instanceof WP_User && in_array( $user->user_login, [ 'alex', 'casey', 'riley' ], true ) && '654321' !== ( $_POST['browser_factor'] ?? '' ) ) {
		return new WP_Error( 'browser_factor_required', 'Complete the test second factor.' );
	}
	return $user;
}, 50 );

add_action( 'init', static function (): void {
	if ( isset( $_GET['pv_browser_identity'] ) ) {
		nocache_headers();
		wp_send_json( [ 'user' => (string) wp_get_current_user()->user_login, 'network' => get_current_network_id(), 'site' => get_current_blog_id() ] );
	}
} );

add_action( 'login_init', static function (): void {
	if ( ! isset( $_GET['browser_saml'] ) ) {
		return;
	}
	$fixture = get_network_option( get_current_network_id(), 'pv_browser_idp' );
	$requestXml = gzinflate( base64_decode( (string) ( $_GET['SAMLRequest'] ?? '' ) ) );
	if ( ! is_string( $requestXml ) || 1 !== preg_match( '/ID="([^"]+)"/', $requestXml, $matches ) ) {
		wp_die( 'Invalid test SAML request.' );
	}
	switch_to_blog( $fixture['hub'] );
	$response = \PV\WpmsSso\Tests\Support\SignedSamlResponse::build( $matches[1], 'riley@example.test', $fixture['key'], $fixture['certificate'] );
	$acs = \PV\WpmsSso\SamlProvider::acsUrl();
	restore_current_blog();
	nocache_headers();
	echo '<form method="post" action="' . esc_url( $acs ) . '"><input type="hidden" name="SAMLResponse" value="' . esc_attr( $response ) . '"><button>Continue from test OneLogin</button></form>';
	exit;
} );
