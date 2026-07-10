define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    var toastHideTimer = null;

    function getToastElement() {
        var toastElement = document.getElementById('edmox-add-to-cart-toast');

        if (!toastElement) {
            toastElement = document.createElement('div');
            toastElement.id = 'edmox-add-to-cart-toast';
            toastElement.className = 'edmox-toast';
            toastElement.setAttribute('role', 'status');
            toastElement.setAttribute('aria-live', 'polite');
            toastElement.setAttribute('aria-atomic', 'true');
            document.body.appendChild(toastElement);
        }

        return toastElement;
    }

    function showToast(message) {
        var toastElement = getToastElement();

        toastElement.textContent = message;
        toastElement.classList.add('edmox-toast--visible');

        if (toastHideTimer) {
            clearTimeout(toastHideTimer);
        }

        toastHideTimer = setTimeout(function () {
            toastElement.classList.remove('edmox-toast--visible');
        }, 3000);
    }

    return function (widget) {
        $.widget('mage.catalogAddToCart', widget, {
            ajaxSubmit: function (form) {
                var feedbackHandler = function (event, data) {
                    if (!data || !data.form || data.form[0] !== form[0]) {
                        return;
                    }

                    if (data.response && data.response.backUrl) {
                        $(document).off('ajax:addToCart', feedbackHandler);
                        return;
                    }

                    showToast($t('Product added to cart'));
                    $(document).off('ajax:addToCart', feedbackHandler);
                };

                $(document).on('ajax:addToCart', feedbackHandler);

                return this._super(form);
            }
        });

        return $.mage.catalogAddToCart;
    };
});
