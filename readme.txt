=== PV WPMS SSO ===
Tags: multisite, sso, single sign-on, saml, login
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Single sign-on for domain-mapped WordPress Multisite: sign in once, reach every site you belong to, across mapped domains and networks.

== Description ==

WordPress login cookies belong to one domain. On a multisite network with mapped domains, signing in on one site does nothing for the next: you type your password and pass two-factor again for every domain.

PV WPMS SSO gives each network one identity site. When you open a site's login page, the page asks the identity site whether you already have a session there that may enter this site. If you do, you are signed in. If you do not, you see the normal WordPress login form.

* **Automatic sign-in** across mapped domains, or a one-click "Continue as {name}" button, per network.
* **No dead ends**: the single sign-on button appears only for people it will work for. Site-local users just see the WordPress login form.
* **Your login stays yours**: passwords and two-factor run on the site in front of you. Signing in on one site carries network staff to the others.
* **Access is never widened**: choose super admins only (default), members of the destination site, or any account. Signing in elsewhere never grants a role.
* **Several networks, several identity sites**: works across the networks of a multi-network installation that shares one database. Networks with different identity sites stay separate.
* **External sign-in with SAML 2.0** (OneLogin, Okta, and other providers), with optional account provisioning.
* **Network settings screen and WP-CLI** commands for status and key rotation.

Sessions are handed over with signed, single-use, 60-second tickets. Each site sets its own cookies for its own domain. No third-party cookies or iframes are used, so it works in every current browser.

This plugin requires WordPress Multisite and is network-activated. The SAML feature uses the bundled [SAML PHP Toolkit](https://github.com/SAML-Toolkits/php-saml) (MIT licence).

== Installation ==

1. Upload the plugin ZIP under Network Admin > Plugins > Add New > Upload Plugin, or install it in `wp-content/plugins/`.
2. Network Activate PV WPMS SSO.
3. Open Network Admin > Settings > SSO to choose the identity site and who may use single sign-on.

== Frequently Asked Questions ==

= Does it work without Multisite? =

No. It solves a multisite problem: one set of users, many domains.

= Who can use single sign-on? =

Each network chooses: super admins only (the default), members of the destination site, or any account in the installation. The rule is checked when a ticket is issued and again when it is used.

= Will every visitor be redirected through the identity site? =

Opening a login page makes one quick round trip to the identity site to look for an existing session. After signing in locally, only accounts that can reach another site (super admins and members of several sites) are handed on to the identity site. Site-local users are not.

= Does signing out of one site sign me out everywhere? =

No. Sign-out is per site. Right after signing out, the login page offers "Continue as {name}" instead of signing you straight back in.

= Can different networks use different identity sites? =

Yes. Each network designates its own identity site, and sign-in never crosses between networks that designate different ones.

= Which two-factor plugins work? =

Two-factor runs on the site where you sign in, before any hand-over. A two-factor plugin must finish through WordPress's standard `login_redirect` filter for the hand-over to the identity site to start.

== Screenshots ==

1. A login page that found your identity-site session offers one click.
2. Network settings: identity site, who may use single sign-on, automatic sign-in.
3. External single sign-on (SAML) settings.

== Changelog ==

= 0.1.0 =
* Initial release.
