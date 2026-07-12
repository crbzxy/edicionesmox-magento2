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
        // Un solo listener global (el mixin se evalúa una vez): el widget core
        // dispara ajax:addToCart solo en éxito, así que no hace falta suscribirse
        // por submit — eso acumulaba handlers cuando la petición fallaba.
        $(document).on('ajax:addToCart.edmoxFeedback', function (event, data) {
            if (data && data.response && data.response.backUrl) {
                return;
            }

            showToast($t('Product added to cart'));
        });

        return widget;
    };
});
