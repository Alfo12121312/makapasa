<?php

function po_payment_ensure_schema(mysqli $conn) {
    $receiptTable = "CREATE TABLE IF NOT EXISTS purchase_order_receipts (
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
        delivery_reference CHAR(32) NULL,
        KEY idx_po_receipts_po (po_id),
        KEY idx_po_receipts_item (item_id),
        KEY idx_po_receipts_delivery (delivery_reference)
    )";
    if (!$conn->query($receiptTable)) {
        throw new RuntimeException('Could not prepare purchase delivery records: ' . $conn->error);
    }

    $receiptColumn = $conn->query("SHOW COLUMNS FROM purchase_order_receipts LIKE 'delivery_reference'");
    if (!$receiptColumn) {
        throw new RuntimeException('Could not check purchase delivery records: ' . $conn->error);
    }
    if ($receiptColumn->num_rows === 0
        && !$conn->query("ALTER TABLE purchase_order_receipts ADD COLUMN delivery_reference CHAR(32) NULL AFTER received_at, ADD KEY idx_po_receipts_delivery (delivery_reference)")) {
        throw new RuntimeException('Could not update purchase delivery records: ' . $conn->error);
    }

    foreach ([
        'payer_type' => "VARCHAR(10) NOT NULL DEFAULT 'owner'",
        'payer_cashier_id' => 'INT NULL',
    ] as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE '{$column}'");
        if (!$check) {
            throw new RuntimeException('Could not check purchase order payment assignment: ' . $conn->error);
        }
        if ($check->num_rows === 0
            && !$conn->query("ALTER TABLE purchase_orders ADD COLUMN {$column} {$definition}")) {
            throw new RuntimeException('Could not update purchase order payment assignment: ' . $conn->error);
        }
    }

    $paymentTable = "CREATE TABLE IF NOT EXISTS purchase_order_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        delivery_reference CHAR(32) NOT NULL,
        po_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        paid_by_type ENUM('cashier','owner') NOT NULL,
        paid_by INT NOT NULL,
        payment_source VARCHAR(50) NOT NULL,
        payment_reference VARCHAR(120) NULL,
        paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_po_delivery_payment (delivery_reference),
        KEY idx_po_payment_order (po_id),
        KEY idx_po_payment_paid_at (paid_at)
    )";
    if (!$conn->query($paymentTable)) {
        throw new RuntimeException('Could not prepare supplier payment records: ' . $conn->error);
    }
}

