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
            toast('Демо-режим: запись показана как созданная, но не сохраняется.');
        } else if (form.id === 'registerForm') {
            toast('Демо-режим: регистрация отключена. Войдите под демо-аккаунтом.');
        } else if ((form.method || 'get').toLowerCase() === 'get') {
            toast('Демо-режим: фильтры и выбор даты доступны в рабочей версии с сервером.');
        } else {
            toast('Демо-режим: изменения не сохраняются.');
        }
    }
    document.addEventListener('submit', onSubmit, true);

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
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
