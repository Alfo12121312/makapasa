/*
 * File: script.js
 * Purpose: Client-side UI helpers for POS and admin interfaces.
 * Sections: shared page helpers, POS cart/discount/payment logic, POS
 * register interactions, filtering, page setup, and shared app components.
 */

/* ---------- Shared page helpers ---------- */
function togglePassword(button) {
    const toggleButton = button || document.querySelector('.field-toggle-btn');
    if (!toggleButton) return;

    // Look for the password input next to this specific button first
    // (supports more than one password field/toggle on the same page);
    // fall back to #passID for any existing markup that doesn't wrap the
    // input and button in a shared container.
    const wrapper = toggleButton.closest('.field-with-toggle');
    const passwordInput = (wrapper && wrapper.querySelector('input[type="password"], input.password-field'))
        || document.getElementById('passID');
    if (!passwordInput) return;

    const showPassword = passwordInput.type === 'password';
    passwordInput.type = showPassword ? 'text' : 'password';
    passwordInput.classList.add('password-field');

    toggleButton.textContent = showPassword ? 'Hide' : 'Show';
    toggleButton.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
    toggleButton.setAttribute('aria-pressed', String(showPassword));
}

function printReport() {
    window.print();
}

function confirmDelete() {
    return confirm('Are you sure you want to deactivate?');
}

function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    sidebar.classList.toggle('collapsed');
}

function toggleSidebarGroup(button) {
    const group = button.closest('.sidebar-group');
    if (!group) return;
    group.classList.toggle('open');
}

function cancelEdit() {
    // Shared by every page that calls cancelEdit() from a Cancel/Close
    // button. Only one of these will exist on any given page, so this
    // stays a single consolidated function instead of each page defining
    // (and silently overriding) its own same-named version.
    const editForm = document.getElementById('editForm');
    if (editForm) editForm.style.display = 'none';

    const editPopup = document.getElementById('editPopup');
    if (editPopup) editPopup.style.display = 'none';
}

function updateStock(id, currentQuantity) {
    const stockId = document.getElementById('stock_product_id');
    const newQuantity = document.getElementById('new_quantity');
    const stockForm = document.getElementById('stockForm');
    const editForm = document.getElementById('editForm');
    if (stockId) stockId.value = id;
    if (newQuantity) newQuantity.value = currentQuantity;
    if (stockForm) stockForm.style.display = 'block';
    if (editForm) editForm.style.display = 'none';
}

function cancelStockUpdate() {
    const form = document.getElementById('stockForm');
    if (form) form.style.display = 'none';
}

function confirmTransferType() {
    const selectedType = document.getElementById('transfer_inventory_type');
    return selectedType ? confirm(`Are you sure you want to transfer this item to ${selectedType.value} inventory?`) : true;
}

function updateTransferButtonLabel() {
    const selectedType = document.getElementById('transfer_inventory_type');
    const transferButton = document.getElementById('transfer_submit_button');
    if (selectedType && transferButton) {
        transferButton.textContent = `Transfer to ${selectedType.value}`;
    }
}

