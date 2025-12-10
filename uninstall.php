<?php
/**
 * Uninstall logic.
 *
 * @package merineo-product-reviews-design
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Clean up options.
delete_option( 'merineo_prd_reviews_settings' );

// Comment meta is left intact by default to avoid destroying content.
// If you want a hard cleanup, you can query comments and delete
// 'merineo_review_images' & 'merineo_review_images_status' meta keys
// here using get_comments() + delete_comment_meta().