<?php
/**
 * Plugin Name:       PadelCamps
 * Description:       Gestió de campionats de pàdel: inscripcions, sortejos, quadres, pistes i horaris, i resultats en directe.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Finismedia
 * Text Domain:       padelcamps
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'PADELCAMPS_VERSION', '0.2.0' );
define( 'PADELCAMPS_FILE', __FILE__ );
define( 'PADELCAMPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'PADELCAMPS_URL', plugin_dir_url( __FILE__ ) );

require_once PADELCAMPS_PATH . 'src/autoload.php';

register_activation_hook( __FILE__, array( \PadelCamps\Db\Schema::class, 'install' ) );

add_action(
	'plugins_loaded',
	static function () {
		\PadelCamps\Plugin::instance()->boot();
	}
);
