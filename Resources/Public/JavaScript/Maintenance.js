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

    function setText(id, text) {
        var el = document.getElementById(id);
        if (el !== null) {
            el.textContent = text;
        }
    }

    function buildInfoRow(colspan, text) {
        var tr = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = colspan;
        td.className = 'text-center text-muted py-3';
        td.textContent = text;
        tr.appendChild(td);
        return tr;
    }

    function renderFileTypes(tbody, fileTypes, emptyText) {
        tbody.textContent = '';
        var extensions = Object.keys(fileTypes || {});

        if (extensions.length === 0) {
            tbody.appendChild(buildInfoRow(3, emptyText));
            return;
        }

        extensions.forEach(function (ext) {
            var typeData = fileTypes[ext];
            var tr = document.createElement('tr');

            var extCell = document.createElement('td');
            extCell.className = 'ps-3';
            var badge = document.createElement('span');
            badge.className = 'badge badge-secondary';
            badge.textContent = '.' + ext;
            extCell.appendChild(badge);

            var countCell = document.createElement('td');
            countCell.className = 'text-end';
            countCell.textContent = String(typeData.count);

            var sizeCell = document.createElement('td');
            sizeCell.className = 'text-end pe-3';
            var strong = document.createElement('strong');
            strong.textContent = typeData.sizeHuman;
            sizeCell.appendChild(strong);

            tr.appendChild(extCell);
            tr.appendChild(countCell);
            tr.appendChild(sizeCell);
            tbody.appendChild(tr);
        });
    }

    function renderLargestFiles(tbody, largestFiles, emptyText) {
        tbody.textContent = '';

        if (!Array.isArray(largestFiles) || largestFiles.length === 0) {
            tbody.appendChild(buildInfoRow(2, emptyText));
            return;
        }

        largestFiles.forEach(function (file) {
            var tr = document.createElement('tr');

            var pathCell = document.createElement('td');
            pathCell.className = 'ps-3';
            var span = document.createElement('span');
            span.className = 'text-break small';
            span.textContent = file.path;
            pathCell.appendChild(span);

            var sizeCell = document.createElement('td');
            sizeCell.className = 'text-end pe-3 text-nowrap';
            var strong = document.createElement('strong');
            strong.textContent = file.sizeHuman;
            sizeCell.appendChild(strong);

            tr.appendChild(pathCell);
            tr.appendChild(sizeCell);
            tbody.appendChild(tr);
        });
    }

    function renderStorageInfo(container, data, config) {
        container.textContent = '';

        if (!data.oldestFile || !data.newestFile) {
            container.className = 'text-center text-muted py-3';
            var p = document.createElement('p');
            p.className = 'mb-0';
            p.textContent = config.noFilesText;
            container.appendChild(p);
            return;
        }

        container.className = '';

        [
            [config.oldestLabel, data.oldestFile, true],
            [config.newestLabel, data.newestFile, false],
        ].forEach(function (entry) {
            var label = entry[0];
            var file = entry[1];
            var isFirst = entry[2];

            var wrapper = document.createElement('div');
            wrapper.className = isFirst ? 'mb-2' : '';

            var labelEl = document.createElement('label');
            labelEl.className = 'form-label text-muted small mb-1';
            labelEl.textContent = label;

            var nameEl = document.createElement('p');
            nameEl.className = 'mb-0 small text-break';
            var strong = document.createElement('strong');
            strong.textContent = file.name;
            nameEl.appendChild(strong);

            var dateEl = document.createElement('p');
            dateEl.className = 'text-muted small mb-0';
            dateEl.textContent = file.date;

            wrapper.appendChild(labelEl);
            wrapper.appendChild(nameEl);
            wrapper.appendChild(dateEl);
            container.appendChild(wrapper);
        });
    }

    function showStatisticsError(config) {
        setText('stat-file-count', config.loadErrorText);
        setText('stat-total-size', config.loadErrorText);
        setText('stat-directory-count', config.loadErrorText);

        var storageInfo = document.getElementById('stat-storage-info');
        if (storageInfo !== null) {
            storageInfo.className = 'text-center text-muted py-3';
            storageInfo.textContent = config.loadErrorText;
        }

        var fileTypesBody = document.getElementById('stat-file-types-body');
        if (fileTypesBody !== null) {
            fileTypesBody.textContent = '';
            fileTypesBody.appendChild(buildInfoRow(3, config.loadErrorText));
        }

        var largestFilesBody = document.getElementById('stat-largest-files-body');
        if (largestFilesBody !== null) {
            largestFilesBody.textContent = '';
            largestFilesBody.appendChild(buildInfoRow(2, config.loadErrorText));
        }
    }

    function loadStatistics() {
        var container = document.getElementById('maintenance-stats');
        if (container === null) {
            return;
        }

        var url = container.getAttribute('data-statistics-url') || '';
        var config = {
            loadErrorText: container.getAttribute('data-load-error-text') || '',
            noFilesText: container.getAttribute('data-no-files-text') || '',
            noFilesAvailableText: container.getAttribute('data-no-files-available-text') || '',
            oldestLabel: container.getAttribute('data-oldest-label') || '',
            newestLabel: container.getAttribute('data-newest-label') || '',
        };

        if (url === '') {
            return;
        }

        fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        }).then(function (data) {
            setText('stat-file-count', String(data.fileCount));
            setText('stat-total-size', data.totalSizeHuman);
            setText('stat-directory-count', String(data.directoryCount));

            var storageInfo = document.getElementById('stat-storage-info');
            if (storageInfo !== null) {
                renderStorageInfo(storageInfo, data, config);
            }

            var fileTypesBody = document.getElementById('stat-file-types-body');
            if (fileTypesBody !== null) {
                renderFileTypes(fileTypesBody, data.fileTypes, config.noFilesAvailableText);
            }

            var largestFilesBody = document.getElementById('stat-largest-files-body');
            if (largestFilesBody !== null) {
                renderLargestFiles(largestFilesBody, data.largestFiles, config.noFilesAvailableText);
            }
        }).catch(function () {
            showStatisticsError(config);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadStatistics);
    } else {
        loadStatistics();
    }
})();