/* ---------- POS state and discount calculations ---------- */
// Money formatting for the POS screen: 12,345.60
function posMoney(value) {
    return (Number(value) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

let cart = [];
let total = 0;
let subtotal = 0;
let saleType = 'Retail';
let selectedDiscountId = 0;
let selectedDiscountRule = null;
let discountOptionsSignature = null;

function getSelectedDiscountRule() {
    const selectedRadio = document.querySelector('input[name="selected_discount"]:checked');
    const discountId = selectedRadio ? parseInt(selectedRadio.value, 10) || 0 : 0;
    if (!discountId && !selectedRadio) return null;
    if (!Array.isArray(window.cashierSelectableDiscounts)) return null;
    const rule = window.cashierSelectableDiscounts.find(d => Number(d.id) === Number(discountId));
    return rule || null;
}

/* Order-wide FIXED discount: api/process_sale.php splits the amount across the lines in proportion to each
   line's subtotal, rounds every share to cents (any leftover cent goes to the biggest line) and then turns the
   share into a per-unit discount rounded to cents. This does exactly the same, so the total on screen is the
   total the server records (otherwise "Exact" cash could come up a centavo short and the sale be refused). */
function fixedOrderPerUnit(rule, item) {
    const idx = cart.indexOf(item);
    if (idx === -1) return 0;
    const fixed = Number(rule.discount_value) || 0;
    const subs = cart.map(ci => Number(ci.price || 0) * Number(ci.quantity || 0));
    const orderSubtotal = subs.reduce((sum, v) => sum + v, 0);
    const totalQty = cart.reduce((sum, ci) => sum + Number(ci.quantity || 0), 0);

    const shares = subs.map((sub, i) => {
        let exact = orderSubtotal > 0
            ? fixed * (sub / orderSubtotal)
            : (totalQty > 0 ? fixed / totalQty : 0) * Number(cart[i].quantity || 0);
        if (exact > sub) exact = sub;
        return roundMoney(exact);
    });
    const remainder = roundMoney(fixed - roundMoney(shares.reduce((sum, v) => sum + v, 0)));
    if (Math.abs(remainder) >= 0.01) {
        let maxIdx = -1;
        let maxSub = -1;
        subs.forEach((sub, i) => { if (sub > maxSub) { maxSub = sub; maxIdx = i; } });
        if (maxIdx !== -1) shares[maxIdx] = roundMoney(shares[maxIdx] + remainder);
    }

    const qty = Math.max(0.001, Number(item.quantity) || 1);
    return Math.min(Number(item.price) || 0, roundMoney(shares[idx] / qty));
}

/* Tells the cashier why a chosen discount is not taking anything off yet (minimum quantity not reached,
   or the product-specific promo's product is not in the order). Empty string when nothing to explain. */
function discountHintText() {
    const rule = selectedDiscountRule;
    if (!rule || cart.length === 0) return '';
    const minQty = Number(rule.min_qty || 1);
    if (rule.scope === 'product') {
        const line = cart.find(ci => Number(ci.id) === Number(rule.product_id));
        if (!line) return `${rule.name} only applies to one specific product, which is not in this order.`;
        const missing = minQty - Number(line.quantity || 0);
        return missing > 0 ? `${rule.name}: add ${formatQty(Math.ceil(missing * 100) / 100)} more of ${line.name} to qualify (minimum ${minQty}).` : '';
    }
    const totalQty = cart.reduce((sum, ci) => sum + Number(ci.quantity || 0), 0);
    const missing = minQty - totalQty;
    return missing > 0 ? `${rule.name}: add ${formatQty(Math.ceil(missing * 100) / 100)} more item(s) to qualify (minimum ${minQty} items).` : '';
}

function getAutomaticDiscount(item) {
    const selectedRule = item.selected_discount || selectedDiscountRule || getSelectedDiscountRule();
    if (selectedRule) {
        const rule = selectedRule;
        if (!rule) return 0;

        const minQty = Number(rule.min_qty || 1);
        const appliesToProduct = rule.scope !== 'product' || Number(rule.product_id) === Number(item.id);
        if (!appliesToProduct) return 0;

        const quantityForRule = rule.scope === 'order'
            ? cart.reduce((sum, cartItem) => sum + Number(cartItem.quantity || 0), 0)
            : item.quantity;

        if (quantityForRule < minQty) return 0;

        const value = Number(rule.discount_value) || 0;
        let discount = 0;
        if (rule.discount_type === 'percentage') {
            discount = item.price * (value / 100);
        } else if ((rule.scope || 'order') === 'order') {
            // Fixed amount on the whole order: same cent-exact split the server uses.
            discount = fixedOrderPerUnit(rule, item);
        } else {
            discount = value; // product-scoped fixed amount per-unit
        }
        return Math.min(item.price, discount);
    }

    if (saleType !== 'Wholesale') {
        return 0;
    }

    // Same rate the server applies in api/process_sale.php (Settings > wholesale discount).
    const wholesaleRate = Math.max(0, Math.min(100, Number(window.wholesalePercent ?? 10))) / 100;
    return item.price * wholesaleRate;
}

function getAutomaticDiscountInfo(item) {
    const automatic = getAutomaticDiscount(item);
    let label = automatic > 0 ? 'Auto promo' : 'No promo';

    if (item.selected_discount) {
        const rule = item.selected_discount;
        const minQty = Number(rule.min_qty ?? 1);
        const totalQty = cart.reduce((sum, cartItem) => sum + Number(cartItem.quantity || 0), 0);
        const quantityForRule = (rule.scope || 'order') === 'order' ? totalQty : item.quantity;
        const ruleApplies = quantityForRule >= minQty;
        label = rule.name || 'Selected discount';
        if (!ruleApplies) {
            label += ` (requires ${minQty} ${rule.scope === 'order' ? 'total items' : 'item(s)'})`;
        }
        return { value: automatic, label };
    }

    if (saleType === 'Wholesale' && automatic > 0) {
        label = 'Wholesale promo';
    }

    if (Array.isArray(window.activeDiscountRules)) {
        window.activeDiscountRules.forEach(rule => {
            const minQty = Number(rule.min_qty ?? 1);
            const matchesQty = (item.quantity || 1) >= minQty;
            const matchesScope = (rule.scope || 'order') !== 'product' || Number(rule.product_id) === Number(item.id);
            if (!matchesQty || !matchesScope) return;

            const value = Number(rule.discount_value) || 0;
            const candidate = rule.discount_type === 'percentage' ? item.price * (value / 100) : value;
            if (Math.min(item.price, candidate) === automatic && automatic > 0) {
                label = rule.name || 'Auto promo';
            }
        });
    }

    return { value: automatic, label };
}

/* ---------- Quantity, stock, and cart operations ---------- */
/* Kg items can be sold in fractions (1/2 kg, or "PHP 200 worth"). Everything else stays whole. */
function isWeighedUnit(unit) {
    return ['kg', 'kgs', 'kls', 'kilo', 'kilos', 'kilogram', 'kilograms'].indexOf(String(unit || '').toLowerCase().replace(/[\s.]+/g, '')) !== -1;
}

function roundMoney(value) {
    // +1e-9 so values like 18.675 (stored as 18.67499999...) round up to 18.68, matching PHP round().
    return Math.round((Number(value) + 1e-9) * 100) / 100;
}

function roundKg(value) {
    return Math.round(Number(value) * 100) / 100;
}

function getProductStock(id, fallback) {
    const productCard = document.querySelector('.pos-item[data-product-id="' + id + '"]');
    const stock = productCard ? parseFloat(productCard.getAttribute('data-stock')) : NaN;
    return Number.isFinite(stock) ? stock : fallback;
}

function formatQty(value) {
    return String(Number(Number(value).toFixed(3)));
}

/* "PHP amount" sales for kg items: the cashier can type the peso amount in the line total box and the
   kilos are worked out as amount / price per kg (e.g. 20 / 36 = 0.556 kg). The typed amount is kept on
   the line (item.amount) so the line still totals exactly what was typed, not 0.556 x 36 = 20.016.
   api/process_sale.php applies the very same rule, so the screen and the recorded sale always agree. */
function roundKg3(value) {
    return Math.round((Number(value) + 1e-9) * 1000) / 1000;
}

// The typed peso amount for this line, or null when the line is an ordinary quantity line
// (the amount is ignored as soon as the quantity no longer matches amount / price).
function lineAmount(item) {
    if (item.amount === undefined || item.amount === null) return null;
    if (!isWeighedUnit(item.unit) || !(Number(item.price) > 0)) return null;
    const amount = Number(item.amount);
    if (!(amount > 0)) return null;
    return Math.abs(roundKg3(amount / item.price) - Number(item.quantity)) < 0.0005 ? amount : null;
}

function lineSubtotal(item) {
    const amount = lineAmount(item);
    return amount !== null ? roundMoney(amount) : roundMoney(item.quantity * item.price);
}

function lineTotalAfterDiscount(item, discountPerUnit) {
    const amount = lineAmount(item);
    if (amount !== null) {
        return Math.max(0, roundMoney(amount * (item.price - discountPerUnit) / item.price));
    }
    return Math.max(0, roundMoney(item.quantity * (item.price - discountPerUnit)));
}

/* ---------- Cart rendering and line controls ---------- */
function setCartAmount(index, value) {
    const item = cart[index];
    if (!item) return;
    if (!isWeighedUnit(item.unit) || !(Number(item.price) > 0)) {
        updateCart();
        return;
    }

    const amount = roundMoney(parseFloat(String(value).replace(/[^0-9.]/g, '')));
    if (!Number.isFinite(amount) || amount <= 0) {
        setPosFeedback('Enter an amount greater than 0.', 'error');
        updateCart();   // put the previous total back in the box
        return;
    }

    let quantity = roundKg3(amount / item.price);
    if (quantity < 0.001) {
        setPosFeedback('That amount is too small to weigh out.', 'error');
        updateCart();
        return;
    }

    const stock = getProductStock(item.id, 9999);
    if (quantity > stock + 0.0001) {
        quantity = Math.floor(stock * 1000) / 1000;
        delete item.amount;   // capped by stock: price it as a normal quantity line
        setPosFeedback(`Only ${formatQty(stock)} kg in stock (about PHP ${posMoney(roundMoney(quantity * item.price))}). Quantity set to ${formatQty(quantity)} kg.`, 'error');
        if (quantity <= 0) {
            removeFromCart(index);
            return;
        }
    } else {
        item.amount = amount;
    }
    item.quantity = quantity;

    if (selectedDiscountId > 0) {
        updateSelectedDiscount();
    } else {
        updateCart();
    }
}

function addToCart(id, name, price, unit) {
    const stock = getProductStock(id, 0);
    const existing = cart.find(item => item.id === id && item.unit === unit);
    if (existing) {
        if (existing.quantity + 1 > stock + 0.0001) {
            setPosFeedback('Not enough stock available.', 'error');
            return;
        }
        delete existing.amount;
        existing.quantity = isWeighedUnit(unit) ? roundKg(existing.quantity + 1) : existing.quantity + 1;
    } else {
        if (stock <= 0) {
            setPosFeedback('This item is out of stock.', 'error');
            return;
        }
        const startQty = isWeighedUnit(unit) ? Math.min(1, roundKg(stock)) : 1;
        cart.push({ id, name, price: Number(price), unit, quantity: startQty, manual_discount: 0 });
    }

    if (selectedDiscountId > 0) {
        updateSelectedDiscount();
    } else {
        updateCart();
    }
}

function setCartQuantity(index, value) {
    if (!cart[index]) return;
    const weighed = isWeighedUnit(cart[index].unit);
    let newQuantity = weighed ? roundKg(value) : Math.floor(Number(value));

    if (!Number.isFinite(newQuantity) || newQuantity <= 0) {
        removeFromCart(index);
        return;
    }

    const stock = getProductStock(cart[index].id, 9999);
    if (newQuantity > stock) {
        newQuantity = weighed ? Math.floor(stock * 100) / 100 : Math.floor(stock);
        setPosFeedback(`Only ${formatQty(stock)} in stock. Quantity set to ${formatQty(newQuantity)}.`, 'error');
    }

    delete cart[index].amount;
    cart[index].quantity = newQuantity;

    if (selectedDiscountId > 0) {
        updateSelectedDiscount();
    } else {
        updateCart();
    }
}

function updateCart() {
    const cartItems = document.getElementById('cart-items');
    const cartTotal = document.getElementById('cart-total');
    const subtotalEl = document.getElementById('subtotal');
    const totalDiscountEl = document.getElementById('total-discount');
    const proceedBtn = document.getElementById('proceed-btn');
    const emptyState = document.getElementById('cart-empty-state');
    const countBadge = document.getElementById('cart-count-badge');

    if (!cartItems || !cartTotal) return;

    cartItems.innerHTML = '';
    total = 0;
    subtotal = 0;
    let totalDiscount = 0;

    if (emptyState) emptyState.style.display = cart.length === 0 ? 'flex' : 'none';
    cartItems.style.display = cart.length === 0 ? 'none' : 'flex';
    if (countBadge) {
        const totalItems = Math.ceil(cart.reduce((sum, item) => sum + Number(item.quantity || 0), 0));
        countBadge.textContent = totalItems === 1 ? '1 item' : `${totalItems} items`;
    }

    cart.forEach((item, index) => {
        const autoInfo = getAutomaticDiscountInfo(item);
        const autoDiscount = autoInfo.value;
        const manualDiscount = window.cashierCanApplyDiscounts ? Math.min(item.price, Number(item.manual_discount) || 0) : 0;
        const itemDiscount = Math.max(autoDiscount, manualDiscount);
        // Round each line to cents exactly like api/process_sale.php, so on-screen total = recorded total.
        const itemSubtotal = lineSubtotal(item);
        const itemTotal = lineTotalAfterDiscount(item, itemDiscount);
        const itemDiscountTotal = roundMoney(itemSubtotal - itemTotal);

        subtotal += itemSubtotal;
        total += itemTotal;
        totalDiscount += itemDiscountTotal;

        const hasDiscount = itemDiscountTotal > 0;
        const promoLabel = manualDiscount > autoDiscount ? 'Manual discount' : autoInfo.label;

        const cartItem = document.createElement('div');
        cartItem.className = 'pos-line' + (hasDiscount ? ' has-discount' : '');

        const main = document.createElement('div');
        main.className = 'pos-line-main';

        const itemName = document.createElement('span');
        itemName.className = 'pos-line-name';
        itemName.textContent = item.name;

        let lineTotal;
        if (isWeighedUnit(item.unit) && item.price > 0) {
            // Kg items: type the peso amount and the kilos adjust (amount / price per kg).
            lineTotal = document.createElement('input');
            lineTotal.type = 'text';
            lineTotal.className = 'pos-line-total pos-line-total-input';
            lineTotal.inputMode = 'decimal';
            lineTotal.autocomplete = 'off';
            lineTotal.value = posMoney(itemTotal);
            lineTotal.title = 'Type an amount in PHP to set the kilos';
            lineTotal.setAttribute('aria-label', 'Amount in PHP for ' + item.name + ' (changes the kilos)');
            lineTotal.addEventListener('focus', event => event.target.select());
            lineTotal.addEventListener('change', event => setCartAmount(index, event.target.value));
            lineTotal.addEventListener('keydown', event => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    event.target.blur();
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    event.target.value = posMoney(itemTotal);
                    event.target.blur();
                }
            });
        } else {
            lineTotal = document.createElement('span');
            lineTotal.className = 'pos-line-total';
            lineTotal.textContent = posMoney(itemTotal);
        }

        main.appendChild(itemName);
        main.appendChild(lineTotal);

        const sub = document.createElement('div');
        sub.className = 'pos-line-sub';

        const qtyControls = document.createElement('span');
        qtyControls.className = 'pos-qty';

        const decrementButton = document.createElement('button');
        decrementButton.type = 'button';
        decrementButton.className = 'pos-qty-btn';
        decrementButton.setAttribute('aria-label', 'Decrease quantity of ' + item.name);
        decrementButton.textContent = '\u2212';
        decrementButton.addEventListener('click', () => changeCartQuantity(index, -1));

        const quantityInput = document.createElement('input');
        quantityInput.type = 'number';
        quantityInput.className = 'pos-qty-input';
        const weighedLine = isWeighedUnit(item.unit);
        quantityInput.min = weighedLine ? '0.01' : '1';
        quantityInput.step = weighedLine ? '0.01' : '1';
        quantityInput.inputMode = weighedLine ? 'decimal' : 'numeric';
        quantityInput.value = formatQty(item.quantity);
        quantityInput.setAttribute('aria-label', 'Quantity for ' + item.name);
        quantityInput.addEventListener('focus', event => event.target.select());
        quantityInput.addEventListener('change', event => setCartQuantity(index, event.target.value));
        quantityInput.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                event.target.blur();
            }
        });

        const incrementButton = document.createElement('button');
        incrementButton.type = 'button';
        incrementButton.className = 'pos-qty-btn';
        incrementButton.setAttribute('aria-label', 'Increase quantity of ' + item.name);
        incrementButton.textContent = '+';
        incrementButton.addEventListener('click', () => changeCartQuantity(index, 1));

        qtyControls.appendChild(decrementButton);
        qtyControls.appendChild(quantityInput);
        qtyControls.appendChild(incrementButton);

        const unitPrice = document.createElement('span');
        unitPrice.className = 'pos-line-unit';
        unitPrice.textContent = '\u00d7 ' + posMoney(item.price) + (item.unit ? ' / ' + (isWeighedUnit(item.unit) ? 'kg' : item.unit) : '');

        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'pos-line-remove';
        removeButton.setAttribute('aria-label', 'Remove ' + item.name);
        removeButton.title = 'Remove';
        removeButton.innerHTML = '<svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M3 3l8 8M11 3l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
        removeButton.addEventListener('click', () => removeFromCart(index));

        sub.appendChild(qtyControls);
        sub.appendChild(unitPrice);
        sub.appendChild(removeButton);

        cartItem.appendChild(main);
        cartItem.appendChild(sub);

        if (hasDiscount) {
            const promo = document.createElement('div');
            promo.className = 'pos-line-promo';
            const promoName = document.createElement('span');
            promoName.textContent = promoLabel;
            const promoAmount = document.createElement('span');
            promoAmount.textContent = '\u2212' + posMoney(itemDiscountTotal);
            promo.appendChild(promoName);
            promo.appendChild(promoAmount);
            cartItem.appendChild(promo);
        }

        cartItems.appendChild(cartItem);
    });

    if (subtotalEl) subtotalEl.textContent = `PHP ${posMoney(subtotal)}`;
    if (totalDiscountEl) totalDiscountEl.textContent = `PHP ${posMoney(totalDiscount)}`;
    cartTotal.textContent = `PHP ${posMoney(total)}`;
    if (proceedBtn) proceedBtn.disabled = cart.length === 0 || !window.sessionOpen;
    const voidBtn = document.getElementById('void-btn');
    if (voidBtn) voidBtn.disabled = cart.length === 0;
    const paneCount = document.getElementById('pos-pane-count');
    if (paneCount) paneCount.textContent = Math.ceil(cart.reduce((sum, item) => sum + Number(item.quantity || 0), 0));
    if (typeof window.posAfterCartRender === 'function') window.posAfterCartRender();

    const discountRow = document.getElementById('discount-row');
    if (discountRow) discountRow.classList.toggle('is-active', totalDiscount > 0);

    const hintEl = document.getElementById('discount-hint');
    if (hintEl) {
        const hint = discountHintText();
        hintEl.textContent = hint;
        hintEl.hidden = hint === '';
    }
}

