function togglePassword() {
    var passwordInput = document.getElementById('passID');
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
    var sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    sidebar.classList.toggle('collapsed');
}

function toggleSidebarGroup(button) {
    var group = button.closest('.sidebar-group');
    if (!group) return;
    group.classList.toggle('open');
}

function cancelEdit() {
    var form = document.getElementById('editForm');
    if (form) form.style.display = 'none';
}

function updateStock(id, currentQuantity) {
    var stockId = document.getElementById('stock_product_id');
    var newQuantity = document.getElementById('new_quantity');
    var stockForm = document.getElementById('stockForm');
    var editForm = document.getElementById('editForm');
    if (stockId) stockId.value = id;
    if (newQuantity) newQuantity.value = currentQuantity;
    if (stockForm) stockForm.style.display = 'block';
    if (editForm) editForm.style.display = 'none';
}

function cancelStockUpdate() {
    var form = document.getElementById('stockForm');
    if (form) form.style.display = 'none';
}

function confirmTransferType() {
    var selectedType = document.getElementById('transfer_inventory_type');
    return selectedType ? confirm('Are you sure you want to transfer this item to ' + selectedType.value + ' inventory?') : true;
}

function updateTransferButtonLabel() {
    var selectedType = document.getElementById('transfer_inventory_type');
    var transferButton = document.getElementById('transfer_submit_button');
    if (selectedType && transferButton) {
        transferButton.textContent = 'Transfer to ' + selectedType.value;
    }
}

var cart = [];
var total = 0;
var subtotal = 0;
var saleType = 'Retail';
var selectedDiscountId = 0;
var selectedDiscountRule = null;

function getAutomaticDiscount(item) {
    // Cashier-selected discount takes full priority
    if (item.selected_discount) {
        var rule = item.selected_discount;
        if (!rule) return 0;

        var minQty = Number(rule.min_qty || 1);
        var appliesToProduct = rule.scope !== 'product' || Number(rule.product_id) === Number(item.id);
        if (!appliesToProduct) return 0;

        var quantityForRule = rule.scope === 'order'
            ? cart.reduce(function(sum, cartItem) { return sum + Number(cartItem.quantity || 0); }, 0)
            : item.quantity;

        if (quantityForRule < minQty) return 0;

        var value = Number(rule.discount_value) || 0;
        var discount = rule.discount_type === 'percentage' ? item.price * (value / 100) : value;
        return Math.min(item.price, discount);
    }

    // Wholesale base discount
    var bestDiscount = saleType === 'Wholesale' ? item.price * 0.1 : 0;

    if (!Array.isArray(window.activeDiscountRules)) return bestDiscount;

    window.activeDiscountRules.forEach(function(rule) {
        if ((item.quantity || 1) < (Number(typeof rule.min_qty !== 'undefined' && rule.min_qty !== null ? rule.min_qty : 1))) return;
        if ((rule.scope || 'order') === 'product' && Number(rule.product_id) !== Number(item.id)) return;

        var value = Number(rule.discount_value) || 0;
        var discount = rule.discount_type === 'percentage' ? item.price * (value / 100) : value;
        bestDiscount = Math.max(bestDiscount, Math.min(item.price, discount));
    });

    return bestDiscount;
}

function getAutomaticDiscountInfo(item) {
    var automatic = getAutomaticDiscount(item);
    var label = automatic > 0 ? 'Auto promo' : 'No promo';

    if (item.selected_discount) {
        var rule = item.selected_discount;
        var minQty = Number(typeof rule.min_qty !== 'undefined' && rule.min_qty !== null ? rule.min_qty : 1);
        var totalQty = cart.reduce(function(sum, cartItem) { return sum + Number(cartItem.quantity || 0); }, 0);
        var quantityForRule = (rule.scope || 'order') === 'order' ? totalQty : item.quantity;
        var ruleApplies = quantityForRule >= minQty;
        label = rule.name || 'Selected discount';
        if (!ruleApplies) {
            label += ' (requires ' + minQty + ' ' + (rule.scope === 'order' ? 'total items' : 'item(s)') + ')';
        }
        return { value: automatic, label: label };
    }

    if (saleType === 'Wholesale' && automatic > 0) {
        label = 'Wholesale promo';
    }

    if (Array.isArray(window.activeDiscountRules)) {
        window.activeDiscountRules.forEach(function(rule) {
            var minQty = Number(typeof rule.min_qty !== 'undefined' && rule.min_qty !== null ? rule.min_qty : 1);
            var matchesQty = (item.quantity || 1) >= minQty;
            var matchesScope = (rule.scope || 'order') !== 'product' || Number(rule.product_id) === Number(item.id);
            if (!matchesQty || !matchesScope) return;

            var value = Number(rule.discount_value) || 0;
            var candidate = rule.discount_type === 'percentage' ? item.price * (value / 100) : value;
            if (Math.min(item.price, candidate) === automatic && automatic > 0) {
                label = rule.name || 'Auto promo';
            }
        });
    }

    return { value: automatic, label: label };
}

