<?php
/*
 * File: Owner/Supplier-Payments.php
 * Purpose: Record supplier payments made by the owner from personal funds.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['Owner'], '../Login.php');
require_once __DIR__ . '/../includes/purchasing_payments.php';

$conn = app_connect();
try {
    po_payment_ensure_schema($conn);
} catch (Throwable $e) {
    error_log('[Supplier Payments] Schema setup failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Supplier payment tracking could not be prepared. Please contact an administrator.');
}
$ownerId = auth_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!isset($_POST['pay_delivery'])) {
        flash_error('The requested supplier payment action was not recognized.');
    } else {
        $deliveryReference = $_POST['delivery_reference'] ?? '';
        $referenceRaw = $_POST['payment_reference'] ?? '';
        $paymentReference = is_scalar($referenceRaw) ? trim((string)$referenceRaw) : '';
        $referenceLength = function_exists('mb_strlen') ? mb_strlen($paymentReference, 'UTF-8') : strlen($paymentReference);
        if (!is_scalar($deliveryReference) || $referenceLength > 120) {
            flash_error('Enter a valid payment reference (up to 120 characters).');
        } else {
            try {
                $payment = po_record_delivery_payment(
                    $conn,
                    (string)$deliveryReference,
                    'owner',
                    $ownerId,
                    'Owner personal funds',
                    $paymentReference
                );
                $poLabel = 'PO-' . str_pad((string)$payment['po_id'], 4, '0', STR_PAD_LEFT);
                flash_success($payment['amount'] > 0
                    ? 'Recorded owner payment of PHP ' . number_format($payment['amount'], 2) . ' to ' . $payment['supplier'] . ' for ' . $poLabel . '.'
                    : 'No-charge delivery recorded for ' . $payment['supplier'] . ' on ' . $poLabel . '.');
            } catch (DomainException $e) {
                flash_error($e->getMessage());
            } catch (Throwable $e) {
                error_log('[Supplier Payments] Owner payment failed: ' . $e->getMessage());
                flash_error('Could not record this supplier payment. No changes were saved; please try again.');
            }
        }
    }
    header('Location: Supplier-Payments.php');
    exit();
}

$deliveries = po_pending_delivery_payments($conn, 'owner', $ownerId);
$page_title = 'Supplier Payments';
$breadcrumb = ['Purchasing', 'Supplier Payments'];
$active = 'Supplier-Payments.php';
$context = 'owner';
$role_title = 'Owner';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('Supplier Payments', 'Record payments you make for deliveries received on purchase orders assigned to you.'); ?>

<main class="supplier-payment-page">
    <section class="supplier-payment-panel">
        <div class="supplier-payment-heading">
            <div>
                <span class="supplier-payment-kicker">OWNER-PAID ORDERS</span>
                <h2>Received deliveries awaiting payment</h2>
                <p>Record the full payment for each delivery. Owner payments are tracked separately and do not affect a cashier drawer.</p>
            </div>
            <span class="supplier-payment-count"><?php echo number_format(count($deliveries)); ?> unpaid</span>
        </div>
        <?php if ($deliveries): ?>
            <div class="supplier-payment-list">
                <?php foreach ($deliveries as $delivery): ?>
                    <article class="supplier-payment-card">
                        <div class="supplier-payment-card-details">
                            <strong>PO-<?php echo str_pad((string)$delivery['po_id'], 4, '0', STR_PAD_LEFT); ?> · <?php echo htmlspecialchars($delivery['supplier'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span>Received <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($delivery['received_at'])), ENT_QUOTES, 'UTF-8'); ?>
                                · <?php echo number_format((int)$delivery['line_count']); ?> item line<?php echo (int)$delivery['line_count'] === 1 ? '' : 's'; ?></span>
                        </div>
                        <strong class="supplier-payment-amount">PHP <?php echo number_format((float)$delivery['amount'], 2); ?></strong>
                        <form method="post" class="supplier-payment-form" onsubmit="return confirm('<?php echo (float)$delivery['amount'] > 0 ? 'Confirm that you paid this supplier using your personal funds?' : 'Confirm this delivery had no charge?'; ?>');">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="delivery_reference" value="<?php echo htmlspecialchars($delivery['delivery_reference'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label>Payment reference <span>Optional</span>
                                <input type="text" name="payment_reference" maxlength="120" placeholder="Receipt or transaction number">
                            </label>
                            <button type="submit" name="pay_delivery" value="1"><?php echo (float)$delivery['amount'] > 0 ? 'Record owner payment' : 'Confirm no-charge delivery'; ?></button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="supplier-payment-empty">No received deliveries are waiting for your payment.</p>
        <?php endif; ?>
    </section>
</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
