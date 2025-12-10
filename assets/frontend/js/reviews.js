/**
 * Toggle review form visibility on product pages.
 *
 * @since 1.0.0
 */

/* global document */
document.addEventListener('DOMContentLoaded', function () {
    var reviewForm = document.querySelector('#review_form');
    if (!reviewForm) {
        return;
    }

    // Only hide the form once JS is available – accessibility first.
    reviewForm.classList.add('merineo-review-form-collapsed');

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'merineo-review-form-toggle-btn';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.textContent = 'Pridať recenziu';

    var container = reviewForm.parentNode;
    if (container) {
        container.insertBefore(toggle, reviewForm);
    }

    toggle.addEventListener('click', function () {
        var isCollapsed = reviewForm.classList.contains('merineo-review-form-collapsed');
        if (isCollapsed) {
            reviewForm.classList.remove('merineo-review-form-collapsed');
            toggle.setAttribute('aria-expanded', 'true');
        } else {
            reviewForm.classList.add('merineo-review-form-collapsed');
            toggle.setAttribute('aria-expanded', 'false');
        }
    });
});