function addToCart(id, name, price, unit) {
    var productCard = document.querySelector('.product-card[data-product-id="' + id + '"]');
    var stock = productCard ? parseInt(productCard.getAttribute('data-stock') || '0', 10) : 0;
    var existing = cart.find(function(item) { return item.id === id && item.unit === unit; });
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
        cart.push({ id: id, name: name, price: Number(price), unit: unit, quantity: 1, manual_discount: 0 });
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

function updateCart() {
    var cartItems = document.getElementById('cart-items');
    var cartTotal = document.getElementById('cart-total');
    var subtotalEl = document.getElementById('subtotal');
    var totalDiscountEl = document.getElementById('total-discount');
    var proceedBtn = document.getElementById('proceed-btn');

    if (!cartItems || !cartTotal) return;

    cartItems.innerHTML = '';
    total = 0;
    subtotal = 0;
    var totalDiscount = 0;

    cart.forEach(function(item, index) {
        var autoInfo = getAutomaticDiscountInfo(item);
        var autoDiscount = autoInfo.value;
        var manualDiscount = window.cashierCanApplyDiscounts ? Math.min(item.price, Number(item.manual_discount) || 0) : 0;
        var itemDiscount = Math.max(autoDiscount, manualDiscount);
        var itemSubtotal = item.quantity * item.price;
        var itemDiscountTotal = item.quantity * itemDiscount;
        var itemTotal = Math.max(0, itemSubtotal - itemDiscountTotal);

        subtotal += itemSubtotal;
        total += itemTotal;
        totalDiscount += itemDiscountTotal;

        var cartItem = document.createElement('div');
        cartItem.className = 'cart-item';

        var itemInfo = document.createElement('div');
        itemInfo.className = 'item-info';

        var itemName = document.createElement('span');
        itemName.className = 'item-name';
        itemName.textContent = item.name;

        var qtyControls = document.createElement('span');
        qtyControls.className = 'qty-controls';

        var decrementButton = document.createElement('button');
        decrementButton.type = 'button';
        decrementButton.className = 'btn-qty';
        decrementButton.setAttribute('aria-label', 'Decrease quantity');
        decrementButton.textContent = '−';
        decrementButton.addEventListener('click', function() { return changeCartQuantity(index, -1); });

        var quantityLabel = document.createElement('span');
        quantityLabel.className = 'item-qty';
        quantityLabel.textContent = item.quantity;

        var incrementButton = document.createElement('button');
        incrementButton.type = 'button';
        incrementButton.className = 'btn-qty';
        incrementButton.setAttribute('aria-label', 'Increase quantity');
        incrementButton.textContent = '+';
        incrementButton.addEventListener('click', function() { return changeCartQuantity(index, 1); });

        qtyControls.appendChild(decrementButton);
        qtyControls.appendChild(quantityLabel);
        qtyControls.appendChild(incrementButton);

        itemInfo.appendChild(itemName);
        itemInfo.appendChild(qtyControls);

        var itemPrices = document.createElement('div');
        itemPrices.className = 'item-prices';
        itemPrices.innerHTML =
            '<div class="item-price-row"><span>Unit: PHP ' + item.price.toFixed(2) + '</span></div>' +
            '<div class="item-price-row"><span>Total: PHP ' + itemTotal.toFixed(2) + '</span></div>' +
            '<div class="item-discount"><span>Discount: -PHP ' + itemDiscountTotal.toFixed(2) + '</span></div>' +
            '<div class="small-text"><span>Promo: ' + (manualDiscount > autoDiscount ? 'Manual discount' : autoInfo.label) + '</span></div>';

        cartItem.appendChild(itemInfo);
        cartItem.appendChild(itemPrices);

        if (window.cashierCanApplyDiscounts) {
            var discountInput = document.createElement('input');
            discountInput.type = 'number';
            discountInput.step = '0.01';
            discountInput.min = '0';
            discountInput.max = item.price.toFixed(2);
            discountInput.value = manualDiscount.toFixed(2);
            discountInput.placeholder = 'Manual discount';
            discountInput.addEventListener('change', function(event) { return updateDiscount(index, event.target.value); });
            cartItem.appendChild(discountInput);
        }

        var cartItemControls = document.createElement('div');
        cartItemControls.className = 'cart-item-controls';
        var removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'btn-remove';
        removeButton.textContent = 'Remove';
        removeButton.addEventListener('click', function() { return removeFromCart(index); });
        cartItemControls.appendChild(removeButton);
        cartItem.appendChild(cartItemControls);

        cartItems.appendChild(cartItem);
    });

    if (subtotalEl) subtotalEl.textContent = 'PHP ' + subtotal.toFixed(2);
    if (totalDiscountEl) totalDiscountEl.textContent = 'PHP ' + totalDiscount.toFixed(2);
    cartTotal.textContent = 'PHP ' + total.toFixed(2);
    if (proceedBtn) proceedBtn.disabled = cart.length === 0 || !sessionOpen;
}

function removeFromCart(index) {
    cart.splice(index, 1);
    updateCart();
}

function changeCartQuantity(index, delta) {
    if (!cart[index]) return;
    var newQuantity = cart[index].quantity + delta;
    if (newQuantity <= 0) {
        cart.splice(index, 1);
    } else {
        var productCard = document.querySelector('.product-card[data-product-id="' + cart[index].id + '"]');
        var stock = productCard ? parseInt(productCard.getAttribute('data-stock') || '9999', 10) : 9999;
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
    var cards = document.querySelectorAll('.product-card');
    cards.forEach(function(card) {
        var cardCategory = card.getAttribute('data-category') || '';
        card.style.display = category === cardCategory ? 'block' : 'none';
    });

    document.querySelectorAll('.tab-button').forEach(function(tab) {
        tab.classList.toggle('active', tab.textContent.trim() === category);
    });

    filterPosProducts();
}

function updateSaleType() {
    var checked = document.querySelector('input[name="sale_type"]:checked');
    saleType = checked ? checked.value : 'Retail';
    updateCart();
}

function updateSelectedDiscount() {
    var selectedRadio = document.querySelector('input[name="selected_discount"]:checked');
    var discountId = selectedRadio ? parseInt(selectedRadio.value) || 0 : 0;

    selectedDiscountId = discountId;
    selectedDiscountRule = null;

    if (selectedDiscountId > 0 && Array.isArray(window.cashierSelectableDiscounts)) {
        selectedDiscountRule = window.cashierSelectableDiscounts.find(function(d) { return Number(d.id) === selectedDiscountId; }) || null;
    }

    // Apply or clear discount on every cart item
    cart.forEach(function(item) {
        if (selectedDiscountRule) {
            var scopeOk = selectedDiscountRule.scope !== 'product' || Number(selectedDiscountRule.product_id) === Number(item.id);
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
    var noDiscountRadio = document.querySelector('input[name="selected_discount"][value=""]');
    if (noDiscountRadio) {
        noDiscountRadio.checked = true;
    }
    updateCart();
}

function proceedOrder() {
    if (cart.length === 0) return;
    var totalEl = document.getElementById('trans-total');
    var amountReceived = document.getElementById('amount-received');
    var changeAmount = document.getElementById('change-amount');
    var confirmBtn = document.querySelector('.btn-confirm-trans');
    var modal = document.getElementById('transaction-modal');
    if (totalEl) totalEl.textContent = 'PHP ' + total.toFixed(2);
    if (amountReceived) amountReceived.value = '';
    if (changeAmount) { changeAmount.textContent = 'PHP 0.00'; changeAmount.style.color = ''; }
    if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.style.opacity = '0.5'; confirmBtn.style.cursor = 'not-allowed'; }
    if (modal) modal.style.display = 'block';
    setTimeout(function() { if (amountReceived) amountReceived.focus(); }, 50);
}

//add function for cahsin and output change

function closeTransactionModal() {
    var modal = document.getElementById('transaction-modal');
    if (modal) modal.style.display = 'none';
}

function calculateChange() {
    var amountReceived = document.getElementById('amount-received');
    var changeAmount = document.getElementById('change-amount');
    var confirmBtn = document.querySelector('.btn-confirm-trans');
    if (!amountReceived || !changeAmount) return;
    var received = parseFloat(amountReceived.value) || 0;
    var change = received - total;
    changeAmount.textContent = 'PHP ' + change.toFixed(2);
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
    var feedback = document.getElementById('pos-feedback');
    if (!feedback) return;
    feedback.className = 'message ' + type;
    feedback.textContent = message;
    feedback.style.display = 'block';
}

function applyInventorySnapshot(items) {
    Object.entries(items || {}).forEach(function([productId, data]) {
        var stock = Number(data.stock_quantity ?? data) || 0;
        var productCard = document.querySelector('.product-card[data-product-id="' + productId + '"]');
        if (productCard) {
            productCard.setAttribute('data-stock', stock);
            var liveStock = productCard.querySelector('.live-stock');
            if (liveStock) liveStock.textContent = stock;
            var button = productCard.querySelector('button');
            if (button) button.disabled = stock <= 0;
            productCard.classList.toggle('out-of-stock', stock <= 0);
        }

        var row = document.querySelector('#inventoryTable tbody tr[data-product-id="' + productId + '"]');
        if (row) {
            var stockCell = row.querySelector('[data-stock-cell]');
            if (stockCell) stockCell.textContent = stock;
        }
    });
}

function syncInventorySnapshot() {
    if (!window.inventorySnapshotUrl) return;
    fetch(window.inventorySnapshotUrl, { credentials: 'same-origin' })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                applyInventorySnapshot(data.items);
            }
        })
        .catch(function() {});
}

function confirmTransaction() {
    console.log("ConfirmTransaction triggered");
    if (!cart || cart.length === 0) {
    alert("Cart is empty");
    return;
    }
    var amountReceived = document.getElementById('amount-received');
    var received = amountReceived ? parseFloat(amountReceived.value) || 0 : 0;
    if (received < total) {
        window.alert('Amount received is less than total.');
        return;
    }
    if (!window.processSaleUrl) return;

    // Get selected discount ID from radio buttons
    var selectedRadio = document.querySelector('input[name="selected_discount"]:checked');
    var selectedDiscountId = selectedRadio ? parseInt(selectedRadio.value) || 0 : 0;
    var payloadCart = cart.map(function(item) { return {
        id: item.id,
        quantity: item.quantity,
        price: item.price,
        unit: item.unit,
        manual_discount: Number(item.manual_discount || 0)
    }; });

    fetch(window.processSaleUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            cart: payloadCart,
            sale_type: saleType,
            selected_discount_id: selectedDiscountId,
            amount_received: received,
            change_amount: received - total
        })
    })
        .then(async function(response) {
            var data = await response.json();
            console.log('RAW RESPONSE:', data);
            return data;
        })
        .then(function(data) {
            if (!data.success) {
                setPosFeedback(data.message || 'Unable to process sale.', 'error');
                return;
            }

            setPosFeedback((data.message || '') + ' Reference: ' + (data.sale_reference || ''), 'success');
            if (data.inventory) {
                var normalized = {};
                Object.entries(data.inventory).forEach(function([key, value]) {
                    normalized[key] = { stock_quantity: value };
                });
                applyInventorySnapshot(normalized);
            }
            var shiftValue = document.getElementById('shift-sales-value');
            if (shiftValue && data.shift) {
                shiftValue.textContent = 'PHP ' + Number(data.shift.total_sales).toFixed(2);
            }
            cancelOrder();
            closeTransactionModal();
            syncInventorySnapshot();
        })
        .catch(function() {
            setPosFeedback('Network error while processing the sale.', 'error');
        });
}