function po_active_cashiers(mysqli $conn) {
    $result = $conn->query("SELECT id, username FROM users WHERE role = 'Cashier' AND status = 'Active' ORDER BY username");
    if (!$result) {
        throw new RuntimeException('Could not load active cashiers: ' . $conn->error);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

function po_pending_delivery_payments(mysqli $conn, string $payerType, int $payerId) {
    $stmt = $conn->prepare("SELECT r.delivery_reference, po.id AS po_id, po.supplier,
                                   MIN(r.received_at) AS received_at,
                                   ROUND(SUM(r.quantity * r.unit_cost), 2) AS amount,
                                   COUNT(*) AS line_count
                            FROM purchase_order_receipts r
                            INNER JOIN purchase_orders po ON po.id = r.po_id
                            LEFT JOIN purchase_order_payments p ON p.delivery_reference = r.delivery_reference
                            WHERE r.delivery_reference IS NOT NULL AND p.id IS NULL
                              AND po.payer_type = ?
                              AND ((? = 'cashier' AND po.payer_cashier_id = ?) OR ? = 'owner')
                            GROUP BY r.delivery_reference, po.id, po.supplier
                            ORDER BY received_at ASC, po.id ASC");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare supplier payment list: ' . $conn->error);
    }
    $stmt->bind_param('siis', $payerType, $payerId, $payerId, $payerType);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not load supplier payment list: ' . $error);
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function po_record_delivery_payment(
    mysqli $conn,
    string $deliveryReference,
    string $payerType,
    int $userId,
    string $paymentSource,
    string $paymentReference
) {
    if (!preg_match('/^[a-f0-9]{32}$/', $deliveryReference)) {
        throw new DomainException('Choose a valid received delivery.');
    }
    $expectedSource = $payerType === 'cashier' ? 'Cashier drawer' : 'Owner personal funds';
    if (!in_array($payerType, ['cashier', 'owner'], true) || $paymentSource !== $expectedSource) {
        throw new DomainException('This supplier payment type is not valid.');
    }

    $transactionStarted = false;
    try {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Could not start supplier payment transaction.');
        }
        $transactionStarted = true;
        $stmt = $conn->prepare("SELECT po.id, po.supplier, po.payer_type, po.payer_cashier_id
                                FROM purchase_order_receipts r
                                INNER JOIN purchase_orders po ON po.id = r.po_id
                                WHERE r.delivery_reference = ?
                                LIMIT 1 FOR UPDATE");
        if (!$stmt) {
            throw new RuntimeException('Could not prepare delivery validation.');
        }
        $stmt->bind_param('s', $deliveryReference);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not validate received delivery.');
        }
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order || $order['payer_type'] !== $payerType
            || ($payerType === 'cashier' && (int)$order['payer_cashier_id'] !== $userId)) {
            throw new DomainException('This delivery is not assigned to you or is no longer available for payment.');
        }

        $stmt = $conn->prepare("SELECT id, quantity, unit_cost
                                FROM purchase_order_receipts
                                WHERE delivery_reference = ?
                                ORDER BY id FOR UPDATE");
        if (!$stmt) {
            throw new RuntimeException('Could not prepare delivery amount lookup.');
        }
        $stmt->bind_param('s', $deliveryReference);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not calculate delivery amount.');
        }
        $receiptRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$receiptRows) {
            throw new DomainException('This delivery has no received items to pay for.');
        }
        $amount = 0.0;
        foreach ($receiptRows as $receiptRow) {
            $amount += (float)$receiptRow['quantity'] * (float)$receiptRow['unit_cost'];
        }
        $amount = round($amount, 2);
        if ($amount < 0) {
            throw new RuntimeException('Received delivery amount cannot be negative.');
        }

        $sessionId = 0;
        if ($payerType === 'cashier' && $amount > 0) {
            $stmt = $conn->prepare("SELECT id, opening_cash, cash_in, cash_out FROM cashier_sessions
                                    WHERE cashier_id = ? AND status = 'Open'
                                    ORDER BY started_at DESC, id DESC
                                    LIMIT 1 FOR UPDATE");
            if (!$stmt) {
                throw new RuntimeException('Could not prepare cashier shift validation.');
            }
            $stmt->bind_param('i', $userId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not validate the open cashier shift.');
            }
            $session = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$session) {
                throw new DomainException('Start an open shift before paying from the cashier drawer.');
            }
            $sessionId = (int)$session['id'];
            $salesStmt = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) AS cash_sales
                                         FROM sales
                                         WHERE shift_id = ? AND payment_method = 'Cash'");
            if (!$salesStmt) {
                throw new RuntimeException('Could not prepare cashier drawer balance check.');
            }
            $salesStmt->bind_param('i', $sessionId);
            if (!$salesStmt->execute()) {
                throw new RuntimeException('Could not check cashier drawer balance.');
            }
            $cashSales = (float)$salesStmt->get_result()->fetch_assoc()['cash_sales'];
            $salesStmt->close();
            $availableCash = (float)$session['opening_cash'] + (float)$session['cash_in']
                + $cashSales - (float)$session['cash_out'];
            if ($amount > $availableCash + 0.0001) {
                throw new DomainException('The drawer does not have enough recorded cash for this payment. The delivery remains unpaid.');
            }
            $cashOut = round((float)$session['cash_out'] + $amount, 2);
        }

        $paidByType = $payerType;
        $source = $amount === 0.0 ? 'No charge' : $paymentSource;
        $note = trim($paymentReference);
        $noteValue = $note === '' ? null : $note;
        $stmt = $conn->prepare("INSERT INTO purchase_order_payments
                                (delivery_reference, po_id, amount, paid_by_type, paid_by, payment_source, payment_reference)
                                VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new RuntimeException('Could not prepare supplier payment record.');
        }
        $poId = (int)$order['id'];
        $stmt->bind_param('sidsiss', $deliveryReference, $poId, $amount, $paidByType, $userId, $source, $noteValue);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $errorCode = $stmt->errno;
            $stmt->close();
            if ($errorCode === 1062 || stripos($error, 'Duplicate') !== false) {
                throw new DomainException('This delivery has already been paid.');
            }
            throw new RuntimeException('Could not save supplier payment: ' . $error);
        }
        $stmt->close();

        if ($payerType === 'cashier' && $amount > 0) {
            $reason = 'Supplier payment for PO-' . str_pad((string)$poId, 4, '0', STR_PAD_LEFT)
                . ' · ' . (string)$order['supplier'];
            $stmt = $conn->prepare("INSERT INTO cashier_session_movements
                                    (session_id, cashier_id, movement_type, amount, reason)
                                    VALUES (?, ?, 'out', ?, ?)");
            if (!$stmt) {
                throw new RuntimeException('Could not prepare cashier cash-out record.');
            }
            $stmt->bind_param('iids', $sessionId, $userId, $amount, $reason);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not save cashier cash-out record.');
            }
            $stmt->close();

            $stmt = $conn->prepare("UPDATE cashier_sessions SET cash_out = ? WHERE id = ? AND status = 'Open'");
            if (!$stmt) {
                throw new RuntimeException('Could not prepare cashier shift update.');
            }
            $stmt->bind_param('di', $cashOut, $sessionId);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                throw new RuntimeException('Could not update cashier shift cash-out total.');
            }
            $stmt->close();
        }

        if (!$conn->commit()) {
            throw new RuntimeException('Could not commit supplier payment.');
        }
        return ['amount' => $amount, 'po_id' => $poId, 'supplier' => (string)$order['supplier']];
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        throw $e;
    }
}
