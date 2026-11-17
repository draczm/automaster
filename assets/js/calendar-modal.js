/* Окно с подробностями записи по клику на событие календаря */
(function () {
    'use strict';

    var modal = null;

    function esc(text) {
        var d = document.createElement('div');
        d.textContent = text == null ? '' : String(text);
        return d.innerHTML;
    }

    function row(label, value) {
        if (!value) { return ''; }
        return '<div class="cal-modal__row"><span>' + esc(label) + '</span><strong>' + esc(value) + '</strong></div>';
    }

    function close() {
        if (modal) {
            modal.remove();
            modal = null;
            document.body.classList.remove('cal-modal-open');
        }
    }

    function open(el) {
        close();
        var d = el.dataset;

        modal = document.createElement('div');
        modal.className = 'cal-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        var link = d.href
            ? '<a class="btn btn--orange btn--small" href="' + esc(d.href) + '">' + esc(d.hrefLabel || 'Открыть') + '</a>'
            : '';

        modal.innerHTML =
            '<div class="cal-modal__overlay" data-close></div>' +
            '<div class="cal-modal__card">' +
                '<button type="button" class="cal-modal__close" data-close aria-label="Закрыть">×</button>' +
                '<span class="label">Запись</span>' +
                '<h2>' + esc(d.service) + '</h2>' +
                '<div class="cal-modal__meta">' +
                    '<span class="cal-modal__chip">' + esc(d.date) + '</span>' +
                    '<span class="cal-modal__chip">' + esc(d.time) + '</span>' +
                    '<span class="cal-modal__chip cal-modal__chip--status">' + esc(d.status) + '</span>' +
                '</div>' +
                '<div class="cal-modal__grid">' +
                    row('Клиент', d.client) +
                    row('Телефон', d.phone) +
                    row('Автомобиль', d.car) +
                    row('Госномер', d.plate) +
                    row('Мастер', d.master) +
                    row(d.priceLabel || 'Стоимость', d.price) +
                    row('Оплата', d.pay) +
                    row('Категория', d.category) +
                '</div>' +
                (d.comment ? '<p class="cal-modal__comment">' + esc(d.comment) + '</p>' : '') +
                '<div class="cal-modal__actions">' + link +
                    '<button type="button" class="btn btn--light btn--small" data-close>Закрыть</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(modal);
        document.body.classList.add('cal-modal-open');

        var first = modal.querySelector('.cal-modal__close');
        if (first) { first.focus(); }
    }

    document.addEventListener('click', function (e) {
        if (modal && e.target.closest('[data-close]')) {
            close();
            return;
        }

        var ev = e.target.closest('.admin-calendar-event[data-service]');
        if (ev) {
            e.preventDefault();
            open(ev);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            close();
            return;
        }

        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.admin-calendar-event[data-service]')) {
            e.preventDefault();
            open(e.target);
        }
    });
})();