function openEndShiftModal() {
    var modal = document.getElementById('end-shift-modal');
    if (modal) modal.style.display = 'block';
}

function closeEndShiftModal() {
    var modal = document.getElementById('end-shift-modal');
    if (modal) modal.style.display = 'none';
}

function searchTable(inputId, tableId) {
    var input = document.getElementById(inputId);
    var table = document.getElementById(tableId);
    if (!input || !table) return;
    var filter = input.value.toUpperCase();
    var rows = table.getElementsByTagName('tr');

    for (var i = 1; i < rows.length; i += 1) {
        var cells = rows[i].getElementsByTagName('td');
        var match = false;
        for (var j = 0; j < cells.length; j += 1) {
            if (cells[j].textContent.toUpperCase().indexOf(filter) > -1) {
                match = true;
                break;
            }
        }
        rows[i].style.display = match ? '' : 'none';
    }
}

function filterByCategory(categoryFilterId, tableId) {
    var categoryFilter = document.getElementById(categoryFilterId);
    var table = document.getElementById(tableId);
    if (!categoryFilter || !table) return;
    var selectedCategory = categoryFilter.value;
    var rows = table.getElementsByTagName('tr');
    for (var i = 1; i < rows.length; i += 1) {
        var cells = rows[i].getElementsByTagName('td');
        var categoryCell = cells[1];
        rows[i].style.display = selectedCategory === '' || (categoryCell && categoryCell.textContent === selectedCategory) ? '' : 'none';
    }
}

