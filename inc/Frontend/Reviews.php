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
use function wp_theme_has_theme_json;
use function get_current_screen;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles WooCommerce product reviews UX, styles and images.
 */
final class Reviews {

    /**
     * Maximum allowed size per image (1.2 MB in bytes).
     */
    private const MAX_IMAGE_SIZE_SINGLE = 1258291;

    /**
     * Maximum allowed total size of all images (3.6 MB in bytes).
     */
    private const MAX_IMAGE_SIZE_TOTAL  = 3774873;

    /**
     * Internal flag to know when we intentionally override upload_dir.
     *
     * @var bool
     */
    private bool $use_custom_upload_dir = false;

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

        /**
         * Handle uploaded review images after the comment is created.
         *
         * @see https://developer.wordpress.org/reference/hooks/comment_post/
         */
        add_action( 'comment_post', array( $this, 'handle_comment_images_upload' ), 10, 3 );

        /**
         * Render review images below the review content on WooCommerce product pages.
         *
         * @see https://woocommerce.com/wc-apidocs/hook-docs.html (woocommerce_review_after_comment_text)
         */
        add_action(
            'woocommerce_review_after_comment_text',
            array( $this, 'render_review_images' ),
            10,
            1
        );

        // Render file input for review images in the comment form.
        // See https://developer.wordpress.org/reference/hooks/comment_form_after_fields/
        add_action( 'comment_form_after_fields', array( $this, 'render_image_upload_field' ) );
        add_action( 'comment_form_logged_in_after', array( $this, 'render_image_upload_field' ) );

