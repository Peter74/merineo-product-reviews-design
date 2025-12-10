<?php
/**
 * Admin settings page.
 *
 * @package merineo-product-reviews-design
 */

declare(strict_types=1);

namespace Merineo\Product_Reviews_Design\Admin;

use WP_Comment;
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
use function wp_enqueue_style;
use function wp_get_global_settings;
use function wp_theme_has_theme_json;
use function get_current_screen;
use function wp_enqueue_script;
use function wp_delete_attachment;
use function delete_comment_meta;
use function add_filter;

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

        // Admin assets for this settings page only.
        // See https://developer.wordpress.org/reference/hooks/admin_enqueue_scripts/
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Comment meta box for review images approval.
        // See https://developer.wordpress.org/reference/hooks/add_meta_boxes_comment/
        add_action( 'add_meta_boxes_comment', array( $this, 'register_comment_images_meta_box' ) );

        // Save meta box data on comment update.
        // See https://developer.wordpress.org/reference/hooks/edit_comment/
        add_action( 'edit_comment', array( $this, 'save_comment_images_meta_box' ) );

        /**
         * Render custom column content for review images (WordPress comments screen).
         *
         * @see https://developer.wordpress.org/reference/hooks/manage_comments_custom_column/
         */
        add_action( 'manage_comments_custom_column', array( $this, 'render_review_images_column' ), 10, 2 );

        /**
         * Add custom column to the WooCommerce > Products > Reviews table.
         *
         * @see https://woocommerce.github.io/code-reference/hooks/hooks.html#hook_woocommerce_product_reviews_table_columns
         */
        add_filter( 'woocommerce_product_reviews_table_columns', array( $this, 'add_review_images_column' ) );

        /**
         * Render custom column content in the WooCommerce > Products > Reviews table.
         *
         * @see https://wp-kama.com/plugin/woocommerce/hook/woocommerce_product_reviews_table_column_%28column_name%29
         */
        add_action(
            'woocommerce_product_reviews_table_column_merineo_review_images',
            array( $this, 'render_wc_review_images_table_column' ),
            10,
            1
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook_suffix Current admin page hook suffix.
     *
     * @return void
     *
     * @see https://developer.wordpress.org/reference/hooks/admin_enqueue_scripts/
     */
    public function enqueue_assets( string $hook_suffix ): void {
        // Admin CSS is lightweight; load it on all admin screens
        // so "Review images" columns are styled everywhere (comments, Woo reviews, etc.).
        wp_enqueue_style(
            'merineo-prd-reviews-admin',
            WP_MERINEO_PRD_REVIEWS_URL . 'assets/admin/css/admin.css',
            array(),
            WP_MERINEO_PRD_REVIEWS_VERSION
        );

        // Plugin settings page only.
        $is_settings_page = ( false !== strpos( $hook_suffix, 'merineo-product-reviews-design' ) );

        if ( ! $is_settings_page ) {
            return;
        }

        // Native WordPress color picker (settings page only).
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        // Admin JS to initialize color pickers (settings page only).
        wp_enqueue_script(
            'merineo-prd-reviews-admin',
            WP_MERINEO_PRD_REVIEWS_URL . 'assets/admin/js/admin.js',
            array( 'wp-color-picker', 'jquery' ),
            WP_MERINEO_PRD_REVIEWS_VERSION,
            true
        );
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
            esc_html__( 'Reviews Dizajn', 'merineo-product-reviews-design' ),
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

        // Load current settings.
        $settings = get_option( 'merineo_prd_reviews_settings', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        // Proxy WooCommerce "Enable product reviews" setting.
        $wc_enable_reviews = get_option( 'woocommerce_enable_reviews', 'yes' ); // See Woo ratings docs.
        $wc_reviews_on     = ( 'yes' === $wc_enable_reviews );

        // Core WordPress comment pagination options.
        $page_comments         = (bool) get_option( 'page_comments', 0 );
        $comments_per_page     = (int) get_option( 'comments_per_page', 50 );
        $default_comments_page = (string) get_option( 'default_comments_page', 'newest' ); // 'newest' or 'oldest'.
        $comment_order         = (string) get_option( 'comment_order', 'asc' ); // 'asc' or 'desc'.

        // Handle settings save.
        if ( isset( $_POST['merineo_prd_reviews_settings_submit'] ) ) {
            // Nonce verification. See https://developer.wordpress.org/reference/functions/check_admin_referer/
            check_admin_referer( 'merineo_prd_reviews_settings' );

            // Booleans using rest_sanitize_boolean. See https://developer.wordpress.org/reference/functions/rest_sanitize_boolean/
            $settings['show_website_field']     = rest_sanitize_boolean( $_POST['show_website_field'] ?? false );
            $settings['allow_images']           = rest_sanitize_boolean( $_POST['allow_images'] ?? false );
            $settings['allow_images_guests']    = rest_sanitize_boolean( $_POST['allow_images_guests'] ?? false );
            $settings['require_image_approval'] = rest_sanitize_boolean( $_POST['require_image_approval'] ?? false );
            $settings['images_subdir']          = sanitize_text_field( (string) ( $_POST['images_subdir'] ?? 'merineo-reviews' ) );

            // Base palette (theme.json + plugin defaults) used as fallback.
            $base_palette = $this->get_base_palette();

            // Colors sanitization: after each save we store a complete set of colors,
            // so from now on the options are the primary source of truth.
            $color_keys = array( 'primary', 'background', 'border', 'text', 'accent' );
            $new_colors = array();

            foreach ( $color_keys as $key ) {
                $field = 'color_' . $key;
                $raw   = isset( $_POST[ $field ] ) ? (string) $_POST[ $field ] : '';
                $val   = sanitize_hex_color( $raw );

                if ( $val ) {
                    // User entered a valid color.
                    $new_colors[ $key ] = $val;
                } else {
                    // Empty or invalid field: use base palette (theme.json + defaults).
                    $new_colors[ $key ] = $base_palette[ $key ] ?? '';
                }
            }

            $settings['colors'] = $new_colors;

            // Proxy WooCommerce setting for reviews.
            $wc_reviews_on = rest_sanitize_boolean( $_POST['wc_enable_reviews'] ?? false );
            update_option( 'woocommerce_enable_reviews', $wc_reviews_on ? 'yes' : 'no' );

            // Persist plugin settings.
            update_option( 'merineo_prd_reviews_settings', $settings );

            // --- Mirror WordPress discussion settings for comment pagination ---

            // Paginate comments.
            $page_comments_value = rest_sanitize_boolean( $_POST['page_comments'] ?? false );
            update_option( 'page_comments', $page_comments_value ? 1 : 0 );

            // Comments per page.
            $comments_per_page_value = isset( $_POST['comments_per_page'] ) ? (int) $_POST['comments_per_page'] : 50;
            if ( $comments_per_page_value < 1 ) {
                $comments_per_page_value = 1;
            }
            update_option( 'comments_per_page', $comments_per_page_value );

            // Default comments page: 'newest' or 'oldest'.
            $allowed_default_pages = array( 'newest', 'oldest' );
            $default_page_value    = isset( $_POST['default_comments_page'] ) ? (string) $_POST['default_comments_page'] : 'newest';
            if ( ! in_array( $default_page_value, $allowed_default_pages, true ) ) {
                $default_page_value = 'newest';
            }
            update_option( 'default_comments_page', $default_page_value );

            // Comment order on each page: 'asc' or 'desc'.
            $allowed_orders = array( 'asc', 'desc' );
            $order_value    = isset( $_POST['comment_order'] ) ? (string) $_POST['comment_order'] : 'asc';
            if ( ! in_array( $order_value, $allowed_orders, true ) ) {
                $order_value = 'asc';
            }
            update_option( 'comment_order', $order_value );

            echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'merineo-product-reviews-design' ) . '</p></div>';
        }

        // Reload settings after a possible save to be sure we are using the latest values.
        $settings = get_option( 'merineo_prd_reviews_settings', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $colors = isset( $settings['colors'] ) && is_array( $settings['colors'] )
            ? $settings['colors']
            : array();

        // Resolve effective colors for the form:
        // - if no colors stored yet → use base palette (theme.json + defaults),
        // - if colors are stored → use them, base palette fills missing keys only.
        $base_palette = $this->get_base_palette();

        if ( empty( $colors ) ) {
            $effective_colors = $base_palette;
        } else {
            $effective_colors = array_merge( $base_palette, $colors );
        }

        $show_website_field     = ! empty( $settings['show_website_field'] );
        $allow_images           = ! empty( $settings['allow_images'] );
        $allow_images_guests    = ! empty( $settings['allow_images_guests'] );
        $require_image_approval = array_key_exists( 'require_image_approval', $settings ) ? (bool) $settings['require_image_approval'] : true;
        $images_subdir          = ! empty( $settings['images_subdir'] ) ? $settings['images_subdir'] : 'merineo-reviews';

        echo '<div class="wrap merineo-prd-reviews-settings-wrap">';
        echo '<h1>' . esc_html__( 'Merineo Product Reviews Dizajn', 'merineo-product-reviews-design' ) . '</h1>';

        echo '<form method="post" action="" class="merineo-prd-reviews-settings-form">';
        wp_nonce_field( 'merineo_prd_reviews_settings' );

        echo '<div class="merineo-prd-reviews-settings-wrap-inner">';
        echo '<section class="merineo-prd-reviews-section">';
        echo '<h2>' . esc_html__( 'General', 'merineo-product-reviews-design' ) . '</h2>';

        // Proxy Woo reviews toggle.
        echo '<table class="form-table merineo-prd-reviews-section__table" role="presentation">';
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Enable product reviews (WooCommerce)', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<label class="merineo-toggle">';
        echo '<input type="checkbox" class="merineo-toggle-input" name="wc_enable_reviews" value="1" ' . checked( $wc_reviews_on, true, false ) . ' />';
        echo '<span class="merineo-toggle-slider" aria-hidden="true"></span>';
        echo '<span class="merineo-toggle-label">' . esc_html__( 'Enable reviews for products (same as WooCommerce → Settings → Products → Reviews).', 'merineo-product-reviews-design' ) . '</span>';
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        // Website field toggle.
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Website field in reviews', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<label class="merineo-toggle">';
        echo '<input type="checkbox" class="merineo-toggle-input" name="show_website_field" value="1" ' . checked( $show_website_field, true, false ) . ' />';
        echo '<span class="merineo-toggle-slider" aria-hidden="true"></span>';
        echo '<span class="merineo-toggle-label">' . esc_html__( 'Show the website/URL field in the product review form.', 'merineo-product-reviews-design' ) . '</span>';
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';
        echo '</section>';

        // --- Comment pagination (mirror of Settings → Discussion) ---
        echo '<section class="merineo-prd-reviews-section">';
        echo '<h2>' . esc_html__( 'Comment pagination', 'merineo-product-reviews-design' ) . '</h2>';
        echo '<p>' . esc_html__( 'These settings mirror the WordPress Discussion settings and affect how product reviews are paginated.', 'merineo-product-reviews-design' ) . '</p>';

        echo '<table class="form-table merineo-prd-reviews-section__table" role="presentation">';

        // Toggle paginate comments.
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Break comments into pages', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<label class="merineo-toggle">';
        echo '<input type="checkbox" class="merineo-toggle-input" name="page_comments" value="1" ' . checked( $page_comments, true, false ) . ' />';
        echo '<span class="merineo-toggle-slider" aria-hidden="true"></span>';
        echo '<span class="merineo-toggle-label">' . esc_html__( 'Paginate comments (including product reviews).', 'merineo-product-reviews-design' ) . '</span>';
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        // Comments per page.
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Comments per page', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<input type="number" min="1" step="1" name="comments_per_page" value="' . esc_attr( (string) $comments_per_page ) . '" />';
        echo '<p class="description">' . esc_html__( 'Number of top-level comments to display per page.', 'merineo-product-reviews-design' ) . '</p>';
        echo '</td>';
        echo '</tr>';

        // Default comments page.
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Default comments page', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<select name="default_comments_page">';
        echo '<option value="newest"' . selected( $default_comments_page, 'newest', false ) . '>' . esc_html__( 'Last page (newest comments)', 'merineo-product-reviews-design' ) . '</option>';
        echo '<option value="oldest"' . selected( $default_comments_page, 'oldest', false ) . '>' . esc_html__( 'First page (oldest comments)', 'merineo-product-reviews-design' ) . '</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';

        // Comment order on each page.
        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Comments on each page should be displayed with', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<select name="comment_order">';
        echo '<option value="desc"' . selected( $comment_order, 'desc', false ) . '>' . esc_html__( 'Newer comments at the top', 'merineo-product-reviews-design' ) . '</option>';
        echo '<option value="asc"' . selected( $comment_order, 'asc', false ) . '>' . esc_html__( 'Older comments at the top', 'merineo-product-reviews-design' ) . '</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';
        echo '</section>';

        // Colors.
        echo '<section class="merineo-prd-reviews-section">';
        echo '<h2>' . esc_html__( 'Colors', 'merineo-product-reviews-design' ) . '</h2>';
        echo '<p>' . esc_html__( 'If left empty, colors are derived from the active theme (theme.json) or sensible defaults.', 'merineo-product-reviews-design' ) . '</p>';
        echo '<table class="form-table merineo-prd-reviews-section__table" role="presentation">';

        $color_fields = array(
            'primary'    => __( 'Primary accent', 'merineo-product-reviews-design' ),
            'background' => __( 'Review background', 'merineo-product-reviews-design' ),
            'border'     => __( 'Border color', 'merineo-product-reviews-design' ),
            'text'       => __( 'Text color', 'merineo-product-reviews-design' ),
            'accent'     => __( 'Star / badge color', 'merineo-product-reviews-design' ),
        );

        foreach ( $color_fields as $key => $label ) {
            $value = isset( $effective_colors[ $key ] ) ? $effective_colors[ $key ] : '';
            echo '<tr>';
            echo '<th scope="row"><label for="color_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th>';
            echo '<td>';
            echo '<input type="text" class="merineo-color-field" id="color_' . esc_attr( $key ) . '" name="color_' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
        echo '</section>';

        // Images.
        echo '<section class="merineo-prd-reviews-section">';
        echo '<h2>' . esc_html__( 'Review images', 'merineo-product-reviews-design' ) . '</h2>';
        echo '<table class="form-table merineo-prd-reviews-section__table" role="presentation">';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Allow images in reviews', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<label class="merineo-toggle">';
        echo '<input type="checkbox" class="merineo-toggle-input" name="allow_images" value="1" ' . checked( $allow_images, true, false ) . ' />';
        echo '<span class="merineo-toggle-slider" aria-hidden="true"></span>';
        echo '<span class="merineo-toggle-label">' . esc_html__( 'Allow up to 3 images per review (jpg, jpeg, png, webp).', 'merineo-product-reviews-design' ) . '</span>';
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Allow images for guests', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<label class="merineo-toggle">';
        echo '<input type="checkbox" class="merineo-toggle-input" name="allow_images_guests" value="1" ' . checked( $allow_images_guests, true, false ) . ' />';
        echo '<span class="merineo-toggle-slider" aria-hidden="true"></span>';
        echo '<span class="merineo-toggle-label">' . esc_html__( 'Allow non-logged-in customers to upload images in reviews.', 'merineo-product-reviews-design' ) . '</span>';
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Require image approval', 'merineo-product-reviews-design' ) . '</th>';
        echo '<td>';
        echo '<label class="merineo-toggle">';
        echo '<input type="checkbox" class="merineo-toggle-input" name="require_image_approval" value="1" ' . checked( $require_image_approval, true, false ) . ' />';
        echo '<span class="merineo-toggle-slider" aria-hidden="true"></span>';
        echo '<span class="merineo-toggle-label">' . esc_html__( 'Images are hidden until explicitly approved in the comment edit screen.', 'merineo-product-reviews-design' ) . '</span>';
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
        echo '</section>';

        echo '<input type="hidden" name="merineo_prd_reviews_settings_submit" value="1" />';

        echo '<p class="submit">';
        submit_button( __( 'Save Changes', 'merineo-product-reviews-design' ), 'primary', 'submit', false );
        echo '</p>';

        echo '</form>';

        echo '</div>';

        echo '</div>';
    }

    /**
     * Resolve colors for admin UI: merge defaults, theme.json palette, and saved settings.
     *
     * @param array $colors Colors stored in settings (may be empty).
     *
     * @return array
     *
     * @see https://developer.wordpress.org/reference/functions/wp_get_global_settings/
     * @see https://developer.wordpress.org/reference/functions/wp_theme_has_theme_json/
     */
    private function resolve_admin_colors( array $colors ): array {
        $defaults = array(
            'primary'    => '#5070ff',
            'background' => '#ffffff',
            'border'     => '#e5e7eb',
            'text'       => '#111827',
            'accent'     => '#fbbf24',
        );

        // Drop empty values so they don't block theme.json / defaults.
        $colors = array_filter(
            $colors,
            static function ( $value ): bool {
                return is_string( $value ) && '' !== $value;
            }
        );

        // If nothing stored yet, try to derive from theme.json palette.
        if ( empty( $colors ) && function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json() ) {
            $palette_by_origin = wp_get_global_settings(
                array(
                    'color',
                    'palette',
                )
            );

            if ( is_array( $palette_by_origin ) && ! empty( $palette_by_origin ) ) {
                // Prefer theme → custom → default for admin (we want theme colors primarily).
                $origins = array( 'theme', 'custom', 'default' );
                $palette = array();

                foreach ( $origins as $origin ) {
                    if ( ! empty( $palette_by_origin[ $origin ] ) && is_array( $palette_by_origin[ $origin ] ) ) {
                        $palette = $palette_by_origin[ $origin ];
                        break;
                    }
                }

                if ( ! empty( $palette ) ) {
                    foreach ( $palette as $entry ) {
                        if ( ! is_array( $entry ) || empty( $entry['slug'] ) || empty( $entry['color'] ) ) {
                            continue;
                        }

                        $slug  = (string) $entry['slug'];
                        $color = (string) $entry['color'];

                        switch ( $slug ) {
                            case 'primary':
                                if ( empty( $colors['primary'] ) ) {
                                    $colors['primary'] = $color;
                                }
                                break;
                            case 'secondary':
                            case 'accent':
                                if ( empty( $colors['accent'] ) ) {
                                    $colors['accent'] = $color;
                                }
                                break;
                            case 'text-color':
                                if ( empty( $colors['text'] ) ) {
                                    $colors['text'] = $color;
                                }
                                break;
                        }
                    }
                }
            }
        }

        return array_merge( $defaults, $colors );
    }

    /**
     * Base palette: plugin defaults overridden by theme.json (if available).
     *
     * Used:
     * - pri prvom otvorení nastavení (options colors sú prázdne),
     * - ako fallback pri ukladaní, ak niektoré pole zostane prázdne.
     *
     * @return array{primary:string,background:string,border:string,text:string,accent:string}
     */
    private function get_base_palette(): array {
        // Plugin defaults.
        $base = array(
            'primary'    => '#5070ff',
            'background' => '#ffffff',
            'border'     => '#e5e7eb',
            'text'       => '#111827',
            'accent'     => '#fbbf24',
        );

        // Override from theme.json if available.
        if ( ! function_exists( 'wp_get_global_settings' ) ) {
            return $base;
        }

        // Fetch only the theme palette.
        $palette = wp_get_global_settings(
            array(
                'color',
                'palette',
            )
        );

        if ( ! is_array( $palette ) || empty( $palette['theme'] ) || ! is_array( $palette['theme'] ) ) {
            return $base;
        }

        // Apply theme palette entries.
        foreach ( $palette['theme'] as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['slug'] ) || empty( $entry['color'] ) ) {
                continue;
            }

            $slug  = (string) $entry['slug'];
            $color = sanitize_hex_color( (string) $entry['color'] );

            if ( ! $color ) {
                continue;
            }

            switch ( $slug ) {
                case 'primary':
                    $base['primary'] = $color;
                    break;
                case 'secondary':
                case 'accent':
                    $base['accent'] = $color;
                    break;
                case 'text-color':
                    $base['text'] = $color;
                    break;
            }
        }

        return $base;
    }

    /**
     * Registers the comment meta box for review images approval.
     *
     * @return void
     *
     * @see https://developer.wordpress.org/reference/hooks/add_meta_boxes_comment/
     */
    public function register_comment_images_meta_box(): void {
        add_meta_box(
            'merineo-prd-review-images',
            esc_html__( 'Merineo Review Images', 'merineo-product-reviews-design' ),
            [ $this, 'render_comment_images_meta_box' ],
            'comment',
            'normal',
            'default'
        );
    }

    /**
     * Renders the comment meta box content.
     *
     * @param WP_Comment $comment Comment object.
     *
     * @return void
     */
    public function render_comment_images_meta_box( WP_Comment $comment ): void {
        $comment_id = (int) $comment->comment_ID;
        $image_ids  = get_comment_meta( $comment_id, '_merineo_prd_review_image_ids', true );

        wp_nonce_field( 'merineo_prd_review_images_meta_box', 'merineo_prd_review_images_meta_box_nonce' );

        if ( empty( $image_ids ) || ! is_array( $image_ids ) ) {
            echo '<p>' . esc_html__( 'No review images attached to this comment.', 'merineo-product-reviews-design' ) . '</p>';
            return;
        }

        $approved_flag = (string) get_comment_meta( $comment_id, '_merineo_prd_review_images_approved', true );
        $is_approved   = ( 'yes' === $approved_flag );

        echo '<p>' . esc_html__( 'Attached review images:', 'merineo-product-reviews-design' ) . '</p>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';

        foreach ( $image_ids as $attachment_id ) {
            $attachment_id = (int) $attachment_id;
            if ( $attachment_id <= 0 ) {
                continue;
            }

            $thumb = wp_get_attachment_image( $attachment_id, 'thumbnail', false, [ 'style' => 'border:1px solid #ddd;border-radius:4px;' ] );
            if ( ! $thumb ) {
                continue;
            }

            echo '<div class="merineo-review-images-admin-item" style="text-align:center;">';
            echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<div>';
            echo '<label style="font-size:12px;">';
            echo '<input type="checkbox" name="merineo_prd_review_image_delete[]" value="' . esc_attr( (string) $attachment_id ) . '" />';
            echo ' ' . esc_html__( 'Remove', 'merineo-product-reviews-design' );
            echo '</label>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';

        echo '<p style="margin-top:1em;">';
        echo '<label>';
        echo '<input type="checkbox" name="merineo_prd_review_images_approved" value="1" ' . checked( $is_approved, true, false ) . ' />';
        echo ' ' . esc_html__( 'Approved (show images on the frontend)', 'merineo-product-reviews-design' );
        echo '</label>';
        echo '</p>';
    }

    /**
     * Saves the comment meta box state when the comment is updated.
     *
     * @param int $comment_id Comment ID.
     *
     * @return void
     */
    public function save_comment_images_meta_box( int $comment_id ): void {
        if ( ! isset( $_POST['merineo_prd_review_images_meta_box_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce(
            sanitize_key( $_POST['merineo_prd_review_images_meta_box_nonce'] ),
            'merineo_prd_review_images_meta_box'
        ) ) {
            return;
        }

        if ( ! current_user_can( 'moderate_comments', $comment_id ) ) {
            return;
        }

        // Load current image IDs.
        $image_ids = get_comment_meta( $comment_id, '_merineo_prd_review_image_ids', true );
        if ( ! is_array( $image_ids ) ) {
            $image_ids = array();
        }

        // Handle removals.
        $to_delete = array();
        if ( isset( $_POST['merineo_prd_review_image_delete'] ) && is_array( $_POST['merineo_prd_review_image_delete'] ) ) {
            foreach ( $_POST['merineo_prd_review_image_delete'] as $raw_id ) {
                $id = absint( $raw_id );
                if ( $id > 0 ) {
                    $to_delete[] = $id;
                }
            }
        }

        if ( ! empty( $to_delete ) ) {
            // Delete attachments physically and remove them from the list.
            foreach ( $to_delete as $attachment_id ) {
                if ( in_array( $attachment_id, $image_ids, true ) ) {
                    // Delete attachment and all generated sizes. See https://developer.wordpress.org/reference/functions/wp_delete_attachment/
                    wp_delete_attachment( $attachment_id, true );
                }
            }

            $image_ids = array_values(
                array_diff( array_map( 'absint', $image_ids ), $to_delete )
            );

            if ( empty( $image_ids ) ) {
                delete_comment_meta( $comment_id, '_merineo_prd_review_image_ids' );
            } else {
                update_comment_meta( $comment_id, '_merineo_prd_review_image_ids', $image_ids );
            }
        }

        // Save approval flag.
        $approved = isset( $_POST['merineo_prd_review_images_approved'] );
        update_comment_meta(
            $comment_id,
            '_merineo_prd_review_images_approved',
            $approved ? 'yes' : 'no'
        );
    }

    /**
     * Adds a "Review images" column to the comments list table.
     *
     * @param array<string,string> $columns Existing columns.
     * @return array<string,string>
     */
    public function add_review_images_column( array $columns ): array {
        $columns['merineo_review_images'] = esc_html__( 'Review images', 'merineo-product-reviews-design' );
        return $columns;
    }

    /**
     * Renders the "Review images" column content.
     *
     * @param string $column     Column name.
     * @param int    $comment_id Comment ID.
     *
     * @return void
     */
    public function render_review_images_column( string $column, int $comment_id ): void {
        if ( 'merineo_review_images' !== $column ) {
            return;
        }

        $image_ids = get_comment_meta( $comment_id, '_merineo_prd_review_image_ids', true );

        if ( empty( $image_ids ) || ! is_array( $image_ids ) ) {
            echo '&mdash;';
            return;
        }

        $approved_flag = (string) get_comment_meta( $comment_id, '_merineo_prd_review_images_approved', true );
        $is_approved   = ( 'yes' === $approved_flag );

        $status_label = $is_approved
            ? esc_html__( 'Approved', 'merineo-product-reviews-design' )
            : esc_html__( 'Pending images', 'merineo-product-reviews-design' );

        $status_class = $is_approved
            ? 'merineo-comment-images-status--approved'
            : 'merineo-comment-images-status--pending';

        echo '<div class="merineo-comment-images-column">';

        echo '<span class="merineo-comment-images-status ' . esc_attr( $status_class ) . '">';
        echo esc_html( $status_label );
        echo '</span>';

        echo '<div class="merineo-comment-images-thumbs">';

        $count = 0;
        foreach ( $image_ids as $attachment_id ) {
            $attachment_id = (int) $attachment_id;
            if ( $attachment_id <= 0 ) {
                continue;
            }

            $thumb = wp_get_attachment_image(
                $attachment_id,
                'thumbnail',
                false,
                array(
                    'class' => 'merineo-comment-images-thumb',
                )
            );

            if ( ! $thumb ) {
                continue;
            }

            // Show up to 3 thumbnails.
            if ( $count >= 3 ) {
                break;
            }

            echo '<span class="merineo-comment-images-thumb-wrapper">' . $thumb . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $count++;
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * Renders the "Review images" column content in the WooCommerce > Products > Reviews table.
     *
     * This reuses the same logic as for the core comments list table,
     * but the callback signature is different (Woo passes WP_Comment only).
     *
     * @param WP_Comment $item The review comment object.
     *
     * @return void
     */
    public function render_wc_review_images_table_column( WP_Comment $item ): void {
        $comment_id = (int) $item->comment_ID;

        // Reuse the existing renderer used by manage_comments_custom_column.
        $this->render_review_images_column( 'merineo_review_images', $comment_id );
    }
}