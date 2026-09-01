<?php
/*
 * File: Admin/Stock-History.php
 * Purpose: Provide stock movement history and AJAX endpoint for filtered history retrieval.
 * Key locations:
 * - Bootstrap: `require_once __DIR__ . '/../includes/app.php';` at line 2
 * - Access control: `require_roles(...)` at line 3
 * - `app_connect()` at line 6
 * - AJAX GET handler for `action=get_stock_history` begins at line ~10 and builds dynamic prepared statements.
 * Known issues / improvements:
 * - Good use of prepared statements for filter params; ensure inputs are validated (dates, product names) before binding.
 * - Consider rate-limiting the AJAX endpoint if exposed publicly.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager', 'Owner'], '../Login.php');

$conn = app_connect();

// Handle AJAX request for stock history
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action']) && $_GET['action'] === 'get_stock_history') {
    header('Content-Type: application/json');
    
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $productName = $_GET['product_name'] ?? null;
    $movementType = $_GET['movement_type'] ?? null;
    
    // Build query
    $query = "SELECT sm.id, sm.product_id, sm.movement_type, sm.quantity, sm.cost_price, sm.batch_reference, sm.notes, sm.created_at, 
                     i.product_name, COALESCE(e.full_name, u.username, 'System') AS created_by_name
              FROM stock_movements sm
              LEFT JOIN inventory i ON sm.product_id = i.id
              LEFT JOIN employees e ON sm.created_by = e.id
              LEFT JOIN users u ON sm.created_by = u.id
              WHERE 1=1";
    
    $params = [];
    $paramTypes = "";
    
    if ($dateFrom) {
        $query .= " AND DATE(sm.created_at) >= ?";
        $params[] = $dateFrom;
        $paramTypes .= "s";
    }
    
    if ($dateTo) {
        $query .= " AND DATE(sm.created_at) <= ?";
        $params[] = $dateTo;
        $paramTypes .= "s";
    }
    
    if ($productName) {
        $query .= " AND i.product_name = ?";
        $params[] = $productName;
        $paramTypes .= "s";
    }
    
    if ($movementType) {
        $query .= " AND sm.movement_type = ?";
        $params[] = $movementType;
        $paramTypes .= "s";
    }
    
    $query .= " ORDER BY sm.created_at DESC LIMIT 1000";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($paramTypes, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $movements = [];
    while ($row = $result->fetch_assoc()) {
        $movements[] = [
            'id' => $row['id'],
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'] ?? 'Unknown',
            'movement_type' => $row['movement_type'],
            'quantity' => (int)$row['quantity'],
            'cost_price' => (float)$row['cost_price'],
            'batch_reference' => $row['batch_reference'],
            'notes' => $row['notes'] ?? 'N/A',
            'created_by' => $row['created_by_name'] ?? 'System',
            'created_at' => date('Y-m-d H:i', strtotime($row['created_at']))
        ];
    }
    
    echo json_encode($movements);
    $stmt->close();
    $conn->close();
    exit();
}

// Get all products for filter dropdown
$products = [];
$productQuery = "SELECT DISTINCT i.product_name FROM inventory i ORDER BY i.product_name ASC";
$productResult = $conn->query($productQuery);
if ($productResult && $productResult->num_rows > 0) {
    while ($row = $productResult->fetch_assoc()) {
        $products[] = $row['product_name'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Movement History</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        body {
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .page-container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #e74c3c;
            padding-bottom: 15px;
        }
        .page-header h1 {
            margin: 0;
            color: #2c3e50;
            font-size: 28px;
        }
        .back-button {
            padding: 8px 16px;
            background-color: #95a5a6;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: background-color 0.3s ease;
        }
        .back-button:hover {
            background-color: #7f8c8d;
        }
        .filters-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .stock-history-filters {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .history-filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: flex-end;
        }
        .history-filter-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .history-filter-item label {
            font-weight: bold;
            color: #2c3e50;
            font-size: 14px;
        }
        .history-filter-item input,
        .history-filter-item select {
            padding: 8px 12px;
            border: 1px solid #bdc3c7;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }
        .history-filter-item input:focus,
        .history-filter-item select:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 5px rgba(52, 152, 219, 0.3);
        }
        .filter-buttons {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        .filter-buttons button {
            padding: 8px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        .filter-buttons .apply-btn {
            background-color: #3498db;
            color: white;
        }
        .filter-buttons .apply-btn:hover {
            background-color: #2980b9;
        }
        .filter-buttons .reset-btn {
            background-color: #95a5a6;
            color: white;
        }
        .filter-buttons .reset-btn:hover {
            background-color: #7f8c8d;
        }
        .batch-table-container {
            overflow-x: auto;
            border: 1px solid #bdc3c7;
            border-radius: 6px;
        }
        .batch-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        .batch-table thead {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            font-weight: bold;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .batch-table th {
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #c0392b;
        }
        .batch-table td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 14px;
        }
        .batch-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        .batch-table code {
            background: #ecf0f1;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 12px;
        }
        .no-results {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
            font-size: 16px;
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-header">
        <h1>Stock Movement History</h1>
        <a href="Inventory.php" class="back-button">← Back to Inventory</a>
    </div>

    <div class="filters-section">
        <div class="stock-history-filters">
            <div class="history-filter-row">
                <div class="history-filter-item">
                    <label>From Date:</label>
                    <input type="date" id="historyDateFrom" value="">
                </div>
                <div class="history-filter-item">
                    <label>To Date:</label>
                    <input type="date" id="historyDateTo" value="">
                </div>
                <div class="history-filter-item">
                    <label>Product:</label>
                    <select id="historyProductFilter">
                        <option value="">All Products</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo htmlspecialchars($product); ?>">
                                <?php echo htmlspecialchars($product); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="history-filter-item">
                    <label>Movement Type:</label>
                    <select id="historyTypeFilter">
                        <option value="">All Types</option>
                        <option value="IN">Stock In</option>
                        <option value="OUT">Stock Out</option>
                    </select>
                </div>
            </div>
            <div class="filter-buttons">
                <button type="button" class="apply-btn" onclick="loadStockHistory()">Apply Filters</button>
                <button type="button" class="reset-btn" onclick="resetHistoryFilters()">Reset</button>
            </div>
        </div>
    </div>

    <div class="batch-table-container">
        <table id="historyTable" class="batch-table">
            <thead>
                <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th>Quantity</th>
                        <th>Cost Price</th>
                        <th>Batch Reference</th>
                        <th>Notes</th>
                        <th>User</th>
                    </tr>
            </thead>
            <tbody id="historyTableBody">
                <!-- Populated by JavaScript -->
            </tbody>
        </table>
    </div>
</div>

<script>
function resetHistoryFilters() {
    const today = new Date();
    const thirtyDaysAgo = new Date(today.getTime() - (30 * 24 * 60 * 60 * 1000));
    
    document.getElementById('historyDateFrom').value = thirtyDaysAgo.toISOString().split('T')[0];
    document.getElementById('historyDateTo').value = today.toISOString().split('T')[0];
    document.getElementById('historyProductFilter').value = '';
    document.getElementById('historyTypeFilter').value = '';
    
    loadStockHistory();
}

function loadStockHistory() {
    const dateFrom = document.getElementById('historyDateFrom').value;
    const dateTo = document.getElementById('historyDateTo').value;
    const productFilter = document.getElementById('historyProductFilter').value;
    const typeFilter = document.getElementById('historyTypeFilter').value;
    
    let url = '?action=get_stock_history';
    if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
    if (productFilter) url += '&product_name=' + encodeURIComponent(productFilter);
    if (typeFilter) url += '&movement_type=' + encodeURIComponent(typeFilter);
    
    const xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            const movements = JSON.parse(xhr.responseText);
            populateHistoryTable(movements);
        } else {
            alert('Error loading stock history');
        }
    };
    xhr.send();
}

function populateHistoryTable(movements) {
    const tbody = document.getElementById('historyTableBody');
    tbody.innerHTML = '';
    
    if (movements.length === 0) {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="8" class="no-results">No movements found</td>';
        tbody.appendChild(row);
        return;
    }
    
    movements.forEach(movement => {
        const row = document.createElement('tr');
        
        // Color code by type
        let typeColor = '';
        let typeLabel = '';
        if (movement.movement_type === 'IN') {
            typeColor = '#27ae60';
            typeLabel = 'Stock In';
        } else if (movement.movement_type === 'OUT') {
            typeColor = '#e74c3c';
            typeLabel = 'Stock Out';
        } else {
            typeColor = '#f39c12';
            typeLabel = '⚠️ ' + movement.movement_type;
        }
        
        row.innerHTML = `
            <td>${movement.created_at}</td>
            <td><strong>${movement.product_name}</strong></td>
            <td><span style="color: ${typeColor}; font-weight: bold;">${typeLabel}</span></td>
            <td>${movement.quantity}</td>
            <td>₱${parseFloat(movement.cost_price || 0).toFixed(2)}</td>
            <td><code>${movement.batch_reference}</code></td>
            <td><small>${movement.notes || 'N/A'}</small></td>
            <td><em>${movement.created_by}</em></td>
        `;
        
        tbody.appendChild(row);
    });
}

// Initialize on page load
window.addEventListener('DOMContentLoaded', function() {
    // Set default dates (last 30 days)
    const today = new Date();
    const thirtyDaysAgo = new Date(today.getTime() - (30 * 24 * 60 * 60 * 1000));
    
    document.getElementById('historyDateFrom').value = thirtyDaysAgo.toISOString().split('T')[0];
    document.getElementById('historyDateTo').value = today.toISOString().split('T')[0];
    
    // Load initial history
    loadStockHistory();
});
</script>
</body>
</html>

<?php
$conn->close();
?>
