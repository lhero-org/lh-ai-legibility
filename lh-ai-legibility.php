<?php
/**
 * Plugin Name: LH AI Legibility
 * Plugin URI:  https://lhero.org
 * Description: Makes LocalHero content legible to AI systems. Serves Markdown via content negotiation (Accept: text/markdown) and generates llms.txt for AI crawler discovery.
 * Version:     0.3
 * Author:      Peter Shaw
 * Author URI:  https://shawfactor.com
 * License:     GPL-2.0+
 * Text Domain: lh-ai-legibility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LH_AI_LEGIBILITY_VERSION', '0.3' );
define( 'LH_AI_LEGIBILITY_PATH', plugin_dir_path( __FILE__ ) );
define( 'LH_AI_LEGIBILITY_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload classes from includes/.
 */
spl_autoload_register( function ( $class ) {
	$prefix = 'LH_AI_Legibility_';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}
	$name = str_replace( $prefix, '', $class );
	$name = strtolower( str_replace( '_', '-', $name ) );
	$file = LH_AI_LEGIBILITY_PATH . 'includes/class-' . $name . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Initialise subsystems.
 */
add_action( 'plugins_loaded', function () {
	LH_AI_Legibility_Markdown_Server::get_instance();
	LH_AI_Legibility_Section_Block::get_instance();
	LH_AI_Legibility_Llms_Txt::get_instance();
} );
