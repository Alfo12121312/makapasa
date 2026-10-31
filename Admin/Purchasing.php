<?php
/*
 * File: Admin/Purchasing.php
 * Purpose: Multi-item purchase orders with supplier dropdown, automatic stock-in on receive,
 *          Failed status (kept for audit) and a history tab.
 *
 * Flow: Pending -> Ordered -> Partially Received -> Received | Failed.
 * Each receipt adds only delivered quantities to stock as new batches.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

/** User-fixable problem (shown as-is). Anything else is logged and shown generically. */
class PurchasingInputException extends RuntimeException {}

// ───────────────────────── Schema (idempotent) ─────────────────────────
function po_ensure_schema(mysqli $conn) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS purchase_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            product_id INT NULL,
            product_name VARCHAR(150) NOT NULL,
            product_unit VARCHAR(30) NOT NULL DEFAULT '',
            quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
            received_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            expiration_date DATE NULL,
            batch_reference VARCHAR(100) NULL,
            KEY idx_po_items_po (po_id)
        )");

        $receivedQuantity = $conn->query("SHOW COLUMNS FROM purchase_order_items LIKE 'received_quantity'");
        if ($receivedQuantity && $receivedQuantity->num_rows === 0) {
            $conn->query("ALTER TABLE purchase_order_items ADD COLUMN received_quantity DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER quantity");
        }

        $conn->query("CREATE TABLE IF NOT EXISTS purchase_order_receipts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            item_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity DECIMAL(12,3) NOT NULL,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            expiration_date DATE NULL,
            batch_reference VARCHAR(100) NOT NULL,
            received_by INT NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_po_receipts_po (po_id),
            KEY idx_po_receipts_item (item_id)
        )");

        $columns = [
            'expiration_date' => 'DATE NULL',
            'received_at'     => 'DATETIME NULL',
            'received_by'     => 'INT NULL',
            'failed_at'       => 'DATETIME NULL',
            'failed_reason'   => 'VARCHAR(255) NULL',
        ];
        foreach ($columns as $name => $def) {
            $check = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE '{$name}'");
            if ($check && $check->num_rows === 0) {
                $conn->query("ALTER TABLE purchase_orders ADD COLUMN {$name} {$def}");
            }
        }

        $statusCol = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'");
        $statusRow = $statusCol ? $statusCol->fetch_assoc() : null;
        if ($statusRow && stripos((string)$statusRow['Type'], 'Partially Received') === false) {
            $conn->query("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('Pending','Ordered','Partially Received','Received','Failed') NOT NULL DEFAULT 'Pending'");
        }

        // Copy old single-item orders into the items table (once per order).
        $legacy = $conn->query("SELECT po.id, po.item_name, po.quantity, po.unit_cost, po.expiration_date
                                FROM purchase_orders po
                                WHERE NOT EXISTS (SELECT 1 FROM purchase_order_items i WHERE i.po_id = po.id)");
        if ($legacy && $legacy->num_rows > 0) {
            $find = $conn->prepare("SELECT id, product_name, product_unit FROM inventory WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))");
            $ins = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, product_name, product_unit, quantity, unit_cost, expiration_date)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            while ($row = $legacy->fetch_assoc()) {
                $name = (string)$row['item_name'];
                $find->bind_param('s', $name);
                $find->execute();
                $matches = $find->get_result()->fetch_all(MYSQLI_ASSOC);
                $productId = null;
                $unit = '';
                if (count($matches) === 1) {
                    $productId = (int)$matches[0]['id'];
                    $unit = (string)$matches[0]['product_unit'];
                }
                $poId = (int)$row['id'];
                $qty = (float)$row['quantity'];
                $cost = (float)$row['unit_cost'];
                $exp = $row['expiration_date'] ?: null;
                $ins->bind_param('iissdds', $poId, $productId, $name, $unit, $qty, $cost, $exp);
                $ins->execute();
            }
            $find->close();
            $ins->close();
        }

        // Previously received orders predate per-line receipt tracking.
        $conn->query("UPDATE purchase_order_items i
                      INNER JOIN purchase_orders po ON po.id = i.po_id
                      SET i.received_quantity = i.quantity
                      WHERE po.status = 'Received' AND i.received_quantity = 0");
    } catch (Throwable $e) {
        error_log('[Purchasing] schema setup failed: ' . $e->getMessage());
        flash_error('Could not prepare the purchasing workspace. Please contact an administrator.');
    }
}
po_ensure_schema($conn);

function po_number($id) {
    return 'PO-' . str_pad((string)(int)$id, 4, '0', STR_PAD_LEFT);
}

