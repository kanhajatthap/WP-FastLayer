(function () {
    'use strict';

    function clearPlaceholder(picture) {
        picture.classList.add('wpfl-loaded');
    }

    function handleImage(picture, img) {
        if (img.complete && img.naturalWidth > 0) {
            clearPlaceholder(picture);
            return;
        }

        img.addEventListener('load', function () {
            clearPlaceholder(picture);
        }, { once: true });

        img.addEventListener('error', function () {
            // Image failed — still remove blur so it doesn't get stuck forever.
            clearPlaceholder(picture);
        }, { once: true });
    }

    function init() {
        var pictures = document.querySelectorAll('picture[data-wp-fastlayer-lqip]');
        pictures.forEach(function (picture) {
            var img = picture.querySelector('img');
            if (img) {
                handleImage(picture, img);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();