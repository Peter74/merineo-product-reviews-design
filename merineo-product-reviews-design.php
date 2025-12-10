<?php
/**
 * Plugin Name:       Merineo Product Reviews Design
 * Plugin URI:        https://merineo.sk
 * Description:       Professional, configurable styling and UX for WooCommerce product reviews.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Merineo s.r.o. (PeterB)
 * Author URI:        https://merineo.sk
 * Text Domain:       merineo-product-reviews-design
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package merineo-product-reviews-design
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Global debug flags shared across Merineo plugins.
if ( ! defined( 'MERINEO_DEBUG' ) ) {
    define( 'MERINEO_DEBUG', true );
}
if ( ! defined( 'MERINEO_DEBUG_GLOBAL' ) ) {
    define( 'MERINEO_DEBUG_GLOBAL', false );
}

// Core constants. See https://developer.wordpress.org/plugins/plugin-basics/determining-plugin-and-content-directories/
define( 'WP_MERINEO_PRD_REVIEWS_VERSION', '1.0.0' );
define( 'WP_MERINEO_PRD_REVIEWS_FILE', __FILE__ );
define( 'WP_MERINEO_PRD_REVIEWS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_MERINEO_PRD_REVIEWS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin textdomain on plugins_loaded.
 *
 * @see https://developer.wordpress.org/reference/functions/load_plugin_textdomain/
 */
add_action( 'plugins_loaded', 'merineo_prd_reviews_load_textdomain' );

function merineo_prd_reviews_load_textdomain(): void {
    load_plugin_textdomain(
        'merineo-product-reviews-design',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}

// HPOS compatibility. See https://woocommerce.com/document/high-performance-order-storage-custom-tables/#section-5
add_action(
    'before_woocommerce_init',
    static function (): void {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                WP_MERINEO_PRD_REVIEWS_FILE,
                true
            );
        }
    }
);

/**
 * Simple autoloader for this plugin namespace.
 *
 * @param string $class Fully qualified class name.
 */
spl_autoload_register(
    static function ( string $class ): void {
        // Namespace prefix.
        $prefix = 'Merineo\\Product_Reviews_Design\\';

        if ( str_starts_with( $class, $prefix ) ) {
            $relative = substr( $class, strlen( $prefix ) );
            $relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
            $file     = WP_MERINEO_PRD_REVIEWS_PATH . 'inc/' . $relative . '.php';

            if ( is_readable( $file ) ) {
                require_once $file;
            }
        }
    }
);

// Activation / deactivation hooks. See https://developer.wordpress.org/plugins/plugin-basics/activation-deactivation-hooks/
register_activation_hook(
    __FILE__,
    static function (): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die(
                esc_html__( 'WooCommerce must be active to use Merineo Product Reviews Dizajn.', 'merineo-product-reviews-design' )
            );
        }

        Merineo\Product_Reviews_Design\Bootstrap::activate();
    }
);

register_deactivation_hook(
    __FILE__,
    static function (): void {
        Merineo\Product_Reviews_Design\Bootstrap::deactivate();
    }
);

// Bootstrap on plugins_loaded. See https://developer.wordpress.org/reference/hooks/plugins_loaded/
add_action(
    'plugins_loaded',
    static function (): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        ( new Merineo\Product_Reviews_Design\Bootstrap() )->run();
    }
);