/**
 * Admin JS for Merineo Product Reviews Dizajn.
 *
 * Initializes native WordPress color picker on plugin settings page.
 *
 * @since 1.0.0
 */

/* global jQuery */
(function ($) {
    'use strict';

    $(function () {
        if ($.fn.wpColorPicker) {
            $('.merineo-color-field').wpColorPicker();
        }
    });
})(jQuery);