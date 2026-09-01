/*
 * File: script.js
 * Purpose: Client-side UI helpers for POS and admin interfaces.
 * - Handles cart manipulation, discounts, UI modals, filtering, and inventory snapshot sync.
 * Known issues / improvements (locations):
 * - Large monolithic file; split POS logic, utilities, and DOM rendering for maintainability.
 * - Some global variables may cause collisions; inspect `let cart = []` at line 70 and globals near lines 1-90.
 * - Discount option loading and state live in `window.cashierSelectableDiscounts` around lines 81-82 and network loading at lines 445,452-458.
 * - Network/error handling and user feedback are minimal; see `confirmTransaction()` and fetch handling around lines 520-580.
 */
function togglePassword() {
    const passwordInput = document.getElementById('passID');
    if (!passwordInput) return;
    passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
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
    const form = document.getElementById('editForm');
    if (form) form.style.display = 'none';
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

let cart = [];
let total = 0;
let subtotal = 0;
let saleType = 'Retail';
let selectedDiscountId = 0;
let selectedDiscountRule = null;

function getSelectedDiscountRule() {
    const selectedRadio = document.querySelector('input[name="selected_discount"]:checked');
    const discountId = selectedRadio ? parseInt(selectedRadio.value, 10) || 0 : 0;
    if (!discountId && !selectedRadio) return null;
    if (!Array.isArray(window.cashierSelectableDiscounts)) return null;
    const rule = window.cashierSelectableDiscounts.find(d => Number(d.id) === Number(discountId));
    return rule || null;
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
        const discount = rule.discount_type === 'percentage' ? item.price * (value / 100) : value;
        return Math.min(item.price, discount);
    }

    if (saleType !== 'Wholesale') {
        return 0;
    }

    return item.price * 0.20;
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

function addToCart(id, name, price, unit) {
    const productCard = document.querySelector('.product-card[data-product-id="' + id + '"]');
    const stock = productCard ? parseInt(productCard.getAttribute('data-stock') || '0', 10) : 0;
    const existing = cart.find(item => item.id === id && item.unit === unit);
    if (existing) {
        if (existing.quantity >= stock) {
            setPosFeedback('Not enough stock available.', 'error');
            return;
        }
        existing.quantity += 1;
    } else {
        if (stock <= 0) {
            setPosFeedback('This item is out of stock.', 'error');
            return;
        }
        cart.push({ id, name, price: Number(price), unit, quantity: 1, manual_discount: 0 });
    }

    if (selectedDiscountId > 0) {
        updateSelectedDiscount();
    } else {
        updateCart();
    }
}

function updateDiscount(index, discountValue) {
    if (!cart[index]) return;
    cart[index].manual_discount = Math.max(0, parseFloat(discountValue) || 0);
    updateCart();
}

function setCartQuantity(index, value) {
    if (!cart[index]) return;
    let newQuantity = Math.floor(Number(value));

    if (!Number.isFinite(newQuantity) || newQuantity <= 0) {
        removeFromCart(index);
        return;
    }

    const productCard = document.querySelector('.product-card[data-product-id="' + cart[index].id + '"]');
    const stock = productCard ? parseInt(productCard.getAttribute('data-stock') || '9999', 10) : 9999;
    if (newQuantity > stock) {
        setPosFeedback(`Only ${stock} in stock. Quantity set to ${stock}.`, 'error');
        newQuantity = stock;
    }

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
        const totalItems = cart.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
        countBadge.textContent = totalItems === 1 ? '1 item' : `${totalItems} items`;
    }

    cart.forEach((item, index) => {
        const autoInfo = getAutomaticDiscountInfo(item);
        const autoDiscount = autoInfo.value;
        const manualDiscount = window.cashierCanApplyDiscounts ? Math.min(item.price, Number(item.manual_discount) || 0) : 0;
        const itemDiscount = Math.max(autoDiscount, manualDiscount);
        const itemSubtotal = item.quantity * item.price;
        const itemDiscountTotal = item.quantity * itemDiscount;
        const itemTotal = Math.max(0, itemSubtotal - itemDiscountTotal);

        subtotal += itemSubtotal;
        total += itemTotal;
        totalDiscount += itemDiscountTotal;

        const cartItem = document.createElement('div');
        cartItem.className = 'cart-item' + (itemDiscount > 0 ? ' has-discount' : '');

        const itemInfo = document.createElement('div');
        itemInfo.className = 'item-info';

        const itemName = document.createElement('span');
        itemName.className = 'item-name';
        itemName.textContent = item.name;

        const qtyControls = document.createElement('span');
        qtyControls.className = 'qty-controls';

        const decrementButton = document.createElement('button');
        decrementButton.type = 'button';
        decrementButton.className = 'btn-qty';
        decrementButton.setAttribute('aria-label', 'Decrease quantity');
        decrementButton.textContent = '−';
        decrementButton.addEventListener('click', () => changeCartQuantity(index, -1));

        const quantityInput = document.createElement('input');
        quantityInput.type = 'number';
        quantityInput.className = 'item-qty-input';
        quantityInput.min = '1';
        quantityInput.step = '1';
        quantityInput.inputMode = 'numeric';
        quantityInput.value = item.quantity;
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
        incrementButton.className = 'btn-qty';
        incrementButton.setAttribute('aria-label', 'Increase quantity');
        incrementButton.textContent = '+';
        incrementButton.addEventListener('click', () => changeCartQuantity(index, 1));

        qtyControls.appendChild(decrementButton);
        qtyControls.appendChild(quantityInput);
        qtyControls.appendChild(incrementButton);

        itemInfo.appendChild(itemName);
        itemInfo.appendChild(qtyControls);

        const itemPrices = document.createElement('div');
        itemPrices.className = 'item-prices';
        itemPrices.innerHTML = `
            <div class="item-price-row"><span>Unit: PHP ${item.price.toFixed(2)}</span></div>
            <div class="item-price-row"><span>Total: PHP ${itemTotal.toFixed(2)}</span></div>
            <div class="item-discount"><span>Discount: -PHP ${itemDiscountTotal.toFixed(2)}</span></div>
            <div class="small-text"><span>Promo: ${manualDiscount > autoDiscount ? 'Manual discount' : autoInfo.label}</span></div>
        `;

        cartItem.appendChild(itemInfo);
        cartItem.appendChild(itemPrices);

        if (window.cashierCanApplyDiscounts) {
            const discountInput = document.createElement('input');
            discountInput.type = 'number';
            discountInput.step = '0.01';
            discountInput.min = '0';
            discountInput.max = item.price.toFixed(2);
            discountInput.value = manualDiscount.toFixed(2);
            discountInput.placeholder = 'Manual discount';
            discountInput.addEventListener('change', event => updateDiscount(index, event.target.value));
            cartItem.appendChild(discountInput);
        }

        const cartItemControls = document.createElement('div');
        cartItemControls.className = 'cart-item-controls';
        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'btn-remove';
        removeButton.textContent = 'Remove';
        removeButton.addEventListener('click', () => removeFromCart(index));
        cartItemControls.appendChild(removeButton);
        cartItem.appendChild(cartItemControls);

        cartItems.appendChild(cartItem);
    });

    if (subtotalEl) subtotalEl.textContent = `PHP ${subtotal.toFixed(2)}`;
    if (totalDiscountEl) totalDiscountEl.textContent = `PHP ${totalDiscount.toFixed(2)}`;
    cartTotal.textContent = `PHP ${total.toFixed(2)}`;
    if (proceedBtn) proceedBtn.disabled = cart.length === 0 || !window.sessionOpen;

    const discountRow = document.getElementById('discount-row');
    if (discountRow) discountRow.classList.toggle('is-active', totalDiscount > 0);
}

