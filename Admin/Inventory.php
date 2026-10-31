<?php
/*
 * File: Admin/Inventory.php
 * Purpose: Inventory management — stock in / stock out (FIFO), batch tracking,
 *          expired-batch archiving and display/warehouse transfers.
 *
 * How stock is modelled
 * - One `inventory` row = one sellable SKU = product name + unit. Stocking in
 *   the same product with a DIFFERENT unit (e.g. Rice in "kg" and in "sack")
 *   creates (or tops up) a separate row for that unit, so quantities in
 *   different units are never mixed together and the POS can sell each one.
 * - `stock_movements` is the ledger. Each stock-in is a batch (IN row); every
 *   deduction is an OUT row tagged with the batch it came from. Batch
 *   remaining = IN - OUT. `inventory.stock_quantity` is a cached total that is
 *   adjusted incrementally in the same transaction as the ledger entry.
 *
 * Request handling
 * - Every POST is CSRF-checked, runs inside a DB transaction where it touches
 *   more than one table, and ends with a redirect (post/redirect/get) so a
 *   browser refresh never re-submits a stock movement.
 * - Schema bootstrapping (extra columns, product codes) lives in
 *   includes/app.php (app_connect), not here.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

// One-time tidy-up of mixed upper/lower-case names already in the database.
normalize_catalog_text_once($conn);

// Auto process expired batches by creating stock-out entries for expired stock
process_auto_expiration($conn, auth_user_id());

// ───────────────────────── Configuration & helpers ─────────────────────────

$UNIT_OPTIONS = [
    'pcs'    => 'pcs — pieces',
    'kls'    => 'kg — kilograms',
    'sack'   => 'sack',
    'ml'     => 'ml — milliliter',
    'bottle' => 'bottle',
];

$STOCK_OUT_REASONS = [
    'Damaged',
    'Returned to supplier',
    'Internal use / sample',
    'Lost / missing',
    'Inventory correction',
    'Other',
];

/** Thrown for problems the user can fix (shown as-is). Anything else is logged and shown generically. */
class InventoryInputException extends RuntimeException {}

/** Normalises unit spellings so "kg", "kls" and "kilos" are treated as the same unit. */
function inv_unit_key($unit) {
    $u = strtolower(trim((string)$unit));
    $aliases = [
        'kg' => 'kls', 'kgs' => 'kls', 'kls' => 'kls', 'kilo' => 'kls', 'kilos' => 'kls',
        'kilogram' => 'kls', 'kilograms' => 'kls',
        'pc' => 'pcs', 'pcs' => 'pcs', 'piece' => 'pcs', 'pieces' => 'pcs',
        'sacks' => 'sack', 'bottles' => 'bottle',
        'milliliter' => 'ml', 'milliliters' => 'ml', 'millilitre' => 'ml', 'millilitres' => 'ml',
    ];
    return $aliases[$u] ?? $u;
}

function inv_prepare(mysqli $conn, $sql) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }
    return $stmt;
}

function inv_execute(mysqli_stmt $stmt) {
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $error);
    }
}

/** Like inv_execute() but leaves the statement open so it can be reused in a loop. */
function inv_execute_keep(mysqli_stmt $stmt) {
    if (!$stmt->execute()) {
        throw new RuntimeException('Execute failed: ' . $stmt->error);
    }
}

