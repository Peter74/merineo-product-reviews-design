<?php
/**
 * Admin settings page.
 *
 * @package merineo-product-reviews-design
 */

declare(strict_types=1);

namespace Merineo\Product_Reviews_Design\Admin;

use function add_action;
use function add_menu_page;
use function add_submenu_page;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function get_option;
use function is_array;
use function rest_sanitize_boolean;
use function sanitize_hex_color;
use function sanitize_text_field;
use function submit_button;
use function update_option;
use function wp_nonce_field;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin settings page for Merineo Product Reviews Dizajn.
 */
final class Settings_Page {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {
        // Admin menu. See https://developer.wordpress.org/reference/hooks/admin_menu/
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
    }

    /**
     * Register Merineo top-level menu (if needed) and plugin submenu.
     *
     * @return void
     */
    public function register_menu(): void {
        // Access global admin page hooks to detect existing menu. See https://developer.wordpress.org/reference/functions/add_menu_page/
        global $admin_page_hooks;

        if ( ! isset( $admin_page_hooks['merineo-settings-page'] ) ) {
            add_menu_page(
                esc_html__( 'Merineo', 'merineo-product-reviews-design' ),
                esc_html__( 'Merineo', 'merineo-product-reviews-design' ),
                'manage_woocommerce',
                'merineo-settings-page',
                array( $this, 'render_merineo_root_page' ),
                'dashicons-admin-generic',
                83
            );
        }

        // Submenu for this plugin only.
        add_submenu_page(
            'merineo-settings-page',
            esc_html__( 'Merineo Product Reviews Dizajn', 'merineo-product-reviews-design' ),
            esc_html__( 'Product Reviews Dizajn', 'merineo-product-reviews-design' ),
            'manage_woocommerce',
            'merineo-product-reviews-design',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Render root Merineo page placeholder.
     *
     * @return void
     */
    public function render_merineo_root_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Merineo Settings', 'merineo-product-reviews-design' ) . '</h1>';
        echo '<p>' . esc_html__( 'Use the submenu pages for specific Merineo plugins.', 'merineo-product-reviews-design' ) . '</p>';
        echo '</div>';
    }

    /**
     * Render plugin settings page.
     *
     * @return void
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            // Capability check. See https://developer.wordpress.org/reference/functions/current_user_can/
            return;
        }

        $settings = get_option( 'merineo_prd_reviews_settings', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $colors = isset( $settings['colors'] ) && is_array( $settings['colors'] )
            ? $settings['colors']
            : array();

        // Proxy WooCommerce "Enable product reviews" setting.
        $wc_enable_reviews = get_option( 'woocommerce_enable_reviews', 'yes' ); // See https://woocommerce.com/document/ratings-and-reviews/
        $wc_reviews_on     = ( 'yes' === $wc_enable_reviews );

        if ( isset( $_POST['merineo_prd_reviews_settings_submit'] ) ) {
            // Nonce verification. See https://developer.wordpress.org/reference/functions/check_admin_referer/
            check_admin_referer( 'merineo_prd_reviews_settings' );

            // Booleans using rest_sanitize_boolean. See https://developer.wordpress.org/reference/functions/rest_sanitize_boolean/
            $settings['show_website_field']     = rest_sanitize_boolean( $_POST['show_website_field'] ?? false );
            $settings['allow_images']           = rest_sanitize_boolean( $_POST['allow_images'] ?? false );
            $settings['allow_images_guests']    = rest_sanitize_boolean( $_POST['allow_images_guests'] ?? false );
            $settings['require_image_approval'] = rest_sanitize_boolean( $_POST['require_image_approval'] ?? false );
            $settings['images_subdir']          = sanitize_text_field( (string) ( $_POST['images_subdir'] ?? 'merineo-reviews' ) );

            // Colors sanitization. See https://developer.wordpress.org/reference/functions/sanitize_hex_color/
            $color_keys = array( 'primary', 'background', 'border', 'text', 'accent' );
            foreach ( $color_keys as $key ) {
                $value = isset( $_POST['color_' . $key ] ) ? sanitize_hex_color( (string) $_POST['color_' . $key ] ) : '';
                if ( ! empty( $value ) ) {
                    $colors[ $key ] = $value;
                }
            }

            $settings['colors'] = $colors;

            // Proxy WooCommerce setting for reviews.
            $wc_reviews_on = rest_sanitize_boolean( $_POST['wc_enable_reviews'] ?? false );
            update_option( 'woocommerce_enable_reviews', $wc_reviews_on ? 'yes' : 'no' );

            update_option( 'merineo_prd_reviews_settings', $settings );

            echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'merineo-product-reviews-design' ) . '</p></div>';
        }

        // Re-fetch after save.
        $settings = get_option( 'merineo_prd_reviews_settings', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        $colors = isset( $settings['colors'] ) && is_array( $settings['colors'] )
            ? $settings['colors']
            : array();

        $show_website_field     = ! empty( $settings['show_website_field'] );
        $allow_images           = ! empty( $settings['allow_images'] );
        $allow_images_guests    = ! empty( $settings['allow_images_guests'] );
        $require_image_approval = array_key_exists( 'require_image_approval', $settings ) ? (bool) $settings['require_image_approval'] : true;
        $images_subdir          = ! empty( $settings['images_subdir'] ) ? $settings['images_subdir'] : 'merineo-reviews';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Merineo Product Reviews Dizajn', 'merineo-product-reviews-design' ) . '</h1>';

        echo '<form method="post" action="">';
        wp_nonce_field( 'merineo_prd_reviews_settings' );

        echo '<h2>' . esc_html__( 'General', 'merineo-product-reviews-design' ) . '</h2>';

        // Proxy Woo reviews toggle.
        echo '<table class="form-table" role="presentation">';
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Enable product reviews (WooCommerce)', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="wc_enable_reviews" value="1" ' . checked( $wc_reviews_on, true, false ) . ' />';
        echo ' ' . esc_html__( 'Enable reviews for products (same as WooCommerce → Settings → Products → Reviews).', 'merineo-product-reviews-design' );
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        // Website field toggle.
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Website field in reviews', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="show_website_field" value="1" ' . checked( $show_website_field, true, false ) . ' />';
        echo ' ' . esc_html__( 'Show the website/URL field in the product review form.', 'merineo-product-reviews-design' );
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // Colors.
        echo '<h2>' . esc_html__( 'Colors', 'merineo-product-reviews-design' ) . '</h2>';
        echo '<p>' . esc_html__( 'If left empty, colors are derived from the active theme (theme.json) or sensible defaults.', 'merineo-product-reviews-design' ) . '</p>';
        echo '<table class="form-table" role="presentation">';

        $color_fields = array(
            'primary'    => __( 'Primary accent', 'merineo-product-reviews-design' ),
            'background' => __( 'Review background', 'merineo-product-reviews-design' ),
            'border'     => __( 'Border color', 'merineo-product-reviews-design' ),
            'text'       => __( 'Text color', 'merineo-product-reviews-design' ),
            'accent'     => __( 'Star / badge color', 'merineo-product-reviews-design' ),
        );

        foreach ( $color_fields as $key => $label ) {
            $value = isset( $colors[ $key ] ) ? $colors[ $key ] : '';
            echo '<tr>';
            echo '<th scope="row"><label for="color_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th>';
            echo '<td>';
            echo '<input type="color" id="color_' . esc_attr( $key ) . '" name="color_' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';

        // Images.
        echo '<h2>' . esc_html__( 'Review images', 'merineo-product-reviews-design' ) . '</h2>';
        echo '<table class="form-table" role="presentation">';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Allow images in reviews', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="allow_images" value="1" ' . checked( $allow_images, true, false ) . ' />';
        echo ' ' . esc_html__( 'Allow up to 3 images per review (jpg, jpeg, png, webp).', 'merineo-product-reviews-design' );
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Allow images for guests', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="allow_images_guests" value="1" ' . checked( $allow_images_guests, true, false ) . ' />';
        echo ' ' . esc_html__( 'Allow non-logged-in customers to upload images in reviews.', 'merineo-product-reviews-design' );
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Require image approval', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="require_image_approval" value="1" ' . checked( $require_image_approval, true, false ) . ' />';
        echo ' ' . esc_html__( 'Images are hidden until explicitly approved in the comment edit screen.', 'merineo-product-reviews-design' );
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Upload subdirectory', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<input type="text" name="images_subdir" value="' . esc_attr( $images_subdir ) . '" />';
        echo '<p class="description">' . esc_html__( 'Subdirectory inside the uploads folder where review images are stored.', 'merineo-product-reviews-design' ) . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        echo '<input type="hidden" name="merineo_prd_reviews_settings_submit" value="1" />';
        submit_button();

        echo '</form>';
        echo '</div>';
    }
}