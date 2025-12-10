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

/**
 * Ensure the WooCommerce review form supports file uploads.
 *
 * @since 1.0.0
 */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('commentform');
    if (!form) {
        return;
    }

    // form.enctype has a default value ("application/x-www-form-urlencoded")
    // even when the attribute is not present, so we check the attribute instead.
    if (form.getAttribute('enctype') !== 'multipart/form-data') {
        form.setAttribute('enctype', 'multipart/form-data');
    }
});

/**
 * Simple lightbox for review images.
 *
 * @since 1.0.0
 */
document.addEventListener('DOMContentLoaded', function () {
    var lightbox = document.getElementById('merineo-review-lightbox');
    if (!lightbox) {
        return;
    }

    var imgEl = lightbox.querySelector('.merineo-review-lightbox-image');
    var closeBtn = lightbox.querySelector('.merineo-review-lightbox-close');
    var prevBtn = lightbox.querySelector('.merineo-review-lightbox-prev');
    var nextBtn = lightbox.querySelector('.merineo-review-lightbox-next');
    var backdrop = lightbox.querySelector('.merineo-review-lightbox-backdrop');

    var currentGroup = [];
    var currentIndex = 0;

    /**
     * Open the lightbox with a given group of images.
     *
     * @param {Array<{src: string, alt: string}>} group
     * @param {number} index
     */
    function openLightbox(group, index) {
        if (!group || !group.length) {
            return;
        }

        currentGroup = group;
        currentIndex = index;

        updateImage();

        if (group.length === 1) {
            lightbox.classList.add('merineo-review-lightbox-single');
        } else {
            lightbox.classList.remove('merineo-review-lightbox-single');
        }

        lightbox.classList.add('is-open');
        lightbox.setAttribute('aria-hidden', 'false');

        if (closeBtn) {
            closeBtn.focus();
        }
    }

    /**
     * Close the lightbox.
     */
    function closeLightbox() {
        lightbox.classList.remove('is-open');
        lightbox.setAttribute('aria-hidden', 'true');
        currentGroup = [];
        currentIndex = 0;
    }

    /**
     * Update the displayed image based on currentIndex.
     */
    function updateImage() {
        if (!currentGroup.length || !imgEl) {
            return;
        }

        if (currentIndex < 0) {
            currentIndex = currentGroup.length - 1;
        } else if (currentIndex >= currentGroup.length) {
            currentIndex = 0;
        }

        var item = currentGroup[currentIndex];
        imgEl.src = item.src;
        imgEl.alt = item.alt || '';
    }

    /**
     * Show the previous image.
     */
    function showPrev() {
        if (!currentGroup.length) {
            return;
        }
        currentIndex -= 1;
        updateImage();
    }

    /**
     * Show the next image.
     */
    function showNext() {
        if (!currentGroup.length) {
            return;
        }
        currentIndex += 1;
        updateImage();
    }

    // Click handlers for navigation and closing.
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            closeLightbox();
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            closeLightbox();
        });
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            showPrev();
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            showNext();
        });
    }

    // Keyboard support: ESC to close, arrows to navigate.
    // Use capture phase + stopImmediatePropagation to prevent other handlers from reacting.
    document.addEventListener('keydown', function (event) {
        if (!lightbox.classList.contains('is-open')) {
            return;
        }

        var key = event.key || event.code;

        if (key === 'Escape' || key === 'Esc') {
            event.preventDefault();
            event.stopImmediatePropagation();
            closeLightbox();
        } else if (key === 'ArrowLeft') {
            event.preventDefault();
            event.stopImmediatePropagation();
            showPrev();
        } else if (key === 'ArrowRight') {
            event.preventDefault();
            event.stopImmediatePropagation();
            showNext();
        }
    }, true); // <- capture phase

    // Delegate click on thumbnails.
    document.addEventListener('click', function (event) {
        var link = event.target.closest('.merineo-review-image');
        if (!link) {
            return;
        }

        // Only intercept clicks inside review image grids.
        var grid = link.closest('.merineo-review-images-grid');
        if (!grid) {
            return;
        }

        event.preventDefault();

        var links = Array.prototype.slice.call(
            grid.querySelectorAll('.merineo-review-image')
        );

        if (!links.length) {
            return;
        }

        var group = links.map(function (a) {
            var img = a.querySelector('img');
            return {
                src: a.getAttribute('href'),
                alt: img ? img.getAttribute('alt') || '' : ''
            };
        });

        var clickedIndex = links.indexOf(link);
        if (clickedIndex < 0) {
            clickedIndex = 0;
        }

        openLightbox(group, clickedIndex);
    });
});