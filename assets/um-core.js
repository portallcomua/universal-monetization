(function (window) {
    'use strict';

    var activeServiceId = null;
    var modalEl = null;

    /* ─── Побудова попапа ─── */
    function buildModal() {
        if (modalEl) return modalEl;

        modalEl = document.createElement('div');
        modalEl.className = 'um-modal-overlay';
        modalEl.style.display = 'none';
        modalEl.innerHTML = ''
            + '<div class="um-modal">'
            + '  <button type="button" class="um-modal-close" aria-label="Закрити">×</button>'
            + '  <h2 class="um-modal-title">Крок 1: Скопіюйте ваш логін</h2>'
            + '  <p>Ваш логін на сайті:</p>'
            + '  <div class="um-login-row">'
            + '    <span class="um-login"></span>'
            + '    <button type="button" class="um-copy-btn">📋 Скопіювати логін</button>'
            + '  </div>'
            + '  <p class="um-instruction"></p>'
            + '  <div class="um-context"></div>'
            + '  <div class="um-modal-actions">'
            + '    <button type="button" class="um-btn um-cancel-btn">Скасувати</button>'
            + '    <button type="button" class="um-btn um-btn-primary um-go-pay" disabled>'
            + '      Перейти до оплати →'
            + '    </button>'
            + '  </div>'
            + '</div>';

        document.body.appendChild(modalEl);

        modalEl.addEventListener('click', function (e) {
            if (e.target === modalEl) closeModal();
        });
        modalEl.querySelector('.um-cancel-btn').addEventListener('click', closeModal);
        modalEl.querySelector('.um-modal-close').addEventListener('click', closeModal);

        modalEl.querySelector('.um-copy-btn').addEventListener('click', function () {
            var btn = this;
            var text = modalEl.querySelector('.um-login').textContent.trim();
            copyText(text, function () {
                btn.classList.add('copied');
                btn.textContent = 'Скопійовано! 👍';
                modalEl.querySelector('.um-go-pay').disabled = false;
            });
        });

        modalEl.querySelector('.um-go-pay').addEventListener('click', function () {
            var btn = this;
            if (!activeServiceId) return;
            btn.disabled = true;
            btn.textContent = 'Активуємо...';

            var fd = new FormData();
            fd.append('action', 'um_activate');
            fd.append('service_id', activeServiceId);
            fd.append('nonce', UM_CFG.nonce);
            fd.append('source', 'popup');

            fetch(UM_CFG.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    var paymentUrl = modalEl.querySelector('.um-go-pay').getAttribute('data-url');
                    if (paymentUrl) window.open(paymentUrl, '_blank');
                    if (resp && resp.success) {
                        setTimeout(function () { window.location.reload(); }, 400);
                    } else {
                        alert((resp && resp.data && resp.data.message) || 'Помилка активації');
                        btn.disabled = false;
                        btn.textContent = 'Перейти до оплати →';
                    }
                })
                .catch(function () {
                    alert('Помилка з\'єднання. Спробуйте ще раз.');
                    btn.disabled = false;
                    btn.textContent = 'Перейти до оплати →';
                });
        });

        return modalEl;
    }

    function copyText(text, onDone) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(onDone).catch(fallback);
        } else { fallback(); }
        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); onDone(); }
            catch (e) { alert('Скопіюйте вручну: ' + text); }
            document.body.removeChild(ta);
        }
    }

    /* ─── Відкрити попап ─── */
    function openModal(serviceId) {
        if (!UM_CFG.is_logged) {
            window.location.href = UM_CFG.login_url;
            return;
        }

        activeServiceId = serviceId;

        // Запитуємо дані попапа з сервера
        var fd = new FormData();
        fd.append('action', 'um_get_popup_data');
        fd.append('service_id', serviceId);
        fd.append('nonce', UM_CFG.nonce);

        var m = buildModal();
        m.querySelector('.um-modal-title').textContent = 'Завантаження...';
        m.querySelector('.um-login').textContent = '';
        m.querySelector('.um-instruction').textContent = '';
        m.querySelector('.um-context').textContent = '';
        m.querySelector('.um-copy-btn').classList.remove('copied');
        m.querySelector('.um-copy-btn').textContent = '📋 Скопіювати логін';
        m.querySelector('.um-go-pay').disabled = true;
        m.querySelector('.um-go-pay').textContent = 'Перейти до оплати →';
        m.style.display = 'flex';

        fetch(UM_CFG.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    alert('Не вдалось завантажити дані попапа');
                    closeModal();
                    return;
                }
                var d = resp.data;
                m.querySelector('.um-modal-title').textContent = d.title || 'Крок 1: Скопіюйте ваш логін';
                m.querySelector('.um-login').textContent = d.user_login || '';
                m.querySelector('.um-instruction').innerHTML = d.popup_instruction || '';
                m.querySelector('.um-context').innerHTML = d.popup_context || '';
                m.querySelector('.um-go-pay').setAttribute('data-url', d.payment_url || '');
            })
            .catch(function () {
                alert('Помилка з\'єднання');
                closeModal();
            });
    }

    function closeModal() {
        if (modalEl) modalEl.style.display = 'none';
        activeServiceId = null;
    }

    /* ─── Делегована обробка кнопок ─── */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.um-buy-btn');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        var sid = parseInt(btn.getAttribute('data-um-service'), 10);
        if (sid) openModal(sid);
    });

    /* ─── Публічний API ─── */
    window.UM = {
        openBuyModal: function (serviceId) { openModal(serviceId); },
        close: closeModal,
    };

})(window);
