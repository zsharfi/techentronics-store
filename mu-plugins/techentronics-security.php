<?php
/**
 * Plugin Name: TechenTronics Security
 * Description: Small hardening layer: login attempt limit, no user enumeration, XML-RPC off, no version leaks, no application passwords.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* 1. Limit failed logins: 6 tries per 15 minutes per IP (wp-login and My Account login). */
function tt_sec_ip() { return isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-fA-F:.]/', '', wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown'; }
add_filter( 'authenticate', function ( $user, $username ) {
	if ( empty( $username ) ) { return $user; }
	$n = (int) get_transient( 'tt_ll_' . md5( tt_sec_ip() ) );
	if ( $n >= 6 ) { return new WP_Error( 'tt_locked', __( '<strong>Too many failed login attempts.</strong> Please wait 15 minutes and try again.' ) ); }
	return $user;
}, 30, 2 );
add_action( 'wp_login_failed', function () { $k = 'tt_ll_' . md5( tt_sec_ip() ); set_transient( $k, (int) get_transient( $k ) + 1, 15 * MINUTE_IN_SECONDS ); } );
add_action( 'wp_login', function () { delete_transient( 'tt_ll_' . md5( tt_sec_ip() ) ); } );
/* Same message for wrong username and wrong password, so usernames can't be guessed. */
add_filter( 'login_errors', function ( $e ) { return ( false !== strpos( (string) $e, 'Too many failed' ) ) ? $e : __( '<strong>Error:</strong> The username or password is incorrect.' ); } );

/* 2. No user enumeration (author archives, ?author=N, public REST user list). */
add_action( 'template_redirect', function () {
	if ( ( is_author() || isset( $_GET['author'] ) ) && ! current_user_can( 'list_users' ) ) { wp_safe_redirect( home_url( '/' ), 301 ); exit; } // phpcs:ignore
}, 1 );
add_filter( 'rest_endpoints', function ( $e ) {
	if ( ! is_user_logged_in() ) { foreach ( array_keys( $e ) as $route ) { if ( 0 === strpos( $route, '/wp/v2/users' ) ) { unset( $e[ $route ] ); } } }
	return $e;
} );
add_filter( 'oembed_response_data', function ( $d ) { unset( $d['author_name'], $d['author_url'] ); return $d; } );

/* 3. XML-RPC off (also blocked in .htaccess), no pingbacks. */
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'wp_headers', function ( $h ) { unset( $h['X-Pingback'] ); return $h; } );
add_filter( 'pings_open', '__return_false' );

/* 4. Don't advertise software versions. */
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );

/* 5. Application passwords are not used on this store. */
add_filter( 'wp_is_application_passwords_available', '__return_false' );

/* 6. Security headers (also in .htaccess; sent here too so cached pages keep them). */
add_action( 'send_headers', function () {
	if ( headers_sent() ) { return; }
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
	if ( is_ssl() ) { header( 'Strict-Transport-Security: max-age=31536000' ); }
} );
