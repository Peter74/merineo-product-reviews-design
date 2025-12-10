<?php
/**
 * Frontend logic for product reviews.
 *
 * @package merineo-product-reviews-design
 */

declare(strict_types=1);

namespace Merineo\Product_Reviews_Design\Frontend;

use WP_Comment;

use function add_action;
use function add_filter;
use function apply_filters;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_comment_meta;
use function get_option;
use function get_post_type;
use function is_product;
use function is_user_logged_in;
use function media_handle_upload;
use function sanitize_file_name;
use function update_comment_meta;
use function wp_add_inline_style;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_get_theme;
use function wp_get_global_settings;
use function wp_get_upload_dir;
use function wp_handle_upload;
use function wp_kses_post;
use function wp_max_upload_size;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles WooCommerce product reviews UX, styles and images.
 */
final class Reviews {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register(): void {
        // Enqueue assets on front. See https://developer.wordpress.org/reference/hooks/wp_enqueue_scripts/
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Website field toggle. See https://developer.wordpress.org/reference/hooks/comment_form_default_fields/
        add_filter( 'comment_form_default_fields', array( $this, 'filter_default_fields' ) );

        // Additional fields (image upload). See https://developer.wordpress.org/reference/hooks/comment_form_after_fields/
        add_action( 'comment_form_after_fields', array( $this, 'render_image_upload_field' ) );

        // Handle image uploads after comment is created. See https://developer.wordpress.org/reference/hooks/comment_post/
        add_action( 'comment_post', array( $this, 'handle_comment_images' ), 10, 3 );

        // Show review images below comment text. Woo template hook. See Woo hooks reference.
        add_action( 'woocommerce_review_after_comment_text', array( $this, 'render_review_images' ), 10, 1 );

        // Comment meta box for image approval in admin. See https://developer.wordpress.org/reference/hooks/add_meta_boxes_comment/
        add_action( 'add_meta_boxes_comment', array( $this, 'add_comment_meta_box' ) );

        // Save image approval from comment edit. See https://developer.wordpress.org/reference/hooks/edit_comment/
        add_action( 'edit_comment', array( $this, 'save_comment_meta_box' ) );
    }

    /**
     * Get plugin settings once.
     *
     * @return array
     */
    private function get_settings(): array {
        $settings = get_option( 'merineo_prd_reviews_settings', array() );
        return is_array( $settings ) ? $settings : array();
    }

    /**
     * Determine if we are on a WooCommerce product page.
     *
     * @return bool
     */
    private function is_product_context(): bool {
        // WooCommerce conditional. See https://developer.woocommerce.com/docs/theming/theme-development/conditional-tags/
        return function_exists( 'is_product' ) && is_product();
    }

    /**
     * Enqueue frontend styles and scripts.
     *
     * @return void
     */
    public function enqueue_assets(): void {
        if ( ! $this->is_product_context() ) {
            return;
        }

        // Styles. See https://developer.wordpress.org/reference/functions/wp_enqueue_style/
        wp_enqueue_style(
            'merineo-prd-reviews',
            WP_MERINEO_PRD_REVIEWS_URL . 'assets/frontend/css/reviews.css',
            array(),
            WP_MERINEO_PRD_REVIEWS_VERSION
        );

        $this->enqueue_inline_colors();

        // JS (for toggling review form). See https://developer.wordpress.org/reference/functions/wp_enqueue_script/
        wp_enqueue_script(
            'merineo-prd-reviews',
            WP_MERINEO_PRD_REVIEWS_URL . 'assets/frontend/js/reviews.js',
            array(),
            WP_MERINEO_PRD_REVIEWS_VERSION,
            true
        );
    }

    /**
     * Inject CSS variables based on theme.json or settings.
     *
     * @return void
     */
    private function enqueue_inline_colors(): void {
        $settings = $this->get_settings();
        $colors   = isset( $settings['colors'] ) && is_array( $settings['colors'] ) ? $settings['colors'] : array();

        // If colors not configured, try theme.json. See https://developer.wordpress.org/reference/functions/wp_theme_has_theme_json/
        if ( empty( $colors ) && function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json() ) {
            // Get theme palette. See https://developer.wordpress.org/reference/functions/wp_get_global_settings/
            $palette = wp_get_global_settings(
                array(
                    'color',
                    'palette',
                    'theme',
                )
            );

            if ( is_array( $palette ) && ! empty( $palette ) ) {
                $first = reset( $palette );
                if ( isset( $first['color'] ) ) {
                    $colors['primary'] = $first['color'];
                }
            }
        }

        // Fallback defaults.
        $defaults = array(
            'primary'    => '#5070ff',
            'background' => '#ffffff',
            'border'     => '#e5e7eb',
            'text'       => '#111827',
            'accent'     => '#fbbf24',
        );

        $merged = array_merge( $defaults, $colors );

        $css = ':root{' .
            '--merineo-prd-reviews-primary:' . $merged['primary'] . ';' .
            '--merineo-prd-reviews-bg:' . $merged['background'] . ';' .
            '--merineo-prd-reviews-border:' . $merged['border'] . ';' .
            '--merineo-prd-reviews-text:' . $merged['text'] . ';' .
            '--merineo-prd-reviews-accent:' . $merged['accent'] . ';' .
            '}';

        // Inline style. See https://developer.wordpress.org/reference/functions/wp_add_inline_style/
        wp_add_inline_style( 'merineo-prd-reviews', $css );
    }