function removeFromCart(index) {
    cart.splice(index, 1);
    updateCart();
}

function changeCartQuantity(index, delta) {
    if (!cart[index]) return;
    const weighed = isWeighedUnit(cart[index].unit);
    const step = weighed ? 0.5 : 1;   // kg items go up/down by 1/2 kg
    const newQuantity = weighed ? roundKg(cart[index].quantity + delta * step) : cart[index].quantity + delta;
    if (newQuantity <= 0) {
        cart.splice(index, 1);
    } else {
        const stock = getProductStock(cart[index].id, 9999);
        if (newQuantity > stock + 0.0001) {
            setPosFeedback('Not enough stock available.', 'error');
            return;
        }
        delete cart[index].amount;
        cart[index].quantity = newQuantity;
    }
    if (selectedDiscountId > 0) {
        updateSelectedDiscount();
    } else {
        updateCart();
    }
}

function showUnitFilter(unit) {
    document.querySelectorAll('.unit-tab-button').forEach(tab => {
        const active = tab.getAttribute('data-unit-filter') === unit;
        tab.classList.toggle('active', active);
        tab.setAttribute('aria-pressed', String(active));
    });

    filterPosProducts();
}

function updateSaleType() {
    const checked = document.querySelector('input[name="sale_type"]:checked');
    saleType = checked ? checked.value : 'Retail';
    updateCart();
}

