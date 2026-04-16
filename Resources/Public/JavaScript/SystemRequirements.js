document.addEventListener('DOMContentLoaded', function () {
    if (typeof bootstrap === 'undefined' || typeof bootstrap.Tooltip !== 'function') {
        return;
    }
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl, {
            boundary: 'window',
            customClass: 'tooltip-wide'
        });
    });
});
