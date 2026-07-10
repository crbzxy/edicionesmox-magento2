define([
    'jquery'
], function ($) {
    'use strict';

    return function (config, element) {
        var $header = $(element);
        var $toggle = $header.find('[data-action="toggle-nav"]');
        var ariaLabel = config.ariaLabel || 'Toggle main navigation';

        if (!$toggle.length) {
            return;
        }

        $toggle.attr({
            'aria-label': ariaLabel,
            'aria-expanded': 'false',
            'aria-controls': 'store.menu'
        });

        $toggle.on('click', function () {
            window.setTimeout(function () {
                var isNavOpen = $('html').hasClass('nav-open');

                $toggle.attr('aria-expanded', isNavOpen ? 'true' : 'false');
            }, 0);
        });

        $(document).on('keydown.edmoxNavigation', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            if (!$('html').hasClass('nav-open')) {
                return;
            }

            $toggle.trigger('click');
        });
    };
});
