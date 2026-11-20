/**
 * Deactivation feedback modal (Plugins screen only).
 *
 * Intercepts this plugin's "Deactivate" link, asks why, and then continues to the
 * real deactivation URL. Deactivation is never blocked: the redirect happens when the
 * feedback request finishes, fails, or after a short timeout, whichever comes first.
 */
(function ($, config) {
    'use strict';

    if (!config || !config.basename) {
        return;
    }

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/["\\]/g, '\\$&');
    }

    $(function () {
        var link = document.querySelector('tr[data-plugin="' + cssEscape(config.basename) + '"] .deactivate a');
        var modal = document.getElementById('tbsh-cal-df-modal');
        if (!link || !modal) {
            return; // Plugin row or modal not on this page: do nothing.
        }

        var dialog = modal.querySelector('.tbsh-cal-df-dialog');
        var form = modal.querySelector('#tbsh-cal-df-form');
        var submitBtn = modal.querySelector('.tbsh-cal-df-submit');
        var skipBtn = modal.querySelector('.tbsh-cal-df-skip');
        var spinner = modal.querySelector('.tbsh-cal-df-spinner');
        var statusText = modal.querySelector('.tbsh-cal-df-status-text');
        var consent = modal.querySelector('#tbsh-cal-df-consent');
        var deactivateUrl = link.getAttribute('href');
        var timeout = parseInt(config.timeout, 10) || 3000;
        var maxLength = parseInt(config.maxLength, 10) || 1000;
        var redirected = false;
        var busy = false;

        function radios() {
            return modal.querySelectorAll('input[name="tbsh_cal_df_reason"]');
        }

        function selectedReason() {
            var checked = modal.querySelector('input[name="tbsh_cal_df_reason"]:checked');
            return checked ? checked.value : '';
        }

        function detailFor(reason) {
            var wrap = reason ? modal.querySelector('[data-tbsh-cal-df-detail="' + cssEscape(reason) + '"]') : null;
            return wrap ? wrap.querySelector('input, textarea') : null;
        }

        function showDetail(reason) {
            modal.querySelectorAll('[data-tbsh-cal-df-detail]').forEach(function (wrap) {
                wrap.hidden = wrap.getAttribute('data-tbsh-cal-df-detail') !== reason;
            });
            // Highlight the selected reason card.
            radios().forEach(function (radio) {
                var card = radio.closest('.tbsh-cal-df-reason');
                if (card) {
                    card.classList.toggle('is-selected', radio.value === reason);
                }
            });
        }

        function goToDeactivation() {
            if (redirected) {
                return;
            }
            redirected = true;
            window.location.href = deactivateUrl;
        }

        function open() {
            modal.hidden = false;
            document.body.classList.add('tbsh-cal-df-open');
            var first = radios()[0];
            if (first) {
                first.focus();
            }
        }

        function close() {
            if (busy) {
                return;
            }
            modal.hidden = true;
            document.body.classList.remove('tbsh-cal-df-open');
            link.focus();
        }

        function trapFocus(event) {
            // Skip controls inside hidden detail fields (layout-independent visibility check).
            var items = Array.prototype.filter.call(dialog.querySelectorAll(FOCUSABLE), function (el) {
                return !el.closest('[hidden]');
            });
            if (!items.length) {
                return;
            }
            var first = items[0];
            var last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        function submit() {
            var reason = selectedReason();
            if (!reason || busy) {
                return;
            }
            busy = true;
            submitBtn.disabled = true;
            skipBtn.disabled = true;
            spinner.classList.add('is-active');
            statusText.textContent = (config.i18n && config.i18n.deactivating) || 'Deactivating...';

            var detail = detailFor(reason);
            var details = detail ? String(detail.value || '').slice(0, maxLength) : '';

            // Whatever happens, deactivate within the timeout.
            window.setTimeout(goToDeactivation, timeout);

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                timeout: timeout,
                data: {
                    action: config.action,
                    nonce: config.nonce,
                    reason: reason,
                    details: details,
                    consent: consent && consent.checked ? '1' : '0'
                }
            }).always(goToDeactivation);
        }

        // Open the modal instead of deactivating straight away.
        link.addEventListener('click', function (event) {
            event.preventDefault();
            open();
        });

        // Character counters under the detail fields.
        modal.querySelectorAll('.tbsh-cal-df-detail').forEach(function (wrap) {
            var field = wrap.querySelector('.tbsh-cal-df-field');
            var count = wrap.querySelector('.tbsh-cal-df-count');
            if (!field || !count) {
                return;
            }
            field.addEventListener('input', function () {
                var length = String(field.value || '').length;
                count.textContent = length + ' / ' + (count.getAttribute('data-max') || maxLength);
                count.classList.toggle('is-near-limit', length >= maxLength * 0.9);
            });
        });

        // Keyboard focus on a radio is shown on its card (no ring on the radio itself).
        radios().forEach(function (radio) {
            var card = radio.closest('.tbsh-cal-df-reason');
            if (!card) {
                return;
            }
            radio.addEventListener('focus', function () {
                var keyboard = true;
                try {
                    keyboard = radio.matches(':focus-visible');
                } catch (e) {
                    // Older browsers without :focus-visible: always show the card focus.
                }
                card.classList.toggle('has-focus', keyboard);
            });
            radio.addEventListener('blur', function () {
                card.classList.remove('has-focus');
            });
        });

        // Reason selection: enable submit and show only the matching detail field.
        radios().forEach(function (radio) {
            radio.addEventListener('change', function () {
                showDetail(radio.value);
                submitBtn.disabled = busy;
            });
        });

        // Close (X and backdrop) without deactivating.
        modal.querySelectorAll('[data-tbsh-cal-df-close]').forEach(function (el) {
            el.addEventListener('click', close);
        });

        // Listen on the document so Escape works even if focus landed on the page body.
        document.addEventListener('keydown', function (event) {
            if (modal.hidden) {
                return;
            }
            if (event.key === 'Escape' || event.key === 'Esc') {
                event.preventDefault();
                close();
            } else if (event.key === 'Tab') {
                trapFocus(event);
            }
        });

        skipBtn.addEventListener('click', function () {
            if (!busy) {
                goToDeactivation();
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submit();
        });
    });
})(window.jQuery, window.tbshCalDeactivationFeedback);
