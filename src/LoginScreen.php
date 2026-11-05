<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function add_query_arg;
use function apply_filters;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function esc_url_raw;
use function get_current_network_id;
use function get_network;
use function get_site_url;
use function restore_current_blog;
use function sanitize_key;
use function sprintf;
use function switch_to_blog;
use function wp_login_url;
use function wp_unslash;

/**
 * The client half users see: the "Sign in with {network}" button on
 * wp-login.php, the inline session probe that personalizes it ("Continue as
 * {name}" with avatar), and error notices for refused flows.
 */
final class LoginScreen {

	public const ERROR_PARAM = 'pv_wpms_sso_error';

	public static function render(): void {
		if ( ! self::isDefaultLoginForm() || ! ClientFlow::ssoAvailableForThisSite() ) {
			return;
		}

		$network     = get_network( get_current_network_id() );
		$networkName = null === $network ? '' : (string) $network->site_name;

		/** Filter the SSO button label; default "Sign in with {network name}". */
		$buttonLabel = (string) apply_filters(
			'pv_wpms_sso/button_label',
			self::hasCustomLabel()
				? self::customLabel()
				: sprintf(
					/* translators: %s: network name. */
					__( 'Sign in with %s', 'pv-wpms-sso' ),
					$networkName
				),
			$networkName
		);

		$startUrl = add_query_arg(
			[
				ClientFlow::QUERY_PARAM => ClientFlow::ACTION_START,
				'redirect_to'           => self::currentRedirectTo(),
			],
			wp_login_url()
		);

		printf(
			'<div id="pv-wpms-sso" class="pv-wpms-sso">
				<div class="pv-wpms-sso-separator" role="separator"><span>%1$s</span></div>
				<a id="pv-wpms-sso-button" class="button pv-wpms-sso-button" href="%2$s"
					data-probe-url="%3$s"
					data-signed-in-label="%4$s">
					<span class="pv-wpms-sso-label">%5$s</span>
				</a>
			</div>
			<style>%6$s</style>
			<script>%7$s</script>',
			esc_html__( 'or', 'pv-wpms-sso' ),
			esc_url( $startUrl ),
			esc_url( self::sessionProbeUrl() ),
			esc_attr( sprintf(
				/* translators: %s: user display name, filled by the session probe. */
				__( 'Continue as %s', 'pv-wpms-sso' ),
				'%s'
			) ),
			esc_html( $buttonLabel ),
			self::buttonCss(),
			self::probeScript()
		);
	}

	/**
	 * Show a readable notice when an SSO flow was refused, instead of a bare
	 * login form with no explanation. Filter: login_message.
	 *
	 * @param string $message core-provided message HTML
	 * @return string
	 */
	public static function filterLoginMessage( $message ): string {
		$errorCode = sanitize_key( self::queryStringParam( self::ERROR_PARAM ) );

		if ( '' === $errorCode ) {
			return $message;
		}

		$errorMessage = self::errorMessageForCode( $errorCode );
		if ( null === $errorMessage ) {
			return $message;
		}

		return '<div class="notice notice-error notice-alt"><p>' . esc_html( $errorMessage ) . '</p></div>' . $message;
	}

	private static function isDefaultLoginForm(): bool {
		$action = sanitize_key( self::queryStringParam( 'action' ) );

		return in_array( $action, [ '', 'login', 'loggedout' ], true );
	}

	private static function hasCustomLabel(): bool {
		return '' !== self::customLabel();
	}

	private static function customLabel(): string {
		$settings = Settings::forNetwork( get_current_network_id() );

		return $settings['button_label'];
	}

	private static function currentRedirectTo(): string {
		// Validated again at start; esc here for attribute context.
		return esc_url_raw( self::queryStringParam( 'redirect_to' ) );
	}

	private static function queryStringParam( string $paramName ): string {
		$value = isset( $_GET[ $paramName ] ) ? wp_unslash( $_GET[ $paramName ] ) : '';

		return is_string( $value ) ? $value : '';
	}

	private static function sessionProbeUrl(): string {
		$idpBlogId = ClientFlow::idpBlogIdForThisSite();

		switch_to_blog( $idpBlogId );
		$probeUrl = get_site_url( $idpBlogId, '/wp-json/' . AuthorizeEndpoint::REST_NAMESPACE . SessionEndpoint::ROUTE );
		restore_current_blog();

		return $probeUrl;
	}

	private static function errorMessageForCode( string $errorCode ): ?string {
		return match ( $errorCode ) {
			AuthorizeEndpoint::ERROR_UNTRUSTED_CLIENT => __(
				'This site is not configured to use network single sign-on. Contact the network administrator.',
				'pv-wpms-sso'
			),
			AuthorizeEndpoint::ERROR_ACCESS_DENIED, ClientFlow::ERROR_NOT_ALLOWED => __(
				'Your account is not allowed to use network single sign-on for this site.',
				'pv-wpms-sso'
			),
			ClientFlow::ERROR_INVALID_STATE => __(
				'The single sign-on attempt timed out or was already used. Try again.',
				'pv-wpms-sso'
			),
			ClientFlow::ERROR_INVALID_TICKET => __(
				'The single sign-on response was invalid or expired. Try again.',
				'pv-wpms-sso'
			),
			ClientFlow::ERROR_REPLAYED_TICKET => __(
				'The single sign-on response was already used. Start again.',
				'pv-wpms-sso'
			),
			default => null,
		};
	}

	private static function buttonCss(): string {
		return '.pv-wpms-sso{margin:12px 0;padding:0 24px;box-sizing:border-box}
.pv-wpms-sso-separator{position:relative;text-align:center;margin:8px 0}
.pv-wpms-sso-separator:before{content:"";position:absolute;top:50%;left:0;width:100%;border-top:1px solid #dcdcde}
.pv-wpms-sso-separator span{position:relative;background:#fff;padding:0 8px;color:#787c82;font-size:12px}
.pv-wpms-sso-button{display:flex!important;align-items:center;justify-content:center;gap:8px;width:100%;height:36px;line-height:2;padding:0 16px}
.pv-wpms-sso-avatar{width:20px;height:20px;border-radius:50%;flex:none}';
	}

	private static function probeScript(): string {
		return '(function(){
	var button=document.getElementById(\'pv-wpms-sso-button\');
	if(!button||!window.fetch){return;}
	fetch(button.dataset.probeUrl,{credentials:\'include\',cache:\'no-store\'})
		.then(function(response){return response.ok?response.json():null;})
		.then(function(session){
			if(!session||!session.authenticated){return;}
			var label=button.querySelector(\'.pv-wpms-sso-label\');
			if(label){label.textContent=button.dataset.signedInLabel.replace(\'%s\',session.display_name||\'\');}
			if(session.avatar_url){
				var avatar=document.createElement(\'img\');
				avatar.src=session.avatar_url;
				avatar.alt=\'\';
				avatar.className=\'pv-wpms-sso-avatar\';
				button.prepend(avatar);
			}
			button.classList.add(\'pv-wpms-sso-authenticated\');
		})
		.catch(function(){/* stay on the default label */});
})();';
	}
}
