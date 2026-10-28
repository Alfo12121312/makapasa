/*
 * File: pos.js
 * Purpose: Terminal behaviours for Cashier/POS.php that sit on top of the
 *          cart / discount / payment logic in script.js:
 *          clock, user menu, tap-to-add, barcode Enter, keyboard shortcuts,
 *          smart cash buttons, void confirmation, sale-complete screen and
 *          the narrow-screen Products/Order switch.
 */
(function () {
    'use strict';

    function $(id) { return document.getElementById(id); }
    function money(n) { return 'PHP ' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function isOpen(el) { return !!el && getComputedStyle(el).display !== 'none'; }
    function isTyping(target) {
        return !!target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);
    }

    /* ---------- Clock ---------- */
    function tickClock() {
        var timeEl = $('pos-clock-time');
        if (!timeEl) return;
        var now = new Date();
        timeEl.textContent = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        var dateEl = $('pos-clock-date');
        if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        var clock = $('pos-clock');
        if (clock) clock.setAttribute('datetime', now.toISOString());
    }

    /* ---------- User menu ---------- */
    function initUserMenu() {
        var btn = $('pos-user-btn');
        var menu = $('pos-user-menu');
        if (!btn || !menu) return;
        function setOpen(open) {
            menu.hidden = !open;
            btn.setAttribute('aria-expanded', String(open));
        }
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            setOpen(menu.hidden);
        });
        document.addEventListener('click', function (e) {
            if (!menu.hidden && !menu.contains(e.target)) setOpen(false);
        });
        window.posCloseUserMenu = function () { setOpen(false); };
    }

    /* ---------- Add to order ---------- */
    function addTile(tile) {
        if (!tile || tile.disabled) return;
        addToCart(
            parseInt(tile.dataset.id, 10),
            tile.dataset.label,
            parseFloat(tile.dataset.price),
            tile.dataset.unit
        );
        tile.classList.remove('just-added');
        void tile.offsetWidth; // restart the animation
        tile.classList.add('just-added');
    }

    function initCatalog() {
        var grid = $('pos-grid');
        if (!grid) return;
        grid.addEventListener('click', function (e) {
            var tile = e.target.closest('.pos-item');
            if (tile) addTile(tile);
        });

        var search = $('pos-search');
        if (!search) return;

        // Barcode scanners type the code and press Enter; humans can do the same.
        search.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var term = search.value.trim().toLowerCase();
            if (!term) return;

            var tiles = Array.prototype.slice.call(grid.querySelectorAll('.pos-item'));
            var exact = tiles.filter(function (t) { return (t.dataset.code || '') === term && !t.disabled; });
            var visible = tiles.filter(function (t) { return t.style.display !== 'none' && !t.disabled; });
            var match = exact.length === 1 ? exact[0] : (visible.length === 1 ? visible[0] : null);

            if (match) {
                addTile(match);
                search.value = '';
                filterPosProducts();
            } else if (visible.length === 0) {
                setPosFeedback('No product matches "' + search.value.trim() + '".', 'error');
                search.select();
            } else {
                setPosFeedback('Several products match. Tap the one you want.', 'error');
            }
        });
    }

    /* ---------- Keep newest line visible ---------- */
    var lastLineCount = 0;
    window.posAfterCartRender = function () {
        var list = $('cart-items');
        if (!list) return;
        var count = list.children.length;
        if (count > lastLineCount) list.scrollTop = list.scrollHeight;
        lastLineCount = count;
        resetVoid();
    };

    /* ---------- Void with confirmation ---------- */
    var voidTimer = null;
    function resetVoid() {
        var btn = $('void-btn');
        if (!btn) return;
        clearTimeout(voidTimer);
        btn.classList.remove('is-confirming');
        btn.textContent = 'Void';
    }
    window.posVoidOrder = function () {
        var btn = $('void-btn');
        if (!btn || typeof cart === 'undefined' || cart.length === 0) return;
        if (!btn.classList.contains('is-confirming')) {
            btn.classList.add('is-confirming');
            btn.textContent = 'Tap again to void';
            voidTimer = setTimeout(resetVoid, 3000);
            return;
        }
        cancelOrder();
        resetVoid();
        setPosFeedback('Order voided.', 'success');
    };

    /* ---------- Payment: smart cash buttons ---------- */
    function buildTender() {
        var wrap = $('quick-tender');
        if (!wrap || typeof total === 'undefined') return;
        var due = Math.round(total * 100) / 100;
        var suggestions = [];
        [50, 100, 500, 1000].forEach(function (d) {
            var v = Math.ceil(due / d) * d;
            if (v > due && suggestions.indexOf(v) === -1) suggestions.push(v);
        });
        suggestions.sort(function (a, b) { return a - b; });
        suggestions = suggestions.slice(0, 4);

        wrap.innerHTML = '';
        var exact = document.createElement('button');
        exact.type = 'button';
        exact.className = 'pos-chip is-exact';
        exact.dataset.tender = due.toFixed(2);
        exact.textContent = 'Exact';
        wrap.appendChild(exact);
        suggestions.forEach(function (v) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'pos-chip';
            b.dataset.tender = String(v);
            b.textContent = v.toLocaleString('en-US');
            wrap.appendChild(b);
        });
    }

    function initPayment() {
        var wrap = $('quick-tender');
        var input = $('amount-received');
        if (wrap && input) {
            wrap.addEventListener('click', function (e) {
                var chip = e.target.closest('[data-tender]');
                if (!chip) return;
                input.value = chip.dataset.tender;
                calculateChange();
                input.focus();
            });
            input.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                var btn = document.querySelector('.pos-confirm-sale');
                if (btn && !btn.disabled) confirmTransaction();
            });
        }
        var ref = $('payment-reference');
        if (ref) {
            ref.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                var btn = document.querySelector('.pos-confirm-sale');
                if (btn && !btn.disabled) confirmTransaction();
            });
        }

        if (typeof window.proceedOrder === 'function') {
            var original = window.proceedOrder;
            window.proceedOrder = function () {
                if (typeof cart !== 'undefined' && cart.length === 0) return;
                original();
                buildTender();
            };
        }
    }

    /* ---------- Sale complete ---------- */
    window.showSaleDone = function (info) {
        var modal = $('sale-done-modal');
        if (!modal) return;
        var change = Math.round((info.received - info.total) * 100) / 100;
        $('done-ref').textContent = info.reference ? 'Reference ' + info.reference : '';
        var printLink = $('done-print');
        if (printLink) {
            // One receipt for the whole order; ?print=1 opens the print dialog straight away.
            printLink.style.display = info.reference ? '' : 'none';
            if (info.reference) printLink.href = 'Receipts.php?ref=' + encodeURIComponent(info.reference) + '&print=1';
        }
        if ($('done-method')) $('done-method').textContent = info.method || 'Cash';
        $('done-total').textContent = money(info.total);
        $('done-received').textContent = money(info.received);
        $('done-change').textContent = money(Math.max(0, change));
        modal.style.display = 'flex';
        var btn = $('done-close');
        if (btn) btn.focus();
    };
    window.closeSaleDone = function () {
        var modal = $('sale-done-modal');
        if (modal) modal.style.display = 'none';
        var search = $('pos-search');
        if (search && window.innerWidth > 900) search.focus();
    };

    /* ---------- Start-shift quick amounts ---------- */
    function initFillChips() {
        document.querySelectorAll('[data-fill]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var target = document.querySelector(chip.getAttribute('data-fill'));
                if (target) {
                    target.value = chip.getAttribute('data-amount');
                    target.focus();
                }
            });
        });
    }

    /* ---------- Narrow screens: Products / Order switch ---------- */
    function initPaneSwitch() {
        var main = $('pos-area');
        if (!main) return;
        var buttons = main.querySelectorAll('[data-pane-target]');
        function show(pane) {
            main.setAttribute('data-pane', pane);
            buttons.forEach(function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-pane-target') === pane);
            });
        }
        buttons.forEach(function (b) {
            b.addEventListener('click', function () { show(b.getAttribute('data-pane-target')); });
        });
    }

    /* ---------- Keyboard ---------- */
    function initKeys() {
        document.addEventListener('keydown', function (e) {
            var done = $('sale-done-modal');
            var pay = $('transaction-modal');
            var shift = $('end-shift-modal');

            if (e.key === 'Escape') {
                if (isOpen(done)) { closeSaleDone(); return; }
                if (isOpen(pay)) { closeTransactionModal(); return; }
                if (typeof window.posCloseUserMenu === 'function') window.posCloseUserMenu();
                var search = $('pos-search');
                if (search && document.activeElement === search && search.value) {
                    search.value = '';
                    filterPosProducts();
                }
                return;
            }

            if (e.key === 'F2') {
                e.preventDefault();
                if (isOpen(done) || isOpen(pay) || isOpen(shift)) return;
                var payBtn = $('proceed-btn');
                if (payBtn && !payBtn.disabled) proceedOrder();
                return;
            }

            if (e.key === '/' && !isTyping(e.target) && !e.ctrlKey && !e.metaKey && !e.altKey) {
                var s = $('pos-search');
                if (s && !isOpen(done) && !isOpen(pay) && !isOpen(shift)) {
                    e.preventDefault();
                    s.focus();
                    s.select();
                }
            }
        });
    }

    /* ---------- Page initialization ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        tickClock();
        setInterval(tickClock, 15000);
        initUserMenu();
        initCatalog();
        initPayment();
        initFillChips();
        initPaneSwitch();
        initKeys();
        var search = $('pos-search');
        if (search && window.innerWidth > 900) search.focus();
    });
})();
