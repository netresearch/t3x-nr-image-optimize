// SPDX-License-Identifier: GPL-3.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

document.addEventListener('DOMContentLoaded', function () {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl, {
            boundary: 'window',
            customClass: 'tooltip-wide'
        });
    });
});