/* ---------- Discount selection and refresh ---------- */
function renderDiscountOptions(discounts) {
    const container = document.getElementById('discount-options-container');
    if (!container) return;

    container.onchange = event => {
        if (event.target.matches('input[name="selected_discount"]')) {
            updateSelectedDiscount();
        }
    };

    const list = Array.isArray(discounts) ? discounts : [];
    const currentSelection = document.querySelector('input[name="selected_discount"]:checked');
    const currentValue = currentSelection ? currentSelection.value : '';

    container.innerHTML = '';

    const noDiscount = document.createElement('label');
    noDiscount.className = 'pos-chip-opt';
    const noDiscountRadio = document.createElement('input');
    noDiscountRadio.type = 'radio';
    noDiscountRadio.name = 'selected_discount';
    noDiscountRadio.value = '';
    noDiscountRadio.checked = currentValue === '';
    const noDiscountLabel = document.createElement('span');
    noDiscountLabel.textContent = 'None';
    noDiscount.appendChild(noDiscountRadio);
    noDiscount.appendChild(noDiscountLabel);
    container.appendChild(noDiscount);

    if (list.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'pos-chips-empty';
        empty.textContent = 'No selectable discounts.';
        container.appendChild(empty);
        return;
    }

    list.forEach(discount => {
        const label = document.createElement('label');
        label.className = 'pos-chip-opt';

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'selected_discount';
        radio.value = String(discount.id);
        radio.checked = String(discount.id) === String(currentValue);

        const span = document.createElement('span');
        const detailText = discount.discount_type === 'percentage'
            ? `${Number(discount.discount_value)}% off`
            : `PHP ${Number(discount.discount_value).toFixed(2)} off`;

        const scopeText = (discount.scope || 'order') === 'product' ? 'product only' : 'order-wide';
        const minQty = Number(discount.min_qty || 1);
        const minText = minQty > 1 ? ` \u00b7 min ${minQty}` : '';
        const nameEl = document.createElement('b');
        nameEl.textContent = discount.name;
        const detailEl = document.createElement('em');
        detailEl.textContent = `${detailText} \u00b7 ${scopeText}${minText}`;
        span.appendChild(nameEl);
        span.appendChild(detailEl);

        label.appendChild(radio);
        label.appendChild(span);
        container.appendChild(label);
    });

    if (currentValue !== '' && !list.some(discount => String(discount.id) === String(currentValue))) {
        const noDiscountRadio = container.querySelector('input[value=""]');
        if (noDiscountRadio) noDiscountRadio.checked = true;
    }
}