function inv_new_batch_reference($productId) {
    return 'BATCH-' . date('YmdHis') . '-' . (int)$productId . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function inv_redirect() {
    header('Location: ' . basename($_SERVER['PHP_SELF']));
    exit();
}

/** Runs $work inside a transaction; user-fixable errors become flash errors, anything else is logged. */
function inv_run_transaction(mysqli $conn, callable $work, $genericError) {
    $conn->begin_transaction();
    try {
        $message = $work();
        $conn->commit();
        if ($message) {
            flash_success($message);
        }
    } catch (InventoryInputException $e) {
        $conn->rollback();
        flash_error($e->getMessage());
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[Inventory] ' . $e->getMessage());
        flash_error($genericError);
    }
}

// ───────────────────────────── POST handlers ──────────────────────────────

/**
 * Stock In — records a new batch.
 * If the chosen unit differs from the product row's unit, the stock goes to the
 * row for that unit (created if it does not exist yet).
 */
function inv_handle_stock_in(mysqli $conn, array $unitOptions) {
    $sourceId   = (int)($_POST['product_id'] ?? 0);
    $quantity   = round((float)($_POST['stock_in_quantity'] ?? 0), 3);
    $unit       = strtolower(trim((string)($_POST['stock_in_unit'] ?? '')));
    $retail     = round((float)($_POST['stock_in_retail_price'] ?? 0), 2);
    $cost       = round((float)($_POST['stock_in_cost_price'] ?? 0), 2);
    $expiration = trim((string)($_POST['stock_in_expiration'] ?? ''));
    $userId     = auth_user_id();

    inv_run_transaction($conn, function () use ($conn, $unitOptions, $sourceId, $quantity, $unit, $retail, $cost, $expiration, $userId) {
        if ($sourceId <= 0) {
            throw new InventoryInputException('Please choose a product to stock in.');
        }
        if ($quantity <= 0) {
            throw new InventoryInputException('Quantity must be greater than 0.');
        }
        if (!isset($unitOptions[$unit])) {
            throw new InventoryInputException('Please select a valid unit.');
        }
        // Only kg may be received in fractions (e.g. 12.5 kg); every other unit is whole.
        if (!is_kg_unit($unit) && abs($quantity - round($quantity)) > 0.0005) {
            throw new InventoryInputException('Quantity must be a whole number for this unit.');
        }
        if ($retail < 0 || $cost < 0) {
            throw new InventoryInputException('Prices cannot be negative.');
        }
        if ($expiration !== '') {
            $parsed = DateTime::createFromFormat('Y-m-d', $expiration);
            if (!$parsed || $parsed->format('Y-m-d') !== $expiration) {
                throw new InventoryInputException('Expiration date is not valid.');
            }
            if ($expiration < date('Y-m-d')) {
                throw new InventoryInputException('Expiration date is already in the past.');
            }
        } else {
            $expiration = null;
        }

        // Lock the product row the user clicked on.
        $stmt = inv_prepare($conn, "SELECT id, product_name, category, supplier, product_unit, inventory_type, product_code, stock_quantity
                                    FROM inventory WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $sourceId);
        inv_execute($stmt);
        $source = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$source) {
            throw new InventoryInputException('That product no longer exists.');
        }

        $targetId   = $sourceId;
        $newRow     = false;
        $reactivate = false;
        $unitNote   = '';

        if (inv_unit_key($source['product_unit']) !== inv_unit_key($unit)) {
            // Is the source row an untouched placeholder (no stock, no history)?
            // Then it simply adopts the unit — e.g. a product freshly created in Manage Products.
            $stmt = inv_prepare($conn, "SELECT COUNT(*) AS c FROM stock_movements WHERE product_id = ?");
            $stmt->bind_param('i', $sourceId);
            inv_execute($stmt);
            $movementCount = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();

            if (abs((float)$source['stock_quantity']) < 0.0005 && $movementCount === 0) {
                $unitNote = 'Unit set to ' . inv_unit_display($unit) . '.';
            } else {
                // Different unit on a product that already has stock/history → use that unit's own row.
                $stmt = inv_prepare($conn, "SELECT id, product_unit, status FROM inventory
                                            WHERE LOWER(product_name) = LOWER(?) AND id <> ? FOR UPDATE");
                $stmt->bind_param('si', $source['product_name'], $sourceId);
                inv_execute($stmt);
                $siblings = $stmt->get_result();
                $sibling = null;
                while ($candidate = $siblings->fetch_assoc()) {
                    if (inv_unit_key($candidate['product_unit']) === inv_unit_key($unit)) {
                        $sibling = $candidate;
                        break;
                    }
                }
                $stmt->close();

                if ($sibling) {
                    $targetId   = (int)$sibling['id'];
                    $reactivate = ($sibling['status'] !== 'Active');
                    $unitNote   = 'Added to the existing ' . inv_unit_display($unit) . ' row.';
                } else {
                    $baseCode = trim((string)$source['product_code']) !== ''
                        ? $source['product_code']
                        : generate_product_code($source['product_name']);
                    $newCode = substr($baseCode . '_' . $unit, 0, 100);

                    $stmt = inv_prepare($conn, "INSERT INTO inventory
                        (product_name, stock_quantity, category, supplier, product_unit, price, cost_price, status, inventory_type, product_code)
                        VALUES (?, 0, ?, ?, ?, ?, ?, 'Active', ?, ?)");
                    $stmt->bind_param('ssssddss',
                        $source['product_name'], $source['category'], $source['supplier'], $unit,
                        $retail, $cost, $source['inventory_type'], $newCode);
                    inv_execute($stmt);
                    $targetId = (int)$conn->insert_id;
                    $stmt->close();
                    $newRow   = true;
                    $unitNote = 'A new inventory row was created for ' . inv_unit_display($unit) . '.';
                }
            }
        }

        // Ledger entry (the batch).
        $batchRef = inv_new_batch_reference($targetId);
        $notes = 'Stock in: ' . fmt_qty($quantity) . ' ' . $unit . ' @ PHP ' . number_format($cost, 2) . ' cost'
               . ($newRow ? ' (new unit row)' : '');
        $stmt = inv_prepare($conn, "INSERT INTO stock_movements
            (product_id, movement_type, quantity, cost_price, expiration_date, batch_reference, notes, created_by)
            VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iddsssi', $targetId, $quantity, $cost, $expiration, $batchRef, $notes, $userId);
        inv_execute($stmt);
        $stmt->close();

        // Cached total + latest prices (+ unit, + reactivate if it had been hidden).
        $stmt = inv_prepare($conn, "UPDATE inventory
            SET stock_quantity = stock_quantity + ?, product_unit = ?, price = ?, cost_price = ?,
                status = CASE WHEN ? = 1 THEN 'Active' ELSE status END
            WHERE id = ?");
        $reactivateFlag = $reactivate ? 1 : 0;
        $stmt->bind_param('dsddii', $quantity, $unit, $retail, $cost, $reactivateFlag, $targetId);
        inv_execute($stmt);
        $stmt->close();

        return 'Added ' . fmt_qty($quantity) . ' ' . inv_unit_display($unit) . ' of "' . $source['product_name'] . '". '
             . ($unitNote !== '' ? $unitNote . ' ' : '') . 'Batch: ' . $batchRef;
    }, 'Could not record the stock in. Nothing was saved — please try again.');
}

/** Stock Out — removes stock FIFO (earliest expiry first), logging one OUT row per batch consumed. */
function inv_handle_stock_out(mysqli $conn, array $reasons) {
    $productId = (int)($_POST['product_id'] ?? 0);
    $qtyOut    = round((float)($_POST['stock_out_quantity'] ?? 0), 3);
    $reason    = trim((string)($_POST['stock_out_reason'] ?? ''));
    $remarks   = mb_substr(trim((string)($_POST['stock_out_remarks'] ?? '')), 0, 150);
    $userId    = auth_user_id();

    inv_run_transaction($conn, function () use ($conn, $reasons, $productId, $qtyOut, $reason, $remarks, $userId) {
        if ($qtyOut <= 0) {
            throw new InventoryInputException('Quantity must be greater than 0.');
        }
        if (!in_array($reason, $reasons, true)) {
            throw new InventoryInputException('Please choose a reason for the stock out.');
        }

        $stmt = inv_prepare($conn, "SELECT product_name, product_unit, stock_quantity FROM inventory WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $productId);
        inv_execute($stmt);
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$product) {
            throw new InventoryInputException('That product no longer exists.');
        }
        $available = (float)$product['stock_quantity'];
        if (!is_kg_unit($product['product_unit']) && abs($qtyOut - round($qtyOut)) > 0.0005) {
            throw new InventoryInputException('Quantity must be a whole number for this unit.');
        }
        if ($qtyOut > $available + 0.0005) {
            throw new InventoryInputException('Insufficient stock! Available: ' . fmt_qty($available) . ' ' . inv_unit_display($product['product_unit']) . ', requested: ' . fmt_qty($qtyOut) . '.');
        }
        $qtyOut = min($qtyOut, $available);

        $note = 'Manual stock-out (FIFO) - ' . $reason . ($remarks !== '' ? ': ' . $remarks : '');
        $stmt = inv_prepare($conn, "INSERT INTO stock_movements
            (product_id, movement_type, quantity, expiration_date, batch_reference, notes, created_by)
            VALUES (?, 'OUT', ?, ?, ?, ?, ?)");

        $remaining = $qtyOut;
        foreach (fetch_available_stock_batches($conn, $productId) as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $batchQty = (float)$batch['available_qty'];
            if ($batchQty <= 0.0005) {
                continue;
            }
            $used       = round(min($batchQty, $remaining), 3);
            $expiration = $batch['expiration_date'] ?: null;
            $batchKey   = (string)$batch['batch_key'];
            $stmt->bind_param('idsssi', $productId, $used, $expiration, $batchKey, $note, $userId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Stock-out insert failed: ' . $stmt->error);
            }
            $remaining = round($remaining - $used, 3);
        }

        // Stock that pre-dates batch tracking (opening balance) has no batch to draw from;
        // book it against a legacy balance reference, same as the POS does.
        if ($remaining > 0.0005) {
            $legacyRef = 'LEGACY-BAL-' . $productId;
            $nullExp   = null;
            $stmt->bind_param('idsssi', $productId, $remaining, $nullExp, $legacyRef, $note, $userId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Legacy stock-out insert failed: ' . $stmt->error);
            }
        }
        $stmt->close();

        $stmt = inv_prepare($conn, "UPDATE inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");
        $stmt->bind_param('di', $qtyOut, $productId);
        inv_execute($stmt);
        $stmt->close();

        return 'Removed ' . fmt_qty($qtyOut) . ' ' . inv_unit_display($product['product_unit']) . ' of "' . $product['product_name'] . '" (FIFO).';
    }, 'Could not record the stock out. Nothing was saved — please try again.');
}

/** Archive what is left of an expired batch as a stock-out tagged with the batch's own reference. */
function inv_handle_archive_batch(mysqli $conn) {
    $batchId = (int)($_POST['batch_id'] ?? 0);
    $userId  = auth_user_id();

    inv_run_transaction($conn, function () use ($conn, $batchId, $userId) {
        $stmt = inv_prepare($conn, "SELECT product_id, batch_reference, expiration_date
                                    FROM stock_movements WHERE id = ? AND movement_type = 'IN'");
        $stmt->bind_param('i', $batchId);
        inv_execute($stmt);
        $batch = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$batch) {
            throw new InventoryInputException('Batch not found!');
        }
        if (!$batch['expiration_date'] || $batch['expiration_date'] >= date('Y-m-d')) {
            throw new InventoryInputException('This batch is not expired yet!');
        }

        $productId = (int)$batch['product_id'];
        $batchRef  = $batch['batch_reference'];

        // Serialise with other stock movements on this product.
        $stmt = inv_prepare($conn, "SELECT id FROM inventory WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $productId);
        inv_execute($stmt);
        $stmt->close();

        $stmt = inv_prepare($conn, "SELECT COALESCE(SUM(CASE WHEN movement_type = 'IN' THEN quantity ELSE -quantity END), 0) AS remaining_qty
                                    FROM stock_movements WHERE product_id = ? AND batch_reference = ?");
        $stmt->bind_param('is', $productId, $batchRef);
        inv_execute($stmt);
        $remainingQty = round((float)$stmt->get_result()->fetch_assoc()['remaining_qty'], 3);
        $stmt->close();
        if ($remainingQty <= 0.0005) {
            throw new InventoryInputException('This batch has no remaining stock to archive.');
        }

        $note = 'Archived expired batch (batch: ' . $batchRef . ')';
        $stmt = inv_prepare($conn, "INSERT INTO stock_movements
            (product_id, movement_type, quantity, expiration_date, batch_reference, notes, created_by)
            VALUES (?, 'OUT', ?, ?, ?, ?, ?)");
        $stmt->bind_param('idsssi', $productId, $remainingQty, $batch['expiration_date'], $batchRef, $note, $userId);
        inv_execute($stmt);
        $stmt->close();

        $stmt = inv_prepare($conn, "UPDATE inventory SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?");
        $stmt->bind_param('di', $remainingQty, $productId);
        inv_execute($stmt);
        $stmt->close();

        return 'Expired batch archived (' . fmt_qty($remainingQty) . ' removed from stock).';
    }, 'Could not archive the batch. Nothing was saved — please try again.');
}

/** Move a product row between the Display (sold at POS) and Warehouse (back stock) locations. */
function inv_handle_transfer(mysqli $conn) {
    $productId = (int)($_POST['product_id'] ?? 0);
    $type      = (string)($_POST['inventory_type'] ?? '');

    if (!in_array($type, ['Display', 'Warehouse'], true)) {
        flash_error('Invalid inventory type selected.');
        return;
    }
    $stmt = $conn->prepare("UPDATE inventory SET inventory_type = ? WHERE id = ?");
    $stmt->bind_param('si', $type, $productId);
    if ($stmt->execute()) {
        flash_success('Moved to ' . $type . ' successfully.');
    } else {
        error_log('[Inventory] transfer failed: ' . $stmt->error);
        flash_error('Could not change the inventory type. Please try again.');
    }
    $stmt->close();
}

/**
 * Convert Stock (manual) — breaks a bulk unit into another unit of the SAME product,
 * e.g. 2 sack of Rice -> 100 kg of Rice (1 sack = 50 kg).
 *
 * - Source stock is consumed FIFO (earliest expiry first), one OUT row per batch.
 * - Each consumed batch becomes its own IN batch on the target row, so expiry dates
 *   are preserved and the cost per unit is carried over (batch cost / factor).
 * - The target row is created automatically if that unit does not exist yet.
 * - Optionally remembers the factor as the saved conversion rule for that pair.
 */
function inv_handle_convert_stock(mysqli $conn, array $unitOptions) {
    $sourceId = (int)($_POST['product_id'] ?? 0);
    $qty      = round((float)($_POST['convert_qty'] ?? 0), 3);
    $toUnit   = strtolower(trim((string)($_POST['convert_to_unit'] ?? '')));
    $factor   = round((float)($_POST['convert_factor'] ?? 0), 3);
    $newPrice = round((float)($_POST['convert_new_price'] ?? 0), 2);
    $remember = isset($_POST['convert_remember']);
    $userId   = auth_user_id();

    inv_run_transaction($conn, function () use ($conn, $unitOptions, $sourceId, $qty, $toUnit, $factor, $newPrice, $remember, $userId) {
        if ($sourceId <= 0) {
            throw new InventoryInputException('Please choose a product to convert.');
        }
        if ($qty <= 0) {
            throw new InventoryInputException('Enter how many to convert.');
        }
        if ($factor <= 0) {
            throw new InventoryInputException('Enter a conversion factor greater than 0.');
        }
        if (!isset($unitOptions[$toUnit])) {
            throw new InventoryInputException('Please select a valid unit to convert into.');
        }

        $stmt = inv_prepare($conn, "SELECT id, product_name, category, supplier, product_unit, inventory_type, product_code, cost_price, stock_quantity
                                    FROM inventory WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $sourceId);
        inv_execute($stmt);
        $source = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$source) {
            throw new InventoryInputException('That product no longer exists.');
        }

        $fromUnit = inv_unit_display($source['product_unit']);
        $toLabel  = inv_unit_display($toUnit);
        if (inv_unit_key($source['product_unit']) === inv_unit_key($toUnit)) {
            throw new InventoryInputException('Choose a different unit to convert into.');
        }
        if (!is_kg_unit($source['product_unit']) && abs($qty - round($qty)) > 0.0005) {
            throw new InventoryInputException('Quantity must be a whole number of ' . $fromUnit . '.');
        }
        if (!is_kg_unit($toUnit) && abs($factor - round($factor)) > 0.0005) {
            throw new InventoryInputException('One ' . $fromUnit . ' must produce a whole number of ' . $toLabel . '.');
        }

        // Find (or create) the target row: same product name, requested unit.
        $stmt = inv_prepare($conn, "SELECT id, product_unit, status FROM inventory
                                    WHERE LOWER(product_name) = LOWER(?) AND id <> ? FOR UPDATE");
        $stmt->bind_param('si', $source['product_name'], $sourceId);
        inv_execute($stmt);
        $siblings = $stmt->get_result();
        $target = null;
        while ($candidate = $siblings->fetch_assoc()) {
            if (inv_unit_key($candidate['product_unit']) === inv_unit_key($toUnit)) { $target = $candidate; break; }
        }
        $stmt->close();

        $newRow = false;
        if ($target) {
            $targetId = (int)$target['id'];
        } else {
            if ($newPrice <= 0) {
                throw new InventoryInputException('Enter the selling price for the new ' . $toLabel . ' row.');
            }
            $baseCode = trim((string)$source['product_code']) !== '' ? $source['product_code'] : generate_product_code($source['product_name']);
            $newCode  = substr($baseCode . '_' . $toUnit, 0, 100);
            $zeroCost = 0.0;
            $stmt = inv_prepare($conn, "INSERT INTO inventory
                (product_name, stock_quantity, category, supplier, product_unit, price, cost_price, status, inventory_type, product_code)
                VALUES (?, 0, ?, ?, ?, ?, ?, 'Active', ?, ?)");
            $stmt->bind_param('ssssddss', $source['product_name'], $source['category'], $source['supplier'], $toUnit,
                              $newPrice, $zeroCost, $source['inventory_type'], $newCode);
            inv_execute($stmt);
            $targetId = (int)$conn->insert_id;
            $stmt->close();
            $newRow = true;
        }

        // Lock the target row too, then check the source batches have enough on hand.
        $stmt = inv_prepare($conn, "SELECT id FROM inventory WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $targetId);
        inv_execute($stmt);
        $stmt->close();

        $batches   = fetch_available_stock_batches($conn, $sourceId);
        $available = 0.0;
        foreach ($batches as $b) { $available += (float)$b['available_qty']; }
        $stockCap = max($available, (float)$source['stock_quantity']);
        if ($qty > $stockCap + 0.0005) {
            throw new InventoryInputException('Not enough ' . $fromUnit . ' in stock. Available: ' . fmt_qty($available) . ' ' . $fromUnit . ', requested: ' . fmt_qty($qty) . '.');
        }

        $costStmt = inv_prepare($conn, "SELECT cost_price FROM stock_movements
                                        WHERE product_id = ? AND movement_type = 'IN'
                                          AND COALESCE(NULLIF(batch_reference, ''), CONCAT('LEGACY-', id)) = ? LIMIT 1");
        $outStmt = inv_prepare($conn, "INSERT INTO stock_movements
            (product_id, movement_type, quantity, expiration_date, batch_reference, notes, created_by)
            VALUES (?, 'OUT', ?, ?, ?, ?, ?)");
        $inStmt = inv_prepare($conn, "INSERT INTO stock_movements
            (product_id, movement_type, quantity, cost_price, expiration_date, batch_reference, notes, created_by)
            VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)");

        $remaining = $qty;
        $totalYield = 0.0;
        $lastUnitCost = 0.0;
        $ruleText = '1 ' . $fromUnit . ' = ' . fmt_qty($factor) . ' ' . $toLabel;
        foreach ($batches as $batch) {
            if ($remaining <= 0.0005) break;
            $avail = (float)$batch['available_qty'];
            if ($avail <= 0.0005) continue;
            $used       = round(min($avail, $remaining), 3);
            $yield      = round($used * $factor, 3);
            $expiration = $batch['expiration_date'] ?: null;
            $batchKey   = (string)$batch['batch_key'];

            $costStmt->bind_param('is', $sourceId, $batchKey);
            inv_execute_keep($costStmt);
            $costRow   = $costStmt->get_result()->fetch_assoc();
            $batchCost = $costRow ? (float)$costRow['cost_price'] : (float)$source['cost_price'];
            $unitCost  = $batchCost > 0 ? round($batchCost / $factor, 2) : 0.0;

            $outNote = 'Converted to ' . $toLabel . ': ' . fmt_qty($used) . ' ' . $fromUnit . ' -> ' . fmt_qty($yield) . ' ' . $toLabel . ' (' . $ruleText . ')';
            $outStmt->bind_param('idsssi', $sourceId, $used, $expiration, $batchKey, $outNote, $userId);
            inv_execute_keep($outStmt);

            $newBatch = 'CONV-' . date('YmdHis') . '-' . $targetId . '-' . strtoupper(bin2hex(random_bytes(2)));
            $inNote   = 'Converted from ' . $fromUnit . ': ' . fmt_qty($used) . ' ' . $fromUnit . ' -> ' . fmt_qty($yield) . ' ' . $toLabel
                      . ' (' . $ruleText . ', source batch ' . $batchKey . ')';
            $inStmt->bind_param('iddsssi', $targetId, $yield, $unitCost, $expiration, $newBatch, $inNote, $userId);
            inv_execute_keep($inStmt);

            $remaining   = round($remaining - $used, 3);
            $totalYield += $yield;
            if ($unitCost > 0) { $lastUnitCost = $unitCost; }
        }
        // Stock that exists only as a cached total (no batch history): convert it as an untracked batch.
        if ($remaining > 0.0005) {
            $used      = round($remaining, 3);
            $yield     = round($used * $factor, 3);
            $batchKey  = 'LEGACY-STOCK';
            $unitCost  = (float)$source['cost_price'] > 0 ? round((float)$source['cost_price'] / $factor, 2) : 0.0;
            $expiration = null;
            $outNote = 'Converted to ' . $toLabel . ': ' . fmt_qty($used) . ' ' . $fromUnit . ' -> ' . fmt_qty($yield) . ' ' . $toLabel . ' (' . $ruleText . ')';
            $outStmt->bind_param('idsssi', $sourceId, $used, $expiration, $batchKey, $outNote, $userId);
            inv_execute_keep($outStmt);
            $newBatch = 'CONV-' . date('YmdHis') . '-' . $targetId . '-' . strtoupper(bin2hex(random_bytes(2)));
            $inNote   = 'Converted from ' . $fromUnit . ': ' . fmt_qty($used) . ' ' . $fromUnit . ' -> ' . fmt_qty($yield) . ' ' . $toLabel . ' (' . $ruleText . ')';
            $inStmt->bind_param('iddsssi', $targetId, $yield, $unitCost, $expiration, $newBatch, $inNote, $userId);
            inv_execute_keep($inStmt);
            $totalYield += $yield;
            if ($unitCost > 0) { $lastUnitCost = $unitCost; }
        }
        $costStmt->close(); $outStmt->close(); $inStmt->close();

        // Cached totals on both inventory rows.
        $stmt = inv_prepare($conn, "UPDATE inventory SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?");
        $stmt->bind_param('di', $qty, $sourceId);
        inv_execute($stmt);
        $stmt->close();

        $stmt = inv_prepare($conn, "UPDATE inventory
            SET stock_quantity = stock_quantity + ?, status = 'Active',
                cost_price = CASE WHEN ? > 0 THEN ? ELSE cost_price END
            WHERE id = ?");
        $stmt->bind_param('dddi', $totalYield, $lastUnitCost, $lastUnitCost, $targetId);
        inv_execute($stmt);
        $stmt->close();

        // Optionally save the factor as this pair's conversion rule (keeps any auto-convert settings).
        if ($remember) {
            $existing  = get_unit_conversion_for_target($conn, $targetId);
            $threshold = $existing ? (int)$existing['low_stock_threshold'] : 0;
            $autoOn    = $existing ? (int)$existing['auto_convert_enabled'] : 0;
            save_unit_conversion($conn, $sourceId, $targetId, 1, $factor, $threshold, $autoOn, $userId);
        }

        $left = max(0, round((float)$source['stock_quantity'] - $qty, 3));
        return 'Converted ' . fmt_qty($qty) . ' ' . $fromUnit . ' of "' . $source['product_name'] . '" into '
             . fmt_qty($totalYield) . ' ' . $toLabel . ' (' . $ruleText . '). '
             . $fromUnit . ' remaining: ' . fmt_qty($left) . '.'
             . ($newRow ? ' A new ' . $toLabel . ' row was created.' : '')
             . ($remember ? ' Conversion factor saved.' : '');
    }, 'Could not convert the stock. Nothing was changed — please try again.');
}

function inv_handle_save_conversion(mysqli $conn) {
    $toProductId   = (int)($_POST['product_id'] ?? 0);
    $fromProductId = (int)($_POST['conversion_from_id'] ?? 0);
    $unitsPer      = max(1, (int)($_POST['conversion_units_per'] ?? 1));
    $ratio         = (float)($_POST['conversion_ratio'] ?? 0);
    $threshold     = max(0, (int)($_POST['conversion_threshold'] ?? 0));
    $autoEnabled   = isset($_POST['conversion_auto_enabled']) ? 1 : 0;

    if ($fromProductId <= 0) {
        flash_error('Choose which product to convert stock from.');
        return;
    }
    if ($fromProductId === $toProductId) {
        flash_error('A product cannot be converted from itself.');
        return;
    }
    if ($ratio <= 0) {
        flash_error('Conversion ratio must be greater than 0.');
        return;
    }

    // Both rows must be the SAME product in DIFFERENT units (e.g. Rice sack -> Rice kg).
    $stmt = $conn->prepare("SELECT id, product_name, product_unit FROM inventory WHERE id IN (?, ?)");
    $stmt->bind_param('ii', $fromProductId, $toProductId);
    $stmt->execute();
    $pair = [];
    $pairRes = $stmt->get_result();
    while ($pr = $pairRes->fetch_assoc()) { $pair[(int)$pr['id']] = $pr; }
    $stmt->close();
    if (!isset($pair[$fromProductId], $pair[$toProductId])) {
        flash_error('One of the selected products no longer exists.');
        return;
    }
    if (mb_strtolower(trim($pair[$fromProductId]['product_name'])) !== mb_strtolower(trim($pair[$toProductId]['product_name']))) {
        flash_error('A conversion can only link two units of the same product.');
        return;
    }
    if (inv_unit_key($pair[$fromProductId]['product_unit']) === inv_unit_key($pair[$toProductId]['product_unit'])) {
        flash_error('Choose a different unit to convert from.');
        return;
    }

    if (save_unit_conversion($conn, $fromProductId, $toProductId, $unitsPer, $ratio, $threshold, $autoEnabled, auth_user_id())) {
        flash_success('Unit conversion saved.');
    } else {
        flash_error('Could not save the unit conversion. Please try again.');
    }
}

function inv_handle_delete_conversion(mysqli $conn) {
    $toProductId = (int)($_POST['product_id'] ?? 0);
    if (delete_unit_conversion($conn, $toProductId)) {
        flash_success('Unit conversion removed.');
    } else {
        flash_error('Could not remove the unit conversion. Please try again.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_POST['stock_in'])) {
        inv_handle_stock_in($conn, $UNIT_OPTIONS);
    } elseif (isset($_POST['stock_out'])) {
        inv_handle_stock_out($conn, $STOCK_OUT_REASONS);
        // Runs in its own transaction, after the stock-out above has already
        // committed — a no-op unless this product has an enabled unit
        // conversion rule and just dropped to/below its threshold.
        maybe_auto_convert_stock($conn, (int)($_POST['product_id'] ?? 0), auth_user_id());
    } elseif (isset($_POST['archive_batch'])) {
        inv_handle_archive_batch($conn);
    } elseif (isset($_POST['transfer_type'])) {
        inv_handle_transfer($conn);
    } elseif (isset($_POST['convert_stock'])) {
        inv_handle_convert_stock($conn, $UNIT_OPTIONS);
    } elseif (isset($_POST['save_conversion'])) {
        inv_handle_save_conversion($conn);
    } elseif (isset($_POST['delete_conversion'])) {
        inv_handle_delete_conversion($conn);
    }
    inv_redirect();
}

// ───────────────────────────── AJAX (GET) ─────────────────────────────────

// Batch details for one product: one row per batch, remaining = IN - OUT.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_batches') {
    header('Content-Type: application/json');
    $product_id = (int)($_GET['product_id'] ?? 0);

    $stmt = $conn->prepare("SELECT MIN(CASE WHEN sm.movement_type = 'IN' THEN sm.id END) AS id,
                                   sm.batch_reference,
                                   MAX(CASE WHEN sm.movement_type = 'IN' THEN sm.cost_price END) AS cost_price,
                                   MAX(sm.expiration_date) AS exp_date,
                                   MIN(sm.created_at) AS first_created,
                                   SUM(CASE WHEN sm.movement_type = 'IN' THEN sm.quantity ELSE -sm.quantity END) AS remaining_qty
                            FROM stock_movements sm
                            WHERE sm.product_id = ? AND sm.batch_reference IS NOT NULL AND sm.batch_reference <> ''
                            GROUP BY sm.batch_reference
                            HAVING SUM(CASE WHEN sm.movement_type = 'IN' THEN 1 ELSE 0 END) > 0
                            ORDER BY (exp_date IS NULL), exp_date ASC, first_created ASC");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $batches = [];
    while ($batch = $result->fetch_assoc()) {
        $batches[] = [
            'id'              => (int)$batch['id'],
            'product_id'      => $product_id,
            'batch_reference' => $batch['batch_reference'],
            'quantity'        => round((float)$batch['remaining_qty'], 3),
            'cost_price'      => (float)$batch['cost_price'],
            'expiration_date' => $batch['exp_date'],
            'created_at'      => date('Y-m-d', strtotime($batch['first_created'])),
        ];
    }
    echo json_encode($batches);
    $stmt->close();
    $conn->close();
    exit();
}

// ───────────────────────────── Page data ──────────────────────────────────

function inv_unit_display($unit) {
    return inv_unit_key($unit) === 'kls' ? 'kg' : (string)$unit;
}

$sql = "SELECT i.id, i.product_name, i.stock_quantity, i.product_unit, i.category, i.supplier, i.inventory_type,
               i.price, i.cost_price, i.expiration_date AS legacy_expiration,
               (SELECT MIN(sm.expiration_date)
                  FROM stock_movements sm
                 WHERE sm.product_id = i.id AND sm.movement_type = 'IN'
                   AND sm.expiration_date >= CURDATE()
                   AND (SELECT COALESCE(SUM(CASE WHEN m2.movement_type = 'IN' THEN m2.quantity ELSE -m2.quantity END), 0)
                          FROM stock_movements m2
                         WHERE m2.product_id = sm.product_id AND m2.batch_reference = sm.batch_reference) > 0
               ) AS next_expiry,
               (SELECT COUNT(*) FROM stock_movements mc WHERE mc.product_id = i.id) AS movement_count
        FROM inventory i
        WHERE i.status = 'Active'
        ORDER BY i.product_name ASC, i.product_unit ASC";
$result = $conn->query($sql);

$inventoryRows = [];
$categoryList  = [];
$supplierList  = [];
$variantMap    = [];   // product group => every unit row, used by the Stock In popup
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['group']       = md5(mb_strtolower(trim($row['product_name'])));
        $row['unit_key']    = inv_unit_key($row['product_unit']);
        $row['has_history'] = ((int)$row['movement_count'] > 0 || (float)$row['stock_quantity'] > 0.0005);
        // Rows with no ledger history fall back to the old per-product expiration column.
        $row['display_expiry'] = $row['next_expiry'] ?: ((int)$row['movement_count'] === 0 ? $row['legacy_expiration'] : null);

        $inventoryRows[] = $row;
        if (!empty($row['category'])) { $categoryList[$row['category']] = true; }
        if (!empty($row['supplier'])) { $supplierList[$row['supplier']] = true; }
        $variantMap[$row['group']][] = [
            'id'    => (int)$row['id'],
            'unit'  => $row['unit_key'],
            'qty'   => (float)$row['stock_quantity'],
            'price' => (float)$row['price'],
            'cost'  => (float)$row['cost_price'],
        ];
    }
}
ksort($categoryList);
ksort($supplierList);

$inventorySummary = [
    'items' => count($inventoryRows),
    'low' => 0,
    'out' => 0,
    'expiring' => 0,
];
$today = strtotime(date('Y-m-d'));
foreach ($inventoryRows as $summaryRow) {
    $summaryQuantity = (float)$summaryRow['stock_quantity'];
    if ($summaryQuantity <= 0) {
        $inventorySummary['out']++;
    } elseif ($summaryQuantity < 10) {
        $inventorySummary['low']++;
    }
    if (!empty($summaryRow['display_expiry'])) {
        $daysUntilExpiry = (int)floor((strtotime($summaryRow['display_expiry']) - $today) / 86400);
        if ($daysUntilExpiry >= 0 && $daysUntilExpiry <= 7) {
            $inventorySummary['expiring']++;
        }
    }
}

// Unit conversion rules, keyed by the retail (to) product id, for the
// "Unit Conversion" popup and the auto-convert indicator pill.
$conversionRules = [];
$convRes = $conn->query("SELECT uc.*, fp.product_name AS from_product_name, fp.product_unit AS from_unit
                          FROM unit_conversions uc
                          JOIN inventory fp ON fp.id = uc.from_product_id");
if ($convRes) {
    while ($convRow = $convRes->fetch_assoc()) {
        $conversionRules[(int)$convRow['to_product_id']] = $convRow;
    }
}

// Lightweight product list for the "convert from" dropdown in that popup.
$conversionSourceOptions = [];
foreach ($inventoryRows as $optRow) {
    $conversionSourceOptions[] = [
        'id'   => (int)$optRow['id'],
        'name' => $optRow['product_name'],
        'unit' => inv_unit_display($optRow['product_unit']),
        'group' => $optRow['group'],
    ];
}

// Saved factor per pair (target units produced by ONE source unit): from id => to id => factor.
$ruleFactorMap = [];
foreach ($conversionRules as $toId => $r) {
    $per = max(1, (int)$r['units_per_conversion']);
    $ruleFactorMap[(int)$r['from_product_id']][(int)$toId] = round((float)$r['conversion_ratio'] / $per, 3);
}

// ───────────────────────────── Page rendering ─────────────────────────────
$page_title = 'Inventory';
$breadcrumb = ['Inventory', 'Stock'];
$active = 'Inventory.php';

ob_start(); ?>
<style>
/* ── Inventory popups (Stock In / Stock Out / Move) ───────────────────────── */
.inv-overlay { position:fixed; inset:0; display:none; align-items:center; justify-content:center;
    padding:16px; background:rgba(18,58,37,.55); backdrop-filter:blur(3px); z-index:10000; }
.inv-overlay.is-open { display:flex; animation:invFade .18s ease; }
@keyframes invFade { from { opacity:0 } to { opacity:1 } }
@keyframes invRise { from { transform:translateY(24px); opacity:0 } to { transform:none; opacity:1 } }

.inv-modal { background:#fff; border-radius:16px; width:100%; max-width:580px; max-height:92vh;
    display:flex; flex-direction:column; overflow:hidden; color:#1f2937;
    box-shadow:0 24px 60px rgba(0,0,0,.28); animation:invRise .22s cubic-bezier(.34,1.26,.64,1); }
.inv-modal form { display:flex; flex-direction:column; min-height:0; flex:1; margin:0; padding:0; }

.inv-modal-head { flex:none; position:relative; background:var(--accent); padding:22px 64px 18px 26px; color:#fff; }
.inv-pill { display:inline-flex; align-items:center; gap:6px; padding:3px 11px; margin-bottom:10px;
    border-radius:20px; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.22);
    font-size:11px; letter-spacing:.03em; color:rgba(255,255,255,.85); }
.inv-pill::before { content:''; width:6px; height:6px; border-radius:50%; background:#86efac; }
.inv-modal-head h2 { margin:0 0 4px; font-size:20px; font-weight:600; color:#fff; line-height:1.25; }
.inv-modal-head p { margin:0; font-size:13px; color:rgba(255,255,255,.7); }
.inv-modal .inv-close { position:absolute; top:16px; right:16px; width:32px; height:32px; padding:0; margin:0;
    border-radius:50%; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25);
    color:#fff; font-size:14px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition:background .15s; }
.inv-modal .inv-close:hover { background:rgba(255,255,255,.26); }

.inv-modal-body { padding:20px 26px 6px; overflow-y:auto; }
.inv-section { font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#6b7280;
    margin:0 0 12px; padding-bottom:7px; border-bottom:1px solid #eef1ee; }
.inv-section:not(:first-child) { margin-top:22px; }
.inv-grid { display:grid; gap:14px; margin-bottom:14px; }
.inv-grid.cols-2 { grid-template-columns:1fr 1fr; }

.inv-field { display:flex; flex-direction:column; gap:5px; min-width:0; }
.inv-field label { font-size:12px; font-weight:600; color:#4b5563; }
.inv-field label .req { color:#dc2626; margin-left:2px; }
.inv-modal .inv-field input, .inv-modal .inv-field select { width:100%; box-sizing:border-box; margin:0; height:auto;
    padding:10px 12px; font-size:14px; color:#111827; background:#fafafa; border:1.5px solid #e5e7eb;
    border-radius:8px; outline:none; transition:border-color .15s, box-shadow .15s, background .15s; }
.inv-modal .inv-field input:focus, .inv-modal .inv-field select:focus { border-color:var(--accent); background:#fff;
    box-shadow:0 0 0 3px var(--accent-soft); }
.inv-modal .inv-field input[readonly] { background:#f3f4f6; color:#374151; cursor:default; font-weight:600; }
.inv-modal .inv-field .has-error { border-color:#dc2626; background:#fff8f8; }
.inv-error { display:none; font-size:11.5px; color:#dc2626; }
.inv-error.show { display:block; }
.inv-hint { font-size:11.5px; color:#6b7280; }

.inv-prefix { position:relative; }
.inv-prefix > span { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#6b7280; font-size:14px; pointer-events:none; }
.inv-modal .inv-prefix input { padding-left:28px; }

.inv-chip { display:inline-flex; align-items:center; gap:6px; margin-top:6px; padding:3px 10px; font-size:12px;
    border-radius:999px; background:var(--accent-soft); color:var(--accent-dark); font-weight:600; width:fit-content; }

.inv-notice { display:none; margin:2px 0 14px; padding:10px 12px; border-radius:8px; font-size:12.5px; line-height:1.45; }
.inv-notice.show { display:block; }
.inv-notice.info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; }
.inv-notice.warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }

.inv-summary { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:6px 0 14px; padding:12px;
    background:#f6faf7; border:1px solid #dcebe1; border-radius:10px; }
.inv-summary div span { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; }
.inv-summary div strong { display:block; margin-top:2px; font-size:15px; color:#111827; }
.inv-summary .neg { color:#dc2626; }

.inv-alert { display:none; margin-bottom:14px; padding:10px 14px; border-radius:8px; background:#fff5f5;
    border:1px solid #fed7d7; color:#c53030; font-size:13px; }
.inv-alert.show { display:block; }

.inv-modal-foot { flex:none; display:flex; justify-content:flex-end; align-items:center; gap:10px;
    padding:14px 26px 18px; border-top:1px solid #eef1ee; background:#fafbfa; }
.inv-modal .inv-btn { margin:0; padding:10px 22px; font-size:14px; font-weight:600; border-radius:8px; cursor:pointer;
    transition:background .15s, transform .1s, opacity .15s; }
.inv-modal .inv-btn-primary { background:var(--accent); color:#fff; border:1.5px solid var(--accent); }
.inv-modal .inv-btn-primary:hover { background:var(--accent-dark); border-color:var(--accent-dark); }
.inv-modal .inv-btn-primary:active { transform:scale(.97); }
.inv-modal .inv-btn-primary:disabled { opacity:.6; cursor:wait; }
.inv-modal .inv-btn-ghost { background:#fff; color:#4b5563; border:1.5px solid #d1d5db; }
.inv-modal .inv-btn-ghost:hover { background:#f3f4f6; }
.inv-link { background:none; border:0; padding:0; color:var(--accent); font-size:12px; font-weight:600; cursor:pointer; text-decoration:underline; }

.inv-toolbar-note { font-size:12px; color:#6b7280; margin:-4px 0 10px; }
.inv-factor { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:10px 12px; border:1.5px solid #d1d5db; border-radius:8px; background:#f9fafb; }
.inv-factor span { font-size:14px; font-weight:600; color:#374151; white-space:nowrap; }
.inv-modal .inv-factor input { width:130px; flex:0 0 auto; background:#fff; text-align:right; }
.inv-field-head { display:flex; justify-content:space-between; align-items:baseline; gap:8px; }
.inv-preview { display:none; margin:2px 0 14px; border:1px solid #bbf7d0; background:#f0fdf4; border-radius:10px; padding:12px 14px; }
.inv-preview.show { display:block; }
.inv-preview.bad { border-color:#fecaca; background:#fef2f2; }
.inv-preview-main { font-size:17px; font-weight:700; color:#14532d; text-align:center; margin-bottom:8px; }
.inv-preview.bad .inv-preview-main { color:#991b1b; font-size:13.5px; font-weight:600; }
.inv-preview table { width:100%; border-collapse:collapse; font-size:12.5px; color:#374151; }
.inv-preview td { padding:3px 0; }
.inv-preview td:last-child { text-align:right; font-weight:600; white-space:nowrap; }
.inv-check { flex-direction:row !important; align-items:center; gap:10px !important; }
.inv-check input { width:auto !important; }
.inv-check label { margin:0; font-weight:500 !important; }
.inv-dim { opacity:.45; pointer-events:none; }
.inv-expiry-soon { color:#b45309; font-weight:600; }
.inv-no-results td { text-align:center; color:#6b7280; padding:22px 12px !important; }

@media (max-width:560px) {
    .inv-grid.cols-2 { grid-template-columns:1fr; }
    .inv-summary { grid-template-columns:1fr; }
    .inv-modal-head, .inv-modal-body, .inv-modal-foot { padding-left:18px; padding-right:18px; }
}
</style>
<?php
$extra_head = ob_get_clean();
require_once __DIR__ . '/../includes/header.php';
?>

<?php render_page_heading('Inventory', 'Monitor stock levels, expiry dates, and product locations. Use Products to manage catalog details.'); ?>

<div class="admin-inventory-summary" aria-label="Inventory summary">
    <span><strong><?php echo number_format($inventorySummary['items']); ?></strong> stock items</span>
    <span><strong><?php echo number_format($inventorySummary['low']); ?></strong> low stock</span>
    <span><strong><?php echo number_format($inventorySummary['out']); ?></strong> out of stock</span>
    <span><strong><?php echo number_format($inventorySummary['expiring']); ?></strong> expiring within 7 days</span>
</div>

<!-- Search and Filter Controls -->
<div class="search-filter-container admin-inventory-filters">
    <div class="search-box">
        <input type="search" id="searchInput" aria-label="Search product name" placeholder="Search product name..." oninput="applyInventoryFilters()">
    </div>
    <div class="filter-group">
        <label class="filter-label" for="categoryFilter">Category</label>
        <select id="categoryFilter" class="filter-select" onchange="applyInventoryFilters()">
            <option value="">All Categories</option>
            <?php foreach (array_keys($categoryList) as $cat): ?>
            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label" for="supplierFilter">Supplier</label>
        <select id="supplierFilter" class="filter-select" onchange="applyInventoryFilters()">
            <option value="">All Suppliers</option>
            <?php foreach (array_keys($supplierList) as $sup): ?>
            <option value="<?php echo htmlspecialchars($sup); ?>"><?php echo htmlspecialchars($sup); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label" for="inventoryTypeFilter">Location</label>
        <select id="inventoryTypeFilter" class="filter-select" onchange="applyInventoryFilters()">
            <option value="">All Types</option>
            <option value="Display">Display</option>
            <option value="Warehouse">Warehouse</option>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label" for="stockStatusFilter">Stock level</label>
        <select id="stockStatusFilter" class="filter-select" onchange="applyInventoryFilters()">
            <option value="">All Stock Levels</option>
            <option value="out">Out of Stock</option>
            <option value="low">Low Stock (&lt; 10)</option>
            <option value="medium">Medium Stock (10-49)</option>
            <option value="high">High Stock (≥ 50)</option>
        </select>
    </div>
    <div class="admin-inventory-history">
        <a class="action-btn" href="Stock-History.php">Stock history</a>
    </div>
</div>

<!-- ═══════════════════ STOCK IN POPUP ═══════════════════ -->
<div id="stockInPopup" class="inv-overlay" aria-hidden="true">
    <div class="inv-modal" role="dialog" aria-modal="true" aria-labelledby="stockInTitle">
        <div class="inv-modal-head">
            <div class="inv-pill">Inventory Management</div>
            <h2 id="stockInTitle">Stock in</h2>
            <p>Record a new batch received for this product.</p>
            <button type="button" class="inv-close" data-inv-close aria-label="Close">&#10005;</button>
        </div>

        <form method="post" action="" id="stockInForm" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" name="stock_in" value="1">
            <input type="hidden" id="stock_in_product_id" name="product_id">

            <div class="inv-modal-body">
                <div class="inv-alert" id="stockInAlert" role="alert"></div>

                <div class="inv-section">Product</div>
                <div class="inv-grid">
                    <div class="inv-field">
                        <label for="stock_in_product_name">Product name</label>
                        <input type="text" id="stock_in_product_name" readonly>
                        <span class="inv-chip" id="stockInCurrent"></span>
                    </div>
                </div>

                <div class="inv-section">Quantity &amp; unit</div>
                <div class="inv-grid cols-2">
                    <div class="inv-field">
                        <label for="stock_in_quantity">Quantity <span class="req">*</span></label>
                        <input type="number" id="stock_in_quantity" name="stock_in_quantity" min="0.01" step="any" inputmode="decimal" placeholder="0">
                        <div class="inv-error" id="err_si_qty">Enter a whole number greater than 0.</div>
                    </div>
                    <div class="inv-field">
                        <label for="stock_in_unit">Unit <span class="req">*</span></label>
                        <select id="stock_in_unit" name="stock_in_unit">
                            <?php foreach ($UNIT_OPTIONS as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value); ?>" data-label="<?php echo htmlspecialchars($label); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="inv-error" id="err_si_unit">Select a unit.</div>
                    </div>
                </div>
                <div class="inv-notice" id="stockInUnitNotice"></div>

                <div class="inv-section">Pricing</div>
                <div class="inv-grid cols-2">
                    <div class="inv-field">
                        <label for="stock_in_retail_price">Retail price <span class="req">*</span></label>
                        <div class="inv-prefix"><span>₱</span>
                            <input type="number" id="stock_in_retail_price" name="stock_in_retail_price" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                        </div>
                        <div class="inv-error" id="err_si_retail">Enter the retail price (0 or more).</div>
                    </div>
                    <div class="inv-field">
                        <label for="stock_in_cost_price">Cost price <span class="req">*</span></label>
                        <div class="inv-prefix"><span>₱</span>
                            <input type="number" id="stock_in_cost_price" name="stock_in_cost_price" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                        </div>
                        <div class="inv-error" id="err_si_cost">Enter the cost price (0 or more).</div>
                    </div>
                </div>

                <div class="inv-section">Batch</div>
                <div class="inv-grid">
                    <div class="inv-field">
                        <label for="stock_in_expiration">Expiration date <span style="font-weight:400;color:#6b7280;">(optional)</span></label>
                        <input type="date" id="stock_in_expiration" name="stock_in_expiration">
                        <span class="inv-hint">Leave blank for items that do not expire.</span>
                        <div class="inv-error" id="err_si_exp">Expiration date cannot be in the past.</div>
                    </div>
                </div>

                <div class="inv-summary" id="stockInSummary">
                    <div><span>Total cost</span><strong id="siTotalCost">₱0.00</strong></div>
                    <div><span>Margin</span><strong id="siMargin">—</strong></div>
                    <div><span>Stock after</span><strong id="siAfter">—</strong></div>
                </div>
            </div>

            <div class="inv-modal-foot">
                <button type="button" class="inv-btn inv-btn-ghost" data-inv-close>Cancel</button>
                <button type="submit" class="inv-btn inv-btn-primary" id="stockInSubmit">Add stock</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ STOCK OUT POPUP ═══════════════════ -->
<div id="stockOutPopup" class="inv-overlay" aria-hidden="true">
    <div class="inv-modal" role="dialog" aria-modal="true" aria-labelledby="stockOutTitle">
        <div class="inv-modal-head">
            <div class="inv-pill">Inventory Management</div>
            <h2 id="stockOutTitle">Stock out</h2>
            <p>Remove stock using FIFO — earliest expiry leaves first.</p>
            <button type="button" class="inv-close" data-inv-close aria-label="Close">&#10005;</button>
        </div>

        <form method="post" action="" id="stockOutForm" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" name="stock_out" value="1">
            <input type="hidden" id="stock_out_product_id" name="product_id">

            <div class="inv-modal-body">
                <div class="inv-alert" id="stockOutAlert" role="alert"></div>

                <div class="inv-grid cols-2">
                    <div class="inv-field">
                        <label for="stock_out_product_name">Product</label>
                        <input type="text" id="stock_out_product_name" readonly>
                    </div>
                    <div class="inv-field">
                        <label for="stock_out_available">Available stock</label>
                        <input type="text" id="stock_out_available" readonly>
                    </div>
                </div>

                <div class="inv-grid cols-2">
                    <div class="inv-field">
                        <label for="stock_out_quantity">Quantity to remove <span class="req">*</span></label>
                        <input type="number" id="stock_out_quantity" name="stock_out_quantity" min="0.01" step="any" inputmode="decimal" placeholder="0">
                        <button type="button" class="inv-link" id="stockOutAll" style="align-self:flex-start;">Remove all</button>
                        <div class="inv-error" id="err_so_qty">Enter a quantity between 1 and the available stock.</div>
                    </div>
                    <div class="inv-field">
                        <label for="stock_out_reason">Reason <span class="req">*</span></label>
                        <select id="stock_out_reason" name="stock_out_reason">
                            <option value="">— Select reason —</option>
                            <?php foreach ($STOCK_OUT_REASONS as $reasonOption): ?>
                            <option value="<?php echo htmlspecialchars($reasonOption); ?>"><?php echo htmlspecialchars($reasonOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="inv-error" id="err_so_reason">Choose a reason.</div>
                    </div>
                </div>

                <div class="inv-grid">
                    <div class="inv-field">
                        <label for="stock_out_remarks">Remarks <span style="font-weight:400;color:#6b7280;">(optional)</span></label>
                        <input type="text" id="stock_out_remarks" name="stock_out_remarks" maxlength="150" placeholder="e.g. torn sack, leaking bottle">
                    </div>
                </div>

                <div class="inv-summary" style="grid-template-columns:1fr;">
                    <div><span>Stock after removal</span><strong id="soAfter">—</strong></div>
                </div>
            </div>

            <div class="inv-modal-foot">
                <button type="button" class="inv-btn inv-btn-ghost" data-inv-close>Cancel</button>
                <button type="submit" class="inv-btn inv-btn-primary" id="stockOutSubmit">Remove stock</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ MOVE (DISPLAY / WAREHOUSE) POPUP ═══════════════════ -->
<div id="transferTypePopup" class="inv-overlay" aria-hidden="true">
    <div class="inv-modal" role="dialog" aria-modal="true" aria-labelledby="transferTitle" style="max-width:480px;">
        <div class="inv-modal-head">
            <div class="inv-pill">Inventory Management</div>
            <h2 id="transferTitle">Move stock location</h2>
            <p>Display stock is sold at the POS; warehouse stock is kept in the back.</p>
            <button type="button" class="inv-close" data-inv-close aria-label="Close">&#10005;</button>
        </div>

        <form method="post" action="">
            <?php csrf_field(); ?>
            <input type="hidden" id="transfer_product_id" name="product_id">
            <div class="inv-modal-body">
                <div class="inv-grid">
                    <div class="inv-field">
                        <label for="transfer_product_name">Product</label>
                        <input type="text" id="transfer_product_name" readonly>
                    </div>
                </div>
                <div class="inv-grid">
                    <div class="inv-field">
                        <label for="transfer_inventory_type">Move to</label>
                        <select id="transfer_inventory_type" name="inventory_type">
                            <option value="Display">Display</option>
                            <option value="Warehouse">Warehouse</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="inv-modal-foot">
                <button type="button" class="inv-btn inv-btn-ghost" data-inv-close>Cancel</button>
                <button type="submit" name="transfer_type" value="1" class="inv-btn inv-btn-primary">Move</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ CONVERT STOCK POPUP ═══════════════════ -->
<div id="convertStockPopup" class="inv-overlay" aria-hidden="true">
    <div class="inv-modal" role="dialog" aria-modal="true" aria-labelledby="convertStockTitle">
        <div class="inv-modal-head">
            <div class="inv-pill">Inventory Management</div>
            <h2 id="convertStockTitle">Convert stock</h2>
            <p>Break a bulk unit into a smaller one — e.g. 2 sacks into 100 kg. Stock, batches and expiry dates move together.</p>
            <button type="button" class="inv-close" data-inv-close aria-label="Close">&#10005;</button>
        </div>

        <form method="post" action="" id="convertStockForm" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" name="convert_stock" value="1">
            <input type="hidden" id="cv_product_id" name="product_id">

            <div class="inv-modal-body">
                <div class="inv-alert" id="convertStockAlert" role="alert"></div>

                <div class="inv-grid">
                    <div class="inv-field">
                        <label for="cv_from_label">Convert from</label>
                        <input type="text" id="cv_from_label" readonly>
                    </div>
                </div>

                <div class="inv-grid cols-2">
                    <div class="inv-field">
                        <div class="inv-field-head">
                            <label for="cv_qty">Quantity to convert <span class="req">*</span></label>
                            <button type="button" class="inv-link" id="cv_all">Convert all</button>
                        </div>
                        <input type="number" id="cv_qty" name="convert_qty" min="0" step="1" placeholder="e.g. 2" inputmode="decimal">
                        <div class="inv-toolbar-note" id="cv_avail"></div>
                        <div class="inv-error" id="err_cv_qty">Enter a valid quantity within what is in stock.</div>
                    </div>
                    <div class="inv-field">
                        <label for="cv_to_unit">Convert into <span class="req">*</span></label>
                        <select id="cv_to_unit" name="convert_to_unit">
                            <?php foreach ($UNIT_OPTIONS as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value, ENT_QUOTES); ?>" data-base="<?php echo htmlspecialchars($label, ENT_QUOTES); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="inv-grid">
                    <div class="inv-field">
                        <label for="cv_factor">Conversion factor <span class="req">*</span></label>
                        <div class="inv-factor">
                            <span>1 <b id="cv_factor_from">unit</b> =</span>
                            <input type="number" id="cv_factor" name="convert_factor" min="0.001" step="0.001" placeholder="e.g. 50" inputmode="decimal">
                            <span id="cv_factor_to">unit</span>
                        </div>
                        <div class="inv-toolbar-note" id="cv_factor_hint" style="margin-top:6px;"></div>
                        <div class="inv-error" id="err_cv_factor">Enter how many units one bulk unit produces.</div>
                    </div>
                </div>

                <div class="inv-grid" id="cv_price_wrap" style="display:none;">
                    <div class="inv-field">
                        <label for="cv_new_price">Selling price per <span id="cv_price_unit">unit</span> <span class="req">*</span></label>
                        <div class="inv-prefix"><span>₱</span><input type="number" id="cv_new_price" name="convert_new_price" min="0" step="0.01" placeholder="0.00"></div>
                        <div class="inv-toolbar-note">There is no row for this unit yet, so one will be created with this price.</div>
                        <div class="inv-error" id="err_cv_price">Enter the selling price for the new unit.</div>
                    </div>
                </div>

                <div class="inv-preview" id="cv_preview" aria-live="polite"></div>

                <div class="inv-grid">
                    <div class="inv-field inv-check">
                        <input type="checkbox" id="cv_remember" name="convert_remember" value="1">
                        <label for="cv_remember">Remember this factor for next time</label>
                    </div>
                </div>
            </div>

            <div class="inv-modal-foot">
                <button type="button" class="inv-btn inv-btn-ghost" data-inv-close>Cancel</button>
                <button type="submit" class="inv-btn inv-btn-primary" id="convertStockSubmit">Convert stock</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ UNIT CONVERSION POPUP (saved rule + auto-convert) ═══════════════════ -->
<div id="unitConversionPopup" class="inv-overlay" aria-hidden="true">
    <div class="inv-modal" role="dialog" aria-modal="true" aria-labelledby="unitConversionTitle">
        <div class="inv-modal-head">
            <div class="inv-pill">Inventory Management</div>
            <h2 id="unitConversionTitle">Conversion rule</h2>
            <p>Save how a bulk unit breaks down into this unit (e.g. 1 sack = 50 kg), and optionally restock automatically when it runs low.</p>
            <button type="button" class="inv-close" data-inv-close aria-label="Close">&#10005;</button>
        </div>

        <form method="post" action="" id="unitConversionForm" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" id="conv_product_id" name="product_id">

            <div class="inv-modal-body">
                <div class="inv-alert" id="unitConversionAlert" role="alert"></div>

                <div class="inv-grid">
                    <div class="inv-field">
                        <label for="conv_product_name">Converts into (this row)</label>
                        <input type="text" id="conv_product_name" readonly>
                    </div>
                </div>

                <div class="inv-grid">
                    <div class="inv-field">
                        <label for="conversion_from_id">Bulk stock to break down <span class="req">*</span></label>
                        <select id="conversion_from_id" name="conversion_from_id">
                            <option value="">— Select the bulk unit —</option>
                            <?php foreach ($conversionSourceOptions as $opt): ?>
                            <option value="<?php echo (int)$opt['id']; ?>" data-unit="<?php echo htmlspecialchars($opt['unit'], ENT_QUOTES); ?>" data-group="<?php echo htmlspecialchars($opt['group'], ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($opt['name'] . ' (' . unit_label($opt['unit']) . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="inv-toolbar-note" id="conv_from_note" style="margin:4px 0 0;"></div>
                        <div class="inv-error" id="err_conv_from">Choose which unit to convert stock from.</div>
                    </div>
                </div>

                <div class="inv-grid cols-2">
                    <div class="inv-field">
                        <label for="conversion_units_per">Open how many <span id="conv_from_lbl">bulk units</span> each time <span class="req">*</span></label>
                        <input type="number" id="conversion_units_per" name="conversion_units_per" min="1" step="1" value="1">
                    </div>
                    <div class="inv-field">
                        <label for="conversion_ratio">Total yield in <span id="conv_to_lbl">units</span> <span class="req">*</span></label>
                        <input type="number" id="conversion_ratio" name="conversion_ratio" min="0.001" step="0.001" placeholder="e.g. 50">
                        <div class="inv-error" id="err_conv_ratio">Enter how much one conversion produces.</div>
                    </div>
                </div>

                <div class="inv-notice info" id="conv_summary"></div>

                <div class="inv-grid">
                    <div class="inv-field inv-check">
                        <input type="checkbox" id="conversion_auto_enabled" name="conversion_auto_enabled" value="1">
                        <label for="conversion_auto_enabled">Restock automatically when this unit runs low</label>
                    </div>
                </div>

                <div class="inv-grid" id="conv_threshold_wrap">
                    <div class="inv-field">
                        <label for="conversion_threshold">Convert when stock falls to <span id="conv_thr_lbl">units</span> or below</label>
                        <input type="number" id="conversion_threshold" name="conversion_threshold" min="0" step="1" placeholder="e.g. 5">
                        <div class="inv-toolbar-note">Checked after every sale or manual stock-out of this unit.</div>
                    </div>
                </div>
            </div>

            <div class="inv-modal-foot" style="justify-content:space-between;">
                <button type="button" class="inv-btn inv-btn-ghost" id="unitConversionRemove" style="display:none;color:var(--danger, #e74c3c);">Remove rule</button>
                <div style="display:flex;gap:10px;margin-left:auto;">
                    <button type="button" class="inv-btn inv-btn-ghost" data-inv-close>Cancel</button>
                    <button type="submit" name="save_conversion" value="1" class="inv-btn inv-btn-primary">Save rule</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Batch Details Popup -->
<div id="batchDetailsPopup" class="popup-overlay popup-overlay-large" style="display:none;">
    <div class="popup-content popup-content-large">
        <div class="batch-details-header">
            <div>
                <h2 style="margin:0;">Stock Batch Details</h2>
                <div id="batchProductLabel" class="inv-toolbar-note" style="margin:4px 0 0;"></div>
            </div>
            <button type="button" class="close-btn" onclick="closeBatchDetails()" aria-label="Close">✕</button>
        </div>

        <div id="batchTableContainer" class="batch-table-container">
            <table id="batchTable" class="userTable batch-table">
                <thead>
                    <tr>
                        <th>Batch Ref</th>
                        <th>Remaining</th>
                        <th>Cost Price</th>
                        <th>Added Date</th>
                        <th>Expiration Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="batchTableBody"></tbody>
            </table>
        </div>

        <div class="form-actions">
            <button type="button" class="secondary-button" onclick="closeBatchDetails()">Close</button>
        </div>
    </div>
</div>

<!-- Products Table -->
<div class="admin-inventory-table-heading">
    <div>
        <h2>Current stock</h2>
        <p>Each product unit is tracked separately. Stocking in a different unit creates a separate row.</p>
    </div>
    <div class="admin-inventory-table-tools">
        <span><?php echo number_format(count($inventoryRows)); ?> items</span>
        <button type="button" class="secondary-button" id="printInventoryChecklist" <?php echo empty($inventoryRows) ? 'disabled' : ''; ?>>Print count sheet</button>
    </div>
</div>
<div class="app-table-wrapper admin-inventory-table-wrap">
    <table id="inventoryTable" class="userTable">
        <thead>
            <tr>
                <th>Product Name</th>
                <th>Category</th>
                <th>Stock Quantity</th>
                <th>Unit</th>
                <th>Retail Price</th>
                <th>Next Expiry</th>
                <th>Type</th>
                <th>Stock Status</th>
                <th class="admin-inventory-actions-heading">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($inventoryRows) > 0): ?>
                <?php foreach ($inventoryRows as $row):
                    $quantity = (float)$row['stock_quantity'];
                    $level = $quantity <= 0 ? 'out' : ($quantity < 10 ? 'low' : ($quantity < 50 ? 'medium' : 'high'));
                    $unitShown = inv_unit_display($row['product_unit']);
                    $expiry = $row['display_expiry'];
                    $expiryDays = $expiry ? (int)floor((strtotime($expiry) - strtotime(date('Y-m-d'))) / 86400) : null;
                ?>
                <tr data-name="<?php echo htmlspecialchars(mb_strtolower($row['product_name'])); ?>"
                    data-category="<?php echo htmlspecialchars($row['category'] ?? ''); ?>"
                    data-supplier="<?php echo htmlspecialchars($row['supplier'] ?? ''); ?>"
                    data-type="<?php echo htmlspecialchars($row['inventory_type']); ?>"
                    data-level="<?php echo $level; ?>">
                    <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['category'] ?? ''); ?></td>
                    <td><?php echo fmt_qty($quantity); ?></td>
                    <td><?php echo htmlspecialchars($unitShown); ?></td>
                    <td>₱<?php echo number_format((float)$row['price'], 2); ?></td>
                    <td<?php echo ($expiryDays !== null && $expiryDays <= 7) ? ' class="inv-expiry-soon"' : ''; ?>>
                        <?php echo $expiry ? htmlspecialchars($expiry) : 'N/A'; ?>
                    </td>
                    <td><?php echo htmlspecialchars($row['inventory_type']); ?></td>
                    <td>
                        <?php
                            if ($level === 'out') {
                                echo "<span class='pill pill-danger'>Out of Stock</span>";
                            } elseif ($level === 'low') {
                                echo "<span class='pill pill-warning'>Low Stock</span>";
                            } else {
                                echo "<span class='pill pill-success'>In Stock</span>";
                            }
                            $convRule = $conversionRules[(int)$row['id']] ?? null;
                            if ($convRule) {
                                $convTitle = ((int)$convRule['auto_convert_enabled'] === 1 ? 'Auto-converts' : 'Converts (manual)')
                                           . ' from ' . $convRule['from_product_name'] . ' (' . inv_unit_display($convRule['from_unit']) . ')'
                                           . ' at ' . (int)$convRule['low_stock_threshold'] . ' ' . $unitShown . ' or below';
                                $convClass = (int)$convRule['auto_convert_enabled'] === 1 ? 'pill-info' : 'pill-neutral';
                                echo ' <span class="pill ' . $convClass . '" title="' . htmlspecialchars($convTitle, ENT_QUOTES) . '">⇄ Conversion</span>';
                            }
                        ?>
                    </td>
                    <td>
                        <?php
                            $attrs = 'data-id="' . (int)$row['id'] . '"'
                                   . ' data-name="' . htmlspecialchars($row['product_name'], ENT_QUOTES) . '"'
                                   . ' data-unit="' . htmlspecialchars($row['unit_key'], ENT_QUOTES) . '"'
                                   . ' data-qty="' . fmt_qty($quantity) . '"'
                                   . ' data-price="' . (float)$row['price'] . '"'
                                   . ' data-cost="' . (float)$row['cost_price'] . '"'
                                   . ' data-group="' . $row['group'] . '"'
                                   . ' data-history="' . ($row['has_history'] ? '1' : '0') . '"'
                                   . ' data-type="' . htmlspecialchars($row['inventory_type'], ENT_QUOTES) . '"';
                            $convAttrs = $convRule
                                   ? ' data-conv-from="' . (int)$convRule['from_product_id'] . '"'
                                   . ' data-conv-units="' . (int)$convRule['units_per_conversion'] . '"'
                                   . ' data-conv-ratio="' . (float)$convRule['conversion_ratio'] . '"'
                                   . ' data-conv-threshold="' . (int)$convRule['low_stock_threshold'] . '"'
                                   . ' data-conv-enabled="' . (int)$convRule['auto_convert_enabled'] . '"'
                                   : '';
                        ?>
                        <div class="admin-inventory-row-actions">
                            <button type="button" class="admin-inventory-action is-stock-in" data-inv-action="stock-in" <?php echo $attrs; ?>>
                                <span aria-hidden="true">+</span> Stock in
                            </button>
                            <button type="button" class="admin-inventory-action is-stock-out" data-inv-action="stock-out" <?php echo $attrs; ?> <?php echo $quantity <= 0 ? 'disabled title="No stock available to remove"' : ''; ?>>
                                <span aria-hidden="true">−</span> Stock out
                            </button>
                            <div class="row-menu admin-inventory-more">
                                <button type="button" class="row-menu-trigger" aria-label="More actions for <?php echo htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?>">More <span aria-hidden="true">⌄</span></button>
                            <div class="row-menu-list">
                                <button type="button" data-inv-action="batches" <?php echo $attrs; ?>>View Batches</button>
                                <button type="button" data-inv-action="convert" <?php echo $attrs; ?> <?php echo $quantity <= 0 ? 'disabled title="No stock available to convert"' : ''; ?>>Convert Stock…</button>
                                <button type="button" data-inv-action="transfer" <?php echo $attrs; ?>>Move to <?php echo $row['inventory_type'] === 'Display' ? 'Warehouse' : 'Display'; ?></button>
                                <button type="button" data-inv-action="conversion" <?php echo $attrs . $convAttrs; ?>><?php echo $convRule ? 'Edit Conversion Rule' : 'Set Up Conversion Rule'; ?></button>
                            </div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="inv-no-results" id="invNoResults" style="display:none;"><td colspan="9">No products match your filters.</td></tr>
            <?php else: ?>
                <tr data-empty-row><td colspan="9">
                    <div class="app-empty-state">
                        <div class="empty-icon">📦</div>
                        <h3>No products in inventory yet</h3>
                        <p>Add your first SKU from the Products page, then stock it in here.</p>
                        <a href="Manage-Product.php" class="btn btn-primary">Go to Products</a>
                    </div>
                </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<section class="inventory-checklist-print" aria-label="Printable inventory count checklist">
    <header class="inventory-checklist-heading">
        <img src="../assets/logo.png" alt="<?php echo htmlspecialchars(app_name($conn), ENT_QUOTES, 'UTF-8'); ?> logo">
        <div>
            <span class="inventory-checklist-kicker">INVENTORY CONTROL · STOCKTAKE</span>
            <h1><?php echo htmlspecialchars(app_name($conn), ENT_QUOTES, 'UTF-8'); ?></h1>
            <h2>Physical Inventory Count Checklist</h2>
        </div>
        <div class="inventory-checklist-meta">
            <span>Count date: ____________________</span>
            <span>Counted by: ____________________</span>
        </div>
    </header>
    <p class="inventory-checklist-instructions">Count each item and record the physical quantity. Note any damage, missing stock, or other discrepancy.</p>
    <table class="inventory-checklist-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Location</th>
                <th>Recorded quantity</th>
                <th>Physical count</th>
                <th>Difference</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventoryRows as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['category'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['inventory_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="inventory-checklist-quantity"><?php echo fmt_qty($row['stock_quantity']) . ' ' . htmlspecialchars(inv_unit_display($row['product_unit']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <footer class="inventory-checklist-footer">
        <span>Reviewed by: ______________________________</span>
    </footer>
</section>

<?php ob_start(); ?>
<script>
(function () {
    'use strict';

    var CSRF = <?php echo json_encode(csrf_token()); ?>;
    var VARIANTS = <?php echo json_encode($variantMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var RULE_FACTORS = <?php echo json_encode($ruleFactorMap ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    var $ = function (id) { return document.getElementById(id); };
    var printChecklistButton = $('printInventoryChecklist');
    if (printChecklistButton) {
        printChecklistButton.addEventListener('click', function () {
            document.body.classList.add('printing-inventory-checklist');
            window.addEventListener('afterprint', function () {
                document.body.classList.remove('printing-inventory-checklist');
            }, { once: true });
            window.print();
        });
    }

    var esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
    var peso = function (n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    var unitName = function (key) { return key === 'kls' ? 'kg' : key; };
    var todayStr = function () {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    };

    /* ───────────── Modal plumbing ───────────── */
    var lastFocus = null;
    function openModal(id, focusId) {
        var el = $(id);
        lastFocus = document.activeElement;
        el.classList.add('is-open');
        el.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (focusId && $(focusId)) setTimeout(function () { $(focusId).focus(); }, 60);
    }
    function closeModals() {
        document.querySelectorAll('.inv-overlay.is-open').forEach(function (el) {
            el.classList.remove('is-open');
            el.setAttribute('aria-hidden', 'true');
        });
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }
    document.querySelectorAll('.inv-overlay').forEach(function (overlay) {
        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) closeModals(); });
    });
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-inv-close]')) closeModals();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (document.querySelector('.inv-overlay.is-open')) closeModals();
        else closeBatchDetails();
    });

    function showAlert(id, messages) {
        var box = $(id);
        if (!messages.length) { box.classList.remove('show'); box.innerHTML = ''; return; }
        box.innerHTML = 'Please fix the following:<ul style="margin:5px 0 0 16px;">' +
            messages.map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('') + '</ul>';
        box.classList.add('show');
    }
    function flag(inputId, errId, bad) {
        $(inputId).classList.toggle('has-error', bad);
        $(errId).classList.toggle('show', bad);
    }

    /* ───────────── Stock In ───────────── */
    var si = null;      // state for the product the popup is open for
    var priceTouched = false;

    function variantFor(unit) {
        var list = (si && VARIANTS[si.group]) || [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].unit === unit && list[i].id !== si.id) return list[i];
        }
        return null;
    }
    // current  → same unit as the row clicked
    // adopt    → row has no stock/history, so it just takes the chosen unit
    // existing → another row already exists for that unit
    // fresh    → a new row will be created
    function unitStatus(unit) {
        if (unit === si.unit) return { type: 'current' };
        if (!si.hasHistory) return { type: 'adopt' };
        var v = variantFor(unit);
        return v ? { type: 'existing', variant: v } : { type: 'fresh' };
    }

    function refreshUnitLabels() {
        Array.prototype.forEach.call($('stock_in_unit').options, function (opt) {
            var st = unitStatus(opt.value);
            var suffix = st.type === 'existing' ? '  ·  existing row' : (st.type === 'fresh' ? '  ·  new row' : '');
            opt.textContent = opt.getAttribute('data-label') + suffix;
        });
    }

    function updateStockInUI(unitChanged) {
        if (!si) return;
        var unit = $('stock_in_unit').value;
        var st = unitStatus(unit);
        var notice = $('stockInUnitNotice');
        var name = esc(si.name);

        notice.className = 'inv-notice';
        if (st.type === 'existing') {
            notice.innerHTML = 'A <strong>' + esc(unitName(unit)) + '</strong> row already exists for ' + name +
                ' (' + st.variant.qty + ' in stock). The stock will be added to that row.';
            notice.classList.add('show', 'info');
        } else if (st.type === 'fresh') {
            notice.innerHTML = name + ' is not stocked in <strong>' + esc(unitName(unit)) + '</strong> yet. ' +
                'A <strong>new inventory row</strong> will be created for it, separate from the ' +
                esc(unitName(si.unit)) + ' stock.';
            notice.classList.add('show', 'warn');
        } else if (st.type === 'adopt' && unit !== si.unit) {
            notice.innerHTML = name + ' has no stock recorded yet, so it will be stocked in <strong>' +
                esc(unitName(unit)) + '</strong>.';
            notice.classList.add('show', 'info');
        }

        if (unitChanged && !priceTouched) {
            var retail = $('stock_in_retail_price'), cost = $('stock_in_cost_price');
            if (st.type === 'existing') { retail.value = st.variant.price || ''; cost.value = st.variant.cost || ''; }
            else if (st.type === 'fresh') { retail.value = ''; cost.value = ''; }
            else { retail.value = si.price || ''; cost.value = si.cost || ''; }
        }

        var qty = parseFloat($('stock_in_quantity').value);
        var retailVal = parseFloat($('stock_in_retail_price').value);
        var costVal = parseFloat($('stock_in_cost_price').value);
        qty = isNaN(qty) || qty < 0 ? 0 : qty;

        $('siTotalCost').textContent = peso(qty * (isNaN(costVal) ? 0 : costVal));

        var margin = $('siMargin');
        margin.classList.remove('neg');
        if (!isNaN(retailVal) && !isNaN(costVal) && retailVal > 0) {
            var pct = ((retailVal - costVal) / retailVal) * 100;
            margin.textContent = pct.toFixed(1) + '%';
            if (pct < 0) margin.classList.add('neg');
        } else {
            margin.textContent = '—';
        }

        var base = st.type === 'existing' ? st.variant.qty : (st.type === 'fresh' ? 0 : si.qty);
        $('siAfter').textContent = (base + qty) + ' ' + unitName(unit);
    }

    function openStockIn(btn) {
        var d = btn.dataset;
        si = {
            id: parseInt(d.id, 10), name: d.name, unit: d.unit, group: d.group,
            qty: parseInt(d.qty, 10) || 0, price: parseFloat(d.price) || 0, cost: parseFloat(d.cost) || 0,
            hasHistory: d.history === '1'
        };
        priceTouched = false;

        $('stock_in_product_id').value = si.id;
        $('stock_in_product_name').value = si.name;
        $('stockInCurrent').textContent = 'Currently ' + si.qty + ' ' + unitName(si.unit) + ' in stock';
        $('stock_in_quantity').value = '';

        var unitSelect = $('stock_in_unit');
        var hasOption = Array.prototype.some.call(unitSelect.options, function (o) { return o.value === si.unit; });
        unitSelect.value = hasOption ? si.unit : 'pcs';
        if (!hasOption) si.unit = 'pcs';

        $('stock_in_retail_price').value = si.price || '';
        $('stock_in_cost_price').value = si.cost || '';
        $('stock_in_expiration').value = '';
        $('stock_in_expiration').min = todayStr();

        showAlert('stockInAlert', []);
        ['stock_in_quantity', 'stock_in_unit', 'stock_in_retail_price', 'stock_in_cost_price', 'stock_in_expiration']
            .forEach(function (id) { $(id).classList.remove('has-error'); });
        document.querySelectorAll('#stockInPopup .inv-error').forEach(function (e) { e.classList.remove('show'); });
        $('stockInSubmit').disabled = false;
        $('stockInSubmit').textContent = 'Add stock';

        refreshUnitLabels();
        updateStockInUI(false);
        openModal('stockInPopup', 'stock_in_quantity');
    }

    ['stock_in_quantity', 'stock_in_retail_price', 'stock_in_cost_price'].forEach(function (id) {
        $(id).addEventListener('input', function () {
            if (id !== 'stock_in_quantity') priceTouched = true;
            updateStockInUI(false);
        });
    });
    $('stock_in_unit').addEventListener('change', function () { updateStockInUI(true); });

    $('stockInForm').addEventListener('submit', function (e) {
        var problems = [];
        var qtyRaw = $('stock_in_quantity').value.trim();
        var qty = Number(qtyRaw);
        var siKg = ['kg','kgs','kls','kilo','kilos','kilogram','kilograms'].indexOf(String($('stock_in_unit').value).toLowerCase().replace(/[\s.]+/g, '')) !== -1;
        var qtyBad = qtyRaw === '' || isNaN(qty) || qty <= 0 || (!siKg && !Number.isInteger(qty));
        var retailRaw = $('stock_in_retail_price').value.trim(), costRaw = $('stock_in_cost_price').value.trim();
        var retailBad = retailRaw === '' || isNaN(Number(retailRaw)) || Number(retailRaw) < 0;
        var costBad = costRaw === '' || isNaN(Number(costRaw)) || Number(costRaw) < 0;
        var exp = $('stock_in_expiration').value;
        var expBad = exp !== '' && exp < todayStr();
        var unitBad = !$('stock_in_unit').value;

        flag('stock_in_quantity', 'err_si_qty', qtyBad);
        flag('stock_in_unit', 'err_si_unit', unitBad);
        flag('stock_in_retail_price', 'err_si_retail', retailBad);
        flag('stock_in_cost_price', 'err_si_cost', costBad);
        flag('stock_in_expiration', 'err_si_exp', expBad);

        if (qtyBad) problems.push(siKg ? 'Quantity must be greater than 0.' : 'Quantity must be a whole number greater than 0.');
        if (unitBad) problems.push('Select a unit.');
        if (retailBad) problems.push('Retail price is required.');
        if (costBad) problems.push('Cost price is required.');
        if (expBad) problems.push('Expiration date cannot be in the past.');

        if (!problems.length && Number(retailRaw) < Number(costRaw) &&
            !window.confirm('The retail price is lower than the cost price, so you would sell at a loss. Continue anyway?')) {
            e.preventDefault();
            return;
        }
        showAlert('stockInAlert', problems);
        if (problems.length) { e.preventDefault(); return; }

        $('stockInSubmit').disabled = true;           // block double submits
        $('stockInSubmit').textContent = 'Saving…';
    });

    /* ───────────── Stock Out ───────────── */
    var so = null;
    function updateStockOutUI() {
        if (!so) return;
        var qty = parseFloat($('stock_out_quantity').value);
        qty = isNaN(qty) || qty < 0 ? 0 : qty;
        $('soAfter').textContent = String(Math.max(0, Math.round((so.qty - qty) * 1000) / 1000)) + ' ' + unitName(so.unit);
    }
    function openStockOut(btn) {
        var d = btn.dataset;
        so = { id: parseInt(d.id, 10), name: d.name, unit: d.unit, qty: parseFloat(d.qty) || 0 };
        $('stock_out_product_id').value = so.id;
        $('stock_out_product_name').value = so.name;
        $('stock_out_available').value = so.qty + ' ' + unitName(so.unit);
        $('stock_out_quantity').value = '';
        $('stock_out_quantity').max = so.qty;
        $('stock_out_reason').value = '';
        $('stock_out_remarks').value = '';
        showAlert('stockOutAlert', []);
        ['stock_out_quantity', 'stock_out_reason'].forEach(function (id) { $(id).classList.remove('has-error'); });
        document.querySelectorAll('#stockOutPopup .inv-error').forEach(function (e) { e.classList.remove('show'); });
        $('stockOutSubmit').disabled = so.qty <= 0;
        $('stockOutSubmit').textContent = 'Remove stock';
        updateStockOutUI();
        openModal('stockOutPopup', 'stock_out_quantity');
    }
    $('stock_out_quantity').addEventListener('input', updateStockOutUI);
    $('stockOutAll').addEventListener('click', function () {
        if (!so) return;
        $('stock_out_quantity').value = so.qty;
        updateStockOutUI();
    });
    $('stockOutForm').addEventListener('submit', function (e) {
        var problems = [];
        var qtyRaw = $('stock_out_quantity').value.trim(), qty = Number(qtyRaw);
        var soKg = ['kg','kgs','kls','kilo','kilos','kilogram','kilograms'].indexOf(String(so.unit).toLowerCase().replace(/[\s.]+/g, '')) !== -1;
        var qtyBad = qtyRaw === '' || isNaN(qty) || qty <= 0 || qty > so.qty + 0.0005 || (!soKg && !Number.isInteger(qty));
        var reasonBad = !$('stock_out_reason').value;
        flag('stock_out_quantity', 'err_so_qty', qtyBad);
        flag('stock_out_reason', 'err_so_reason', reasonBad);
        if (qtyBad) problems.push('Quantity must be between ' + (soKg ? '0.01' : '1') + ' and ' + so.qty + (soKg ? '.' : ' (whole number).'));
        if (reasonBad) problems.push('Choose a reason.');
        showAlert('stockOutAlert', problems);
        if (problems.length) { e.preventDefault(); return; }
        $('stockOutSubmit').disabled = true;
        $('stockOutSubmit').textContent = 'Saving…';
    });

    /* ───────────── Move Display / Warehouse ───────────── */
    function openTransfer(btn) {
        var d = btn.dataset;
        $('transfer_product_id').value = d.id;
        $('transfer_product_name').value = d.name + ' (' + unitName(d.unit) + ')';
        $('transfer_inventory_type').value = d.type === 'Display' ? 'Warehouse' : 'Display';
        openModal('transferTypePopup');
    }

    /* ───────────── Convert stock (manual) ───────────── */
    var cv = null;
    var cvFactorTouched = false, cvRememberTouched = false;
    var isKgUnit = function (u) {
        return ['kg','kgs','kls','kilo','kilos','kilogram','kilograms'].indexOf(String(u).toLowerCase().replace(/[\s.]+/g, '')) !== -1;
    };
    var num = function (n) { return String(Math.round(Number(n) * 1000) / 1000); };

    function cvTargetRow(unit) {
        var list = VARIANTS[cv.group] || [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].unit === unit && list[i].id !== cv.id) return list[i];
        }
        return null;
    }
    function cvSavedFactor(target) {
        var m = RULE_FACTORS[cv.id];
        return (m && target && m[target.id]) ? m[target.id] : null;
    }

    function openConvert(btn) {
        var d = btn.dataset;
        cv = { id: parseInt(d.id, 10), name: d.name, unit: d.unit, qty: Number(d.qty), group: d.group };
        cvFactorTouched = false; cvRememberTouched = false;

        $('cv_product_id').value = cv.id;
        $('cv_from_label').value = cv.name + ' (' + unitName(cv.unit) + ') — ' + num(cv.qty) + ' in stock';
        $('cv_factor_from').textContent = unitName(cv.unit);
        $('cv_qty').value = '';
        $('cv_qty').step = isKgUnit(cv.unit) ? '0.001' : '1';
        $('cv_avail').textContent = 'Available: ' + num(cv.qty) + ' ' + unitName(cv.unit);
        $('cv_factor').value = '';
        $('cv_new_price').value = '';

        // Unit choices: not the unit we are converting from; tag each as existing row / new row.
        var sel = $('cv_to_unit'), preferred = '', rulePick = '', firstFree = '';
        Array.prototype.forEach.call(sel.options, function (opt) {
            var isSource = opt.value === cv.unit;
            opt.disabled = isSource;
            if (isSource) { opt.textContent = opt.dataset.base; return; }
            var t = cvTargetRow(opt.value);
            opt.textContent = opt.dataset.base + (t ? '  ·  existing row (' + num(t.qty) + ' in stock)' : '  ·  new row');
            if (!firstFree) firstFree = opt.value;
            if (t && cvSavedFactor(t) && !rulePick) rulePick = opt.value;
            if (t && !preferred) preferred = opt.value;
        });
        if (!preferred && cv.unit === 'sack' && !sel.querySelector('option[value="kls"]').disabled) preferred = 'kls';
        sel.value = rulePick || preferred || firstFree;

        showAlert('convertStockAlert', []);
        document.querySelectorAll('#convertStockPopup .has-error').forEach(function (e) { e.classList.remove('has-error'); });
        document.querySelectorAll('#convertStockPopup .inv-error').forEach(function (e) { e.classList.remove('show'); });
        $('convertStockSubmit').disabled = false;
        $('convertStockSubmit').textContent = 'Convert stock';

        updateConvert(true);
        openModal('convertStockPopup', 'cv_qty');
    }

    function updateConvert(targetChanged) {
        if (!cv) return;
        var toUnit = $('cv_to_unit').value;
        var target = cvTargetRow(toUnit);
        var saved = cvSavedFactor(target);
        var toKg = isKgUnit(toUnit);

        $('cv_factor_to').textContent = unitName(toUnit);
        $('cv_price_unit').textContent = unitName(toUnit);
        $('cv_price_wrap').style.display = target ? 'none' : 'grid';
        $('cv_factor').step = toKg ? '0.001' : '1';

        if (targetChanged && !cvFactorTouched) { $('cv_factor').value = saved ? num(saved) : ''; }
        $('cv_factor_hint').textContent = saved
            ? 'Saved conversion: 1 ' + unitName(cv.unit) + ' = ' + num(saved) + ' ' + unitName(toUnit) + '. You can change it for this conversion.'
            : 'How many ' + unitName(toUnit) + ' come out of one ' + unitName(cv.unit) + '? (e.g. a 50 kg sack → 50)';

        var qty = Number($('cv_qty').value), factor = Number($('cv_factor').value);
        if (!cvRememberTouched) {
            $('cv_remember').checked = !saved || (factor > 0 && Math.abs(saved - factor) > 0.0005);
        }

        var box = $('cv_preview');
        if (!(qty > 0) || !(factor > 0)) { box.className = 'inv-preview'; box.innerHTML = ''; return; }

        var problem = '';
        var yieldQty = Math.round(qty * factor * 1000) / 1000;
        if (qty > cv.qty + 0.0005) problem = 'Only ' + num(cv.qty) + ' ' + unitName(cv.unit) + ' in stock.';
        else if (!isKgUnit(cv.unit) && !Number.isInteger(qty)) problem = 'Quantity must be a whole number of ' + unitName(cv.unit) + '.';
        else if (!toKg && Math.abs(factor - Math.round(factor)) > 0.0005) problem = unitName(toUnit) + ' can only be a whole number per ' + unitName(cv.unit) + '.';

        if (problem) { box.className = 'inv-preview show bad'; box.innerHTML = '<div class="inv-preview-main">' + esc(problem) + '</div>'; return; }

        var toBefore = target ? target.qty : 0;
        var costHint = '';
        box.className = 'inv-preview show';
        box.innerHTML =
            '<div class="inv-preview-main">' + esc(num(qty) + ' ' + unitName(cv.unit)) + ' → ' + esc(num(yieldQty) + ' ' + unitName(toUnit)) + '</div>' +
            '<table>' +
              '<tr><td>' + esc(cv.name) + ' (' + esc(unitName(cv.unit)) + ')</td><td>' + num(cv.qty) + ' → ' + num(cv.qty - qty) + '</td></tr>' +
              '<tr><td>' + esc(cv.name) + ' (' + esc(unitName(toUnit)) + ')' + (target ? '' : ' — new row') + '</td><td>' + num(toBefore) + ' → ' + num(toBefore + yieldQty) + '</td></tr>' +
            '</table>' + costHint;
    }

    $('cv_to_unit').addEventListener('change', function () { updateConvert(true); });
    $('cv_qty').addEventListener('input', function () { updateConvert(false); });
    $('cv_factor').addEventListener('input', function () { cvFactorTouched = true; updateConvert(false); });
    $('cv_remember').addEventListener('change', function () { cvRememberTouched = true; });
    $('cv_all').addEventListener('click', function () { $('cv_qty').value = num(cv.qty); updateConvert(false); $('cv_qty').focus(); });

    $('convertStockForm').addEventListener('submit', function (e) {
        var problems = [];
        var qty = Number($('cv_qty').value), factor = Number($('cv_factor').value);
        var toUnit = $('cv_to_unit').value, target = cvTargetRow(toUnit);
        var srcKg = isKgUnit(cv.unit), toKg = isKgUnit(toUnit);

        var qtyBad = !($('cv_qty').value.trim() !== '' && qty > 0) || qty > cv.qty + 0.0005 || (!srcKg && !Number.isInteger(qty));
        var factorBad = !($('cv_factor').value.trim() !== '' && factor > 0) || (!toKg && Math.abs(factor - Math.round(factor)) > 0.0005);
        var priceBad = !target && !(Number($('cv_new_price').value) > 0);

        flag('cv_qty', 'err_cv_qty', qtyBad);
        flag('cv_factor', 'err_cv_factor', factorBad);
        flag('cv_new_price', 'err_cv_price', priceBad);

        if (qtyBad) problems.push('Quantity must be between ' + (srcKg ? '0.001' : '1') + ' and ' + num(cv.qty) + (srcKg ? '.' : ' (whole number).'));
        if (factorBad) problems.push(toKg ? 'Enter a conversion factor greater than 0.' : 'Conversion factor must be a whole number for ' + unitName(toUnit) + '.');
        if (priceBad) problems.push('Enter the selling price for the new ' + unitName(toUnit) + ' row.');

        showAlert('convertStockAlert', problems);
        if (problems.length) { e.preventDefault(); return; }
        $('convertStockSubmit').disabled = true;
        $('convertStockSubmit').textContent = 'Converting…';
    });

    /* ───────────── Conversion rule (saved factor + auto-convert) ───────────── */
    var convRow = null;
    function convRefresh() {
        if (!convRow) return;
        var fromSel = $('conversion_from_id');
        var fromOpt = fromSel.options[fromSel.selectedIndex];
        var fromUnit = (fromOpt && fromOpt.value) ? fromOpt.dataset.unit : '';
        var toU = unitName(convRow.unit);
        var per = Number($('conversion_units_per').value), ratio = Number($('conversion_ratio').value);
        var thr = $('conversion_threshold').value;
        var auto = $('conversion_auto_enabled').checked;

        $('conv_from_lbl').textContent = fromUnit || 'bulk units';
        $('conv_to_lbl').textContent = toU;
        $('conv_thr_lbl').textContent = toU;
        $('conv_threshold_wrap').classList.toggle('inv-dim', !auto);
        $('conversion_threshold').disabled = !auto;

        var box = $('conv_summary');
        if (fromUnit && per > 0 && ratio > 0) {
            var each = Math.round(ratio / per * 1000) / 1000;
            var txt = '<strong>1 ' + esc(fromUnit) + ' = ' + num(each) + ' ' + esc(toU) + '</strong>';
            if (per !== 1) txt += ' (' + num(per) + ' ' + esc(fromUnit) + ' → ' + num(ratio) + ' ' + esc(toU) + ' per conversion)';
            txt += auto
                ? '<br>When ' + esc(convRow.name) + ' (' + esc(toU) + ') falls to ' + (thr === '' ? '…' : esc(thr)) + ' ' + esc(toU) + ' or below, ' + num(per) + ' ' + esc(fromUnit) + ' is opened automatically.'
                : '<br>Used as the default factor when you convert stock manually. Auto-restock is off.';
            box.innerHTML = txt; box.classList.add('show');
        } else {
            box.classList.remove('show'); box.innerHTML = '';
        }
    }

    function openUnitConversion(btn) {
        var d = btn.dataset;
        convRow = { id: d.id, name: d.name, unit: d.unit };
        $('conv_product_id').value = d.id;
        $('conv_product_name').value = d.name + ' (' + unitName(d.unit) + ')';

        // Only other units of the SAME product can be a source.
        var fromSelect = $('conversion_from_id'), eligible = 0;
        Array.prototype.forEach.call(fromSelect.options, function (opt) {
            if (!opt.value) return;
            var ok = opt.dataset.group === d.group && opt.value !== d.id;
            opt.hidden = !ok; opt.disabled = !ok;
            if (ok) eligible++;
        });
        $('conv_from_note').textContent = eligible
            ? ''
            : 'No other unit of this product exists yet. Use “Convert Stock…” on the bulk row (e.g. sack) — it creates this unit automatically.';

        fromSelect.value = d.convFrom || '';
        if (!fromSelect.value && eligible === 1) {
            Array.prototype.some.call(fromSelect.options, function (opt) { if (opt.value && !opt.disabled) { fromSelect.value = opt.value; return true; } });
        }
        $('conversion_units_per').value = d.convUnits || '1';
        $('conversion_ratio').value = d.convRatio || '';
        $('conversion_threshold').value = d.convThreshold || '';
        $('conversion_auto_enabled').checked = d.convEnabled === '1';

        $('unitConversionRemove').style.display = d.convFrom ? 'inline-flex' : 'none';

        showAlert('unitConversionAlert', []);
        ['conversion_from_id', 'conversion_ratio'].forEach(function (id) { $(id).classList.remove('has-error'); });
        document.querySelectorAll('#unitConversionPopup .inv-error').forEach(function (e) { e.classList.remove('show'); });

        convRefresh();
        openModal('unitConversionPopup', 'conversion_from_id');
    }
    ['conversion_from_id', 'conversion_units_per', 'conversion_ratio', 'conversion_threshold', 'conversion_auto_enabled'].forEach(function (id) {
        $(id).addEventListener(id === 'conversion_from_id' || id === 'conversion_auto_enabled' ? 'change' : 'input', convRefresh);
    });

    $('unitConversionForm').addEventListener('submit', function (e) {
        var problems = [];
        var fromBad = !$('conversion_from_id').value;
        var ratioRaw = $('conversion_ratio').value.trim();
        var ratioBad = ratioRaw === '' || isNaN(Number(ratioRaw)) || Number(ratioRaw) <= 0;

        flag('conversion_from_id', 'err_conv_from', fromBad);
        flag('conversion_ratio', 'err_conv_ratio', ratioBad);

        if (fromBad) problems.push('Choose which unit to convert stock from.');
        if (ratioBad) problems.push('Enter how much one conversion produces.');

        if (problems.length) { e.preventDefault(); showAlert('unitConversionAlert', problems); return; }
        showAlert('unitConversionAlert', []);
    });

    $('unitConversionRemove').addEventListener('click', function () {
        if (!window.confirm('Remove this conversion rule? Auto-restock will stop for this product. Existing stock is not affected.')) return;
        var form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        [['delete_conversion', '1'], ['product_id', $('conv_product_id').value], ['csrf_token', CSRF]].forEach(function (p) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = p[0]; input.value = p[1];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    });

    /* ───────────── Batch details ───────────── */
    window.closeBatchDetails = function () { $('batchDetailsPopup').style.display = 'none'; };

    function openBatchDetails(btn) {
        var d = btn.dataset;
        $('batchProductLabel').textContent = d.name + ' · ' + unitName(d.unit);
        fetch('?action=get_batches&product_id=' + encodeURIComponent(d.id), { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (batches) {
                populateBatchTable(batches);
                $('batchDetailsPopup').style.display = 'flex';
            })
            .catch(function () { window.alert('Could not load batch details. Please try again.'); });
    }

    function populateBatchTable(batches) {
        var tbody = $('batchTableBody');
        tbody.innerHTML = '';
        if (!batches.length) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#6b7280;padding:18px;">No batches recorded for this product yet.</td></tr>';
            return;
        }
        var today = new Date(); today.setHours(0, 0, 0, 0);

        batches.forEach(function (b) {
            var exp = b.expiration_date ? new Date(b.expiration_date + 'T00:00:00') : null;
            var expired = exp !== null && exp < today;
            var days = exp ? Math.round((exp - today) / 86400000) : null;
            var cls, text;

            if (b.quantity <= 0)        { cls = 'status-archived';       text = 'Depleted'; }
            else if (!exp)              { cls = 'status-valid';          text = 'No expiry'; }
            else if (expired)           { cls = 'status-expired';        text = 'EXPIRED'; }
            else if (days <= 7)         { cls = 'status-expiring-soon';  text = 'Expiring soon (' + days + ' day' + (days === 1 ? '' : 's') + ')'; }
            else                        { cls = 'status-valid';          text = 'Valid (' + days + ' days)'; }

            var action = (b.quantity > 0 && expired)
                ? '<button type="button" class="action-btn action-btn-small" data-archive-batch="' + b.id + '">Archive</button>'
                : '<span style="color:#999;">-</span>';

            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + esc(b.batch_reference) + '</td>' +
                '<td>' + esc(b.quantity) + '</td>' +
                '<td>' + peso(b.cost_price) + '</td>' +
                '<td>' + esc(b.created_at) + '</td>' +
                '<td>' + (b.expiration_date ? esc(b.expiration_date) : 'N/A') + '</td>' +
                '<td><span class="' + cls + '">' + esc(text) + '</span></td>' +
                '<td>' + action + '</td>';
            if (expired && b.quantity > 0) tr.style.backgroundColor = 'rgba(255, 100, 100, 0.1)';
            tbody.appendChild(tr);
        });
    }

    document.addEventListener('click', function (e) {
        var arch = e.target.closest('[data-archive-batch]');
        if (!arch) return;
        if (!window.confirm('Archive this expired batch? The remaining quantity will be recorded as a stock out.')) return;
        var form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        [['archive_batch', '1'], ['batch_id', arch.getAttribute('data-archive-batch')], ['csrf_token', CSRF]].forEach(function (p) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = p[0]; input.value = p[1];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    });

    /* ───────────── Row action menu ───────────── */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-inv-action]');
        if (!btn) return;
        document.querySelectorAll('.row-menu.open').forEach(function (m) { m.classList.remove('open'); });
        switch (btn.getAttribute('data-inv-action')) {
            case 'stock-in':  openStockIn(btn); break;
            case 'stock-out': openStockOut(btn); break;
            case 'batches':   openBatchDetails(btn); break;
            case 'transfer':  openTransfer(btn); break;
            case 'conversion': openUnitConversion(btn); break;
            case 'convert':   openConvert(btn); break;
        }
    });

    /* ───────────── Combined search + filters ───────────── */
    window.applyInventoryFilters = function () {
        var q = $('searchInput').value.trim().toLowerCase();
        var cat = $('categoryFilter').value, sup = $('supplierFilter').value;
        var type = $('inventoryTypeFilter').value, level = $('stockStatusFilter').value;
        var shown = 0;

        document.querySelectorAll('#inventoryTable tbody tr[data-name]').forEach(function (row) {
            var ok = (!q || row.dataset.name.indexOf(q) > -1) &&
                     (!cat || row.dataset.category === cat) &&
                     (!sup || row.dataset.supplier === sup) &&
                     (!type || row.dataset.type === type) &&
                     (!level || row.dataset.level === level);
            row.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        var empty = $('invNoResults');
        if (empty) empty.style.display = shown ? 'none' : '';
    };
})();
</script>
<?php
$extra_js = ob_get_clean();
require_once __DIR__ . '/../includes/footer.php';
$conn->close();