(function () {
    const globalConfig = window.eapMeetingCalendar || {};
    const strings = Object.assign(
        {
            noEvents: 'No events scheduled yet.',
            viewEvent: 'View Event',
            listTitle: 'Upcoming Events',
            multipleEvents: '%d events',
            columnEvent: 'Event',
            columnDate: 'Date',
            columnAction: 'Action',
            modalTitle: 'Event details',
            close: 'Close',
        },
        globalConfig.strings || {}
    );

    const weekStartsOnRaw = Number(globalConfig.weekStartsOn);
    const weekStartsOn = Number.isFinite(weekStartsOnRaw)
        ? ((weekStartsOnRaw % 7) + 7) % 7
        : 1;

    const locale = document.documentElement.lang || navigator.language || 'en-US';

    const calendars = document.querySelectorAll('.eap-meeting-calendar');
    if (!calendars.length) {
        return;
    }

    calendars.forEach((calendar) => initCalendar(calendar));

    function initCalendar(container) {
        let rawEvents = [];
        try {
            rawEvents = JSON.parse(container.dataset.events || '[]');
        } catch (error) {
            rawEvents = [];
        }

        const events = rawEvents
            .map((event, index) => normalizeEvent(event, index))
            .filter(Boolean)
            .sort((a, b) => {
                if (a.startDate === b.startDate) {
                    return a.name.localeCompare(b.name);
                }
                return a.startDate.localeCompare(b.startDate);
            });

        const dateMap = buildDateMap(events);
        const today = new Date();
        const initialMonthDate = new Date(today.getFullYear(), today.getMonth(), 1);

        const state = {
            view: 'month',
            activeDate: initialMonthDate,
        };

        let hasFinishedLoading = false;

        const formatters = {
            monthYear: new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }),
            fullDate: new Intl.DateTimeFormat(locale, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }),
            shortDate: new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric' }),
            monthName: new Intl.DateTimeFormat(locale, { month: 'long' }),
            weekdayShort: new Intl.DateTimeFormat(locale, { weekday: 'short' }),
        };

        const weekdays = buildWeekdayLabels(formatters.weekdayShort, weekStartsOn);
        const todayKey = formatDateKey(today);
        let returnFocusTo = null;

        const elements = {
            label: container.querySelector('[data-role="range-label"]'),
            prev: container.querySelector('[data-action="prev"]'),
            next: container.querySelector('[data-action="next"]'),
            viewButtons: container.querySelectorAll('[data-calendar-view-toggle]'),
            views: {
                month: container.querySelector('[data-calendar-view="month"]'),
                year: container.querySelector('[data-calendar-view="year"]'),
                list: container.querySelector('[data-calendar-view="list"]'),
            },
            modal: container.querySelector('.eap-meeting-calendar__modal'),
            modalBody: container.querySelector('.eap-meeting-calendar__modal-events'),
            modalClose: container.querySelector('.eap-meeting-calendar__modal-close'),
            loading: container.querySelector('.eap-meeting-calendar__loading'),
        };

        elements.viewButtons.forEach((button) => {
            const view = button.getAttribute('data-calendar-view-toggle');
            button.addEventListener('click', () => {
                if (view === state.view) {
                    return;
                }

                state.view = view;

                elements.viewButtons.forEach((btn) => {
                    const isActive = btn === button;
                    btn.classList.toggle('is-active', isActive);
                    btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                render();
            });
        });

        elements.prev.addEventListener('click', () => shiftRange(-1));
        elements.next.addEventListener('click', () => shiftRange(1));

        container.addEventListener('click', (event) => {
            const closeTrigger = event.target.closest('[data-modal-close]');
            if (closeTrigger) {
                closeModal();
                return;
            }

            if (event.target.closest('.eap-meeting-calendar__day-event a')) {
                return;
            }

            const dayButton = event.target.closest('.eap-meeting-calendar__day');
            if (!dayButton) {
                return;
            }

            const isDisabled = dayButton.matches('[disabled]') || dayButton.getAttribute('aria-disabled') === 'true';
            if (isDisabled) {
                return;
            }

            const dateKey = dayButton.getAttribute('data-date');
            const dayEvents = dateMap.get(dateKey);
            if (!dayEvents || !dayEvents.length) {
                return;
            }

            const shouldOpenModal = dayButton.dataset.openModal === 'true';
            const linkTarget = dayButton.dataset.dayLink;

            if (shouldOpenModal) {
                returnFocusTo = dayButton;
                openModal(dateKey, dayEvents);
                return;
            }

            if (linkTarget) {
                openDayLink(linkTarget);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && elements.modal && !elements.modal.hasAttribute('hidden')) {
                closeModal();
            }
        });

        container.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            const dayButton = event.target.closest('.eap-meeting-calendar__day');
            if (!dayButton) {
                return;
            }

            const linkTarget = dayButton.dataset.dayLink;
            const shouldOpenModal = dayButton.dataset.openModal === 'true';

            if (shouldOpenModal || !linkTarget) {
                return;
            }

            event.preventDefault();
            openDayLink(linkTarget);
        });

        render();

        function shiftRange(direction) {
            if (state.view === 'list') {
                return;
            }

            if (state.view === 'month') {
                state.activeDate = new Date(state.activeDate.getFullYear(), state.activeDate.getMonth() + direction, 1);
            } else if (state.view === 'year') {
                state.activeDate = new Date(state.activeDate.getFullYear() + direction, state.activeDate.getMonth(), 1);
            }

            render();
        }

        function render() {
            if (state.view === 'month' && elements.views.month) {
                renderMonthView(elements.views.month);
            } else if (state.view === 'year' && elements.views.year) {
                renderYearView(elements.views.year);
            } else if (elements.views.list) {
                renderListView(elements.views.list);
            }

            Object.entries(elements.views).forEach(([viewKey, viewEl]) => {
                if (!viewEl) {
                    return;
                }
                const isActiveView = viewKey === state.view;
                viewEl.hidden = !isActiveView;
                viewEl.setAttribute('aria-hidden', isActiveView ? 'false' : 'true');
            });

            const navDisabled = state.view === 'list';
            if (elements.prev) {
                elements.prev.disabled = navDisabled;
            }
            if (elements.next) {
                elements.next.disabled = navDisabled;
            }

            if (elements.label) {
                elements.label.textContent = getRangeLabel();
            }

            finishLoading();
        }

        function getRangeLabel() {
            if (state.view === 'month') {
                return formatters.monthYear.format(state.activeDate);
            }

            if (state.view === 'year') {
                return state.activeDate.getFullYear().toString();
            }

            return strings.listTitle;
        }

        function renderMonthView(containerEl) {
            if (!containerEl) {
                return;
            }
            containerEl.innerHTML = '';
            containerEl.appendChild(buildWeekdaysRow(false));

            const matrix = buildMonthMatrix(state.activeDate, weekStartsOn);

            matrix.forEach((week) => {
                const row = document.createElement('div');
                row.className = 'eap-meeting-calendar__week';

                week.forEach((day) => {
                    row.appendChild(
                        createDayButton({
                            date: day.date,
                            isCurrentMonth: day.isCurrentMonth,
                            display: 'month',
                        })
                    );
                });

                containerEl.appendChild(row);
            });
        }

        function renderYearView(containerEl) {
            if (!containerEl) {
                return;
            }
            containerEl.innerHTML = '';

            const grid = document.createElement('div');
            grid.className = 'eap-meeting-calendar__year-grid';

            const targetYear = state.activeDate.getFullYear();

            for (let month = 0; month < 12; month += 1) {
                const monthWrapper = document.createElement('div');
                monthWrapper.className = 'eap-meeting-calendar__year-month';

                const title = document.createElement('div');
                title.className = 'eap-meeting-calendar__year-month-label';
                title.textContent = formatters.monthName.format(new Date(targetYear, month, 1));
                monthWrapper.appendChild(title);

                monthWrapper.appendChild(buildWeekdaysRow(true));

                const matrix = buildMonthMatrix(new Date(targetYear, month, 1), weekStartsOn);

                matrix.forEach((week) => {
                    const row = document.createElement('div');
                    row.className = 'eap-meeting-calendar__mini-week';

                    week.forEach((day) => {
                        const button = createDayButton({
                            date: day.date,
                            isCurrentMonth: day.isCurrentMonth,
                            display: 'mini',
                        });
                        row.appendChild(button);
                    });

                    monthWrapper.appendChild(row);
                });

                grid.appendChild(monthWrapper);
            }

            containerEl.appendChild(grid);
        }

        function renderListView(containerEl) {
            containerEl.innerHTML = '';

            if (!events.length) {
                const empty = document.createElement('p');
                empty.className = 'eap-meeting-calendar__empty';
                empty.textContent = strings.noEvents;
                containerEl.appendChild(empty);
                return;
            }

            const header = document.createElement('div');
            header.className = 'eap-meeting-calendar__list-header';
            [strings.columnEvent, strings.columnDate, strings.columnAction].forEach((label) => {
                const cell = document.createElement('div');
                cell.textContent = label;
                header.appendChild(cell);
            });
            containerEl.appendChild(header);

            events.forEach((event) => {
                const row = document.createElement('div');
                row.className = 'eap-meeting-calendar__list-row';

                const eventCell = document.createElement('div');
                const title = document.createElement('p');
                title.className = 'eap-meeting-calendar__list-title';
                title.textContent = event.name;
                const meta = document.createElement('p');
                meta.className = 'eap-meeting-calendar__list-meta';
                meta.textContent = formatters.fullDate.format(parseDateString(event.startDate));
                eventCell.appendChild(title);
                eventCell.appendChild(meta);

                const dateCell = document.createElement('div');
                dateCell.className = 'eap-meeting-calendar__list-date';
                dateCell.textContent = formatEventRange(event);

                const actionCell = document.createElement('div');
                actionCell.className = 'eap-meeting-calendar__list-action';

                if (event.link) {
                    const link = document.createElement('a');
                    link.className = 'button button-secondary';
                    link.href = event.link;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.textContent = strings.viewEvent;
                    actionCell.appendChild(link);
                } else {
                    const disabled = document.createElement('button');
                    disabled.className = 'button';
                    disabled.type = 'button';
                    disabled.textContent = strings.viewEvent;
                    disabled.disabled = true;
                    actionCell.appendChild(disabled);
                }

                row.appendChild(eventCell);
                row.appendChild(dateCell);
                row.appendChild(actionCell);
                containerEl.appendChild(row);
            });
        }

        function buildWeekdaysRow(compact) {
            const wrapper = document.createElement('div');
            wrapper.className = compact
                ? 'eap-meeting-calendar__mini-weekdays'
                : 'eap-meeting-calendar__weekdays';

            weekdays.forEach((label) => {
                const cell = document.createElement('div');
                cell.textContent = compact ? label.charAt(0) : label;
                wrapper.appendChild(cell);
            });

            return wrapper;
        }

        function createDayButton(options) {
            const { date, isCurrentMonth, display } = options;
            const isMini = display === 'mini';
            const dateKey = formatDateKey(date);
            const dayEvents = dateMap.get(dateKey) || [];
            const count = dayEvents.length;
            const elementTag = isMini ? 'button' : 'div';
            const dayElement = document.createElement(elementTag);

            dayElement.className = 'eap-meeting-calendar__day';
            dayElement.dataset.date = dateKey;

            if (isMini) {
                dayElement.type = 'button';
                dayElement.classList.add('eap-meeting-calendar__mini-day');
                dayElement.dataset.openModal = 'true';
            } else {
                dayElement.classList.add('eap-meeting-calendar__day--month');
                dayElement.dataset.openModal = 'false';
                if (count === 1 && dayEvents[0].link) {
                    dayElement.dataset.dayLink = dayEvents[0].link;
                }
                if (count) {
                    dayElement.setAttribute('role', 'button');
                    dayElement.tabIndex = 0;
                } else {
                    dayElement.setAttribute('aria-disabled', 'true');
                    dayElement.tabIndex = -1;
                }
            }

            if (!isCurrentMonth) {
                dayElement.classList.add('is-outside-month');
                if (isMini) {
                    dayElement.classList.add('is-placeholder');
                }
            }

            if (dateKey === todayKey) {
                dayElement.classList.add('is-today');
            }

            if (!count) {
                dayElement.setAttribute('aria-label', formatters.fullDate.format(date));
            } else {
                dayElement.classList.add('has-event');
                const label = count === 1 ? dayEvents[0].name : strings.multipleEvents.replace('%d', count);
                dayElement.setAttribute('data-event-label', label);
                const ariaLabel = `${formatters.fullDate.format(date)}: ${dayEvents
                    .map((event) => event.name)
                    .join(', ')}`;
                dayElement.setAttribute('aria-label', ariaLabel);
            }

            const header = document.createElement('div');
            header.className = 'eap-meeting-calendar__day-header';

            const number = document.createElement('span');
            number.className = 'eap-meeting-calendar__day-number';
            number.textContent = date.getDate();
            header.appendChild(number);

            if (count) {
                const countBadge = document.createElement('span');
                countBadge.className = 'eap-meeting-calendar__day-count';
                countBadge.textContent = count;
                header.appendChild(countBadge);
            }

            dayElement.appendChild(header);

            if (isMini && !count) {
                dayElement.disabled = true;
            }

            if (!isMini && count) {
                const list = document.createElement('ul');
                list.className = 'eap-meeting-calendar__day-events';

                dayEvents.forEach((event) => {
                    const item = document.createElement('li');
                    item.className = 'eap-meeting-calendar__day-event';

                    if (event.link) {
                        const link = document.createElement('a');
                        link.href = event.link;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        link.textContent = event.name;
                        link.addEventListener('click', (evt) => evt.stopPropagation());
                        item.appendChild(link);
                    } else {
                        item.textContent = event.name;
                    }

                    list.appendChild(item);
                });

                dayElement.appendChild(list);
            }

            return dayElement;
        }

        function openModal(dateKey, dayEvents) {
            if (!elements.modal || !elements.modalBody) {
                return;
            }

            elements.modalBody.innerHTML = '';

            dayEvents.forEach((event) => {
                const card = document.createElement('div');
                card.className = 'eap-meeting-calendar__modal-card';

                const title = document.createElement('h4');
                title.textContent = event.name;

                const range = document.createElement('p');
                range.textContent = formatEventRange(event);

                const actions = document.createElement('div');
                actions.className = 'eap-meeting-calendar__modal-actions';

                if (event.link) {
                    const link = document.createElement('a');
                    link.href = event.link;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.className = 'button';
                    link.textContent = strings.viewEvent;
                    actions.appendChild(link);
                } else {
                    const disabled = document.createElement('button');
                    disabled.type = 'button';
                    disabled.className = 'button';
                    disabled.textContent = strings.viewEvent;
                    disabled.disabled = true;
                    actions.appendChild(disabled);
                }

                card.appendChild(title);
                card.appendChild(range);
                card.appendChild(actions);
                elements.modalBody.appendChild(card);
            });

            elements.modal.removeAttribute('hidden');
            elements.modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('eap-meeting-calendar-modal-open');

            if (elements.modalClose) {
                elements.modalClose.focus();
            }
        }

        function closeModal() {
            if (!elements.modal) {
                return;
            }

            elements.modal.setAttribute('hidden', '');
            elements.modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('eap-meeting-calendar-modal-open');

            if (returnFocusTo) {
                returnFocusTo.focus();
            }
            returnFocusTo = null;
        }

        function openDayLink(url) {
            if (!url) {
                return;
            }
            const newWindow = window.open(url, '_blank');
            if (newWindow) {
                newWindow.opener = null;
            } else {
                window.location.href = url;
            }
        }

        function formatEventRange(event) {
            if (event.startDate === event.endDate) {
                return formatters.fullDate.format(parseDateString(event.startDate));
            }

            const start = formatters.fullDate.format(parseDateString(event.startDate));
            const end = formatters.fullDate.format(parseDateString(event.endDate));
            return `${start} – ${end}`;
        }

        function finishLoading() {
            if (hasFinishedLoading) {
                return;
            }
            hasFinishedLoading = true;
            container.classList.remove('is-loading');
            container.classList.add('is-ready');
            if (elements.loading) {
                elements.loading.setAttribute('aria-hidden', 'true');
                elements.loading.setAttribute('aria-busy', 'false');
            }
        }
    }

    function normalizeEvent(event, index) {
        if (!event || !event.name || !event.start_date) {
            return null;
        }

        const start = parseDateString(event.start_date);
        if (!start) {
            return null;
        }

        const end = event.end_date ? parseDateString(event.end_date) : null;
        const resolvedEnd = end && end >= start ? end : start;

        return {
            id: event.id || `event-${index}`,
            name: event.name,
            link: event.link || '',
            start,
            end: resolvedEnd,
            startDate: formatDateKey(start),
            endDate: formatDateKey(resolvedEnd),
        };
    }

    function parseDateString(value) {
        if (!value) {
            return null;
        }
        const parts = value.split('-').map((part) => parseInt(part, 10));
        if (parts.length !== 3 || parts.some(Number.isNaN)) {
            return null;
        }
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function formatDateKey(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function buildDateMap(events) {
        const map = new Map();
        events.forEach((event) => {
            const cursor = new Date(event.start);
            while (cursor <= event.end) {
                const key = formatDateKey(cursor);
                if (!map.has(key)) {
                    map.set(key, []);
                }
                map.get(key).push(event);
                cursor.setDate(cursor.getDate() + 1);
            }
        });
        return map;
    }

    function buildWeekdayLabels(formatter, startOfWeek) {
        const labels = [];
        const base = new Date(Date.UTC(2021, 5, 6)); // Sunday
        for (let i = 0; i < 7; i += 1) {
            const date = new Date(base);
            const offset = (startOfWeek + i) % 7;
            date.setUTCDate(base.getUTCDate() + offset);
            labels.push(formatter.format(date));
        }
        return labels;
    }

    function buildMonthMatrix(referenceDate, startOfWeek) {
        const year = referenceDate.getFullYear();
        const month = referenceDate.getMonth();
        const firstOfMonth = new Date(year, month, 1);
        const matrix = [];

        const leadingDays = (firstOfMonth.getDay() - startOfWeek + 7) % 7;
        const gridStart = new Date(firstOfMonth);
        gridStart.setDate(firstOfMonth.getDate() - leadingDays);

        for (let week = 0; week < 6; week += 1) {
            const row = [];
            for (let day = 0; day < 7; day += 1) {
                const cellDate = new Date(gridStart);
                cellDate.setDate(gridStart.getDate() + week * 7 + day);
                row.push({
                    date: cellDate,
                    isCurrentMonth: cellDate.getMonth() === month,
                });
            }
            matrix.push(row);
        }

        return matrix;
    }

})();