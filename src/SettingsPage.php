<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function absint;
use function add_query_arg;
use function array_key_exists;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_js;
use function esc_textarea;
use function esc_url;
use function esc_url_raw;
use function get_blog_details;
use function get_current_network_id;
use function get_site_url;
use function is_super_admin;
use function network_admin_url;
use function printf;
use function rest_url;
use function restore_current_blog;
use function sanitize_key;
use function sanitize_text_field;
use function switch_to_blog;
use function wp_unslash;
use WP_Site;

/**
 * Network admin settings screen: Settings → SSO (settings.php submenu).
 * Server-rendered form: enable/disable, IdP site (root or designated),
 * scope, button label, and key rotation.
 */
final class SettingsPage {

	public const MENU_SLUG   = 'pv-wpms-sso';
	public const CAPABILITY  = 'manage_network_options';
	private const NONCE_ACTION = 'pv_wpms_sso_settings';

	public static function register(): void {
		add_submenu_page(
			'settings.php',
			__( 'Network Single Sign-On', 'pv-wpms-sso' ),
			__( 'SSO', 'pv-wpms-sso' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage network single sign-on settings.', 'pv-wpms-sso' ) );
		}

		$networkId = get_current_network_id();

		$notice = null;
		if ( isset( $_POST['action'] ) ) {
			$notice = self::handlePost( $networkId );
		}

		$settings     = Settings::forNetwork( $networkId );
		$idpBlogId    = Settings::resolveIdpBlogId( $networkId );
		$idpSiteUrl   = get_site_url( $idpBlogId );
		$signingKey   = KeyStore::getForBlog( $idpBlogId );
		$fingerprint  = KeyStore::fingerprint( $signingKey );
		$keyOverridden = defined( KeyStore::CONSTANT_NAME );

		self::renderHtml( $networkId, $settings, $idpSiteUrl, $fingerprint, $keyOverridden, $notice );
		self::renderSamlSection( $idpBlogId, $notice );
	}

	/**
	 * @param array{enabled: bool, idp_blog_id: int, scope: string, button_label: string} $settings
	 * @param array{type: string, message: string}|null $notice
	 */
	private static function renderHtml(
		int $networkId,
		array $settings,
		string $idpSiteUrl,
		string $keyFingerprint,
		bool $keyOverridden,
		?array $notice
	): void {
		$formUrl = add_query_arg( 'page', self::MENU_SLUG, network_admin_url( 'settings.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Network Single Sign-On', 'pv-wpms-sso' ); ?></h1>

			<?php if ( null !== $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s: site URL of the identity site. */
					esc_html__( 'Sites of this network offer a "Sign in with" button that delegates to the identity site: %s. Users sign in there once (two-factor included) and are signed into any site of the network.', 'pv-wpms-sso' ),
					'<code>' . esc_html( $idpSiteUrl ) . '</code>'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( $formUrl ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="save_settings" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable single sign-on', 'pv-wpms-sso' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> />
								<?php esc_html_e( 'Show the SSO button on this network\'s site login screens', 'pv-wpms-sso' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Identity site', 'pv-wpms-sso' ); ?></th>
						<td>
							<label>
								<input type="radio" name="idp_source" value="root" <?php checked( 0, $settings['idp_blog_id'] ); ?> />
								<?php esc_html_e( 'Root site of this network', 'pv-wpms-sso' ); ?>
							</label><br />
							<label>
								<input type="radio" name="idp_source" value="designated" <?php checked( 0 !== $settings['idp_blog_id'] ); ?> />
								<?php
								printf(
									/* translators: %s: input field for a site ID. */
									esc_html__( 'Designated site ID %s (any site of this install — another network\'s site enables cross-network SSO)', 'pv-wpms-sso' ),
									'<input type="number" name="idp_blog_id" min="1" step="1" value="' . esc_attr( (string) ( 0 === $settings['idp_blog_id'] ? '' : $settings['idp_blog_id'] ) ) . '" />'
								);
								?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pv-wpms-sso-scope"><?php esc_html_e( 'Who can use single sign-on', 'pv-wpms-sso' ); ?></label></th>
						<td>
							<select name="scope" id="pv-wpms-sso-scope">
								<?php foreach ( ScopePolicy::scopeChoices() as $scopeValue => $scopeLabel ) : ?>
									<option value="<?php echo esc_attr( $scopeValue ); ?>" <?php selected( $scopeValue, $settings['scope'] ); ?>><?php echo esc_html( $scopeLabel ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pv-wpms-sso-button-label"><?php esc_html_e( 'Button label', 'pv-wpms-sso' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="button_label" id="pv-wpms-sso-button-label"
								value="<?php echo esc_attr( $settings['button_label'] ); ?>"
								placeholder="<?php esc_attr_e( 'Sign in with {network name}', 'pv-wpms-sso' ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave empty to use "Sign in with {network name}".', 'pv-wpms-sso' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'pv-wpms-sso' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Signing key', 'pv-wpms-sso' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: key fingerprint. */
					esc_html__( 'Current key fingerprint: %s', 'pv-wpms-sso' ),
					'<code>' . esc_html( $keyFingerprint ) . '</code>'
				);
				?>
			</p>
			<?php if ( $keyOverridden ) : ?>
				<p><?php esc_html_e( 'The key is provided by the PV_WPMS_SSO_KEY constant in wp-config; rotate it there.', 'pv-wpms-sso' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $formUrl ); ?>"
					onsubmit="return confirm('<?php echo esc_js( __( 'Rotate the signing key? Outstanding tickets (at most 60 seconds of them) become invalid.', 'pv-wpms-sso' ) ); ?>');">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="rotate_key" />
					<?php submit_button( __( 'Rotate key', 'pv-wpms-sso' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @return array{type: string, message: string} */
	private static function handlePost( int $networkId ): array {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage network single sign-on settings.', 'pv-wpms-sso' ) );
		}

		$postedAction = sanitize_key( self::postTextParam( 'action', '' ) );

		if ( 'save_saml_settings' === $postedAction ) {
			return self::handleSamlPost( $networkId );
		}

		if ( 'rotate_key' === $postedAction ) {
			KeyStore::rotateForBlog( Settings::resolveIdpBlogId( $networkId ) );

			return [
				'type'    => 'success',
				'message' => __( 'Signing key rotated. Outstanding tickets (at most 60 seconds of them) are now invalid.', 'pv-wpms-sso' ),
			];
		}

		$idpSource = sanitize_key( self::postTextParam( 'idp_source', 'root' ) );
		$idpBlogId = 'designated' === $idpSource
			? absint( self::postTextParam( 'idp_blog_id', '0' ) )
			: 0;

		if ( 'designated' === $idpSource && ( 0 === $idpBlogId || ! get_blog_details( $idpBlogId ) instanceof WP_Site ) ) {
			return [
				'type'    => 'error',
				'message' => __( 'The designated identity site ID does not exist. Settings were not saved.', 'pv-wpms-sso' ),
			];
		}

		$scope = sanitize_key( self::postTextParam( 'scope', '' ) );
		if ( ! array_key_exists( $scope, ScopePolicy::scopeChoices() ) ) {
			$scope = Settings::SCOPE_SUPER_ADMINS;
		}

		Settings::save(
			$networkId,
			[
				'enabled'       => isset( $_POST['enabled'] ),
				'idp_blog_id'   => $idpBlogId,
				'scope'         => $scope,
				'button_label'  => sanitize_text_field( self::postTextParam( 'button_label', '' ) ),
			]
		);

		return [
			'type'    => 'success',
			'message' => __( 'Settings saved.', 'pv-wpms-sso' ),
		];
	}

	private static function postTextParam( string $paramName, string $default ): string {
		$value = isset( $_POST[ $paramName ] ) ? wp_unslash( $_POST[ $paramName ] ) : $default;

		return is_string( $value ) ? $value : $default;
	}

	/**
	 * External SSO (SAML) section. The SAML application describes the
	 * identity site, which belongs to the network that owns it — for
	 * cross-network setups this page edits that network's SAML settings,
	 * and saving requires super admin.
	 *
	 * @param array{type: string, message: string}|null $notice
	 */
	private static function renderSamlSection( int $idpBlogId, ?array $notice ): void {
		$currentNetworkId = get_current_network_id();
		$idpSite          = get_blog_details( $idpBlogId );
		$idpNetworkId     = $idpSite instanceof WP_Site ? (int) $idpSite->site_id : $currentNetworkId;
		$crossNetwork     = $idpNetworkId !== $currentNetworkId;

		$samlSettings = SamlSettings::forNetwork( $idpNetworkId );
		$samlRunnable = \PV\WpmsSso\saml_library_available();

		switch_to_blog( $idpBlogId );
		$metadataUrl = rest_url( AuthorizeEndpoint::REST_NAMESPACE . SamlMetadata::ROUTE );
		$acsUrl      = SamlProvider::acsUrl();
		restore_current_blog();

		$formUrl = add_query_arg( 'page', self::MENU_SLUG, network_admin_url( 'settings.php' ) );
		?>
		<hr />

		<h2><?php esc_html_e( 'External single sign-on (SAML)', 'pv-wpms-sso' ); ?></h2>

		<?php if ( ! $samlRunnable ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'The bundled SAML library is missing (vendor/ not shipped with this copy of the plugin). External SSO is inactive until the plugin is installed from the built package.', 'pv-wpms-sso' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $crossNetwork ) : ?>
			<div class="notice notice-info"><p><?php esc_html_e( 'This network uses an identity site of another network; the SAML application below belongs to that network\'s identity site.', 'pv-wpms-sso' ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Add a "Sign in with OneLogin" button to every login screen: users authenticate at your SAML identity provider (OneLogin, Okta, ...) on the identity site, then continue into any site of the network. Copy these two URLs into the provider\'s application:', 'pv-wpms-sso' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'SAML metadata (entity ID)', 'pv-wpms-sso' ); ?></th>
				<td><code><?php echo esc_html( $metadataUrl ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'ACS (response) URL', 'pv-wpms-sso' ); ?></th>
				<td><code><?php echo esc_html( $acsUrl ); ?></code></td>
			</tr>
		</table>

		<form method="post" action="<?php echo esc_url( $formUrl ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="save_saml_settings" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable SAML single sign-on', 'pv-wpms-sso' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="saml_enabled" value="1" <?php checked( $samlSettings['enabled'] ); ?> />
							<?php esc_html_e( 'Show the external SSO button', 'pv-wpms-sso' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pv-wpms-sso-saml-entity"><?php esc_html_e( 'IdP entity ID (issuer)', 'pv-wpms-sso' ); ?></label></th>
					<td><input type="url" class="regular-text" name="saml_idp_entity_id" id="pv-wpms-sso-saml-entity" value="<?php echo esc_attr( $samlSettings['idp_entity_id'] ); ?>" placeholder="https://app.onelogin.com/saml/metadata/123456" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="pv-wpms-sso-saml-sso"><?php esc_html_e( 'IdP single sign-on URL', 'pv-wpms-sso' ); ?></label></th>
					<td><input type="url" class="regular-text" name="saml_idp_sso_url" id="pv-wpms-sso-saml-sso" value="<?php echo esc_attr( $samlSettings['idp_sso_url'] ); ?>" placeholder="https://example.onelogin.com/trust/saml2/http-redirect/sso/123456" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="pv-wpms-sso-saml-cert"><?php esc_html_e( 'IdP X.509 certificate', 'pv-wpms-sso' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="4" name="saml_idp_x509cert" id="pv-wpms-sso-saml-cert" placeholder="<?php esc_attr_e( 'PEM or base64 body of the IdP signing certificate', 'pv-wpms-sso' ); ?>"><?php echo esc_textarea( $samlSettings['idp_x509cert'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Responses must be signed with this certificate (message-signed, RSA-SHA256).', 'pv-wpms-sso' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pv-wpms-sso-saml-label"><?php esc_html_e( 'Button label', 'pv-wpms-sso' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" name="saml_button_label" id="pv-wpms-sso-saml-label" value="<?php echo esc_attr( $samlSettings['button_label'] ); ?>" placeholder="<?php esc_attr_e( 'Sign in with OneLogin', 'pv-wpms-sso' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-provision users', 'pv-wpms-sso' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="saml_auto_provision" value="1" <?php checked( $samlSettings['auto_provision'] ); ?> />
							<?php esc_html_e( 'Create a network user on first sign-in when the email does not match an existing account', 'pv-wpms-sso' ); ?>
						</label><br />
						<label for="pv-wpms-saml-role"></label>
						<select name="saml_default_role" id="pv-wpms-saml-role">
							<?php
							$standardRoles = [
								'subscriber'    => __( 'Subscriber', 'pv-wpms-sso' ),
								'contributor'   => __( 'Contributor', 'pv-wpms-sso' ),
								'author'        => __( 'Author', 'pv-wpms-sso' ),
								'editor'        => __( 'Editor', 'pv-wpms-sso' ),
								'administrator' => __( 'Administrator', 'pv-wpms-sso' ),
							];
							foreach ( $standardRoles as $roleValue => $roleLabel ) :
								?>
								<option value="<?php echo esc_attr( $roleValue ); ?>" <?php selected( $roleValue, $samlSettings['default_role'] ); ?>><?php echo esc_html( $roleLabel ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="description"><?php esc_html_e( 'Role given to provisioned users on the identity site. Destination sites still apply the network SSO scope above.', 'pv-wpms-sso' ); ?></span>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save SAML settings', 'pv-wpms-sso' ) ); ?>
		</form>
		<?php
	}

	/** @return array{type: string, message: string} */
	private static function handleSamlPost( int $currentNetworkId ): array {
		$idpBlogId    = Settings::resolveIdpBlogId( $currentNetworkId );
		$idpSite      = get_blog_details( $idpBlogId );
		$idpNetworkId = $idpSite instanceof WP_Site ? (int) $idpSite->site_id : $currentNetworkId;

		if ( $idpNetworkId !== $currentNetworkId && ! is_super_admin() ) {
			return [
				'type'    => 'error',
				'message' => __( 'The identity site belongs to another network; only super admins may edit its SAML application.', 'pv-wpms-sso' ),
			];
		}

		SamlSettings::save(
			$idpNetworkId,
			[
				'enabled'        => isset( $_POST['saml_enabled'] ),
				'idp_entity_id'  => esc_url_raw( self::postTextParam( 'saml_idp_entity_id', '' ) ),
				'idp_sso_url'    => esc_url_raw( self::postTextParam( 'saml_idp_sso_url', '' ) ),
				'idp_x509cert'   => self::postTextParam( 'saml_idp_x509cert', '' ),
				'button_label'   => sanitize_text_field( self::postTextParam( 'saml_button_label', '' ) ),
				'auto_provision' => isset( $_POST['saml_auto_provision'] ),
				'default_role'   => sanitize_key( self::postTextParam( 'saml_default_role', SamlSettings::DEFAULT_ROLE ) ),
			]
		);

		return [
			'type'    => 'success',
			'message' => __( 'SAML settings saved.', 'pv-wpms-sso' ),
		];
	}
}
