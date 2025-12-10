<?php
/**
 * Bootstrap class.
 *
 * @package merineo-product-reviews-design
 */

declare(strict_types=1);

namespace Merineo\Product_Reviews_Design;

use Merineo\Product_Reviews_Design\Admin\Settings_Page;
use Merineo\Product_Reviews_Design\Frontend\Reviews;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main bootstrap for registering services.
 */
final class Bootstrap {

    /**
     * Run plugin services.
     *
     * @return void
     */
    public function run(): void {
        // Admin settings page.
        ( new Settings_Page() )->register();

        // Frontend reviews behaviour and styling.
        ( new Reviews() )->register();
    }

    /**
     * Handle plugin activation.
     *
     * @return void
     */
    public static function activate(): void {
        // Set default options if not existing.
        $defaults = array(
            'colors'                 => array(),
            'show_website_field'     => true,
            'allow_images'           => false,
            'allow_images_guests'    => false,
            'require_image_approval' => true,
            'images_subdir'          => 'merineo-reviews',
        );

        if ( ! get_option( 'merineo_prd_reviews_settings', null ) ) {
            update_option( 'merineo_prd_reviews_settings', $defaults );
        }
    }

    /**
     * Handle plugin deactivation.
     *
     * @return void
     */
    public static function deactivate(): void {
        // Nothing destructive on deactivate; uninstall.php handles cleanup.
    }
}