function removeFromCart(index) {
    cart.splice(index, 1);
    updateCart();
}

function changeCartQuantity(index, delta) {
    if (!cart[index]) return;
    const newQuantity = cart[index].quantity + delta;
    if (newQuantity <= 0) {
        cart.splice(index, 1);
    } else {
        const productCard = document.querySelector('.product-card[data-product-id="' + cart[index].id + '"]');
        const stock = productCard ? parseInt(productCard.getAttribute('data-stock') || '9999', 10) : 9999;
        if (newQuantity > stock) {
            setPosFeedback('Not enough stock available.', 'error');
            return;
        }
        cart[index].quantity = newQuantity;
    }
    if (selectedDiscountId > 0) {
        updateSelectedDiscount();
    } else {
        updateCart();
    }
}

function showCategory(category) {
    const cards = document.querySelectorAll('.product-card');
    cards.forEach(card => {
        const cardCategory = card.getAttribute('data-category') || '';
        card.style.display = category === cardCategory ? 'block' : 'none';
    });

    document.querySelectorAll('.tab-button').forEach(tab => {
        tab.classList.toggle('active', tab.textContent.trim() === category);
    });

    filterPosProducts();
}

function updateSaleType() {
    const checked = document.querySelector('input[name="sale_type"]:checked');
    saleType = checked ? checked.value : 'Retail';
    updateCart();
}