function loadDiscountOptions() {
    if (!window.discountOptionsUrl) {
        renderDiscountOptions(window.cashierSelectableDiscounts || []);
        return;
    }

    fetch(window.discountOptionsUrl, { credentials: 'same-origin' })
        .then(response => {
            if (!response.ok) throw new Error(`Discount options request failed (${response.status}).`);
            return response.json();
        })
        .then(data => {
            const discounts = data && Array.isArray(data.discounts) ? data.discounts : (window.cashierSelectableDiscounts || []);
            const signature = JSON.stringify(discounts);
            const container = document.getElementById('discount-options-container');
            const optionsRendered = container && container.querySelector('input[name="selected_discount"]');
            if (signature === discountOptionsSignature && optionsRendered) return;
            // Never rebuild the cart while the cashier is typing in a quantity box (it would save a half-typed value like 0.6).
            const typing = document.activeElement && document.activeElement.classList
                && (document.activeElement.classList.contains('pos-qty-input') || document.activeElement.classList.contains('pos-line-total-input'));
            if (typing) return;
            window.cashierSelectableDiscounts = discounts;
            discountOptionsSignature = signature;
            renderDiscountOptions(discounts);
            updateSelectedDiscount();
        })
        .catch(error => {
            console.error('Unable to refresh cashier discount options.', error);
            const discounts = window.cashierSelectableDiscounts || [];
            const container = document.getElementById('discount-options-container');
            if (!container || !container.querySelector('input[name="selected_discount"]')) {
                renderDiscountOptions(discounts);
                updateSelectedDiscount();
            }
        });
}

function updateSelectedDiscount() {
    const selectedRule = getSelectedDiscountRule();
    selectedDiscountId = selectedRule ? Number(selectedRule.id) : 0;
    selectedDiscountRule = selectedRule;

    cart.forEach(item => {
        if (selectedDiscountRule) {
            const scopeOk = selectedDiscountRule.scope !== 'product' || Number(selectedDiscountRule.product_id) === Number(item.id);
            item.selected_discount = scopeOk ? selectedDiscountRule : null;
        } else {
            item.selected_discount = null;
        }
    });

    updateCart();
}

function cancelOrder() {
    cart = [];
    selectedDiscountId = 0;
    selectedDiscountRule = null;
    const noDiscountRadio = document.querySelector('input[name="selected_discount"][value=""]');
    if (noDiscountRadio) {
        noDiscountRadio.checked = true;
    }
    updateCart();
}

/* ---------- Payment and transaction flow ---------- */
// Mode of payment chosen in the Pay pop-up (Cash, GCash, Maya, Card, Bank Transfer).
let paymentMethod = 'Cash';

function setPaymentMethod(method) {
    paymentMethod = method || 'Cash';
    const isCash = paymentMethod === 'Cash';
    const cashFields = document.getElementById('cash-fields');
    const nonCashFields = document.getElementById('noncash-fields');
    const kicker = document.getElementById('pay-kicker');
    const confirmBtn = document.querySelector('.pos-confirm-sale');
    const amountReceived = document.getElementById('amount-received');
    const reference = document.getElementById('payment-reference');
    if (cashFields) cashFields.hidden = !isCash;
    if (nonCashFields) nonCashFields.hidden = isCash;
    if (kicker) kicker.textContent = isCash ? 'Cash payment' : `${paymentMethod} payment`;
    if (isCash) {
        if (amountReceived) { amountReceived.focus(); }
        calculateChange();
    } else {
        // Cashless = exact amount, nothing to count or give back.
        if (amountReceived) amountReceived.value = total.toFixed(2);
        if (confirmBtn) confirmBtn.disabled = false;
        if (reference) reference.focus();
    }
}

function resetPaymentMethod() {
    const cashRadio = document.querySelector('input[name="payment_method"][value="Cash"]');
    if (cashRadio) cashRadio.checked = true;
    const reference = document.getElementById('payment-reference');
    if (reference) reference.value = '';
    paymentMethod = 'Cash';
    const cashFields = document.getElementById('cash-fields');
    const nonCashFields = document.getElementById('noncash-fields');
    const kicker = document.getElementById('pay-kicker');
    if (cashFields) cashFields.hidden = false;
    if (nonCashFields) nonCashFields.hidden = true;
    if (kicker) kicker.textContent = 'Cash payment';
}