function filterBySupplier(supplierFilterId, tableId) {
    var supplierFilter = document.getElementById(supplierFilterId);
    var table = document.getElementById(tableId);
    if (!supplierFilter || !table) return;
    var selectedSupplier = supplierFilter.value;
    var rows = table.getElementsByTagName('tr');
    for (var i = 1; i < rows.length; i += 1) {
        var cells = rows[i].getElementsByTagName('td');
        var supplierCell = cells[2];
        rows[i].style.display = selectedSupplier === '' || (supplierCell && supplierCell.textContent === selectedSupplier) ? '' : 'none';
    }
}

function getColumnIndex(table, headerText) {
    var header = table?.tHead?.rows?.[0];
    if (!header) return -1;
    for (var i = 0; i < header.cells.length; i += 1) {
        if (header.cells[i].textContent.trim().toLowerCase().includes(headerText.toLowerCase())) {
            return i;
        }
    }
    return -1;
}

function filterByInventoryType(typeFilterId, tableId) {
    var filter = document.getElementById(typeFilterId);
    var table = document.getElementById(tableId);
    if (!filter || !table) return;
    var typeIndex = getColumnIndex(table, 'type');
    Array.from(table.querySelectorAll('tbody tr')).forEach(function(row) {
        var cell = row.cells[typeIndex];
        row.style.display = filter.value === '' || (cell && cell.textContent === filter.value) ? '' : 'none';
    });
}