function po_valid_date($value) {
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

/**
 * Record one delivery against a purchase order. Remaining quantities stay open for later receipts.
 */
function po_receive(mysqli $conn, $poId, $userId, array $receivedQuantities, array $expirations) {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id, supplier, status FROM purchase_orders WHERE id = ? FOR UPDATE");
        if (!$stmt) throw new RuntimeException('Could not prepare purchase order lookup.');
        $stmt->bind_param('i', $poId);
        if (!$stmt->execute()) throw new RuntimeException('Could not load purchase order.');
        $po = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$po) {
            throw new PurchasingInputException('Purchase order not found.');
        }
        if (!in_array($po['status'], ['Pending', 'Ordered', 'Partially Received'], true)) {
            throw new PurchasingInputException(po_number($poId) . ' cannot receive another delivery in its current status.');
        }

        $stmt = $conn->prepare("SELECT id, product_id, product_name, product_unit, quantity, received_quantity, unit_cost
                                FROM purchase_order_items WHERE po_id = ? ORDER BY id FOR UPDATE");
        if (!$stmt) throw new RuntimeException('Could not prepare purchase order item lookup.');
        $stmt->bind_param('i', $poId);
        if (!$stmt->execute()) throw new RuntimeException('Could not load purchase order items.');
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$items) {
            throw new PurchasingInputException('This order has no items to receive.');
        }

        $today = date('Y-m-d');
        $lock = $conn->prepare("SELECT id, product_name, product_unit FROM inventory WHERE id = ? FOR UPDATE");
        $move = $conn->prepare("INSERT INTO stock_movements
            (product_id, movement_type, quantity, cost_price, expiration_date, batch_reference, notes, created_by)
            VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)");
        $stock = $conn->prepare("UPDATE inventory
            SET stock_quantity = stock_quantity + ?, cost_price = CASE WHEN ? > 0 THEN ? ELSE cost_price END
            WHERE id = ?");
        $mark = $conn->prepare("UPDATE purchase_order_items
                                SET received_quantity = received_quantity + ?, expiration_date = ?, batch_reference = ?
                                WHERE id = ?");
        $receipt = $conn->prepare("INSERT INTO purchase_order_receipts
            (po_id, item_id, product_id, quantity, unit_cost, expiration_date, batch_reference, received_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$lock || !$move || !$stock || !$mark || !$receipt) {
            throw new RuntimeException('Could not prepare receipt stock updates.');
        }

        $received = 0;
        $remainingTotal = 0.0;
        foreach ($items as $item) {
            $itemId = (int)$item['id'];
            $qtyRaw = $receivedQuantities[$itemId] ?? '';
            if (!is_scalar($qtyRaw)) {
                throw new PurchasingInputException('Enter a valid quantity for each received product.');
            }
            $qtyInput = trim((string)$qtyRaw);
            if ($qtyInput === '') $qtyInput = '0';
            if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $qtyInput)) {
                throw new PurchasingInputException('Enter valid delivered quantities using up to three decimal places.');
            }
            $qty = round((float)$qtyInput, 3);
            $orderedQty = round((float)$item['quantity'], 3);
            $previouslyReceived = round((float)$item['received_quantity'], 3);
            $remaining = round($orderedQty - $previouslyReceived, 3);
            if ($remaining < 0) {
                throw new RuntimeException('A purchase order line has more received stock than ordered.');
            }
            $remainingTotal += $remaining;
            if ($qty <= 0) continue;

            $productId = (int)$item['product_id'];
            if ($productId <= 0) {
                throw new PurchasingInputException('"' . $item['product_name'] . '" is not linked to an inventory product, so it cannot be added to stock.');
            }
            if ($qty > $remaining + 0.0005) {
                throw new PurchasingInputException('Received quantity for "' . $item['product_name'] . '" cannot exceed the remaining ' . fmt_qty($remaining) . '.');
            }
            $lock->bind_param('i', $productId);
            if (!$lock->execute()) throw new RuntimeException('Could not lock inventory product.');
            $product = $lock->get_result()->fetch_assoc();
            if (!$product) {
                throw new PurchasingInputException('"' . $item['product_name'] . '" no longer exists in inventory.');
            }
            if (!is_kg_unit($product['product_unit']) && abs($qty - round($qty)) > 0.0005) {
                throw new PurchasingInputException('"' . $product['product_name'] . '" can only be received in whole units.');
            }
            $expiryRaw = $expirations[$itemId] ?? '';
            if (!is_scalar($expiryRaw)) {
                throw new PurchasingInputException('Enter a valid expiration date for "' . $product['product_name'] . '".');
            }
            $expiryInput = trim((string)$expiryRaw);
            $expiration = $expiryInput === '' ? null : $expiryInput;
            if ($expiration !== null && !po_valid_date($expiration)) {
                throw new PurchasingInputException('Enter a valid expiration date for "' . $product['product_name'] . '".');
            }
            if ($expiration !== null && $expiration < $today) {
                throw new PurchasingInputException('"' . $product['product_name'] . '" has an expiration date in the past (' . $expiration . ').');
            }
            $cost = round((float)$item['unit_cost'], 2);
            $batch = 'BATCH-PO' . $poId . '-' . (int)$item['id'] . '-' . strtoupper(bin2hex(random_bytes(2)));
            $note = 'Purchase order ' . po_number($poId) . ' received from ' . $po['supplier'];

            $move->bind_param('iddsssi', $productId, $qty, $cost, $expiration, $batch, $note, $userId);
            if (!$move->execute()) throw new RuntimeException('Could not add received stock movement.');
            $stock->bind_param('dddi', $qty, $cost, $cost, $productId);
            if (!$stock->execute()) throw new RuntimeException('Could not update inventory stock.');
            $mark->bind_param('dssi', $qty, $expiration, $batch, $itemId);
            if (!$mark->execute()) throw new RuntimeException('Could not update received order quantity.');
            $receipt->bind_param('iiiddssi', $poId, $itemId, $productId, $qty, $cost, $expiration, $batch, $userId);
            if (!$receipt->execute()) throw new RuntimeException('Could not save receipt history.');
            $received++;
            $remainingTotal = round($remainingTotal - $qty, 3);
        }
        $lock->close(); $move->close(); $stock->close(); $mark->close();
        $receipt->close();
        if ($received === 0) {
            throw new PurchasingInputException('Enter at least one quantity greater than zero to receive.');
        }

        $newStatus = $remainingTotal <= 0.0005 ? 'Received' : 'Partially Received';
        $stmt = $conn->prepare("UPDATE purchase_orders
                                SET status = ?, received_at = CASE WHEN ? = 'Received' THEN NOW() ELSE received_at END,
                                    received_by = CASE WHEN ? = 'Received' THEN ? ELSE received_by END
                                WHERE id = ?");
        if (!$stmt) throw new RuntimeException('Could not prepare purchase order status update.');
        $stmt->bind_param('sssii', $newStatus, $newStatus, $newStatus, $userId, $poId);
        if (!$stmt->execute()) throw new RuntimeException('Could not update purchase order status.');
        $stmt->close();

        if (!$conn->commit()) throw new RuntimeException('Could not commit received stock.');
        return $newStatus === 'Received'
            ? po_number($poId) . ' fully received. ' . $received . ' product line' . ($received === 1 ? '' : 's') . ' added to stock.'
            : po_number($poId) . ' partially received. Remaining quantities stay open for the next delivery.';
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

// ───────────────────────── Reference data ─────────────────────────
$supplierNames = [];
$supplierResult = $conn->query("SELECT supplier_name FROM product_suppliers WHERE is_active = 1 ORDER BY supplier_name ASC");
if ($supplierResult) {
    while ($s = $supplierResult->fetch_assoc()) {
        $supplierNames[] = $s['supplier_name'];
    }
}

$productsById = [];
$productResult = $conn->query("SELECT id, product_name, product_unit, cost_price, stock_quantity, supplier
                               FROM inventory WHERE status = 'Active'
                               ORDER BY product_name ASC, product_unit ASC");
if ($productResult) {
    while ($p = $productResult->fetch_assoc()) {
        $productsById[(int)$p['id']] = $p;
    }
}

// ───────────────────────── Actions ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $userId = auth_user_id();

    if (isset($_POST['add_po'])) {
        $supplier = trim((string)($_POST['supplier'] ?? ''));
        $productIds = $_POST['item_product'] ?? [];
        $problem = null;
        $lines = [];

        if (!in_array($supplier, $supplierNames, true)) {
            $problem = 'Choose a supplier from the list.';
        } elseif (!is_array($productIds)) {
            $problem = 'Add at least one product to the order.';
        } else {
            foreach ($productIds as $i => $rawId) {
                $pid = (int)$rawId;
                $qtyRaw = trim((string)($_POST['item_qty'][$i] ?? ''));
                $costRaw = trim((string)($_POST['item_cost'][$i] ?? ''));
                if ($pid === 0 && $qtyRaw === '' && $costRaw === '') {
                    continue; // empty optional line
                }
                $n = count($lines) + 1;
                if (!isset($productsById[$pid])) {
                    $problem = "Line {$n}: choose a product.";
                } elseif (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $qtyRaw) || (float)$qtyRaw <= 0) {
                    $problem = "Line {$n}: enter a quantity greater than zero.";
                } elseif (!is_kg_unit($productsById[$pid]['product_unit']) && abs((float)$qtyRaw - round((float)$qtyRaw)) > 0.0005) {
                    $problem = "Line {$n}: {$productsById[$pid]['product_name']} must be ordered in whole units.";
                } elseif (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $costRaw)) {
                    $problem = "Line {$n}: enter a valid unit cost (0 or more).";
                }
                if ($problem !== null) {
                    break;
                }
                $lines[] = [
                    'product_id' => $pid,
                    'name' => $productsById[$pid]['product_name'],
                    'unit' => $productsById[$pid]['product_unit'],
                    'qty' => round((float)$qtyRaw, 3),
                    'cost' => round((float)$costRaw, 2),
                ];
            }
            if ($problem === null && !$lines) {
                $problem = 'Add at least one product to the order.';
            } elseif ($problem === null && count($lines) > 30) {
                $problem = 'An order can have at most 30 products.';
            }
        }

        if ($problem !== null) {
            flash_error($problem);
        } else {
            $conn->begin_transaction();
            try {
                $summary = $lines[0]['name'] . (count($lines) > 1 ? ' +' . (count($lines) - 1) . ' more' : '');
                $summary = mb_substr($summary, 0, 120);
                $totalQty = array_sum(array_column($lines, 'qty'));
                $totalCost = 0.0;
                foreach ($lines as $l) { $totalCost += $l['qty'] * $l['cost']; }
                $avgCost = $totalQty > 0 ? round($totalCost / $totalQty, 2) : 0.0;

                $stmt = $conn->prepare("INSERT INTO purchase_orders (supplier, item_name, quantity, unit_cost) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssdd', $supplier, $summary, $totalQty, $avgCost);
                $stmt->execute();
                $poId = (int)$conn->insert_id;
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, product_name, product_unit, quantity, unit_cost)
                                        VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($lines as $l) {
                    $stmt->bind_param('iissdd', $poId, $l['product_id'], $l['name'], $l['unit'], $l['qty'], $l['cost']);
                    if (!$stmt->execute()) throw new RuntimeException('Could not save purchase order item.');
                }
                $stmt->close();
                $conn->commit();
                flash_success(po_number($poId) . ' created with ' . count($lines) . ' product' . (count($lines) === 1 ? '' : 's') . '.');
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('[Purchasing] create failed: ' . $e->getMessage());
                flash_error('Could not create the purchase order. Nothing was saved; please try again.');
            }
        }
    } elseif (isset($_POST['receive_order'])) {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $quantities = $_POST['receive_qty'] ?? [];
        $expirations = $_POST['receive_expiry'] ?? [];
        try {
            if (!$id || $id < 1 || !is_array($quantities) || !is_array($expirations)) {
                throw new PurchasingInputException('Choose a valid purchase order and enter its received quantities.');
            }
            flash_success(po_receive($conn, $id, $userId, $quantities, $expirations));
        } catch (PurchasingInputException $e) {
            flash_error($e->getMessage());
        } catch (Throwable $e) {
            error_log('[Purchasing] receipt failed: ' . $e->getMessage());
            flash_error('Could not receive this delivery. No changes were saved; please try again.');
        }
    } elseif (isset($_POST['set_status'])) {
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $reason = trim((string)($_POST['reason'] ?? ''));
        try {
            if ($id <= 0 || !in_array($status, ['Ordered', 'Failed'], true)) {
                throw new PurchasingInputException('Choose a valid purchase order and status.');
            }
            if ($status === 'Ordered') {
                $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'Ordered' WHERE id = ? AND status = 'Pending'");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $changed = $stmt->affected_rows > 0;
                $stmt->close();
                if (!$changed) {
                    throw new PurchasingInputException('Only pending orders can be marked as ordered.');
                }
                flash_success(po_number($id) . ' marked as ordered.');
            } else {
                $len = mb_strlen($reason);
                if ($len < 3 || $len > 255) {
                    throw new PurchasingInputException('Enter the reason this order failed (3–255 characters).');
                }
                $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'Failed', failed_at = NOW(), failed_reason = ?
                                        WHERE id = ? AND status IN ('Pending','Ordered')");
                $stmt->bind_param('si', $reason, $id);
                $stmt->execute();
                $changed = $stmt->affected_rows > 0;
                $stmt->close();
                if (!$changed) {
                    throw new PurchasingInputException('Only open orders can be marked as failed.');
                }
                flash_success(po_number($id) . ' marked as failed and moved to History.');
            }
        } catch (PurchasingInputException $e) {
            flash_error($e->getMessage());
        } catch (Throwable $e) {
            error_log('[Purchasing] status change failed: ' . $e->getMessage());
            flash_error('Could not update the purchase order. Nothing was changed; please try again.');
        }
    } else {
        flash_error('The requested purchasing action was not recognized.');
    }

    header('Location: Purchasing.php');
    exit();
}

// ───────────────────────── Data loading ─────────────────────────
$view = (($_GET['view'] ?? '') === 'history') ? 'history' : 'active';

$orders = [];
$ordersResult = $conn->query(
    "SELECT po.*, COALESCE(t.total_cost, 0) AS total_cost, COALESCE(t.remaining_cost, 0) AS remaining_cost,
            COALESCE(t.line_count, 0) AS line_count, u.username AS received_by_name
     FROM purchase_orders po
     LEFT JOIN (SELECT po_id, SUM(quantity * unit_cost) AS total_cost,
                       SUM(GREATEST(quantity - received_quantity, 0) * unit_cost) AS remaining_cost,
                       COUNT(*) AS line_count
                FROM purchase_order_items GROUP BY po_id) t ON t.po_id = po.id
     LEFT JOIN users u ON u.id = po.received_by
     ORDER BY po.created_at DESC, po.id DESC
     LIMIT 1000"
);
if ($ordersResult) {
    $orders = $ordersResult->fetch_all(MYSQLI_ASSOC);
} else {
    error_log('[Purchasing] Could not load purchase orders: ' . $conn->error);
    flash_error('Could not load purchase orders. Please try again.');
}

$itemsByPo = [];
$receiptsByItem = [];
if ($orders) {
    $ids = implode(',', array_map(static function ($o) { return (int)$o['id']; }, $orders));
    $itemsResult = $conn->query("SELECT * FROM purchase_order_items WHERE po_id IN ({$ids}) ORDER BY id");
    if ($itemsResult) {
        while ($it = $itemsResult->fetch_assoc()) {
            $itemsByPo[(int)$it['po_id']][] = $it;
        }
    }
    $receiptResult = $conn->query("SELECT item_id, quantity, expiration_date, batch_reference, received_at
                                   FROM purchase_order_receipts WHERE po_id IN ({$ids}) ORDER BY received_at, id");
    if ($receiptResult) {
        while ($receiptRow = $receiptResult->fetch_assoc()) {
            $receiptsByItem[(int)$receiptRow['item_id']][] = $receiptRow;
        }
    }
}

$summary = ['pending' => 0, 'ordered' => 0, 'partial' => 0, 'received' => 0, 'failed' => 0, 'committed' => 0.0];
$shown = [];
foreach ($orders as $o) {
    $key = $o['status'] === 'Partially Received' ? 'partial' : strtolower((string)$o['status']);
    if (isset($summary[$key])) $summary[$key]++;
    $open = in_array($o['status'], ['Pending', 'Ordered', 'Partially Received'], true);
    if ($open) {
        $summary['committed'] += (float)$o['remaining_cost'];
    }
    if (($view === 'active') === $open) {
        $shown[] = $o;
    }
}

$h = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

// ───────────────────────── Rendering ─────────────────────────
$page_title = 'Purchasing';
$breadcrumb = ['Purchasing', 'Orders'];
$active = 'Purchasing.php';
$extra_head = '<style>
.admin-po-workspace{grid-template-columns:minmax(330px,400px) minmax(0,1fr)}
.admin-po-workspace.is-single{grid-template-columns:minmax(0,1fr)}
.admin-po-workspace.is-single .admin-po-list{padding:20px}
.po-tabs{display:flex;gap:6px;margin:0 0 16px}
.po-tabs a{padding:8px 15px;border:1px solid #d8e1d7;border-radius:999px;background:#fff;color:#4d6453;font-size:12px;font-weight:700;text-decoration:none}
.po-tabs a.is-active{background:#24573a;border-color:#24573a;color:#fff}
.po-tabs small{margin-left:5px;opacity:.75}
.po-lines{display:grid;gap:10px}
.po-line{position:relative;display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:11px;border:1px solid #dfe7dc;border-radius:8px;background:#fff}
.po-line .po-line-product{grid-column:1/-1}
.po-line label{display:grid;gap:4px;font-size:9px;font-weight:750;color:#6a7d6e}
.po-line select,.po-line input{box-sizing:border-box;width:100%;min-width:0;min-height:35px;padding:6px 8px;border:1px solid #d8e1d7;border-radius:6px;background:#fff;color:#35483a;font:inherit;font-size:11px}
.po-line-remove{position:absolute;top:6px;right:6px;width:22px;height:22px;border:0;border-radius:50%;background:#f3e8e6;color:#a0443d;font-size:14px;line-height:1;cursor:pointer}
.po-line .po-line-product{padding-right:26px}
.po-add-line{min-height:36px;border:1px dashed #9bb8a3;border-radius:6px;background:transparent;color:#2f6a46;font:inherit;font-size:11px;font-weight:750;cursor:pointer}
.po-add-line:hover{background:#f0f6f1}
.po-total-est{display:flex;justify-content:space-between;padding:9px 12px;border:1px solid #e2eee6;border-radius:8px;background:#f5faf6;color:#57715f;font-size:11px}
.po-total-est strong{color:#1c5e3a}
.po-items summary{cursor:pointer;color:#344d3b;font-weight:700;font-size:11px}
.po-items ul{margin:6px 0 0;padding-left:16px;color:#617367;font-size:10.5px;line-height:1.6}
.po-items code{font-size:10px;background:#eef2ec;padding:1px 4px;border-radius:3px}
.po-outcome{font-size:10.5px;color:#617367;line-height:1.5}
.po-outcome em{color:#a0443d;font-style:normal;font-weight:650}
.po-status-form{flex-wrap:wrap}
.po-status-form .po-reason{flex:1 1 100%;min-height:31px;padding:4px 7px;border:1px solid #e3c9c5;border-radius:5px;font:inherit;font-size:10px}
.admin-po-status.is-failed{background:#fbe9e7;color:#a0443d}
.admin-po-status.is-partially-received{background:#fff4df;color:#8a5a16}
.po-receive-details{min-width:190px;margin-top:7px}
.po-receive-details summary{padding:6px 8px;border:1px solid #c9ddcd;border-radius:5px;background:#f4faf5;color:#2f6a46;font-size:10px;font-weight:750;cursor:pointer}
.po-receive-form{display:grid;gap:8px;margin-top:7px;padding:9px;border:1px solid #dfe7dc;border-radius:7px;background:#fbfdfb}
.po-receive-line{display:grid;gap:4px;color:#52675a;font-size:9px;font-weight:700}
.po-receive-line input{box-sizing:border-box;width:100%;min-height:30px;padding:5px 7px;border:1px solid #d8e1d7;border-radius:5px;background:#fff;font:inherit;font-size:10px}
.po-receive-hint{margin:0;color:#778477;font-size:9px}
.po-receive-form button{min-height:32px;border:0;border-radius:5px;background:#24573a;color:#fff;font:inherit;font-size:10px;font-weight:750;cursor:pointer}
.po-receive-form button:hover{background:#19472f}
</style>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('Purchasing', 'Create purchase orders, receive deliveries into stock, and review order history.'); ?>

<main class="admin-purchasing">
    <section class="admin-po-overview" aria-label="Purchase order overview">
        <span><strong><?php echo number_format($summary['pending']); ?></strong> pending</span>
        <span><strong><?php echo number_format($summary['ordered']); ?></strong> ordered</span>
        <span><strong><?php echo number_format($summary['partial']); ?></strong> partially received</span>
        <span><strong><?php echo number_format($summary['received']); ?></strong> received</span>
        <span><strong><?php echo number_format($summary['failed']); ?></strong> failed</span>
        <span class="admin-po-committed"><strong>PHP <?php echo number_format($summary['committed'], 2); ?></strong> not yet received</span>
    </section>

    <nav class="po-tabs" aria-label="Purchase order views">
        <a href="Purchasing.php" class="<?php echo $view === 'active' ? 'is-active' : ''; ?>">Active orders<small><?php echo $summary['pending'] + $summary['ordered'] + $summary['partial']; ?></small></a>
        <a href="Purchasing.php?view=history" class="<?php echo $view === 'history' ? 'is-active' : ''; ?>">History<small><?php echo $summary['received'] + $summary['failed']; ?></small></a>
    </nav>

    <section class="admin-po-workspace<?php echo $view === 'history' ? ' is-single' : ''; ?>">
        <?php if ($view === 'active'): ?>
        <div class="admin-po-create">
            <div class="admin-po-create-heading">
                <span class="admin-po-step">01</span>
                <div>
                    <h2>Draft a purchase order</h2>
                    <p>Choose a supplier, then add one or more products.</p>
                </div>
            </div>
            <?php if (!$supplierNames): ?>
                <div class="admin-po-empty">No active suppliers yet. <a href="Suppliers.php">Add a supplier</a> first.</div>
            <?php elseif (!$productsById): ?>
                <div class="admin-po-empty">No active products yet. <a href="Manage-Product.php">Add a product</a> first.</div>
            <?php else: ?>
            <form method="post" class="admin-po-form" id="poForm">
                <?php csrf_field(); ?>
                <label>
                    Supplier
                    <select name="supplier" required>
                        <option value="">Select a supplier</option>
                        <?php foreach ($supplierNames as $name): ?>
                            <option value="<?php echo $h($name); ?>"><?php echo $h($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="po-lines" id="poLines"></div>
                <button type="button" class="po-add-line" id="poAddLine">+ Add another product <span style="font-weight:400">(optional)</span></button>
                <div class="po-total-est"><span>Estimated total</span><strong id="poTotalEst">PHP 0.00</strong></div>
                <button type="submit" name="add_po" class="admin-po-submit">Create purchase order</button>
            </form>

            <template id="poLineTpl">
                <div class="po-line">
                    <button type="button" class="po-line-remove" aria-label="Remove product">&times;</button>
                    <label class="po-line-product">Product
                        <select name="item_product[]">
                            <option value="">Select a product</option>
                            <?php foreach ($productsById as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"
                                        data-unit="<?php echo $h(unit_label($p['product_unit'])); ?>"
                                        data-cost="<?php echo $h(number_format((float)$p['cost_price'], 2, '.', '')); ?>">
                                    <?php echo $h($p['product_name'] . ' (' . unit_label($p['product_unit']) . ') · ' . fmt_qty($p['stock_quantity']) . ' in stock'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Quantity <span class="po-unit"></span>
                        <input type="number" name="item_qty[]" min="0.001" step="1" inputmode="decimal" placeholder="0">
                    </label>
                    <label>Unit cost (PHP)
                        <input type="number" name="item_cost[]" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
                    </label>
                </div>
            </template>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="admin-po-list">
            <div class="admin-po-list-heading">
                <div>
                    <span class="admin-po-step"><?php echo $view === 'active' ? '02' : '•'; ?></span>
                    <div>
                        <h2><?php echo $view === 'active' ? 'Open orders' : 'Order history'; ?></h2>
                        <p><?php echo $view === 'active'
                                    ? 'Record the quantities that arrived; short shipments stay open for later deliveries.'
                            : 'Received and failed orders. Failed orders are kept here for reference.'; ?></p>
                    </div>
                </div>
                <span class="admin-po-count" id="poVisibleCount"><?php echo number_format(count($shown)); ?> orders</span>
            </div>
            <div class="admin-po-controls">
                <label class="admin-po-search">
                    <span aria-hidden="true">⌕</span>
                    <input type="search" id="poSearch" placeholder="Search order no., supplier or product" aria-label="Search purchase orders">
                </label>
                <label class="sr-only" for="poStatusFilter">Filter by status</label>
                <select id="poStatusFilter">
                    <option value="">All statuses</option>
                    <?php foreach (($view === 'active' ? ['Pending', 'Ordered', 'Partially Received'] : ['Received', 'Failed']) as $st): ?>
                        <option value="<?php echo $st; ?>"><?php echo $st; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="user-table-wrapper admin-po-table-wrap">
                <table class="userTable admin-po-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Supplier</th>
                            <th>Products</th>
                            <th>Total cost</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th><?php echo $view === 'active' ? 'Update status' : 'Outcome'; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($shown): foreach ($shown as $row):
                        $poId = (int)$row['id'];
                        $items = $itemsByPo[$poId] ?? [];
                        $names = implode(' ', array_column($items, 'product_name'));
                        $search = mb_strtolower(po_number($poId) . ' ' . $row['supplier'] . ' ' . $names);
                        $statusClass = strtolower(str_replace(' ', '-', (string)$row['status']));
                    ?>
                        <tr data-status="<?php echo $h($row['status']); ?>" data-search="<?php echo $h($search); ?>">
                            <td><strong class="admin-po-supplier"><?php echo $h(po_number($poId)); ?></strong></td>
                            <td><?php echo $h($row['supplier']); ?></td>
                            <td>
                                <details class="po-items">
                                    <summary><?php echo count($items); ?> product<?php echo count($items) === 1 ? '' : 's'; ?> · <?php echo $h(mb_strimwidth($items ? $items[0]['product_name'] : $row['item_name'], 0, 28, '…')); ?></summary>
                                    <ul>
                                    <?php foreach ($items as $it): ?>
                                        <li>
                                            <?php echo $h($it['product_name']); ?>
                                            <?php if ($it['product_unit'] !== ''): ?>(<?php echo $h(unit_label($it['product_unit'])); ?>)<?php endif; ?>
                                            — <?php echo fmt_qty($it['quantity']); ?> × ₱<?php echo number_format((float)$it['unit_cost'], 2); ?>
                                            = ₱<?php echo number_format((float)$it['quantity'] * (float)$it['unit_cost'], 2); ?>
                                            · received <?php echo fmt_qty($it['received_quantity'] ?? 0); ?> / <?php echo fmt_qty($it['quantity']); ?>
                                            <?php if ((float)($it['received_quantity'] ?? 0) + 0.0005 < (float)$it['quantity']): ?>
                                                · remaining <?php echo fmt_qty((float)$it['quantity'] - (float)($it['received_quantity'] ?? 0)); ?>
                                            <?php endif; ?>
                                            <?php if (empty($it['product_id']) && $row['status'] !== 'Received'): ?> · <em style="color:#a0443d">not linked to inventory</em><?php endif; ?>
                                            <?php if (!empty($receiptsByItem[(int)$it['id']])): ?>
                                                <ul class="po-receipt-list">
                                                <?php foreach ($receiptsByItem[(int)$it['id']] as $receiptRow): ?>
                                                    <li>
                                                        Received <?php echo $h(date('M j, Y', strtotime($receiptRow['received_at']))); ?>:
                                                        <?php echo fmt_qty($receiptRow['quantity']); ?>
                                                        <?php if (!empty($receiptRow['expiration_date'])): ?> · exp <?php echo $h(date('M j, Y', strtotime($receiptRow['expiration_date']))); ?><?php endif; ?>
                                                        · <code><?php echo $h($receiptRow['batch_reference']); ?></code>
                                                    </li>
                                                <?php endforeach; ?>
                                                </ul>
                                            <?php elseif (!empty($it['expiration_date'])): ?>
                                                · exp <?php echo $h(date('M j, Y', strtotime($it['expiration_date']))); ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                    </ul>
                                </details>
                            </td>
                            <td class="admin-po-total">₱<?php echo number_format((float)$row['total_cost'], 2); ?></td>
                            <td><?php echo $h(date('M j, Y', strtotime($row['created_at']))); ?></td>
                            <td><span class="admin-po-status is-<?php echo $h($statusClass); ?>"><?php echo $h($row['status']); ?></span></td>
                            <td>
                            <?php if ($view === 'active'):
                                $next = $row['status'] === 'Pending' ? ['Ordered', 'Failed'] : ($row['status'] === 'Ordered' ? ['Failed'] : []); ?>
                                <?php if ($next): ?>
                                <form method="post" class="admin-po-status-form po-status-form">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $poId; ?>">
                                    <select name="status" aria-label="New status for <?php echo $h(po_number($poId)); ?>">
                                        <?php foreach ($next as $st): ?>
                                            <option value="<?php echo $st; ?>"><?php echo $st === 'Failed' ? 'Failed (error)' : $st; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="set_status" value="1">Save</button>
                                    <input type="text" name="reason" class="po-reason" maxlength="255" placeholder="Reason for failure" hidden>
                                </form>
                                <?php endif; ?>
                                <details class="po-receive-details">
                                    <summary>Receive delivery</summary>
                                    <form method="post" class="po-receive-form">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo $poId; ?>">
                                        <p class="po-receive-hint">Enter only quantities delivered now. Leave the rest at 0 for a later delivery.</p>
                                        <?php foreach ($items as $it):
                                            $itemId = (int)$it['id'];
                                            $quantityOrdered = (float)$it['quantity'];
                                            $quantityReceived = (float)($it['received_quantity'] ?? 0);
                                            $quantityRemaining = max(0, round($quantityOrdered - $quantityReceived, 3));
                                            $productUnit = unit_label($it['product_unit']);
                                            $quantityStep = is_kg_unit($it['product_unit']) ? '0.001' : '1';
                                        ?>
                                            <label class="po-receive-line">
                                                <?php echo $h($it['product_name']); ?> · <?php echo fmt_qty($quantityReceived); ?> received, <?php echo fmt_qty($quantityRemaining); ?> remaining
                                                <?php if ((int)$it['product_id'] > 0 && $quantityRemaining > 0): ?>
                                                    <input type="number" name="receive_qty[<?php echo $itemId; ?>]" min="0" max="<?php echo $h(number_format($quantityRemaining, 3, '.', '')); ?>" step="<?php echo $quantityStep; ?>" inputmode="decimal" placeholder="Quantity received (<?php echo $h($productUnit); ?>)">
                                                    <span>Expiration date <span class="admin-po-optional">Optional</span></span>
                                                    <input type="date" name="receive_expiry[<?php echo $itemId; ?>]" min="<?php echo date('Y-m-d'); ?>">
                                                <?php elseif ((int)$it['product_id'] <= 0): ?>
                                                    <span>Not linked to an inventory product</span>
                                                <?php else: ?>
                                                    <span>Fully received</span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                        <button type="submit" name="receive_order" value="1">Save delivery and update stock</button>
                                    </form>
                                </details>
                            <?php elseif ($row['status'] === 'Received'): ?>
                                <div class="po-outcome">Received <?php echo $h(date('M j, Y g:i A', strtotime($row['received_at'] ?: $row['created_at']))); ?><?php echo $row['received_by_name'] ? ' by ' . $h($row['received_by_name']) : ''; ?><br>Added to stock</div>
                            <?php else: ?>
                                <div class="po-outcome">Failed <?php echo $row['failed_at'] ? $h(date('M j, Y g:i A', strtotime($row['failed_at']))) : ''; ?><br><em><?php echo $h($row['failed_reason'] ?: 'No reason recorded'); ?></em></div>
                            <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                        <tr id="poNoResults" hidden><td colspan="7">No purchase orders match these search and filter options.</td></tr>
                    <?php else: ?>
                        <tr><td colspan="7" class="admin-po-empty"><?php echo $view === 'active'
                            ? 'No open purchase orders. Create one using the form.'
                            : 'No received or failed orders yet.'; ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>

<script>
(function () {
    // ----- search / filter -----
    var search = document.getElementById('poSearch');
    var status = document.getElementById('poStatusFilter');
    var table = document.querySelector('.admin-po-table');
    if (search && status && table) {
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-status]'));
        var noResults = document.getElementById('poNoResults');
        var count = document.getElementById('poVisibleCount');
        var apply = function () {
            var q = search.value.trim().toLowerCase(), st = status.value, visible = 0;
            rows.forEach(function (row) {
                var ok = (!q || row.dataset.search.indexOf(q) !== -1) && (!st || row.dataset.status === st);
                row.hidden = !ok;
                if (ok) visible++;
            });
            if (noResults) noResults.hidden = visible > 0 || rows.length === 0;
            if (count) count.textContent = visible + (visible === 1 ? ' order' : ' orders');
        };
        search.addEventListener('input', apply);
        status.addEventListener('change', apply);
    }

    // ----- status forms: reason box for Failed, confirmations -----
    document.querySelectorAll('.po-status-form').forEach(function (form) {
        var sel = form.querySelector('select[name="status"]');
        var reason = form.querySelector('.po-reason');
        sel.addEventListener('change', function () {
            var failed = sel.value === 'Failed';
            reason.hidden = !failed;
            reason.required = failed;
            if (failed) reason.focus();
        });
        form.addEventListener('submit', function (e) {
            if (sel.value === 'Failed' &&
                !window.confirm('Mark this order as failed? It will move to History and can no longer be received.')) {
                e.preventDefault();
            }
        });
    });
    document.querySelectorAll('.po-receive-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm('Save this delivery and add the entered quantities to inventory?')) {
                e.preventDefault();
            }
        });
    });

    // ----- create form: dynamic product lines -----
    var list = document.getElementById('poLines');
    var tpl = document.getElementById('poLineTpl');
    var addBtn = document.getElementById('poAddLine');
    var form = document.getElementById('poForm');
    if (!list || !tpl || !addBtn) return;

    var KG = ['kg', 'kgs', 'kls', 'kilo', 'kilos', 'kilogram', 'kilograms'];
    var totalEl = document.getElementById('poTotalEst');

    function updateTotal() {
        var total = 0;
        list.querySelectorAll('.po-line').forEach(function (row) {
            var q = parseFloat(row.querySelector('[name="item_qty[]"]').value) || 0;
            var c = parseFloat(row.querySelector('[name="item_cost[]"]').value) || 0;
            total += q * c;
        });
        totalEl.textContent = 'PHP ' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function bind(row) {
        var sel = row.querySelector('select');
        var qty = row.querySelector('[name="item_qty[]"]');
        var cost = row.querySelector('[name="item_cost[]"]');
        sel.addEventListener('change', function () {
            var opt = sel.options[sel.selectedIndex];
            var unit = opt && opt.value ? opt.getAttribute('data-unit') : '';
            var kg = KG.indexOf(String(unit).toLowerCase()) !== -1;
            qty.step = kg ? '0.001' : '1';
            qty.min = kg ? '0.001' : '1';
            row.querySelector('.po-unit').textContent = unit ? '(' + unit + ')' : '';
            if (opt && opt.value && cost.value === '') cost.value = opt.getAttribute('data-cost') || '';
            updateTotal();
        });
        qty.addEventListener('input', updateTotal);
        cost.addEventListener('input', updateTotal);
        row.querySelector('.po-line-remove').addEventListener('click', function () {
            if (list.children.length === 1) {
                row.querySelectorAll('select, input').forEach(function (el) { el.value = ''; });
                row.querySelector('.po-unit').textContent = '';
            } else {
                row.remove();
            }
            updateTotal();
        });
    }

    function addLine() {
        if (list.children.length >= 30) return;
        var row = tpl.content.firstElementChild.cloneNode(true);
        list.appendChild(row);
        bind(row);
    }

    addBtn.addEventListener('click', addLine);
    addLine();

    form.addEventListener('submit', function (e) {
        var ok = false;
        list.querySelectorAll('.po-line').forEach(function (row) {
            if (row.querySelector('select').value) ok = true;
        });
        if (!ok) {
            e.preventDefault();
            window.alert('Add at least one product to the order.');
        }
    });
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();