function proceedOrder() {
    if (cart.length === 0) return;
    const totalEl = document.getElementById('trans-total');
    const amountReceived = document.getElementById('amount-received');
    const changeAmount = document.getElementById('change-amount');
    const confirmBtn = document.querySelector('.pos-confirm-sale');
    const modal = document.getElementById('transaction-modal');
    if (totalEl) totalEl.textContent = `PHP ${posMoney(total)}`;
    if (amountReceived) amountReceived.value = '';
    if (changeAmount) { changeAmount.textContent = 'PHP 0.00'; }
    if (confirmBtn) confirmBtn.disabled = true;
    const changeBox = changeAmount ? changeAmount.parentElement : null;
    if (changeBox) changeBox.classList.remove('is-short', 'is-ok');
    const changeLabel = document.getElementById('change-label');
    if (changeLabel) changeLabel.textContent = 'Change';
    resetPaymentMethod();
    if (modal) modal.style.display = 'flex';
    setTimeout(() => { if (amountReceived) amountReceived.focus(); }, 50);
}

//add function for cahsin and output change

function closeTransactionModal() {
    const modal = document.getElementById('transaction-modal');
    if (modal) modal.style.display = 'none';
}

function calculateChange() {
    const amountReceived = document.getElementById('amount-received');
    const changeAmount = document.getElementById('change-amount');
    const confirmBtn = document.querySelector('.pos-confirm-sale');
    if (!amountReceived || !changeAmount) return;
    if (paymentMethod !== 'Cash') return; // cashless payments are always exact
    const received = parseFloat(amountReceived.value) || 0;
    // Work in centavos so 0.1 + 0.2 style float noise never blocks an exact payment.
    const changeCents = Math.round(received * 100) - Math.round(total * 100);
    const change = changeCents / 100;
    const short = changeCents < 0;
    const label = document.getElementById('change-label');
    if (label) label.textContent = short ? 'Still owed' : 'Change';
    changeAmount.textContent = `PHP ${posMoney(Math.abs(change))}`;
    const box = changeAmount.parentElement;
    if (box) {
        box.classList.toggle('is-short', short && received > 0);
        box.classList.toggle('is-ok', !short && received > 0);
    }
    if (confirmBtn) confirmBtn.disabled = short;
}

/* ---------- Inventory snapshot and sale processing ---------- */
let posFeedbackTimer = null;
function setPosFeedback(message, type) {
    const feedback = document.getElementById('pos-feedback');
    if (!feedback) return;
    feedback.className = `pos-snack ${type || 'success'}`;
    feedback.textContent = message;
    feedback.hidden = false;
    clearTimeout(posFeedbackTimer);
    posFeedbackTimer = setTimeout(() => { feedback.hidden = true; }, type === 'error' ? 5000 : 3500);
}

function applyInventorySnapshot(items) {
    Object.entries(items || {}).forEach(([productId, data]) => {
        const stock = Math.round((Number(data.stock_quantity ?? data) || 0) * 1000) / 1000;
        const productCard = document.querySelector(`.pos-item[data-product-id="${productId}"]`);
        if (productCard) {
            productCard.setAttribute('data-stock', stock);
            const liveStock = productCard.querySelector('.live-stock');
            if (liveStock) liveStock.textContent = formatQty(stock);
            productCard.disabled = stock <= 0;
            productCard.classList.toggle('out-of-stock', stock <= 0);
            productCard.classList.toggle('is-low', stock > 0 && stock <= 5);
        }

        const row = document.querySelector(`#inventoryTable tbody tr[data-product-id="${productId}"]`);
        if (row) {
            const stockCell = row.querySelector('[data-stock-cell]');
            if (stockCell) stockCell.textContent = formatQty(stock);
        }
    });
}

function syncInventorySnapshot() {
    if (!window.inventorySnapshotUrl) return;
    fetch(window.inventorySnapshotUrl, { credentials: 'same-origin' })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                applyInventorySnapshot(data.items);
            }
        })
        .catch(() => {});
}

let posSaleInFlight = false;
function confirmTransaction() {
    if (posSaleInFlight) return;
    if (!cart || cart.length === 0) {
        setPosFeedback('The order is empty.', 'error');
        return;
    }
    const amountReceived = document.getElementById('amount-received');
    const isCash = paymentMethod === 'Cash';
    const received = isCash ? (amountReceived ? parseFloat(amountReceived.value) || 0 : 0) : total;
    if (isCash && Math.round(received * 100) < Math.round(total * 100)) {
        setPosFeedback('Amount received is less than the total.', 'error');
        return;
    }
    const referenceInput = document.getElementById('payment-reference');
    const paymentReference = (!isCash && referenceInput) ? referenceInput.value.trim() : '';
    const salePaymentMethod = paymentMethod;
    if (!window.processSaleUrl) return;

    const selectedRadio = document.querySelector('input[name="selected_discount"]:checked');
    const selectedDiscountIdForRequest = selectedRadio ? parseInt(selectedRadio.value, 10) || 0 : 0;
    const payloadCart = cart.map(item => {
        const row = {
            id: item.id,
            quantity: item.quantity,
            price: item.price,
            unit: item.unit,
            manual_discount: Number(item.manual_discount || 0)
        };
        const typedAmount = lineAmount(item);
        if (typedAmount !== null) row.amount = typedAmount;   // server recomputes kilos from this
        return row;
    });

    const saleTotal = total;
    const confirmBtn = document.querySelector('.pos-confirm-sale');
    posSaleInFlight = true;
    if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.classList.add('is-busy'); }

    fetch(window.processSaleUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken || '' },
        body: JSON.stringify({
            csrf_token: window.csrfToken || '',
            cart: payloadCart,
            sale_type: saleType,
            selected_discount_id: selectedDiscountIdForRequest,
            amount_received: received,
            change_amount: isCash ? received - saleTotal : 0,
            payment_method: salePaymentMethod,
            payment_reference: paymentReference
        })
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                setPosFeedback(data.message || 'Unable to process sale.', 'error');
                if (confirmBtn) confirmBtn.disabled = false;
                return;
            }

            if (data.inventory) {
                const normalized = {};
                Object.entries(data.inventory).forEach(([key, value]) => {
                    normalized[key] = { stock_quantity: value };
                });
                applyInventorySnapshot(normalized);
            }
            const shiftValue = document.getElementById('shift-sales-value');
            if (shiftValue && data.shift) {
                const salesTotal = Number(data.shift.total_sales);
                shiftValue.textContent = `PHP ${posMoney(salesTotal)}`;
                const endShiftSales = document.getElementById('end-shift-sales');
                if (endShiftSales) {
                    endShiftSales.textContent = `PHP ${salesTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                }
                const expectedCash = document.getElementById('expected-closing-cash');
                if (expectedCash && Number.isFinite(Number(window.shiftExpectedBase))) {
                    const expectedBase = Number(window.shiftExpectedBase) + salesTotal;
                    expectedCash.dataset.base = expectedBase.toFixed(2);
                    expectedCash.textContent = `PHP ${expectedBase.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                }
            }
            cancelOrder();
            closeTransactionModal();
            syncInventorySnapshot();

            if (typeof showSaleDone === 'function') {
                showSaleDone({ reference: data.sale_reference, total: saleTotal, received: received, method: data.payment_method || salePaymentMethod });
            } else {
                setPosFeedback(`${data.message} Reference: ${data.sale_reference} (${data.payment_method || salePaymentMethod})`, 'success');
            }
        })
        .catch(() => {
            setPosFeedback('Network error while processing the sale. Nothing was charged; try again.', 'error');
            if (confirmBtn) confirmBtn.disabled = false;
        })
        .finally(() => {
            posSaleInFlight = false;
            if (confirmBtn) confirmBtn.classList.remove('is-busy');
        });
}

