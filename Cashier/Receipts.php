<?php
/*
 * File: Cashier/Receipts.php
 * Purpose: Receipt lookup and reprint for the current cashier — ONE receipt per transaction (per customer),
 *          covering every item bought in that checkout, with a single total and a single payment line.
 * Key locations:
 * - Every cart line is stored as its own `sales` row; all rows from one checkout share a `sale_reference`.
 *   Receipts are therefore grouped by `sale_reference` (old rows with no reference stay as their own receipt).
 * - Protects access via `require_roles(['Cashier'])`; a cashier can only open their own transactions.
 * - `?ref=...` opens a receipt, `&print=1` opens it and goes straight to the print dialog (used by the POS
 *   "Print receipt" button after a sale). The old `?sale_id=` links still work (resolved to their transaction).
 * Notes:
 * - amount_received / change_amount are saved on every row of the checkout (the whole payment, not a share
 *   of it), so they are read once here — never summed.
 */
require_once __DIR__ . "/../includes/app.php";
require_roles(['Cashier'], '../Login.php');

// Load receipts belonging to the signed-in cashier
$conn = app_connect();
$cashier_id = auth_user_id();

// One entry per transaction (customer), newest first.
$listStmt = $conn->prepare("SELECT COALESCE(NULLIF(s.sale_reference, ''), CONCAT('LEGACY-', s.id)) AS receipt_ref,
                                   MAX(s.created_at) AS created_at,
                                   COUNT(*) AS line_count,
                                   SUM(s.total_price) AS total_price,
                                   MAX(s.payment_method) AS payment_method
                            FROM sales s
                            WHERE s.cashier_id = ?
                            GROUP BY receipt_ref
                            ORDER BY MAX(s.created_at) DESC, MAX(s.id) DESC
                            LIMIT 200");
$listStmt->bind_param("i", $cashier_id);
$listStmt->execute();
$list = $listStmt->get_result();
$listStmt->close();

function payment_badge_class($method) {
    if ($method === 'Cash') return 'pay-badge';
    if ($method === 'Card' || $method === 'Bank Transfer') return 'pay-badge is-card';
    return 'pay-badge is-digital';
}

// Which transaction was asked for? (?ref=... — or an old ?sale_id=... link)
$ref = isset($_GET['ref']) ? trim((string)$_GET['ref']) : '';
if ($ref === '' && isset($_GET['sale_id']) && (int)$_GET['sale_id'] > 0) {
    $oldStmt = $conn->prepare("SELECT COALESCE(NULLIF(sale_reference, ''), CONCAT('LEGACY-', id)) AS receipt_ref
                               FROM sales WHERE id = ? AND cashier_id = ?");
    $oldId = (int)$_GET['sale_id'];
    $oldStmt->bind_param("ii", $oldId, $cashier_id);
    $oldStmt->execute();
    $oldRow = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();
    $ref = $oldRow ? (string)$oldRow['receipt_ref'] : '';
}

$lines = [];
$receipt = null;   // header details, taken from the first line of the transaction
$grandTotal = 0.0;
$discountTotal = 0.0;
// Load the requested receipt, when one was selected
if ($ref !== '') {
    $isLegacy = strpos($ref, 'LEGACY-') === 0;
    $sql = "SELECT s.id, s.created_at, s.quantity, s.unit_price, s.discount, s.total_price, s.product_unit,
                   s.sale_reference, s.payment_method, s.payment_reference, s.amount_received, s.change_amount,
                   i.product_name, u.username AS cashier_name
            FROM sales s
            JOIN inventory i ON i.id = s.product_id
            LEFT JOIN users u ON u.id = s.cashier_id
            WHERE s.cashier_id = ? AND " . ($isLegacy ? "s.id = ?" : "s.sale_reference = ?") . "
            ORDER BY s.id ASC";
    $stmt = $conn->prepare($sql);
    if ($isLegacy) {
        $legacyId = (int)substr($ref, 7);
        $stmt->bind_param("ii", $cashier_id, $legacyId);
    } else {
        $stmt->bind_param("is", $cashier_id, $ref);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $lines[] = $row;
        $grandTotal += (float)$row['total_price'];
        $discountTotal += round((float)$row['discount'] * (float)$row['quantity'], 2);
    }
    $stmt->close();
    if ($lines) {
        $receipt = $lines[0];
    }
}
$grandTotal = round($grandTotal, 2);
$discountTotal = round($discountTotal, 2);

$extraJs = '';
if ($receipt && !empty($_GET['print'])) {
    // Opened from the POS "Print receipt" button: go straight to the print dialog.
    $extraJs = "<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>\n";
}

// Render receipt selection and details
render_app_open([
    'context' => 'cashier',
    'active' => 'Receipts.php',
    'role_title' => 'Cashier',
    'title' => 'Receipt',
    'breadcrumb' => ['Sales', 'Receipts'],
    'body_class' => 'receipt-page',
]);
?>
    <header class="receipt-page-heading">
        <div>
            <span class="receipt-page-kicker">SALES DOCUMENTS</span>
            <h1>Receipt</h1>
            <p>Review a completed transaction and prepare a clean copy for your customer.</p>
        </div>
        <span class="receipt-page-count"><?php echo $list ? number_format($list->num_rows) : '0'; ?> recent transactions</span>
    </header>
    <div class="report-filters receipt-toolbar">
        <form method="get" class="receipt-lookup-form">
            <label for="receipt-transaction">Select a transaction</label>
            <select id="receipt-transaction" name="ref" required>
                <option value="">Select Transaction</option>
                <?php if ($list && $list->num_rows > 0): while($row = $list->fetch_assoc()): ?>
                    <?php $items = (int)$row['line_count']; ?>
                    <option value="<?php echo htmlspecialchars($row['receipt_ref']); ?>" <?php echo $ref === $row['receipt_ref'] ? 'selected' : ''; ?>>
                        <?php echo date('M d h:i A', strtotime($row['created_at'])); ?> - <?php echo $items; ?> item<?php echo $items === 1 ? '' : 's'; ?> - PHP <?php echo number_format((float)$row['total_price'], 2); ?> - <?php echo htmlspecialchars($row['payment_method'] ?: 'Cash'); ?> - <?php echo htmlspecialchars($row['receipt_ref']); ?>
                    </option>
                <?php endwhile; endif; ?>
            </select>
            <button type="submit" class="filter-btn">Load Receipt</button>
        </form>
        <?php if ($receipt): ?>
            <button type="button" class="print-btn receipt-print-action" onclick="window.print()" aria-label="Print receipt">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/>
                    <path d="M7 14h10v7H7zM17 11h.01"/>
                </svg>
                <span>Print receipt</span>
            </button>
        <?php endif; ?>
    </div>

    <?php if ($ref !== '' && !$receipt): ?>
        <div class="message error">That transaction wasn't found.</div>
    <?php endif; ?>

    <?php if ($receipt): ?>
    <?php $receiptMethod = $receipt['payment_method'] ?: 'Cash'; ?>
    <div class="table-section receipt-sheet">
        <div class="receipt-print-title">Sales Receipt</div>
        <header class="receipt-head">
            <div class="receipt-brand">
                <strong class="receipt-store"><?php echo htmlspecialchars(app_name($conn)); ?></strong>
                <span class="receipt-document-type">Thank you for shopping with us</span>
            </div>
            <div class="receipt-number">
                <span>Receipt no.</span>
                <strong><?php echo htmlspecialchars(strpos($ref, 'LEGACY-') === 0 ? '#' . (int)substr($ref, 7) : $ref); ?></strong>
            </div>
        </header>
        <section class="receipt-meta" aria-label="Transaction details">
            <div class="receipt-detail">
                <span>Date and time</span>
                <strong><?php echo date('M d, Y h:i A', strtotime($receipt['created_at'])); ?></strong>
            </div>
            <div class="receipt-detail">
                <span>Served by</span>
                <strong><?php echo htmlspecialchars($receipt['cashier_name'] ?: 'Cashier'); ?></strong>
            </div>
            <div class="receipt-detail">
                <span>Items</span>
                <strong><?php echo count($lines); ?></strong>
            </div>
            <div class="receipt-detail">
                <span>Payment method</span>
                <strong class="pay-method">
                    <?php $logoFile = ['GCash' => 'gcash.png', 'Maya' => 'maya.png'][$receiptMethod] ?? null; ?>
                    <?php if ($logoFile): ?>
                        <img class="pay-logo" src="../assets/payments/<?php echo $logoFile; ?>" alt="">
                    <?php endif; ?>
                    <span class="<?php echo payment_badge_class($receiptMethod); ?>"><?php echo htmlspecialchars($receiptMethod); ?></span>
                </strong>
            </div>
            <?php if (!empty($receipt['payment_reference'])): ?>
                <div class="receipt-detail receipt-payment-reference">
                    <span>Payment reference</span>
                    <strong><?php echo htmlspecialchars($receipt['payment_reference']); ?></strong>
                </div>
            <?php endif; ?>
        </section>
        <div class="user-table-wrapper">
            <table class="userTable receipt-items-table">
                <thead><tr><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Unit</th><th scope="col">Price</th><th scope="col">Discount</th><th scope="col">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($line['product_name']); ?></td>
                        <td><?php echo fmt_qty($line['quantity']); ?></td>
                        <td><?php echo htmlspecialchars(unit_label($line['product_unit'])); ?></td>
                        <td>PHP <?php echo number_format((float)$line['unit_price'], 2); ?></td>
                        <td>PHP <?php echo number_format(round((float)$line['discount'] * (float)$line['quantity'], 2), 2); ?></td>
                        <td>PHP <?php echo number_format((float)$line['total_price'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <table class="receipt-print-table">
            <thead>
                <tr>
                    <th scope="col">Item description</th>
                    <th scope="col">Quantity</th>
                    <th scope="col">Unit price</th>
                    <th scope="col">Line total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($line['product_name']); ?></td>
                        <td><?php echo fmt_qty($line['quantity']) . ' ' . htmlspecialchars(unit_label($line['product_unit'])); ?></td>
                        <td>PHP <?php echo number_format((float)$line['unit_price'], 2); ?></td>
                        <td>PHP <?php echo number_format((float)$line['total_price'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="receipt-print-lines" aria-label="Purchased items">
            <?php foreach ($lines as $line): ?>
                <div class="receipt-print-item">
                    <strong><?php echo htmlspecialchars($line['product_name']); ?></strong>
                    <div>
                        <span><?php echo fmt_qty($line['quantity']); ?> <?php echo htmlspecialchars(unit_label($line['product_unit'])); ?> × PHP <?php echo number_format((float)$line['unit_price'], 2); ?></span>
                        <strong>PHP <?php echo number_format((float)$line['total_price'], 2); ?></strong>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <table class="receipt-totals">
            <tr><td>Subtotal</td><td>PHP <?php echo number_format($grandTotal + $discountTotal, 2); ?></td></tr>
            <?php if ($discountTotal > 0): ?>
                <tr><td>Discount</td><td>- PHP <?php echo number_format($discountTotal, 2); ?></td></tr>
            <?php endif; ?>
            <tr class="receipt-grand"><td>Total</td><td>PHP <?php echo number_format($grandTotal, 2); ?></td></tr>
            <?php if ($receipt['amount_received'] !== null): ?>
                <tr><td>Amount received</td><td>PHP <?php echo number_format((float)$receipt['amount_received'], 2); ?></td></tr>
                <tr><td>Change</td><td>PHP <?php echo number_format((float)$receipt['change_amount'], 2); ?></td></tr>
            <?php endif; ?>
        </table>
        <footer class="receipt-thanks">
            <strong>Thank you for your purchase!</strong>
            <span>We appreciate your business.</span>
        </footer>
    </div>
    <?php
        // ---- values used only by the print-only slip below ----
        $receiptMethod = $receipt['payment_method'] ?: 'Cash';
        $receiptNo     = strpos($ref, 'LEGACY-') === 0 ? '#' . (int)substr($ref, 7) : $ref;
        $storeAddress  = trim((string)app_setting($conn, 'store_address', ''));
        $grossTotal    = round($grandTotal + $discountTotal, 2);
        $tendered      = $receipt['amount_received'] !== null ? (float)$receipt['amount_received'] : null;
        $tenderLabel   = strtoupper($receiptMethod);

        // Code 39 barcode (inline SVG) so the receipt number can be scanned at returns.
        if (!function_exists('rc_code39_svg')) {
            function rc_code39_svg($text) {
                static $map = [
                    '0'=>'nnnwwnwnn','1'=>'wnnwnnnnw','2'=>'nnwwnnnnw','3'=>'wnwwnnnnn','4'=>'nnnwwnnnw',
                    '5'=>'wnnwwnnnn','6'=>'nnwwwnnnn','7'=>'nnnwnnwnw','8'=>'wnnwnnwnn','9'=>'nnwwnnwnn',
                    'A'=>'wnnnnwnnw','B'=>'nnwnnwnnw','C'=>'wnwnnwnnn','D'=>'nnnnwwnnw','E'=>'wnnnwwnnn',
                    'F'=>'nnwnwwnnn','G'=>'nnnnnwwnw','H'=>'wnnnnwwnn','I'=>'nnwnnwwnn','J'=>'nnnnwwwnn',
                    'K'=>'wnnnnnnww','L'=>'nnwnnnnww','M'=>'wnwnnnnwn','N'=>'nnnnwnnww','O'=>'wnnnwnnwn',
                    'P'=>'nnwnwnnwn','Q'=>'nnnnnnwww','R'=>'wnnnnnwwn','S'=>'nnwnnnwwn','T'=>'nnnnwnwwn',
                    'U'=>'wwnnnnnnw','V'=>'nwwnnnnnw','W'=>'wwwnnnnnn','X'=>'nwnnwnnnw','Y'=>'wwnnwnnnn',
                    'Z'=>'nwwnwnnnn','-'=>'nwnnnnwnw','.'=>'wwnnnnwnn',' '=>'nwwnnnwnn','*'=>'nwnnwnwnn',
                ];
                $text = preg_replace('/[^A-Z0-9\-\. ]/', '', strtoupper((string)$text));
                if ($text === '') {
                    return '';
                }
                $x = 0;
                $bars = '';
                foreach (str_split('*' . $text . '*') as $ch) {
                    $pattern = $map[$ch];
                    for ($i = 0; $i < 9; $i++) {
                        $w = $pattern[$i] === 'w' ? 3 : 1;
                        if ($i % 2 === 0) {
                            $bars .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="30"/>';
                        }
                        $x += $w;
                    }
                    $x += 1; // gap between characters
                }
                $x -= 1;
                return '<svg class="rc-barcode" viewBox="0 0 ' . $x . ' 30" preserveAspectRatio="none" aria-hidden="true">' . $bars . '</svg>';
            }
        }
    ?>
    <!-- Print-only supermarket-style slip (80 mm). Hidden on screen; shown only when printing. -->
    <div class="receipt-slip" role="document" aria-label="Printed sales receipt">
        <header class="rc-center">
            <img class="rc-logo" src="../assets/logo.png" alt="">
            <div class="rc-store"><?php echo htmlspecialchars(app_name($conn)); ?></div>
            <?php if ($storeAddress !== ''): ?>
                <div class="rc-sub"><?php echo nl2br(htmlspecialchars($storeAddress)); ?></div>
            <?php endif; ?>
            <div class="rc-title">Sales Receipt</div>
        </header>

        <section class="rc-meta" aria-label="Transaction details">
            <div class="rc-kv"><span>Receipt No.</span><span><?php echo htmlspecialchars($receiptNo); ?></span></div>
            <div class="rc-kv"><span>Date</span><span><?php echo date('M d, Y', strtotime($receipt['created_at'])); ?></span></div>
            <div class="rc-kv"><span>Time</span><span><?php echo date('h:i A', strtotime($receipt['created_at'])); ?></span></div>
            <div class="rc-kv"><span>Cashier</span><span><?php echo htmlspecialchars($receipt['cashier_name'] ?: 'Cashier'); ?></span></div>
        </section>

        <hr class="rc-rule">
        <div class="rc-kv rc-cols"><span>ITEM</span><span>AMOUNT (PHP)</span></div>
        <hr class="rc-rule">

        <section class="rc-items" aria-label="Purchased items">
            <?php foreach ($lines as $line): ?>
                <?php
                    $lineDisc  = round((float)$line['discount'] * (float)$line['quantity'], 2);
                    $lineGross = round((float)$line['total_price'] + $lineDisc, 2);
                ?>
                <div class="rc-item">
                    <div class="rc-item-name"><?php echo htmlspecialchars($line['product_name']); ?></div>
                    <div class="rc-item-row">
                        <span><?php echo fmt_qty($line['quantity']); ?> <?php echo htmlspecialchars(unit_label($line['product_unit'])); ?> @ <?php echo number_format((float)$line['unit_price'], 2); ?></span>
                        <span class="rc-amt"><?php echo number_format($lineGross, 2); ?></span>
                    </div>
                    <?php if ($lineDisc > 0): ?>
                        <div class="rc-item-row rc-item-disc">
                            <span>Discount</span>
                            <span class="rc-amt">-<?php echo number_format($lineDisc, 2); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <hr class="rc-rule">
        <section class="rc-totals" aria-label="Totals">
            <?php if ($discountTotal > 0): ?>
                <div class="rc-kv"><span>Subtotal</span><span class="rc-amt"><?php echo number_format($grossTotal, 2); ?></span></div>
                <div class="rc-kv"><span>Total discount</span><span class="rc-amt">-<?php echo number_format($discountTotal, 2); ?></span></div>
            <?php endif; ?>
            <div class="rc-grand"><span>TOTAL</span><span class="rc-amt">PHP <?php echo number_format($grandTotal, 2); ?></span></div>
            <div class="rc-kv rc-muted"><span><?php echo count($lines); ?> item<?php echo count($lines) === 1 ? '' : 's'; ?></span><span></span></div>
        </section>

        <hr class="rc-rule">
        <section class="rc-pay" aria-label="Payment">
            <?php if ($tendered !== null): ?>
                <div class="rc-kv"><span><?php echo htmlspecialchars($tenderLabel); ?></span><span class="rc-amt"><?php echo number_format($tendered, 2); ?></span></div>
                <div class="rc-kv"><span>CHANGE</span><span class="rc-amt"><?php echo number_format((float)$receipt['change_amount'], 2); ?></span></div>
            <?php else: ?>
                <div class="rc-kv"><span><?php echo htmlspecialchars($tenderLabel); ?></span><span class="rc-amt"><?php echo number_format($grandTotal, 2); ?></span></div>
            <?php endif; ?>
            <?php if (!empty($receipt['payment_reference'])): ?>
                <div class="rc-kv"><span>Ref. No.</span><span><?php echo htmlspecialchars($receipt['payment_reference']); ?></span></div>
            <?php endif; ?>
        </section>

        <hr class="rc-rule">
        <footer class="rc-center rc-foot">
            <div class="rc-barcode-wrap">
                <?php echo rc_code39_svg($receiptNo); ?>
                <div class="rc-barcode-text"><?php echo htmlspecialchars($receiptNo); ?></div>
            </div>
            <div class="rc-thanks">THANK YOU!</div>
            <div class="rc-come">Please come again.</div>
            <div class="rc-fine">This serves as your sales receipt.</div>
            <div class="rc-fine">Printed <?php echo date('M d, Y h:i A'); ?></div>
        </footer>
    </div>
    <?php endif; ?>

<?php render_app_close(['context' => 'cashier', 'extra_js' => $extraJs]); ?>