function filterByStockStatus(statusFilterId, tableId) {
    var filter = document.getElementById(statusFilterId);
    var table = document.getElementById(tableId);
    if (!filter || !table) return;
    var quantityIndex = getColumnIndex(table, 'stock');
    Array.from(table.querySelectorAll('tbody tr')).forEach(function(row) {
        var quantity = parseInt(row.cells[quantityIndex]?.textContent || '0', 10);
        var show = true;
        if (filter.value === 'low') show = quantity < 10;
        if (filter.value === 'medium') show = quantity >= 10 && quantity < 50;
        if (filter.value === 'high') show = quantity >= 50;
        row.style.display = show ? '' : 'none';
    });
}

function filterPosProducts() {
    var input = document.getElementById('pos-search');
    if (!input) return;
    var term = input.value.trim().toLowerCase();
    var activeTab = document.querySelector('.tab-button.active');
    var activeCategory = activeTab ? activeTab.textContent.trim() : '';
    document.querySelectorAll('.product-card').forEach(function(card) {
        var name = (card.getAttribute('data-name') || '').toLowerCase();
        var category = card.getAttribute('data-category') || '';
        var categoryMatch = !activeCategory || category === activeCategory;
        var textMatch = !term || name.includes(term);
        card.style.display = categoryMatch && textMatch ? 'block' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.querySelector('.sidebar');
    var menuToggle = document.querySelector('.menu-toggle');

    if (sidebar && window.innerWidth <= 768) {
        sidebar.classList.add('collapsed');
    }

    document.querySelectorAll('.sidebar a').forEach(function(link) {
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