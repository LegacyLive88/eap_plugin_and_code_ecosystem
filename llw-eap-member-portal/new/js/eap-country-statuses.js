(function () {
    function init() {
        var settings = window.eapCountryStatuses || null;

        if (!settings) {
            return;
        }

        var manager = document.getElementById('eap-country-status-manager');
        var template = document.getElementById('eap-country-status-row-template');

        if (!manager || !template) {
            return;
        }

        var tableBody = manager.querySelector('.eap-country-status-rows');
        var addButton = manager.querySelector('.eap-country-status-add');
        var messages = manager.querySelector('.eap-country-status-messages');
        var emptyClass = 'no-country-status-rows';
        var saveTimeout = null;
        var isSaving = false;

        if (!tableBody) {
            return;
        }

        var strings = Object.assign({
            duplicate: 'Each country can only be assigned once.',
            saving: 'Saving changes...',
            saved: 'All changes saved.',
            error: 'Unable to save changes. Please try again.',
            empty: 'No countries have been assigned yet. Click "Add Country" to create the first mapping.'
        }, settings.strings || {});

        function createNotice(className, text) {
            var notice = document.createElement('div');
            notice.className = 'notice notice-' + className;
            var paragraph = document.createElement('p');
            paragraph.textContent = text;
            notice.appendChild(paragraph);
            return notice;
        }

        function showMessage(text, type) {
            if (!messages) {
                return;
            }

            var variant = type || 'info';
            messages.innerHTML = '';
            messages.appendChild(createNotice(variant, text));
        }

        function clearMessage() {
            if (messages) {
                messages.innerHTML = '';
            }
        }

        function getRealRows() {
            return Array.from(tableBody.querySelectorAll('tr')).filter(function (row) {
                return !row.classList.contains(emptyClass);
            });
        }

        function ensureEmptyState() {
            var hasRealRows = getRealRows().length > 0;
            var placeholder = tableBody.querySelector('.' + emptyClass);

            if (!hasRealRows && !placeholder) {
                var row = document.createElement('tr');
                row.className = emptyClass;
                var cell = document.createElement('td');
                cell.colSpan = 3;
                cell.textContent = strings.empty;
                row.appendChild(cell);
                tableBody.appendChild(row);
            } else if (hasRealRows && placeholder) {
                placeholder.remove();
            }
        }

        function markDuplicates() {
            var rows = getRealRows();
            var seen = new Map();
            var hasDuplicate = false;

            rows.forEach(function (row) {
                row.classList.remove('has-duplicate');
            });

            rows.forEach(function (row) {
                var countrySelect = row.querySelector('.eap-country-status-country');
                if (!countrySelect) {
                    return;
                }

                var value = countrySelect.value;
                if (!value) {
                    return;
                }

                if (seen.has(value)) {
                    hasDuplicate = true;
                    row.classList.add('has-duplicate');
                    var existingRow = seen.get(value);
                    if (existingRow) {
                        existingRow.classList.add('has-duplicate');
                    }
                } else {
                    seen.set(value, row);
                }
            });

            if (hasDuplicate) {
                showMessage(strings.duplicate, 'error');
            } else if (!isSaving) {
                clearMessage();
            }

            return !hasDuplicate;
        }

        function buildPayload() {
            var payload = [];

            getRealRows().forEach(function (row) {
                var countrySelect = row.querySelector('.eap-country-status-country');
                var statusSelect = row.querySelector('.eap-country-status-status');
                var countryId = countrySelect ? parseInt(countrySelect.value, 10) : 0;
                var status = statusSelect ? statusSelect.value : '';

                if (countryId && status) {
                    payload.push({
                        countryId: countryId,
                        status: status
                    });
                }
            });

            return payload;
        }

        function saveRows() {
            if (!markDuplicates()) {
                return;
            }

            var rows = buildPayload();
            isSaving = true;
            showMessage(strings.saving, 'info');

            var formData = new window.FormData();
            formData.append('action', 'eap_save_country_statuses');
            formData.append('nonce', settings.nonce);
            formData.append('rows', JSON.stringify(rows));

            window.fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (!data || !data.success) {
                        var errorMessage = (data && data.data && data.data.message) ? data.data.message : strings.error;
                        throw new Error(errorMessage);
                    }
                    showMessage(strings.saved, 'success');
                })
                .catch(function (error) {
                    showMessage(error.message || strings.error, 'error');
                })
                .finally(function () {
                    isSaving = false;
                });
        }

        function scheduleSave() {
            window.clearTimeout(saveTimeout);
            saveTimeout = window.setTimeout(saveRows, 500);
        }

        function handleSelectChange() {
            if (markDuplicates()) {
                scheduleSave();
            }
        }

        function handleRemove(event) {
            event.preventDefault();
            var row = event.currentTarget.closest('tr');
            if (!row) {
                return;
            }

            row.remove();
            ensureEmptyState();
            if (markDuplicates()) {
                scheduleSave();
            }
        }

        function attachRowEvents(row) {
            row.querySelectorAll('select').forEach(function (select) {
                select.addEventListener('change', handleSelectChange);
            });

            var removeButton = row.querySelector('.eap-country-status-remove');
            if (removeButton) {
                removeButton.addEventListener('click', handleRemove);
            }
        }

        function addRow() {
            var fragment = template.content.cloneNode(true);
            var row = fragment.querySelector('tr');
            if (!row) {
                return;
            }

            tableBody.appendChild(row);
            ensureEmptyState();
            attachRowEvents(row);

            var countrySelect = row.querySelector('.eap-country-status-country');
            if (countrySelect) {
                countrySelect.focus();
            }
        }

        getRealRows().forEach(attachRowEvents);
        ensureEmptyState();
        markDuplicates();

        if (addButton) {
            addButton.addEventListener('click', function (event) {
                event.preventDefault();
                var placeholder = tableBody.querySelector('.' + emptyClass);
                if (placeholder) {
                    placeholder.remove();
                }
                addRow();
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();