        /**
         * Render lightbox markup in the footer on product pages.
         *
         * @see https://developer.wordpress.org/reference/hooks/wp_footer/
         */
        add_action( 'wp_footer', array( $this, 'render_lightbox_markup' ) );
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
     * Ensure WordPress media functions are loaded before handling uploads.
     *
     * @return void
     *
     * @see https://developer.wordpress.org/reference/functions/media_handle_upload/
     */
    private function ensure_media_functions_loaded(): void {
        if ( function_exists( 'media_handle_upload' ) ) {
            return;
        }

        // These files are needed for media_handle_upload() outside of the admin.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
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
     * Inject CSS variables based on settings and base palette.
     *
     * Order:
     * - if colors are saved in options they are used (as overrides),
     * - otherwise the base palette is used (theme.json + plugin defaults).
     *
     * @return void
     */
    private function enqueue_inline_colors(): void {
        $settings      = $this->get_settings();
        $stored_colors = isset( $settings['colors'] ) && is_array( $settings['colors'] ) ? $settings['colors'] : array();
        $base_palette  = $this->get_base_palette();

        if ( empty( $stored_colors ) ) {
            // No stored colors → use base palette (theme.json/fallback).
            $effective = $base_palette;
        } else {
            // Stored colors are the source of truth; base palette only fills missing keys.
            $effective = array_merge( $base_palette, $stored_colors );
        }

        $css = ':root{' .
            '--merineo-prd-reviews-primary:' . $effective['primary'] . ';' .
            '--merineo-prd-reviews-bg:' . $effective['background'] . ';' .
            '--merineo-prd-reviews-border:' . $effective['border'] . ';' .
            '--merineo-prd-reviews-text:' . $effective['text'] . ';' .
            '--merineo-prd-reviews-accent:' . $effective['accent'] . ';' .
            '}';

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

    /**
     * Base palette: plugin defaults overridden by theme.json (if available).
     *
     * @return array{primary:string,background:string,border:string,text:string,accent:string}
     */
    private function get_base_palette(): array {
        $base = array(
            'primary'    => '#5070ff',
            'background' => '#ffffff',
            'border'     => '#e5e7eb',
            'text'       => '#111827',
            'accent'     => '#fbbf24',
        );

        if ( ! function_exists( 'wp_get_global_settings' ) ) {
            return $base;
        }

        $palette = wp_get_global_settings(
            array(
                'color',
                'palette',
            )
        );

        if ( ! is_array( $palette ) || empty( $palette['theme'] ) || ! is_array( $palette['theme'] ) ) {
            return $base;
        }

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
     * Adds the file input field for review images to the comment form on product pages.
     *
     * @param array<string,string> $fields Comment form fields including 'comment'.
     * @return array<string,string>
     */
    public function filter_comment_form_fields( array $fields ): array {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return $fields;
        }

        $settings = $this->get_settings(); // predpokladám existujúcu metódu.

        $allow_images = ! empty( $settings['allow_images'] );
        $allow_guests = ! empty( $settings['allow_images_guests'] );

        if ( ! $allow_images ) {
            return $fields;
        }

        if ( ! is_user_logged_in() && ! $allow_guests ) {
            return $fields;
        }

        // Limit notice text – môžeš si ho prispôsobiť / preložiť cez i18n.
        $max_files_text = esc_html__( 'You can upload up to 3 images (JPG, PNG, WEBP, max 1.2 MB each).', 'merineo-product-reviews-design' );

        $field_html  = '<p class="comment-form-merineo-review-images">';
        $field_html .= '<label for="merineo-review-images">';
        $field_html .= esc_html__( 'Review images', 'merineo-product-reviews-design' );
        $field_html .= '</label> ';
        $field_html .= sprintf(
            '<input id="merineo-review-images" name="merineo_review_images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple="multiple" />'
        );
        $field_html .= '<span class="description">' . $max_files_text . '</span>';
        $field_html .= '</p>';

        // Pridáme pole pred submit, ale po textarea "comment".
        // 'comment' je vždy v poli, takže si ho vytiahneme a vložíme naspäť.
        $comment_field = $fields['comment'] ?? '';
        unset( $fields['comment'] );

        $new_fields              = [];
        $new_fields['comment']   = $comment_field;
        $new_fields['merineo_review_images'] = $field_html;

        // Zvyšok pôvodných polí.
        foreach ( $fields as $key => $field ) {
            $new_fields[ $key ] = $field;
        }

        return $new_fields;
    }

    /**
     * Handles uploaded review images when a comment is created.
     *
     * @param int         $comment_id       Comment ID.
     * @param int|string  $comment_approved Approval status.
     * @param array       $commentdata      Raw comment data.
     *
     * @return void
     *
     * @see https://developer.wordpress.org/reference/hooks/comment_post/
     * @see https://developer.wordpress.org/reference/functions/media_handle_upload/
     */
    public function handle_comment_images_upload( int $comment_id, $comment_approved, array $commentdata ): void {
        if ( empty( $_FILES['merineo_review_images'] ) ) {
            return;
        }

        // Only for product reviews.
        $post_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
        if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
            return;
        }

        $settings = $this->get_settings();

        $allow_images = ! empty( $settings['allow_images'] );
        $allow_guests = ! empty( $settings['allow_images_guests'] );

        if ( ! $allow_images ) {
            return;
        }

        if ( ! is_user_logged_in() && ! $allow_guests ) {
            return;
        }

        // Make sure media functions are available on the frontend.
        $this->ensure_media_functions_loaded();

        $files       = $this->normalize_uploaded_files( $_FILES['merineo_review_images'] );
        $max_files   = 3;
        $total_size  = 0;
        $image_ids   = [];
        $file_index  = 0;

        // Only allow specific mime types.
        $allowed_mimes = [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'webp'     => 'image/webp',
        ];

        foreach ( $files as $single_file ) {
            if ( $file_index >= $max_files ) {
                break;
            }

            if ( ! isset( $single_file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $single_file['error'] ) {
                continue;
            }

            if ( UPLOAD_ERR_OK !== (int) $single_file['error'] ) {
                continue;
            }

            if ( empty( $single_file['size'] ) || empty( $single_file['tmp_name'] ) ) {
                continue;
            }

            $file_size = (int) $single_file['size'];

            if ( $file_size > self::MAX_IMAGE_SIZE_SINGLE ) {
                continue;
            }

            $total_size += $file_size;
            if ( $total_size > self::MAX_IMAGE_SIZE_TOTAL ) {
                break;
            }

            // Perform a quick filetype check before delegating to media_handle_upload().
            $checked = wp_check_filetype_and_ext(
                $single_file['tmp_name'],
                $single_file['name'],
                $allowed_mimes
            );

            /** @see https://developer.wordpress.org/reference/functions/wp_check_filetype_and_ext/ */
            if ( ! $checked['ext'] || ! $checked['type'] ) {
                continue;
            }

            // Temporarily register this single file under its own key for media_handle_upload().
            $temp_key                  = 'merineo_review_image_' . $file_index;
            $_FILES[ $temp_key ]       = $single_file;
            $this->use_custom_upload_dir = true;

            /**
             * Override upload directory only during this upload.
             *
             * @see https://developer.wordpress.org/reference/hooks/upload_dir/
             */
            add_filter( 'upload_dir', [ $this, 'filter_upload_dir_for_review_images' ] );

            $attachment_id = media_handle_upload(
                $temp_key,
                $post_id,
                [],
                [
                    'test_form' => false,
                    'mimes'     => $allowed_mimes,
                ]
            );

            remove_filter( 'upload_dir', [ $this, 'filter_upload_dir_for_review_images' ] );
            $this->use_custom_upload_dir = false;
            unset( $_FILES[ $temp_key ] );

            if ( is_wp_error( $attachment_id ) ) {
                continue;
            }

            $image_ids[] = (int) $attachment_id;
            $file_index++;
        }

        if ( empty( $image_ids ) ) {
            return;
        }

        // Store attachment IDs as a single serialized array.
        add_comment_meta( $comment_id, '_merineo_prd_review_image_ids', array_map( 'absint', $image_ids ), true );

        $require_approval = ! empty( $settings['require_image_approval'] );

        // If approval is required, mark as not approved yet.
        if ( $require_approval ) {
            add_comment_meta( $comment_id, '_merineo_prd_review_images_approved', 'no', true );
        } else {
            add_comment_meta( $comment_id, '_merineo_prd_review_images_approved', 'yes', true );
        }
    }

    /**
     * Normalizes the $_FILES array for a multi-file upload into a flat array.
     *
     * @param array<string,mixed> $files Raw $_FILES['field'] array.
     * @return array<int,array<string,mixed>>
     */
    private function normalize_uploaded_files( array $files ): array {
        $normalized = [];

        if ( ! isset( $files['name'] ) || ! is_array( $files['name'] ) ) {
            return $normalized;
        }

        foreach ( $files['name'] as $index => $name ) {
            if ( '' === (string) $name ) {
                continue;
            }

            $normalized[ $index ] = [
                'name'     => $files['name'][ $index ] ?? '',
                'type'     => $files['type'][ $index ] ?? '',
                'tmp_name' => $files['tmp_name'][ $index ] ?? '',
                'error'    => $files['error'][ $index ] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $files['size'][ $index ] ?? 0,
            ];
        }

        return $normalized;
    }

    /**
     * Filters the upload directory so review images are stored in a dedicated subdirectory.
     *
     * @param array<string,mixed> $dirs Upload directory data.
     * @return array<string,mixed>
     *
     * @see https://developer.wordpress.org/reference/hooks/upload_dir/
     * @see https://developer.wordpress.org/reference/functions/wp_upload_dir/
     */
    public function filter_upload_dir_for_review_images( array $dirs ): array {
        if ( ! $this->use_custom_upload_dir ) {
            return $dirs;
        }

        $settings   = $this->get_settings();
        $subdir_raw = ! empty( $settings['images_subdir'] ) ? (string) $settings['images_subdir'] : 'merineo-reviews';

        // Build safe subdirectory name, e.g. "/merineo-reviews".
        $safe_slug = \sanitize_title_with_dashes( $subdir_raw );
        $subdir    = '/' . trim( $safe_slug, '/' );

        // Override only subdir, and recompute path/url based on existing basedir/baseurl.
        $dirs['subdir'] = $subdir;
        $dirs['path']   = rtrim( (string) $dirs['basedir'], '/' ) . $dirs['subdir'];
        $dirs['url']    = rtrim( (string) $dirs['baseurl'], '/' ) . $dirs['subdir'];

        return $dirs;
    }

    /**
     * Outputs the review images grid below the review text on product pages.
     *
     * @param \WP_Comment $comment Comment object.
     *
     * @return void
     *
     * @see https://woocommerce.com/wc-apidocs/hook-docs.html (woocommerce_review_after_comment_text)
     * @see https://developer.wordpress.org/reference/functions/wp_get_attachment_image_url/
     * @see https://developer.wordpress.org/reference/functions/wp_get_attachment_image/
     */
    public function render_review_images( $comment ): void {
        if ( ! $comment instanceof \WP_Comment ) {
            return;
        }

        $comment_id = (int) $comment->comment_ID;
        $image_ids  = get_comment_meta( $comment_id, '_merineo_prd_review_image_ids', true );

        if ( empty( $image_ids ) || ! is_array( $image_ids ) ) {
            return;
        }

        $settings         = $this->get_settings();
        $require_approval = ! empty( $settings['require_image_approval'] );
        $approved_flag    = (string) get_comment_meta( $comment_id, '_merineo_prd_review_images_approved', true );

        if ( $require_approval && 'yes' !== $approved_flag ) {
            // Images exist but are not approved yet.
            return;
        }

        echo '<div class="merineo-review-images-grid" aria-label="' . esc_attr__( 'Review images', 'merineo-product-reviews-design' ) . '">';

        $index = 0;

        foreach ( $image_ids as $attachment_id ) {
            $attachment_id = (int) $attachment_id;
            if ( $attachment_id <= 0 ) {
                continue;
            }

            $full_url = wp_get_attachment_image_url( $attachment_id, 'large' );
            if ( ! $full_url ) {
                continue;
            }

            // Thumbnail HTML.
            $thumb = wp_get_attachment_image(
                $attachment_id,
                'thumbnail',
                false,
                array(
                    'class' => 'merineo-review-image-img',
                )
            );

            if ( ! $thumb ) {
                continue;
            }

            $index_attr    = (string) $index;
            $comment_attr  = (string) $comment_id;

            echo '<a class="merineo-review-image" href="' . esc_url( $full_url ) . '" data-merineo-review-comment="' . esc_attr( $comment_attr ) . '" data-merineo-review-index="' . esc_attr( $index_attr ) . '">';
            echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</a>';

            $index++;
        }

        echo '</div>';
    }

    /**
     * Render a simple lightbox container for review images.
     *
     * @return void
     */
    public function render_lightbox_markup(): void {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }

        echo '<div id="merineo-review-lightbox" class="merineo-review-lightbox" aria-hidden="true" role="dialog" aria-modal="true">';
        echo '  <div class="merineo-review-lightbox-backdrop"></div>';
        echo '  <div class="merineo-review-lightbox-inner" role="document">';
        echo '      <button type="button" class="merineo-review-lightbox-close" aria-label="' . esc_attr__( 'Close', 'merineo-product-reviews-design' ) . '">&times;</button>';
        echo '      <button type="button" class="merineo-review-lightbox-nav merineo-review-lightbox-prev" aria-label="' . esc_attr__( 'Previous image', 'merineo-product-reviews-design' ) . '">&#10094;</button>';
        echo '      <div class="merineo-review-lightbox-image-wrapper">';
        echo '          <img class="merineo-review-lightbox-image" src="" alt="" />';
        echo '      </div>';
        echo '      <button type="button" class="merineo-review-lightbox-nav merineo-review-lightbox-next" aria-label="' . esc_attr__( 'Next image', 'merineo-product-reviews-design' ) . '">&#10095;</button>';
        echo '  </div>';
        echo '</div>';
    }
}