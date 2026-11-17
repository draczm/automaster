/* Демо-слой для статической версии сайта: вместо сервера и базы данных. */
(function () {
    'use strict';

    var script = document.currentScript;
    var scriptUrl = new URL(script.src, location.href);
    var siteRoot = new URL('../../', scriptUrl); // .../docs/
    var dataUrl = new URL('../data/ajax.json', scriptUrl);

    var ACCOUNTS = [
        { email: 'admin@automaster.local', password: 'Admin123!', home: 's/admin/admin/index.html' },
        { email: 'user@automaster.local', password: 'User123!', home: 's/emp/master-schedule.html' },
        { email: 'client@automaster.local', password: 'Client123!', home: 's/client/profile.html' }
    ];

    /* ---------- уведомление ---------- */
    function toast(text) {
        var el = document.createElement('div');
        el.textContent = text;
        el.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:99999;' +
            'max-width:90vw;padding:14px 22px;border-radius:14px;background:#172033;color:#fff;' +
            'font:600 14px/1.4 Inter,Arial,sans-serif;box-shadow:0 18px 42px rgba(18,24,38,.35);';
        document.body.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .4s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 450);
        }, 3200);
    }

    /* ---------- сдвиг дат на целое число недель ---------- */
    var ajaxPromise = null;
    function loadAjax() {
        if (!ajaxPromise) {
            ajaxPromise = fetch(dataUrl.href, { cache: 'no-store' }).then(function (r) { return r.json(); });
        }
        return ajaxPromise;
    }

    function ymd(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function shiftDate(str, days) {
        var p = str.split('-');
        var d = new Date(+p[0], +p[1] - 1, +p[2] + days);
        return ymd(d);
    }

    function weeksShiftDays(built) {
        var b = built.split('-');
        var start = new Date(+b[0], +b[1] - 1, +b[2]);
        var now = new Date();
        now = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var diff = Math.round((now - start) / 86400000);
        return Math.floor(diff / 7) * 7;
    }

    function jsonResponse(text) {
        return new Response(text, { status: 200, headers: { 'Content-Type': 'application/json; charset=utf-8' } });
    }

    /* ---------- подмена запросов к get-available-*.php ---------- */
    var nativeFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var m = url.match(/get-available-(dates|times|masters)\.php\?(.*)$/);
        if (!m) { return nativeFetch(input, init); }

        var kind = m[1];
        var q = new URLSearchParams(m[2]);
        var sid = q.get('service_id');

        return loadAjax().then(function (data) {
            var shift = weeksShiftDays(data.built);
            var key;
            if (kind === 'dates') {
                key = 'dates:' + sid;
            } else if (kind === 'times') {
                key = 'times:' + sid + ':' + shiftDate(q.get('date'), -shift);
            } else {
                key = 'masters:' + sid + ':' + shiftDate(q.get('date'), -shift) + ':' + q.get('time');
            }
            var text = data.items[key];
            if (!text) {
                text = JSON.stringify({ success: true, dates: [], times: [], masters: [], times_count: 0, masters_count: 0 });
            } else if (shift) {
                text = text.replace(/\d{4}-\d{2}-\d{2}/g, function (d) { return shiftDate(d, shift); });
            }
            return jsonResponse(text);
        });
    };

    /* ---------- формы ---------- */
    function showLoginError(form, msg) {
        var box = form.parentNode.querySelector('.demo-alert');
        if (!box) {
            box = document.createElement('div');
            box.className = 'alert alert-error demo-alert';
            form.parentNode.insertBefore(box, form);
        }
        box.textContent = msg;
    }

    function onSubmit(e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) { return; }

        if (form.id === 'loginForm') {
            e.preventDefault();
            e.stopImmediatePropagation();
            var email = (form.querySelector('[name="email"]').value || '').trim().toLowerCase();
            var pass = form.querySelector('[name="password"]').value || '';
            var acc = ACCOUNTS.filter(function (a) { return a.email === email && a.password === pass; })[0];
            if (acc) {
                location.href = new URL(acc.home, siteRoot).href;
            } else {
                showLoginError(form, 'Неверный email или пароль. Используйте демо-аккаунты, указанные ниже.');
            }
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();

        if (form.id === 'appointmentForm') {
            saveAppointment(form);
        } else if (form.id === 'registerForm') {
            toast('Демо-режим: регистрация отключена. Войдите под демо-аккаунтом.');
        } else if ((form.method || 'get').toLowerCase() === 'get') {
            toast('Демо-режим: фильтры и выбор даты доступны в рабочей версии с сервером.');
        } else {
            toast('Демо-режим: изменения не сохраняются.');
        }
    }
    document.addEventListener('submit', onSubmit, true);


    /* ---------- заявки, сохранённые в браузере ---------- */
    var STORE_KEY = 'cr_demo_appts_v1';

    function loadLocal() {
        try { return JSON.parse(localStorage.getItem(STORE_KEY) || '[]'); } catch (e) { return []; }
    }

    function storeLocal(list) {
        try { localStorage.setItem(STORE_KEY, JSON.stringify(list)); return true; } catch (e) { return false; }
    }

    function val(form, name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? String(el.value || '').trim() : '';
    }

    function saveAppointment(form) {
        var sel = form.querySelector('#service_id');
        var opt = sel && sel.options[sel.selectedIndex];
        var date = val(form, 'preferred_date');
        var time = val(form, 'preferred_time');
        var emp = val(form, 'employee_id');

        if (!opt || !opt.value || !date || !time || !emp) {
            toast('Выберите услугу, дату, время и мастера.');
            return;
        }

        var masterEl = form.querySelector('.master-card.is-selected strong');
        var name = (opt.textContent || '').split(' — ')[0].trim();
        var brand = val(form, 'car_brand');
        var model = val(form, 'car_model');

        loadAjax().then(function (data) {
            var shift = weeksShiftDays(data.built);
            var list = loadLocal();
            list.push({
                id: Date.now(),
                service: name,
                price: Number(opt.dataset.price || 0),
                duration: Number(opt.dataset.duration || 60),
                client: val(form, 'client_name'),
                phone: val(form, 'phone'),
                car: (brand + ' ' + model).trim(),
                plate: val(form, 'license_plate'),
                date: shiftDate(date, -shift),
                realDate: date,
                time: time.substring(0, 5),
                employeeId: emp,
                master: masterEl ? masterEl.textContent.trim() : 'Мастер',
                comment: val(form, 'work_description'),
                status: 'Новая',
                pay: 'Не оплачено'
            });
            storeLocal(list);
            toast('Заявка создана и сохранена в этом браузере. Она появилась в календарях и списках.');
            setTimeout(function () { location.reload(); }, 1800);
        });
    }

    function isoFromRu(text) {
        var m = String(text || '').match(/(\d{2})\.(\d{2})\.(\d{4})/);
        return m ? m[3] + '-' + m[2] + '-' + m[1] : '';
    }

    function ruFromIso(iso) {
        var p = String(iso).split('-');
        return p[2] + '.' + p[1] + '.' + p[0];
    }

    function money(n) {
        return Number(n).toLocaleString('ru-RU') + ' ₽';
    }

    function endTime(a) {
        var p = a.time.split(':');
        var total = (+p[0]) * 60 + (+p[1]) + (a.duration || 60);
        return String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0');
    }

    function esc2(t) {
        var d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    function metaBox(label, value) {
        return '<div><span>' + esc2(label) + '</span><strong>' + esc2(value) + '</strong></div>';
    }

    function setData(el, a, isEmp) {
        var d = el.dataset;
        d.service = a.service; d.client = a.client; d.phone = a.phone; d.car = a.car; d.plate = a.plate;
        d.master = a.master; d.date = ruFromIso(a.date); d.time = a.time + ' — ' + endTime(a);
        d.status = a.status; d.pay = a.pay; d.comment = a.comment || '';
        d.price = money(isEmp ? Math.round(a.price * 0.3) : a.price);
        if (isEmp) { d.priceLabel = 'Оплата мастера'; }
        d.local = '1';
    }

    function renderCalendar() {
        var grid = document.querySelector('.admin-calendar-grid');
        if (!grid) { return; }
        var isEmp = !!document.querySelector('.master-calendar');
        var cfg = isEmp ? { start: 540, end: 1140, h: 52 } : { start: 510, end: 1260, h: 58 };
        var heads = grid.querySelectorAll('.admin-calendar-day-head span');
        var cols = grid.querySelectorAll('.admin-calendar-day-column');
        var dates = Array.prototype.map.call(heads, function (h) { return isoFromRu(h.textContent); });

        loadLocal().forEach(function (a) {
            if (isEmp && String(a.employeeId) !== '1') { return; }
            var idx = dates.indexOf(a.date);
            if (idx < 0) { return; }
            var p = a.time.split(':');
            var startMin = (+p[0]) * 60 + (+p[1]);
            if (startMin < cfg.start || startMin >= cfg.end) { return; }

            var top = Math.round((startMin - cfg.start) / 30 * cfg.h);
            var height = Math.max(44, Math.round((a.duration || 60) / 30 * cfg.h - 6));
            var el = document.createElement(isEmp ? 'a' : 'article');
            el.className = 'admin-calendar-event admin-calendar-event--new';
            el.style.cssText = 'top:' + top + 'px;height:' + height + 'px;z-index:40;outline:2px dashed #fff;outline-offset:-4px;';
            if (isEmp) { el.href = '#'; } else { el.tabIndex = 0; el.setAttribute('role', 'button'); }
            setData(el, a, isEmp);

            el.innerHTML = '<div class="admin-calendar-event__time">' + esc2(a.time) + ' — ' + endTime(a) + '</div>' +
                '<strong>' + esc2(a.service) + '</strong>' +
                '<span>' + esc2(a.client) + '</span>' +
                '<span>' + esc2(a.car) + '</span>' +
                (isEmp ? '' : '<span>' + esc2(a.master) + '</span>') +
                '<div class="admin-calendar-event__bottom"><em class="status-badge status-badge--new">Новая · ваша</em></div>';

            var empty = cols[idx].querySelector('.admin-calendar-empty-day');
            if (empty) { empty.remove(); }
            cols[idx].appendChild(el);
        });
    }

    function renderDayList() {
        var title = document.querySelector('.master-section-card:not(.master-calendar) h2');
        var section = title && title.closest('.master-section-card');
        if (!section) { return; }
        var iso = isoFromRu(title.textContent);
        var items = loadLocal().filter(function (a) { return a.date === iso && String(a.employeeId) === '1'; });
        if (!items.length) { return; }

        var box = section.querySelector('.master-records');
        if (!box) {
            var empty = section.querySelector('.empty-text');
            if (empty) { empty.remove(); }
            box = document.createElement('div');
            box.className = 'master-records';
            section.appendChild(box);
        }

        items.forEach(function (a) {
            var card = document.createElement('article');
            card.className = 'master-record-card';
            card.innerHTML =
                '<div class="master-record-time master-record-time--new"><strong>' + esc2(a.time) + '</strong><span>' + endTime(a) + '</span></div>' +
                '<div class="master-record-content">' +
                '<div class="record-card__top"><div><h3>' + esc2(a.service) + '</h3><p>' + esc2(a.client) + ' · ' + esc2(a.phone) + '</p></div>' +
                '<span class="status-badge status-badge--new">Новая · ваша</span></div>' +
                '<div class="record-card__meta">' + metaBox('Автомобиль', a.car) + metaBox('Госномер', a.plate || 'не указан') +
                metaBox('Оплата мастера', money(Math.round(a.price * 0.3))) + '</div>' +
                (a.comment ? '<p class="record-card__comment">' + esc2(a.comment) + '</p>' : '') +
                '</div>';
            box.insertBefore(card, box.firstChild);
        });
    }

    function renderAdminList() {
        var list = document.querySelector('.admin-appointment-list');
        if (!list || !/appointments\.html$/.test(location.pathname)) { return; }
        loadLocal().forEach(function (a) {
            var card = document.createElement('article');
            card.className = 'admin-appointment-card';
            card.innerHTML =
                '<div class="admin-appointment-card__top"><div>' +
                '<span class="admin-card-time">' + ruFromIso(a.date) + ' ' + esc2(a.time) + '</span>' +
                '<h3>' + esc2(a.service) + '</h3><p>' + esc2(a.client) + ' · ' + esc2(a.phone) + '</p></div>' +
                '<span class="status-badge status-badge--new">Новая · ваша</span></div>' +
                '<div class="record-card__meta">' + metaBox('Автомобиль', a.car) + metaBox('Госномер', a.plate || 'не указан') +
                metaBox('Мастер', a.master) + metaBox('Стоимость', money(a.price)) + metaBox('Оплата', a.pay) + '</div>' +
                (a.comment ? '<p class="record-card__comment">' + esc2(a.comment) + '</p>' : '');
            list.insertBefore(card, list.firstChild);
        });
    }

    function renderProfile() {
        if (!/profile\.html$/.test(location.pathname)) { return; }
        var items = loadLocal();
        if (!items.length) { return; }
        var list = document.querySelector('.records-list');
        if (!list) {
            var empty = Array.prototype.filter.call(document.querySelectorAll('.empty-text'), function (e) {
                return /Активных записей нет/.test(e.textContent);
            })[0];
            if (!empty) { return; }
            list = document.createElement('div');
            list.className = 'records-list';
            empty.replaceWith(list);
        }
        items.forEach(function (a) {
            var card = document.createElement('article');
            card.className = 'record-card';
            card.innerHTML =
                '<div class="record-card__top"><div><h3>' + esc2(a.service) + '</h3><p>' + esc2(a.car) + (a.plate ? ' · ' + esc2(a.plate) : '') + '</p></div>' +
                '<span class="status-badge status-badge--new">Новая · ваша</span></div>' +
                '<div class="record-card__meta">' + metaBox('Дата и время', ruFromIso(a.date) + ' ' + a.time) +
                metaBox('Мастер', a.master) + metaBox('Стоимость', money(a.price)) + '</div>';
            list.insertBefore(card, list.firstChild);
        });
    }

    function addClearButton() {
        var n = loadLocal().length;
        if (!n) { return; }
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = 'Сбросить мои заявки (' + n + ')';
        b.style.cssText = 'position:fixed;left:14px;bottom:14px;z-index:9999;padding:7px 12px;border:0;border-radius:999px;' +
            'background:#d97852;color:#fff;font:700 11px/1 Inter,Arial,sans-serif;cursor:pointer;';
        b.addEventListener('click', function () {
            storeLocal([]);
            location.reload();
        });
        document.body.appendChild(b);
    }

    /* ---------- подсказка с аккаунтами на странице входа ---------- */
    function addLoginHint() {
        var form = document.getElementById('loginForm');
        if (!form) { return; }
        var hint = document.createElement('div');
        hint.style.cssText = 'margin-top:18px;padding:14px 16px;border-radius:14px;background:#fff1eb;' +
            'font:13px/1.6 Inter,Arial,sans-serif;color:#172033;';
        hint.innerHTML = '<strong>Демо-аккаунты</strong><br>' +
            'Администратор: <b>admin@automaster.local</b> / <b>Admin123!</b><br>' +
            'Сотрудник: <b>user@automaster.local</b> / <b>User123!</b><br>' +
            'Покупатель: <b>client@automaster.local</b> / <b>Client123!</b>';
        form.parentNode.appendChild(hint);
    }

    /* ---------- плашка «демо-версия» ---------- */
    function addBadge() {
        var b = document.createElement('div');
        b.textContent = 'Демо-версия · данные для показа, изменения не сохраняются';
        b.style.cssText = 'position:fixed;right:14px;bottom:14px;z-index:9999;padding:7px 12px;border-radius:999px;' +
            'background:rgba(23,32,51,.88);color:#fff;font:600 11px/1 Inter,Arial,sans-serif;pointer-events:none;';
        document.body.appendChild(b);
    }

    function init() {
        addLoginHint();
        addBadge();
        renderCalendar();
        renderDayList();
        renderAdminList();
        renderProfile();
        addClearButton();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
