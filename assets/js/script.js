document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;

    const burgerBtn = document.getElementById('burgerBtn');
    const mainNav = document.getElementById('mainNav');

    function closeMenu() {
        if (!burgerBtn || !mainNav) {
            return;
        }

        burgerBtn.classList.remove('is-active');
        mainNav.classList.remove('is-open');
        body.classList.remove('menu-open');
    }

    if (burgerBtn && mainNav) {
        burgerBtn.addEventListener('click', function () {
            burgerBtn.classList.toggle('is-active');
            mainNav.classList.toggle('is-open');
            body.classList.toggle('menu-open');
        });

        mainNav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', closeMenu);
        });

        document.addEventListener('click', function (event) {
            const clickInsideMenu = mainNav.contains(event.target);
            const clickOnBurger = burgerBtn.contains(event.target);

            if (!clickInsideMenu && !clickOnBurger) {
                closeMenu();
            }
        });
    }

    const toast = document.getElementById('toast');
    let toastTimer = null;

    function showToast(message) {
        if (!toast || !message) {
            return;
        }

        clearTimeout(toastTimer);

        toast.textContent = message;
        toast.classList.add('is-visible');

        toastTimer = setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 3500);
    }

    window.showToast = showToast;

    const bonusHelpBtn = document.getElementById('bonusHelpBtn');
    const bonusModal = document.getElementById('bonusModal');

    function openBonusModal() {
        if (!bonusModal) {
            return;
        }

        bonusModal.classList.add('is-open');
        bonusModal.setAttribute('aria-hidden', 'false');
        body.classList.add('modal-open');
    }

    function closeBonusModal() {
        if (!bonusModal) {
            return;
        }

        bonusModal.classList.remove('is-open');
        bonusModal.setAttribute('aria-hidden', 'true');
        body.classList.remove('modal-open');
    }

    if (bonusHelpBtn && bonusModal) {
        bonusHelpBtn.addEventListener('click', openBonusModal);

        bonusModal.querySelectorAll('[data-close-modal]').forEach(function (element) {
            element.addEventListener('click', closeBonusModal);
        });
    }

    const faqItems = document.querySelectorAll('.faq-list details');

    faqItems.forEach(function (item) {
        item.addEventListener('toggle', function () {
            if (item.open) {
                faqItems.forEach(function (otherItem) {
                    if (otherItem !== item) {
                        otherItem.removeAttribute('open');
                    }
                });
            }
        });
    });

    function formatDuration(minutes) {
        const value = Number(minutes || 0);

        if (value < 60) {
            return value + ' мин.';
        }

        const hours = Math.floor(value / 60);
        const rest = value % 60;

        if (rest === 0) {
            return hours + ' ч.';
        }

        return hours + ' ч. ' + rest + ' мин.';
    }

    function setZoneMessage(zone, message) {
        if (!zone) {
            return;
        }

        zone.innerHTML = '<p class="muted-text">' + message + '</p>';
    }

    function parseJsonResponse(text, zone, fallbackMessage) {
        try {
            return JSON.parse(text);
        } catch (error) {
            console.error('Ответ сервера не JSON:', text);
            setZoneMessage(zone, fallbackMessage);
            return null;
        }
    }

    function enhanceServiceSelect(select) {
        if (!select || select.dataset.enhanced === '1') {
            return null;
        }

        select.dataset.enhanced = '1';
        select.classList.add('native-enhanced-select');

        const wrapper = document.createElement('div');
        wrapper.className = 'smart-select';

        const control = document.createElement('div');
        control.className = 'smart-select__control';

        const search = document.createElement('input');
        search.type = 'text';
        search.className = 'smart-select__search';
        search.placeholder = 'Начните писать название услуги';

        const arrow = document.createElement('span');
        arrow.className = 'smart-select__arrow';
        arrow.textContent = '⌄';

        const dropdown = document.createElement('div');
        dropdown.className = 'smart-select__dropdown';

        const list = document.createElement('div');
        list.className = 'smart-select__list';

        dropdown.appendChild(list);
        control.appendChild(search);
        control.appendChild(arrow);
        wrapper.appendChild(control);
        wrapper.appendChild(dropdown);

        select.insertAdjacentElement('afterend', wrapper);

        const options = Array.from(select.options)
            .filter(function (option) {
                return option.value !== '';
            })
            .map(function (option) {
                return {
                    value: option.value,
                    text: option.textContent.trim(),
                    price: option.dataset.price || '',
                    duration: option.dataset.duration || ''
                };
            });

        function setSearchText() {
            const selected = select.options[select.selectedIndex];

            if (selected && selected.value) {
                search.value = selected.textContent.trim();
            } else {
                search.value = '';
            }
        }

        function renderOptions(query) {
            list.innerHTML = '';

            const normalizedQuery = String(query || '').toLowerCase().trim();

            const filtered = options.filter(function (option) {
                return option.text.toLowerCase().includes(normalizedQuery);
            });

            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'smart-select__empty';
                empty.textContent = 'Услуга не найдена';
                list.appendChild(empty);
                return;
            }

            filtered.forEach(function (option) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'smart-select__option';

                if (String(select.value) === String(option.value)) {
                    button.classList.add('is-selected');
                }

                const title = document.createElement('strong');
                title.textContent = option.text;

                const meta = document.createElement('span');

                if (option.price || option.duration) {
                    const priceText = option.price
                        ? Number(option.price).toLocaleString('ru-RU') + ' ₽'
                        : 'стоимость не указана';

                    const durationText = option.duration
                        ? formatDuration(option.duration)
                        : 'время не указано';

                    meta.textContent = priceText + ' · ' + durationText;
                } else {
                    meta.textContent = 'Стоимость и длительность подтянутся автоматически';
                }

                button.appendChild(title);
                button.appendChild(meta);

                button.addEventListener('click', function () {
                    select.value = option.value;
                    setSearchText();
                    wrapper.classList.remove('is-open');
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                });

                list.appendChild(button);
            });
        }

        control.addEventListener('click', function () {
            wrapper.classList.add('is-open');
            search.focus();
            renderOptions(search.value);
        });

        search.addEventListener('input', function () {
            wrapper.classList.add('is-open');
            renderOptions(search.value);
        });

        search.addEventListener('focus', function () {
            wrapper.classList.add('is-open');
            renderOptions(search.value);
        });

        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) {
                wrapper.classList.remove('is-open');
                setSearchText();
            }
        });

        setSearchText();
        renderOptions('');

        return {
            wrapper: wrapper,
            search: search,
            refresh: function () {
                setSearchText();
                renderOptions(search.value);
            }
        };
    }

    function enhanceDatePicker(input) {
        if (!input || input.dataset.enhanced === '1') {
            return null;
        }

        input.dataset.enhanced = '1';
        input.classList.add('native-date-hidden');

        const serviceSelect = document.getElementById('service_id');

        const monthNames = [
            'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
            'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'
        ];

        const weekDays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

        const wrapper = document.createElement('div');
        wrapper.className = 'smart-date';

        const control = document.createElement('button');
        control.type = 'button';
        control.className = 'smart-date__control';

        const text = document.createElement('span');
        text.className = 'smart-date__text';
        text.textContent = 'Выберите дату';

        const icon = document.createElement('span');
        icon.className = 'smart-date__icon';
        icon.textContent = '▣';

        const dropdown = document.createElement('div');
        dropdown.className = 'smart-date__dropdown';

        control.appendChild(text);
        control.appendChild(icon);
        wrapper.appendChild(control);
        wrapper.appendChild(dropdown);

        input.insertAdjacentElement('afterend', wrapper);

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const minDate = input.min ? new Date(input.min + 'T00:00:00') : today;

        let viewDate = input.value
            ? new Date(input.value + 'T00:00:00')
            : new Date();

        let availableDates = new Set();
        let isLoadingDates = false;
        let lastServiceId = '';

        function pad(value) {
            return String(value).padStart(2, '0');
        }

        function toISO(date) {
            return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
        }

        function formatRu(date) {
            return pad(date.getDate()) + '.' + pad(date.getMonth() + 1) + '.' + date.getFullYear();
        }

        function isSameDay(a, b) {
            return a.getFullYear() === b.getFullYear()
                && a.getMonth() === b.getMonth()
                && a.getDate() === b.getDate();
        }

        function getServiceId() {
            return serviceSelect ? serviceSelect.value : '';
        }

        function updateText() {
            if (input.value) {
                text.textContent = formatRu(new Date(input.value + 'T00:00:00'));
            } else {
                text.textContent = 'Выберите дату';
            }
        }

        function clearDate(dispatchChange = true) {
            input.value = '';
            updateText();

            if (dispatchChange) {
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        function loadAvailableDates() {
            const serviceId = getServiceId();

            availableDates = new Set();
            lastServiceId = serviceId;

            if (!serviceId) {
                renderCalendar();
                return Promise.resolve();
            }

            isLoadingDates = true;
            renderCalendar();

            return fetch('get-available-dates.php?service_id=' + encodeURIComponent(serviceId) + '&days=60')
                .then(function (response) {
                    return response.text();
                })
                .then(function (textResponse) {
                    let data;

                    try {
                        data = JSON.parse(textResponse);
                    } catch (error) {
                        console.error('Ответ сервера не JSON:', textResponse);
                        availableDates = new Set();
                        return;
                    }

                    if (!data.success || !data.dates) {
                        availableDates = new Set();

                        if (data.message && window.showToast) {
                            window.showToast(data.message);
                        }

                        return;
                    }

                    availableDates = new Set(
                        data.dates.map(function (item) {
                            return item.date;
                        })
                    );

                    if (input.value && !availableDates.has(input.value)) {
                        clearDate(true);
                    }
                })
                .catch(function (error) {
                    console.error(error);
                    availableDates = new Set();

                    if (window.showToast) {
                        window.showToast('Не удалось загрузить доступные даты.');
                    }
                })
                .finally(function () {
                    isLoadingDates = false;
                    renderCalendar();
                });
        }

        function renderCalendar() {
            dropdown.innerHTML = '';

            const calendar = document.createElement('div');
            calendar.className = 'calendar';

            const head = document.createElement('div');
            head.className = 'calendar__head';

            const title = document.createElement('div');
            title.className = 'calendar__title';
            title.textContent = monthNames[viewDate.getMonth()] + ' ' + viewDate.getFullYear();

            const nav = document.createElement('div');
            nav.className = 'calendar__nav';

            const prev = document.createElement('button');
            prev.type = 'button';
            prev.className = 'calendar__btn';
            prev.textContent = '‹';

            const next = document.createElement('button');
            next.type = 'button';
            next.className = 'calendar__btn';
            next.textContent = '›';

            nav.appendChild(prev);
            nav.appendChild(next);
            head.appendChild(title);
            head.appendChild(nav);

            const week = document.createElement('div');
            week.className = 'calendar__week';

            weekDays.forEach(function (day) {
                const span = document.createElement('span');
                span.textContent = day;
                week.appendChild(span);
            });

            const days = document.createElement('div');
            days.className = 'calendar__days';

            const firstDay = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);
            const startOffset = (firstDay.getDay() + 6) % 7;
            const startDate = new Date(firstDay);
            startDate.setDate(firstDay.getDate() - startOffset);

            const selectedDate = input.value ? new Date(input.value + 'T00:00:00') : null;
            const serviceId = getServiceId();

            for (let i = 0; i < 42; i++) {
                const current = new Date(startDate);
                current.setDate(startDate.getDate() + i);
                current.setHours(0, 0, 0, 0);

                const iso = toISO(current);

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'calendar__day';
                button.textContent = current.getDate();

                if (current.getMonth() !== viewDate.getMonth()) {
                    button.classList.add('is-muted');
                }

                if (isSameDay(current, today)) {
                    button.classList.add('is-today');
                }

                if (selectedDate && isSameDay(current, selectedDate)) {
                    button.classList.add('is-selected');
                }

                const disabledByMin = minDate && current < minDate;
                const disabledByService = !serviceId;
                const disabledByLoading = isLoadingDates;
                const disabledBySlots = serviceId && !availableDates.has(iso);

                if (availableDates.has(iso)) {
                    button.classList.add('has-slots');
                }

                if (disabledBySlots) {
                    button.classList.add('no-slots');
                }

                if (disabledByLoading) {
                    button.classList.add('is-loading');
                }

                if (disabledByMin || disabledByService || disabledByLoading || disabledBySlots) {
                    button.disabled = true;
                } else {
                    button.addEventListener('click', function () {
                        input.value = iso;
                        updateText();
                        wrapper.classList.remove('is-open');
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }

                days.appendChild(button);
            }

            const foot = document.createElement('div');
            foot.className = 'calendar__foot';

            const clear = document.createElement('button');
            clear.type = 'button';
            clear.className = 'calendar__link';
            clear.textContent = 'Очистить';

            const todayBtn = document.createElement('button');
            todayBtn.type = 'button';
            todayBtn.className = 'calendar__link';
            todayBtn.textContent = 'Сегодня';

            clear.addEventListener('click', function () {
                clearDate(true);
                wrapper.classList.remove('is-open');
            });

            todayBtn.addEventListener('click', function () {
                const isoToday = toISO(today);

                if (!getServiceId()) {
                    if (window.showToast) {
                        window.showToast('Сначала выберите услугу.');
                    }

                    return;
                }

                if (!availableDates.has(isoToday)) {
                    if (window.showToast) {
                        window.showToast('На сегодня нет свободных мастеров по выбранной услуге.');
                    }

                    return;
                }

                input.value = isoToday;
                viewDate = new Date(today);
                updateText();
                wrapper.classList.remove('is-open');
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });

            prev.addEventListener('click', function () {
                viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, 1);
                renderCalendar();
            });

            next.addEventListener('click', function () {
                viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 1);
                renderCalendar();
            });

            foot.appendChild(clear);
            foot.appendChild(todayBtn);

            calendar.appendChild(head);
            calendar.appendChild(week);
            calendar.appendChild(days);
            calendar.appendChild(foot);

            const hint = document.createElement('div');
            hint.className = 'calendar__hint';

            if (!serviceId) {
                hint.textContent = 'Сначала выберите услугу. После этого календарь покажет доступные даты.';
            } else if (isLoadingDates) {
                hint.textContent = 'Проверяем даты со свободными мастерами...';
            } else if (availableDates.size === 0) {
                hint.textContent = 'На ближайшие даты свободных мастеров по услуге нет.';
            } else {
                hint.textContent = 'Активны только даты, где есть свободные мастера.';
            }

            calendar.appendChild(hint);
            dropdown.appendChild(calendar);
        }

        control.addEventListener('click', function () {
            wrapper.classList.toggle('is-open');

            const currentServiceId = getServiceId();

            if (currentServiceId !== lastServiceId) {
                loadAvailableDates();
            } else {
                renderCalendar();
            }
        });

        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) {
                wrapper.classList.remove('is-open');
            }
        });

        if (serviceSelect) {
            serviceSelect.addEventListener('change', function () {
                clearDate(true);
                loadAvailableDates();
            });
        }

        updateText();
        renderCalendar();

        return {
            refresh: loadAvailableDates,
            clear: clearDate
        };
    }

    enhanceServiceSelect(document.getElementById('service_id'));
    enhanceDatePicker(document.getElementById('preferred_date'));

    const appointmentForm = document.getElementById('appointmentForm');

    if (appointmentForm) {
        const serviceSelect = document.getElementById('service_id');
        const dateInput = document.getElementById('preferred_date');
        const timeInput = document.getElementById('preferred_time');
        const employeeInput = document.getElementById('employee_id');

        const timeZone = document.getElementById('timeZone');
        const masterZone = document.getElementById('masterZone');
        const serviceSummary = document.getElementById('serviceSummary');

        const initialTime = timeInput ? timeInput.value : '';
        const initialMaster = employeeInput ? employeeInput.value : '';

        function updateServiceSummary() {
            if (!serviceSelect || !serviceSummary) {
                return;
            }

            const option = serviceSelect.options[serviceSelect.selectedIndex];

            if (!option || !option.value) {
                serviceSummary.textContent = 'Выберите услугу, чтобы увидеть длительность и стоимость.';
                return;
            }

            const price = Number(option.dataset.price || 0);
            const duration = Number(option.dataset.duration || 60);

            serviceSummary.textContent =
                'Стоимость: ' +
                price.toLocaleString('ru-RU') +
                ' ₽. Длительность: ' +
                formatDuration(duration) +
                '.';
        }

        function resetTimeAndMaster() {
            if (timeInput) {
                timeInput.value = '';
            }

            if (employeeInput) {
                employeeInput.value = '';
            }

            setZoneMessage(masterZone, 'Мастер появится после выбора времени.');
        }

        function loadTimes(preselectTime = '', preselectMaster = '') {
            if (!serviceSelect || !dateInput || !timeZone) {
                return;
            }

            const serviceId = serviceSelect.value;
            const date = dateInput.value;

            resetTimeAndMaster();
            updateServiceSummary();

            if (!serviceId || !date) {
                setZoneMessage(timeZone, 'Сначала выберите услугу и дату.');
                return;
            }

            setZoneMessage(timeZone, 'Проверяем свободное время...');

            const url =
                'get-available-times.php?service_id=' +
                encodeURIComponent(serviceId) +
                '&date=' +
                encodeURIComponent(date);

            fetch(url)
                .then(function (response) {
                    return response.text();
                })
                .then(function (text) {
                    const data = parseJsonResponse(
                        text,
                        timeZone,
                        'Сервер вернул ошибку. Проверьте файл get-available-times.php.'
                    );

                    if (!data) {
                        return;
                    }

                    if (!data.success) {
                        setZoneMessage(timeZone, data.message || 'Не удалось получить свободное время.');
                        return;
                    }

                    if (!data.times || data.times.length === 0) {
                        setZoneMessage(timeZone, 'На выбранную дату свободного времени нет.');
                        return;
                    }

                    let html = '<div class="time-grid">';

                    data.times.forEach(function (item) {
                        const itemTime = String(item.time).substring(0, 5);

                        const selected =
                            preselectTime &&
                            String(preselectTime).substring(0, 5) === itemTime;

                        html += '<button type="button" class="time-slot' + (selected ? ' is-selected' : '') + '" data-time="' + itemTime + '">';
                        html += '<strong>' + item.label + '</strong>';
                        html += '<span>' + item.masters_count + ' мастер(а)</span>';
                        html += '</button>';
                    });

                    html += '</div>';

                    timeZone.innerHTML = html;

                    timeZone.querySelectorAll('.time-slot').forEach(function (button) {
                        button.addEventListener('click', function () {
                            timeZone.querySelectorAll('.time-slot').forEach(function (item) {
                                item.classList.remove('is-selected');
                            });

                            button.classList.add('is-selected');

                            if (timeInput) {
                                timeInput.value = button.dataset.time;
                            }

                            loadMasters(button.dataset.time, '');
                        });
                    });

                    if (preselectTime) {
                        if (timeInput) {
                            timeInput.value = String(preselectTime).substring(0, 5);
                        }

                        loadMasters(String(preselectTime).substring(0, 5), preselectMaster);
                    }
                })
                .catch(function (error) {
                    console.error(error);
                    setZoneMessage(timeZone, 'Не удалось загрузить свободное время.');
                });
        }

        function loadMasters(time, preselectMaster = '') {
            if (!serviceSelect || !dateInput || !masterZone || !employeeInput) {
                return;
            }

            const serviceId = serviceSelect.value;
            const date = dateInput.value;

            if (!serviceId || !date || !time) {
                setZoneMessage(masterZone, 'Мастер появится после выбора времени.');
                return;
            }

            setZoneMessage(masterZone, 'Ищем свободных мастеров...');

            const url =
                'get-available-masters.php?service_id=' +
                encodeURIComponent(serviceId) +
                '&date=' +
                encodeURIComponent(date) +
                '&time=' +
                encodeURIComponent(time);

            fetch(url)
                .then(function (response) {
                    return response.text();
                })
                .then(function (text) {
                    const data = parseJsonResponse(
                        text,
                        masterZone,
                        'Сервер вернул ошибку. Проверьте файл get-available-masters.php.'
                    );

                    if (!data) {
                        employeeInput.value = '';
                        return;
                    }

                    if (!data.success) {
                        employeeInput.value = '';
                        setZoneMessage(masterZone, data.message || 'Не удалось получить мастеров.');
                        return;
                    }

                    if (!data.masters || data.masters.length === 0) {
                        employeeInput.value = '';
                        setZoneMessage(masterZone, 'На это время свободных мастеров нет.');
                        return;
                    }

                    let html = '<div class="master-grid">';

                    data.masters.forEach(function (master) {
                        const selected =
                            preselectMaster &&
                            Number(preselectMaster) === Number(master.id);

                        html += '<button type="button" class="master-card' + (selected ? ' is-selected' : '') + '" data-master-id="' + master.id + '">';
                        html += '<strong>' + master.full_name + '</strong>';
                        html += '<span>' + master.position + '</span>';

                        if (master.specialization) {
                            html += '<p>' + master.specialization + '</p>';
                        }

                        html += '</button>';
                    });

                    html += '</div>';

                    if (data.masters.length === 1) {
                        html += '<p class="muted-text small">Свободен один мастер. Он выбран автоматически.</p>';
                    } else {
                        html += '<p class="muted-text small">Свободно несколько мастеров. Выберите подходящего.</p>';
                    }

                    masterZone.innerHTML = html;

                    const cards = masterZone.querySelectorAll('.master-card');

                    cards.forEach(function (card) {
                        card.addEventListener('click', function () {
                            cards.forEach(function (item) {
                                item.classList.remove('is-selected');
                            });

                            card.classList.add('is-selected');
                            employeeInput.value = card.dataset.masterId;
                        });
                    });

                    if (data.masters.length === 1 && !preselectMaster) {
                        cards[0].classList.add('is-selected');
                        employeeInput.value = cards[0].dataset.masterId;
                    }

                    if (preselectMaster) {
                        employeeInput.value = preselectMaster;
                    }
                })
                .catch(function (error) {
                    console.error(error);
                    employeeInput.value = '';
                    setZoneMessage(masterZone, 'Не удалось загрузить мастеров.');
                });
        }

        if (serviceSelect) {
            serviceSelect.addEventListener('change', function () {
                updateServiceSummary();
                resetTimeAndMaster();
                setZoneMessage(timeZone, 'Выберите доступную дату.');
            });
        }

        if (dateInput) {
            dateInput.addEventListener('change', function () {
                loadTimes('', '');
            });
        }

        appointmentForm.addEventListener('submit', function (event) {
            const serviceId = serviceSelect ? serviceSelect.value : '';
            const date = dateInput ? dateInput.value : '';
            const time = timeInput ? timeInput.value : '';
            const employeeId = employeeInput ? employeeInput.value : '';

            if (!serviceId) {
                event.preventDefault();
                showToast('Выберите услугу.');
                return;
            }

            if (!date) {
                event.preventDefault();
                showToast('Выберите доступную дату.');
                return;
            }

            if (!time) {
                event.preventDefault();
                showToast('Выберите свободное время.');
                return;
            }

            if (!employeeId) {
                event.preventDefault();
                showToast('Выберите мастера.');
            }
        });

        updateServiceSummary();

        if (serviceSelect && serviceSelect.value && dateInput && dateInput.value) {
            loadTimes(initialTime, initialMaster);
        } else {
            setZoneMessage(timeZone, 'Сначала выберите услугу и доступную дату.');
            setZoneMessage(masterZone, 'Мастер появится после выбора времени.');
        }
    }

    const carSelect = document.getElementById('car_id');

    if (carSelect) {
        const carBrandInput = document.getElementById('car_brand');
        const carModelInput = document.getElementById('car_model');
        const carYearInput = document.getElementById('car_year');
        const licensePlateInput = document.getElementById('license_plate');

        function fillCarFields() {
            const option = carSelect.options[carSelect.selectedIndex];

            if (!option || !option.value) {
                return;
            }

            if (carBrandInput) {
                carBrandInput.value = option.dataset.brand || '';
            }

            if (carModelInput) {
                carModelInput.value = option.dataset.model || '';
            }

            if (carYearInput) {
                carYearInput.value = option.dataset.year || '';
            }

            if (licensePlateInput) {
                licensePlateInput.value = option.dataset.license || '';
            }

            if (window.showToast) {
                window.showToast('Данные автомобиля заполнены автоматически.');
            }
        }

        carSelect.addEventListener('change', fillCarFields);

        if (carSelect.value) {
            fillCarFields();
        }
    }

    const registerForm = document.getElementById('registerForm');

    if (registerForm) {
        registerForm.addEventListener('submit', function (event) {
            const fullName = document.getElementById('full_name');
            const phone = document.getElementById('phone');
            const email = document.getElementById('email');
            const password = document.getElementById('password');
            const passwordRepeat = document.getElementById('password_repeat');

            if (fullName && fullName.value.trim().length < 2) {
                event.preventDefault();
                showToast('Введите ФИО полностью.');
                fullName.focus();
                return;
            }

            if (phone && phone.value.trim().length < 6) {
                event.preventDefault();
                showToast('Введите номер телефона.');
                phone.focus();
                return;
            }

            if (email && !email.value.includes('@')) {
                event.preventDefault();
                showToast('Введите корректный email.');
                email.focus();
                return;
            }

            if (password && password.value.length < 6) {
                event.preventDefault();
                showToast('Пароль должен содержать минимум 6 символов.');
                password.focus();
                return;
            }

            if (passwordRepeat && password && password.value !== passwordRepeat.value) {
                event.preventDefault();
                showToast('Пароли не совпадают.');
                passwordRepeat.focus();
            }
        });
    }

    const loginForm = document.getElementById('loginForm');

    if (loginForm) {
        loginForm.addEventListener('submit', function (event) {
            const email = document.getElementById('email');
            const password = document.getElementById('password');

            if (email && !email.value.includes('@')) {
                event.preventDefault();
                showToast('Введите корректный email.');
                email.focus();
                return;
            }

            if (password && password.value.trim() === '') {
                event.preventDefault();
                showToast('Введите пароль.');
                password.focus();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
            closeBonusModal();
        }
    });
});