/* ---------- Shift controls and table filters ---------- */
function openEndShiftModal() {
    const modal = document.getElementById('end-shift-modal');
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        const closingInput = document.getElementById('closing-cash');
        if (closingInput) closingInput.focus();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        loadDiscountOptions();
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                loadDiscountOptions();
            }
        }, 15000);
    });
} else {
    loadDiscountOptions();
    setInterval(() => {
        if (document.visibilityState === 'visible') {
            loadDiscountOptions();
        }
    }, 15000);
}

function closeEndShiftModal() {
    const modal = document.getElementById('end-shift-modal');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        const trigger = document.getElementById('end-shift-trigger');
        if (trigger) trigger.focus();
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && document.getElementById('end-shift-modal')?.style.display === 'flex') {
        closeEndShiftModal();
    }
});

/* ---------- Table and inventory filtering ---------- */
function searchTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!input || !table) return;
    const filter = input.value.toUpperCase();
    const rows = table.getElementsByTagName('tr');

    for (let i = 1; i < rows.length; i += 1) {
        const cells = rows[i].getElementsByTagName('td');
        let match = false;
        for (let j = 0; j < cells.length; j += 1) {
            if (cells[j].textContent.toUpperCase().indexOf(filter) > -1) {
                match = true;
                break;
            }
        }
        rows[i].style.display = match ? '' : 'none';
    }
}

function filterByCategory(categoryFilterId, tableId) {
    const categoryFilter = document.getElementById(categoryFilterId);
    const table = document.getElementById(tableId);
    if (!categoryFilter || !table) return;
    const selectedCategory = categoryFilter.value;
    const rows = table.getElementsByTagName('tr');
    for (let i = 1; i < rows.length; i += 1) {
        const cells = rows[i].getElementsByTagName('td');
        const categoryCell = cells[1];
        rows[i].style.display = selectedCategory === '' || (categoryCell && categoryCell.textContent === selectedCategory) ? '' : 'none';
    }
}

function filterBySupplier(supplierFilterId, tableId) {
    const supplierFilter = document.getElementById(supplierFilterId);
    const table = document.getElementById(tableId);
    if (!supplierFilter || !table) return;
    const selectedSupplier = supplierFilter.value;
    const rows = table.getElementsByTagName('tr');
    for (let i = 1; i < rows.length; i += 1) {
        const cells = rows[i].getElementsByTagName('td');
        const supplierCell = cells[2];
        rows[i].style.display = selectedSupplier === '' || (supplierCell && supplierCell.textContent === selectedSupplier) ? '' : 'none';
    }
}

function getColumnIndex(table, headerText) {
    const header = table?.tHead?.rows?.[0];
    if (!header) return -1;
    for (let i = 0; i < header.cells.length; i += 1) {
        if (header.cells[i].textContent.trim().toLowerCase().includes(headerText.toLowerCase())) {
            return i;
        }
    }
    return -1;
}

function filterByInventoryType(typeFilterId, tableId) {
    const filter = document.getElementById(typeFilterId);
    const table = document.getElementById(tableId);
    if (!filter || !table) return;
    const typeIndex = getColumnIndex(table, 'type');
    Array.from(table.querySelectorAll('tbody tr')).forEach(row => {
        const cell = row.cells[typeIndex];
        row.style.display = filter.value === '' || (cell && cell.textContent === filter.value) ? '' : 'none';
    });
}

function filterByStockStatus(statusFilterId, tableId) {
    const filter = document.getElementById(statusFilterId);
    const table = document.getElementById(tableId);
    if (!filter || !table) return;
    const quantityIndex = getColumnIndex(table, 'stock');
    Array.from(table.querySelectorAll('tbody tr')).forEach(row => {
        const quantity = parseFloat(row.cells[quantityIndex]?.textContent || '0') || 0;
        let show = true;
        if (filter.value === 'low') show = quantity < 10;
        if (filter.value === 'medium') show = quantity >= 10 && quantity < 50;
        if (filter.value === 'high') show = quantity >= 50;
        row.style.display = show ? '' : 'none';
    });
}

/* ---------- POS product filtering ---------- */
function filterPosProducts() {
    const input = document.getElementById('pos-search');
    if (!input) return;
    const term = input.value.trim().toLowerCase();
    const activeTab = document.querySelector('.unit-tab-button.active');
    const activeUnit = activeTab ? activeTab.getAttribute('data-unit-filter') : 'all';
    const categorySelect = document.getElementById('pos-category');
    const activeCategory = categorySelect ? categorySelect.value : '';
    let visibleProducts = 0;
    document.querySelectorAll('.pos-item').forEach(card => {
        const name = (card.getAttribute('data-name') || '').toLowerCase();
        const code = (card.getAttribute('data-code') || '').toLowerCase();
        const unit = card.getAttribute('data-unit-filter') || 'other';
        const category = card.getAttribute('data-category') || '';
        const unitMatch = activeUnit === 'all' || unit === activeUnit;
        const categoryMatch = !activeCategory || category === activeCategory;
        const textMatch = !term || name.includes(term) || code.includes(term);
        const visible = unitMatch && categoryMatch && textMatch;
        card.style.display = visible ? '' : 'none';
        if (visible) visibleProducts++;
    });

    const emptyState = document.getElementById('pos-no-results');
    if (emptyState) emptyState.hidden = visibleProducts > 0;
}

