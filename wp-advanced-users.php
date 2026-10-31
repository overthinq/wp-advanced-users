<?php
/**
 * Plugin Name:       Advanced Users
 * Description:       Sincronizza biografie e dati del profilo degli autori da Gravatar.
 * Version:           0.2.0
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-advanced-users
 */

namespace AdvancedUsers;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			require __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) . '.php';
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		$avatars = new LocalAvatar();
		$sync    = new ProfileSync( $avatars );
		$avatars->hook();
		$sync->hook();

		if ( is_admin() ) {
			( new Admin\ProfileSection( $sync ) )->hook();
			( new Admin\SyncPage( new SyncJob( $sync ), plugin_dir_url( __FILE__ ) ) )->hook();
		}
	}
);
