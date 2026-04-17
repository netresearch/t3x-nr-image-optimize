(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        var form = target.closest('form[data-confirm]');
        if (form === null) {
            return;
        }

        var message = form.getAttribute('data-confirm') || '';
        if (message !== '' && !window.confirm(message)) {
            event.preventDefault();
        }
    });
})();