/* ---------- Page setup ---------- */
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.sidebar');
    const menuToggle = document.querySelector('.menu-toggle');

    if (sidebar && window.innerWidth <= 768) {
        sidebar.classList.add('collapsed');
    }

    document.querySelectorAll('.sidebar a').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768 && sidebar) {
                sidebar.classList.add('collapsed');
            }
        });
    });

    document.addEventListener('click', function(event) {
        if (window.innerWidth <= 768 && sidebar && menuToggle) {
            if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                sidebar.classList.add('collapsed');
            }
        }
    });

    if (window.inventorySnapshotUrl) {
        syncInventorySnapshot();
        window.setInterval(syncInventorySnapshot, 10000);
    }
});

/* ========================================================================
 * SHARED APP-PAGE COMPONENTS
 * Toast stack, row-action menus, toolbar search/filter, and a small
 * client-side paginator for plain HTML tables. Used by includes/header.php
 * + includes/footer.php and any page using the .app-toolbar / .row-menu /
 * .app-pagination markup.
 * ==================================================================== */

function showToast(type, message, timeout) {
    var stack = document.getElementById('app-toast-stack');
    if (!stack) return;
    var icons = { success: '✓', error: '✕', warning: '!' };
    var toast = document.createElement('div');
    toast.className = 'app-toast ' + (type || 'success');
    toast.innerHTML =
        '<span class="toast-icon">' + (icons[type] || icons.success) + '</span>' +
        '<span class="toast-message"></span>' +
        '<button type="button" class="toast-close" aria-label="Dismiss">&times;</button>';
    toast.querySelector('.toast-message').textContent = message;
    toast.querySelector('.toast-close').addEventListener('click', function () {
        toast.remove();
    });
    stack.appendChild(toast);
    window.setTimeout(function () {
        if (toast.parentNode) toast.remove();
    }, timeout || 5000);
}

function initAppToasts() {
    if (!Array.isArray(window.__appToasts)) return;
    window.__appToasts.forEach(function (t) {
        showToast(t.type, t.message);
    });
    window.__appToasts = [];
}

function initRowMenus() {
    document.addEventListener('click', function (event) {
        var openMenus = document.querySelectorAll('.row-menu.open');
        var trigger = event.target.closest('.row-menu-trigger');
        var clickedMenu = event.target.closest('.row-menu');

        openMenus.forEach(function (menu) {
            if (menu !== clickedMenu) menu.classList.remove('open');
        });

        if (trigger) {
            var menu = trigger.closest('.row-menu');
            if (menu) menu.classList.toggle('open');
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.row-menu.open').forEach(function (m) {
                m.classList.remove('open');
            });
        }
    });
}

// Wires up any [data-app-search] input to filter rows in the table it
// targets via data-target="#selector". Filters on every <td> text in the row.
function initToolbarSearch() {
    document.querySelectorAll('[data-app-search]').forEach(function (input) {
        var targetSelector = input.getAttribute('data-target');
        var table = targetSelector ? document.querySelector(targetSelector) : input.closest('.app-toolbar').parentElement.querySelector('table');
        if (!table) return;
        var countEl = document.querySelector(input.getAttribute('data-count-target') || '');

        function applyFilter() {
            var term = input.value.trim().toLowerCase();
            var rows = table.querySelectorAll('tbody tr');
            var visible = 0;
            rows.forEach(function (row) {
                if (row.hasAttribute('data-empty-row')) return;
                var text = row.textContent.toLowerCase();
                var match = !term || text.indexOf(term) !== -1;
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            if (countEl) countEl.textContent = visible + (visible === 1 ? ' row' : ' rows');
            document.dispatchEvent(new CustomEvent('app-table-filtered', { detail: { table: table, visible: visible } }));
        }

        input.addEventListener('input', applyFilter);
        applyFilter();
    });
}

document.addEventListener('DOMContentLoaded', function () {
    initAppToasts();
    initRowMenus();
    initToolbarSearch();
});

/* ---------- Top navigation (dropdowns, user menu, mobile menu, clock) ---------- */
(function () {
    function closeAll(except) {
        document.querySelectorAll('.app-nav-group.is-open').forEach(function (g) {
            if (g === except) return;
            g.classList.remove('is-open');
            var t = g.querySelector('.app-nav-trigger');
            var m = g.querySelector('.app-nav-menu');
            if (t) t.setAttribute('aria-expanded', 'false');
            if (m) m.hidden = true;
        });
        var um = document.getElementById('app-user-menu');
        var ub = document.getElementById('app-user-btn');
        if (um && except !== um) { um.hidden = true; if (ub) ub.setAttribute('aria-expanded', 'false'); }
    }

    function tickClock() {
        var t = document.getElementById('app-clock-time');
        if (!t) return;
        var now = new Date();
        t.textContent = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        var d = document.getElementById('app-clock-date');
        if (d) d.textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var nav = document.getElementById('app-nav');
        if (!nav) return;

        nav.querySelectorAll('.app-nav-group').forEach(function (group) {
            var trigger = group.querySelector('.app-nav-trigger');
            var menu = group.querySelector('.app-nav-menu');
            if (!trigger || !menu) return;
            trigger.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = !group.classList.contains('is-open');
                closeAll(group);
                group.classList.toggle('is-open', open);
                trigger.setAttribute('aria-expanded', String(open));
                menu.hidden = !open;
            });
        });

        var userBtn = document.getElementById('app-user-btn');
        var userMenu = document.getElementById('app-user-menu');
        if (userBtn && userMenu) {
            userBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = userMenu.hidden;
                closeAll(userMenu);
                userMenu.hidden = !open;
                userBtn.setAttribute('aria-expanded', String(open));
            });
        }

        var toggle = document.getElementById('app-nav-toggle');
        if (toggle) {
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = !nav.classList.contains('is-open');
                nav.classList.toggle('is-open', open);
                toggle.setAttribute('aria-expanded', String(open));
            });
        }

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.app-nav-group') && !e.target.closest('.app-user')) closeAll();
            if (nav.classList.contains('is-open') && !e.target.closest('#app-nav') && !e.target.closest('#app-nav-toggle')) {
                nav.classList.remove('is-open');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });

        tickClock();
        setInterval(tickClock, 15000);
    });
})();

/* ---------- Cashier POS register interactions ---------- */
/*
 * POS register interactions
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