function renderDiscountOptions(discounts) {
    const container = document.getElementById('discount-options-container');
    if (!container) return;

    const list = Array.isArray(discounts) ? discounts : [];
    const currentSelection = document.querySelector('input[name="selected_discount"]:checked');
    const currentValue = currentSelection ? currentSelection.value : '';

    container.innerHTML = '';

    const noDiscount = document.createElement('label');
    noDiscount.className = 'segment-option';
    noDiscount.innerHTML = '<input type="radio" name="selected_discount" value="" checked onchange="updateSelectedDiscount()"><span>No Discount</span>';
    container.appendChild(noDiscount);

    if (list.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'small-text';
        empty.textContent = 'No cashier-selectable promotions right now. Order-wide promos and wholesale pricing still apply automatically.';
        container.appendChild(empty);
        return;
    }

    list.forEach(discount => {
        const label = document.createElement('label');
        label.className = 'segment-option';

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'selected_discount';
        radio.value = String(discount.id);
        radio.checked = String(discount.id) === String(currentValue);
        radio.onchange = updateSelectedDiscount;

        const span = document.createElement('span');
        const detailText = discount.discount_type === 'percentage'
            ? `${Number(discount.discount_value).toFixed(2)}% off`
            : `PHP ${Number(discount.discount_value).toFixed(2)} off`;

        const scopeText = (discount.scope || 'order') === 'product' ? 'product only' : 'order-wide';
        const minQty = Number(discount.min_qty || 1);
        const minText = minQty > 1 ? ` &middot; min ${minQty} items` : '';
        span.innerHTML = `${discount.name}<em>${detailText} &middot; ${scopeText}${minText}</em>`;

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
        .then(response => response.json())
        .then(data => {
            const discounts = data && Array.isArray(data.discounts) ? data.discounts : (window.cashierSelectableDiscounts || []);
            window.cashierSelectableDiscounts = discounts;
            renderDiscountOptions(discounts);
            updateSelectedDiscount();
        })
        .catch(() => {
            renderDiscountOptions(window.cashierSelectableDiscounts || []);
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

function proceedOrder() {
    if (cart.length === 0) return;
    const totalEl = document.getElementById('trans-total');
    const amountReceived = document.getElementById('amount-received');
    const changeAmount = document.getElementById('change-amount');
    const confirmBtn = document.querySelector('.btn-confirm-trans');
    const modal = document.getElementById('transaction-modal');
    if (totalEl) totalEl.textContent = `PHP ${total.toFixed(2)}`;
    if (amountReceived) amountReceived.value = '';
    if (changeAmount) { changeAmount.textContent = 'PHP 0.00'; changeAmount.style.color = ''; }
    if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.style.opacity = '0.5'; confirmBtn.style.cursor = 'not-allowed'; }
    if (modal) modal.style.display = 'block';
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
    const confirmBtn = document.querySelector('.btn-confirm-trans');
    if (!amountReceived || !changeAmount) return;
    const received = parseFloat(amountReceived.value) || 0;
    const change = received - total;
    changeAmount.textContent = `PHP ${change.toFixed(2)}`;
    if (confirmBtn) {
        confirmBtn.disabled = change < 0;
        confirmBtn.style.opacity = change < 0 ? '0.5' : '';
        confirmBtn.style.cursor = change < 0 ? 'not-allowed' : '';
    }
    if (changeAmount) {
        changeAmount.style.color = change < 0 ? '#c0392b' : '';
    }
}

function setPosFeedback(message, type) {
    const feedback = document.getElementById('pos-feedback');
    if (!feedback) return;
    feedback.className = `message ${type}`;
    feedback.textContent = message;
    feedback.style.display = 'block';
}

function applyInventorySnapshot(items) {
    Object.entries(items || {}).forEach(([productId, data]) => {
        const stock = Number(data.stock_quantity ?? data) || 0;
        const productCard = document.querySelector(`.product-card[data-product-id="${productId}"]`);
        if (productCard) {
            productCard.setAttribute('data-stock', stock);
            const liveStock = productCard.querySelector('.live-stock');
            if (liveStock) liveStock.textContent = stock;
            const button = productCard.querySelector('button');
            if (button) button.disabled = stock <= 0;
            productCard.classList.toggle('out-of-stock', stock <= 0);
        }

        const row = document.querySelector(`#inventoryTable tbody tr[data-product-id="${productId}"]`);
        if (row) {
            const stockCell = row.querySelector('[data-stock-cell]');
            if (stockCell) stockCell.textContent = stock;
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

function confirmTransaction() {
    console.log("ConfirmTransaction triggered");
    if (!cart || cart.length === 0) {
    alert("Cart is empty");
    return;
    }
    const amountReceived = document.getElementById('amount-received');
    const received = amountReceived ? parseFloat(amountReceived.value) || 0 : 0;
    if (received < total) {
        window.alert('Amount received is less than total.');
        return;
    }
    if (!window.processSaleUrl) return;

    const selectedRadio = document.querySelector('input[name="selected_discount"]:checked');
    const selectedDiscountIdForRequest = selectedRadio ? parseInt(selectedRadio.value, 10) || 0 : 0;
    const payloadCart = cart.map(item => ({
        id: item.id,
        quantity: item.quantity,
        price: item.price,
        unit: item.unit,
        manual_discount: Number(item.manual_discount || 0)
    }));

    fetch(window.processSaleUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            cart: payloadCart,
            sale_type: saleType,
            selected_discount_id: selectedDiscountIdForRequest,
            amount_received: received,
            change_amount: received - total
        })
    })
        .then(async response => {
            const data = await response.json();
            console.log('RAW RESPONSE:', data);
            return data;
        })
        .then(data => {
            if (!data.success) {
                setPosFeedback(data.message || 'Unable to process sale.', 'error');
                return;
            }

            setPosFeedback(`${data.message} Reference: ${data.sale_reference}`, 'success');
            if (data.inventory) {
                const normalized = {};
                Object.entries(data.inventory).forEach(([key, value]) => {
                    normalized[key] = { stock_quantity: value };
                });
                applyInventorySnapshot(normalized);
            }
            const shiftValue = document.getElementById('shift-sales-value');
            if (shiftValue && data.shift) {
                shiftValue.textContent = `PHP ${Number(data.shift.total_sales).toFixed(2)}`;
            }
            cancelOrder();
            closeTransactionModal();
            syncInventorySnapshot();
        })
        .catch(() => {
            setPosFeedback('Network error while processing the sale.', 'error');
        });
}

function openEndShiftModal() {
    const modal = document.getElementById('end-shift-modal');
    if (modal) modal.style.display = 'block';
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
    if (modal) modal.style.display = 'none';
}

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
        const quantity = parseInt(row.cells[quantityIndex]?.textContent || '0', 10);
        let show = true;
        if (filter.value === 'low') show = quantity < 10;
        if (filter.value === 'medium') show = quantity >= 10 && quantity < 50;
        if (filter.value === 'high') show = quantity >= 50;
        row.style.display = show ? '' : 'none';
    });
}

function filterPosProducts() {
    const input = document.getElementById('pos-search');
    if (!input) return;
    const term = input.value.trim().toLowerCase();
    const activeTab = document.querySelector('.tab-button.active');
    const activeCategory = activeTab ? activeTab.textContent.trim() : '';
    document.querySelectorAll('.product-card').forEach(card => {
        const name = (card.getAttribute('data-name') || '').toLowerCase();
        const category = card.getAttribute('data-category') || '';
        const categoryMatch = !activeCategory || category === activeCategory;
        const textMatch = !term || name.includes(term);
        card.style.display = categoryMatch && textMatch ? 'block' : 'none';
    });
}

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