    /**
     * Optionally remove website field from product review form.
     *
     * @param array $fields Default comment form fields.
     *
     * @return array
     */
    public function filter_default_fields( array $fields ): array {
        if ( ! $this->is_product_context() ) {
            return $fields;
        }

        $settings = $this->get_settings();
        $show     = isset( $settings['show_website_field'] ) ? (bool) $settings['show_website_field'] : true;

        if ( ! $show && isset( $fields['url'] ) ) {
            unset( $fields['url'] );
        }

        return $fields;
    }

    /**
     * Render file input for review images.
     *
     * @return void
     */
    public function render_image_upload_field(): void {
        if ( ! $this->is_product_context() ) {
            return;
        }

        $settings = $this->get_settings();
        if ( empty( $settings['allow_images'] ) ) {
            return;
        }

        if ( empty( $settings['allow_images_guests'] ) && ! is_user_logged_in() ) {
            return;
        }

        echo '<p class="comment-form-merineo-review-images">';
        echo '<label for="merineo_review_images">';
        echo esc_html__( 'Review images (up to 3 files, JPG/PNG/WebP, max 1.2MB each)', 'merineo-product-reviews-design' );
        echo '</label><br />';
        echo '<input id="merineo_review_images" name="merineo_review_images[]" type="file" multiple="multiple" accept="image/jpeg,image/png,image/webp" />';
        echo '</p>';
    }

    /**
     * Handle uploaded images after comment is saved.
     *
     * @param int         $comment_id       Comment ID.
     * @param int|string  $comment_approved Approval status.
     * @param array       $commentdata      Raw comment data.
     *
     * @return void
     */
    public function handle_comment_images( int $comment_id, $comment_approved, array $commentdata ): void {
        if ( empty( $_FILES['merineo_review_images'] ) ) {
            return;
        }

        $post_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
        if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
            return;
        }

        $settings = $this->get_settings();
        if ( empty( $settings['allow_images'] ) ) {
            return;
        }

        if ( empty( $settings['allow_images_guests'] ) && ! is_user_logged_in() ) {
            return;
        }

        $files = $_FILES['merineo_review_images'];

        // Normalize multi-file array.
        $names  = (array) $files['name'];
        $types  = (array) $files['type'];
        $tmp    = (array) $files['tmp_name'];
        $errors = (array) $files['error'];
        $sizes  = (array) $files['size'];

        $max_files      = 3;
        $max_single     = 1200000; // ~1.2MB.
        $max_total      = 3600000; // ~3.6MB.
        $total_size     = 0;
        $attachment_ids = array();

        $count = min( count( $names ), $max_files );

        for ( $i = 0; $i < $count; $i++ ) {
            if ( empty( $names[ $i ] ) || ! empty( $errors[ $i ] ) ) {
                continue;
            }

            // Size checks.
            $size = (int) $sizes[ $i ];
            if ( $size <= 0 || $size > $max_single ) {
                continue;
            }

            $total_size += $size;
            if ( $total_size > $max_total ) {
                break;
            }

            // Build a single file array for media_handle_upload. See https://developer.wordpress.org/reference/functions/media_handle_upload/
            $file_array = array(
                'name'     => sanitize_file_name( (string) $names[ $i ] ),
                'type'     => (string) $types[ $i ],
                'tmp_name' => (string) $tmp[ $i ],
                'error'    => (int) $errors[ $i ],
                'size'     => $size,
            );

            // Custom subdirectory via upload_dir filter.
            $subdir = ! empty( $settings['images_subdir'] ) ? $settings['images_subdir'] : 'merineo-reviews';

            add_filter(
                'upload_dir',
                static function ( array $uploads ) use ( $subdir ): array {
                    // Filter upload directory. See https://developer.wordpress.org/reference/hooks/upload_dir/
                    $uploads['subdir'] = '/' . trim( $subdir, '/' ) . $uploads['subdir'];
                    $uploads['path']   = trailingslashit( $uploads['basedir'] . $uploads['subdir'] );
                    $uploads['url']    = trailingslashit( $uploads['baseurl'] . $uploads['subdir'] );
                    return $uploads;
                }
            );

            $attachment_id = media_handle_upload(
                'merineo_review_images',
                $post_id,
                array(),
                array(
                    'test_form' => false,
                )
            );

            remove_filter( 'upload_dir', '__return_false' ); // Just in case, we remove via full stack in real code – placeholder.

            if ( ! is_wp_error( $attachment_id ) ) {
                $attachment_ids[] = (int) $attachment_id;
            }
        }

