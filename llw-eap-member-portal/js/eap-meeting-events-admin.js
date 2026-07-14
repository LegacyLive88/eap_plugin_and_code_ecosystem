(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const rowsContainer = document.getElementById('eap-meeting-events-rows');
        const template = document.getElementById('eap-meeting-event-row-template');
        const addButton = document.getElementById('eap-add-event-row');

        if (!rowsContainer || !template || !addButton) {
            return;
        }

        function createRow(data = {}) {
            let row = null;

            if ('content' in template) {
                const fragment = template.content.cloneNode(true);
                row = fragment.querySelector('.eap-meeting-event-row');
            } else {
                const tempWrap = document.createElement('tbody');
                tempWrap.innerHTML = template.innerHTML.trim();
                row = tempWrap.querySelector('.eap-meeting-event-row');
            }

            if (!row) {
                return;
            }

            const fields = {
                name: row.querySelector('input[name="event_name[]"]'),
                link: row.querySelector('input[name="event_link[]"]'),
                start: row.querySelector('input[name="event_start_date[]"]'),
                end: row.querySelector('input[name="event_end_date[]"]'),
            };

            if (fields.name) {
                fields.name.value = data.name || '';
            }
            if (fields.link) {
                fields.link.value = data.link || '';
            }
            if (fields.start) {
                fields.start.value = data.start_date || '';
            }
            if (fields.end) {
                fields.end.value = data.end_date || '';
            }

            rowsContainer.appendChild(row);

            if (fields.name) {
                fields.name.focus();
            }
        }

        addButton.addEventListener('click', function (event) {
            event.preventDefault();
            createRow();
        });

        rowsContainer.addEventListener('click', function (event) {
            const removeButton = event.target.closest('.eap-remove-event');

            if (!removeButton) {
                return;
            }

            event.preventDefault();

            const row = removeButton.closest('.eap-meeting-event-row');

            if (row) {
                row.remove();
            }
        });
    });
})();