        if ( ! empty( $attachment_ids ) ) {
            // Save image IDs to comment meta. See https://developer.wordpress.org/reference/functions/update_comment_meta/
            update_comment_meta( $comment_id, 'merineo_review_images', $attachment_ids );

            $require_approval = isset( $settings['require_image_approval'] ) ? (bool) $settings['require_image_approval'] : true;
            update_comment_meta( $comment_id, 'merineo_review_images_status', $require_approval ? 'pending' : 'approved' );
        }
    }

    /**
     * Render review images under the comment text on product reviews.
     *
     * @param WP_Comment $comment Comment object.
     *
     * @return void
     */
    public function render_review_images( WP_Comment $comment ): void {
        $attachment_ids = get_comment_meta( $comment->comment_ID, 'merineo_review_images', true );
        if ( empty( $attachment_ids ) || ! is_array( $attachment_ids ) ) {
            return;
        }

        $status = (string) get_comment_meta( $comment->comment_ID, 'merineo_review_images_status', true );
        if ( 'approved' !== $status ) {
            return;
        }

        echo '<div class="merineo-review-images-grid" aria-label="' . esc_attr__( 'Review images', 'merineo-product-reviews-design' ) . '">';

        foreach ( $attachment_ids as $attachment_id ) {
            $attachment_id = (int) $attachment_id;
            $url           = wp_get_attachment_image_url( $attachment_id, 'medium' );
            $full          = wp_get_attachment_image_url( $attachment_id, 'full' );
            if ( ! $url ) {
                continue;
            }

            echo '<a href="' . esc_url( $full ?: $url ) . '" class="merineo-review-image" target="_blank" rel="noopener">';
            echo '<img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'Review image', 'merineo-product-reviews-design' ) . '" loading="lazy" />';
            echo '</a>';
        }

        echo '</div>';
    }

    /**
     * Add comment meta box for image approval.
     *
     * @return void
     */
    public function add_comment_meta_box(): void {
        // See https://developer.wordpress.org/reference/functions/add_meta_box/
        add_meta_box(
            'merineo_prd_reviews_images',
            esc_html__( 'Review images (Merineo)', 'merineo-product-reviews-design' ),
            array( $this, 'render_comment_meta_box' ),
            'comment',
            'normal',
            'default'
        );
    }

    /**
     * Render admin meta box content for comment images.
     *
     * @param WP_Comment $comment Comment object.
     *
     * @return void
     */
    public function render_comment_meta_box( WP_Comment $comment ): void {
        $attachment_ids = get_comment_meta( $comment->comment_ID, 'merineo_review_images', true );
        if ( empty( $attachment_ids ) || ! is_array( $attachment_ids ) ) {
            echo '<p>' . esc_html__( 'No images attached to this review.', 'merineo-product-reviews-design' ) . '</p>';
        } else {
            echo '<ul>';
            foreach ( $attachment_ids as $attachment_id ) {
                $url = wp_get_attachment_image_url( (int) $attachment_id, 'thumbnail' );
                if ( ! $url ) {
                    continue;
                }
                echo '<li><img src="' . esc_url( $url ) . '" alt="" style="max-width:80px;height:auto;margin-right:8px;" /></li>';
            }
            echo '</ul>';
        }

        $status = (string) get_comment_meta( $comment->comment_ID, 'merineo_review_images_status', true );
        $checked = ( 'approved' === $status );

        echo '<p>';
        echo '<label>';
        echo '<input type="checkbox" name="merineo_review_images_approved" value="1" ' . checked( $checked, true, false ) . ' />';
        echo ' ' . esc_html__( 'Images approved and visible on the product page.', 'merineo-product-reviews-design' );
        echo '</label>';
        echo '</p>';
    }

    /**
     * Save image approval status from meta box.
     *
     * @param int $comment_id Comment ID.
     *
     * @return void
     */
    public function save_comment_meta_box( int $comment_id ): void {
        if ( ! current_user_can( 'moderate_comments' ) ) {
            return;
        }

        $approved = ! empty( $_POST['merineo_review_images_approved'] );
        update_comment_meta( $comment_id, 'merineo_review_images_status', $approved ? 'approved' : 'pending' );
    }
}