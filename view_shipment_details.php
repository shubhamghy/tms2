<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

// Backend logic to add a new charge
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_charge'])) {
    
    $shipment_id_post = intval($_POST['shipment_id']);
    $payment_type = trim($_POST['payment_type']);
    $amount = floatval($_POST['amount']);
    $payment_date = $_POST['payment_date'];
    $created_by_id = $_SESSION['id'];

    $sql_insert = "INSERT INTO shipment_payments (shipment_id, payment_type, amount, payment_date, created_by_id) VALUES (?, ?, ?, ?, ?)";
    
    if ($stmt_insert = $mysqli->prepare($sql_insert)) {
        $stmt_insert->bind_param("isdsi", $shipment_id_post, $payment_type, $amount, $payment_date, $created_by_id);
        
        if ($stmt_insert->execute()) {
            header("Location: view_shipment_details.php?id=" . $shipment_id_post . "&status=charge_added");
            exit;
        } else {
            echo "Error: " . $stmt_insert->error;
        }
        $stmt_insert->close();
    }
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("location: view_bookings.php");
    exit;
}

$shipment_id = intval($_GET['id']);

// Fetch main shipment data
$sql = "SELECT s.*, 
               p_consignor.name as consignor_name, p_consignor.address as consignor_address, p_consignor.gst_no as consignor_gst,
               p_consignee.name as consignee_name, p_consignee.address as consignee_address, p_consignee.gst_no as consignee_gst,
               b.name as broker_name,
               v.vehicle_number,
               d.name as driver_name, d.license_number as driver_license, d.contact_number as driver_contact,
               cd.description as consignment_description,
               u.username as created_by_user
        FROM shipments s
        LEFT JOIN parties p_consignor ON s.consignor_id = p_consignor.id
        LEFT JOIN parties p_consignee ON s.consignee_id = p_consignee.id
        LEFT JOIN brokers b ON s.broker_id = b.id
        LEFT JOIN vehicles v ON s.vehicle_id = v.id
        LEFT JOIN drivers d ON s.driver_id = d.id
        LEFT JOIN consignment_descriptions cd ON s.description_id = cd.id
        LEFT JOIN users u ON s.created_by_id = u.id
        WHERE s.id = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$result = $stmt->get_result();
$shipment = $result->fetch_assoc();
$stmt->close();

if (!$shipment) {
    echo "Shipment not found.";
    exit;
}

// Fetch invoices
$invoices = [];
$invoice_sql = "SELECT * FROM shipment_invoices WHERE shipment_id = ?";
if ($invoice_stmt = $mysqli->prepare($invoice_sql)) {
    $invoice_stmt->bind_param("i", $shipment_id);
    $invoice_stmt->execute();
    $invoice_result = $invoice_stmt->get_result();
    $invoices = $invoice_result->fetch_all(MYSQLI_ASSOC);
    $invoice_stmt->close();
}

// Fetch tracking history
$tracking_history = [];
$tracking_sql = "SELECT th.*, u.username as updated_by_user FROM shipment_tracking th LEFT JOIN users u ON th.updated_by_id = u.id WHERE th.shipment_id = ? ORDER BY th.created_at ASC";
if ($tracking_stmt = $mysqli->prepare($tracking_sql)) {
    $tracking_stmt->bind_param("i", $shipment_id);
    $tracking_stmt->execute();
    $tracking_result = $tracking_stmt->get_result();
    $tracking_history = $tracking_result->fetch_all(MYSQLI_ASSOC);
    $tracking_stmt->close();
}

// Fetch all payment details into an associative array for display
$payments = [];
if (in_array($_SESSION['role'], ['admin', 'manager'])) {
    $payment_sql = "SELECT * FROM shipment_payments WHERE shipment_id = ?";
    if ($payment_stmt = $mysqli->prepare($payment_sql)) {
        $payment_stmt->bind_param("i", $shipment_id);
        $payment_stmt->execute();
        $payment_result = $payment_stmt->get_result();
        while ($row = $payment_result->fetch_assoc()) {
            $payments[$row['payment_type']] = $row;
        }
        $payment_stmt->close();
    }
}

// Fetch "Other Charges" for display in the new section
$other_charges = [];
if (in_array($_SESSION['role'], ['admin', 'manager'])) {
    $charges_sql = "SELECT * FROM shipment_payments WHERE shipment_id = ? AND payment_type NOT IN ('Billing Rate', 'Lorry Hire', 'Advance Cash', 'Advance Bank', 'Advance Diesel', 'Balance Payment', 'Damage Deduction', 'Shortage Deduction')";
    if ($charges_stmt = $mysqli->prepare($charges_sql)) {
        $charges_stmt->bind_param("i", $shipment_id);
        $charges_stmt->execute();
        $charges_result = $charges_stmt->get_result();
        $other_charges = $charges_result->fetch_all(MYSQLI_ASSOC);
        $charges_stmt->close();
    }
}

// Reverted payment calculation logic
$lorry_hire = 0;
$total_deductions = 0;
$balance_amount = 0;
if (in_array($_SESSION['role'], ['admin', 'manager'])) {
    $lorry_hire = $payments['Lorry Hire']['amount'] ?? 0;
    $advance_cash = $payments['Advance Cash']['amount'] ?? 0;
    $advance_bank = $payments['Advance Bank']['amount'] ?? 0;
    $advance_diesel = $payments['Advance Diesel']['amount'] ?? 0;
    $balance_paid = $payments['Balance Payment']['amount'] ?? 0;
    $damage_deduction = $payments['Damage Deduction']['amount'] ?? 0;
    $shortage_deduction = $payments['Shortage Deduction']['amount'] ?? 0;
    
    $deduction_types = ['Advance Cash', 'Advance Bank', 'Advance Diesel', 'Labour Charge', 'Dala Charge'];
    foreach($deduction_types as $deduction_type) {
        $total_deductions += $payments[$deduction_type]['amount'] ?? 0;
    }
    $balance_amount = $lorry_hire - $total_deductions;
}

// Function to get status badge colors
function getStatusBadge($status) {
    $colors = [
        'Booked' => 'bg-blue-100 text-blue-800', 'Billed' => 'bg-indigo-100 text-indigo-800',
        'Pending Payment' => 'bg-yellow-100 text-yellow-800', 'Reverify' => 'bg-orange-100 text-orange-800',
        'In Transit' => 'bg-cyan-100 text-cyan-800', 'Reached' => 'bg-teal-100 text-teal-800',
        'Delivered' => 'bg-green-100 text-green-800', 'Completed' => 'bg-gray-100 text-gray-800',
    ];
    return $colors[$status] ?? 'bg-gray-100 text-gray-800';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shipment Details - <?php echo htmlspecialchars($shipment['consignment_no']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        @media print {
            body * { visibility: hidden; }
            #print-area, #print-area * { visibility: visible; }
            #print-area { position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none; }
        }
    </style>
</head>
<body class="bg-gray-100">

<div id="loader" class="fixed inset-0 bg-white bg-opacity-75 z-50 flex items-center justify-center">
    <div class="fas fa-spinner fa-spin fa-3x text-indigo-600"></div>
</div>
<div class="flex h-screen bg-gray-100 overflow-hidden">
    <?php include 'sidebar.php'; ?>
    <div class="flex flex-col flex-1 relative">
        <header class="bg-white shadow-sm border-b border-gray-200 no-print">
            <div class="mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center space-x-4">
                        <a href="view_bookings.php" class="text-sm font-medium bg-gray-100 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-200">
                            <i class="fas fa-arrow-left mr-2"></i>Back to List
                        </a>
                        <h1 class="text-xl font-semibold text-gray-800">Shipment Details</h1>
                    </div>
                    <a href="logout.php" class="text-gray-500 hover:text-red-600"><i class="fas fa-sign-out-alt fa-lg"></i></a>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-4 md:p-8" id="print-area">
            <div class="bg-white p-6 rounded-xl shadow-md mb-6">
                <div class="flex flex-col md:flex-row justify-between items-start">
                    <div>
                        <h2 class="text-2xl font-bold text-orange-800">CN: <?php echo htmlspecialchars($shipment['consignment_no']); ?></h2>
                        <p class="text-sm text-gray-500">Booked on <?php echo date("d M, Y", strtotime($shipment['consignment_date'])); ?> by <?php echo htmlspecialchars($shipment['created_by_user']); ?></p>
                    </div>
                    <div class="mt-4 md:mt-0 flex items-center space-x-4">
                        <span class="px-4 py-2 text-sm font-semibold rounded-full <?php echo getStatusBadge($shipment['status']); ?>">
                            <?php echo htmlspecialchars($shipment['status']); ?>
                        </span>
                        <button onclick="window.print()" class="no-print p-2 rounded-full bg-indigo-50 text-indigo-600 hover:bg-indigo-100">
                            <i class="fas fa-print"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white p-6 rounded-xl shadow-md">
                        <h3 class="text-lg font-semibold text-gray-800 border-b pb-3 mb-4 flex items-center"><i class="fas fa-users mr-3 text-gray-400"></i>Party Details</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div><h4 class="font-semibold text-gray-600">Consignor</h4><p class="text-gray-800"><?php echo htmlspecialchars($shipment['consignor_name']); ?></p><p class="text-sm text-gray-500"><?php echo htmlspecialchars($shipment['consignor_address']); ?></p><p class="text-sm text-gray-500">GST: <?php echo htmlspecialchars($shipment['consignor_gst'] ?? 'N/A'); ?></p></div>
                            <div><h4 class="font-semibold text-gray-600">Consignee</h4><p class="text-gray-800"><?php echo htmlspecialchars($shipment['consignee_name']); ?></p><p class="text-sm text-gray-500"><?php echo htmlspecialchars($shipment['consignee_address']); ?></p><p class="text-sm text-gray-500">GST: <?php echo htmlspecialchars($shipment['consignee_gst'] ?? 'N/A'); ?></p></div>
                            <?php if($shipment['is_shipping_different']): ?><div class="md:col-span-2 border-t pt-4"><h4 class="font-semibold text-gray-600">Shipping Address</h4><p class="text-gray-800"><?php echo htmlspecialchars($shipment['shipping_name']); ?></p><p class="text-sm text-gray-500"><?php echo htmlspecialchars($shipment['shipping_address']); ?></p></div><?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-xl shadow-md">
                        <h3 class="text-lg font-semibold text-gray-800 border-b pb-3 mb-4 flex items-center"><i class="fas fa-file-invoice-dollar mr-3 text-gray-400"></i>Invoice Details</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50"><tr><th class="py-2 px-3 text-left font-semibold">Invoice No.</th><th class="py-2 px-3 text-left font-semibold">Date</th><th class="py-2 px-3 text-left font-semibold">Amount</th><th class="py-2 px-3 text-left font-semibold">E-Way Bill</th><th class="py-2 px-3 text-left font-semibold">E-Way Expiry</th></tr></thead>
                                <tbody class="divide-y">
                                    <?php foreach($invoices as $invoice): ?>
                                    <tr><td class="py-2 px-3"><?php echo htmlspecialchars($invoice['invoice_no']); ?></td><td class="py-2 px-3"><?php echo date("d-m-Y", strtotime($invoice['invoice_date'])); ?></td><td class="py-2 px-3">₹<?php echo number_format($invoice['invoice_amount'], 2); ?></td><td class="py-2 px-3"><?php echo htmlspecialchars($invoice['eway_bill_no'] ?? 'N/A'); ?></td><td class="py-2 px-3"><?php echo $invoice['eway_bill_expiry'] ? date("d-m-Y", strtotime($invoice['eway_bill_expiry'])) : 'N/A'; ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if (in_array($_SESSION['role'], ['admin', 'manager'])): ?>
                    <div class="bg-white p-6 rounded-xl shadow-md">
                         <h3 class="text-lg font-semibold text-gray-800 border-b pb-3 mb-4 flex items-center"><i class="fas fa-credit-card mr-3 text-gray-400"></i>Payment Details</h3>
                         <?php if (!empty($payments)): ?>
                         <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                            <div><p class="text-sm text-gray-500">Party Billing</p><p class="font-bold text-lg">₹<?php echo number_format($payments['Billing Rate']['amount'] ?? 0, 2); ?></p></div>
                            <div><p class="text-sm text-gray-500">Lorry Hire</p><p class="font-bold text-lg">₹<?php echo number_format($lorry_hire, 2); ?></p></div>
                            <div><p class="text-sm text-gray-500">Advance Cash</p><p class="font-bold text-lg">₹<?php echo number_format($advance_cash, 2); ?></p></div>
                            <div><p class="text-sm text-gray-500">Advance Bank</p><p class="font-bold text-lg">₹<?php echo number_format($advance_bank, 2); ?></p></div>
                            <div><p class="text-sm text-gray-500">Advance Diesel</p><p class="font-bold text-lg">₹<?php echo number_format($advance_diesel, 2); ?></p></div>
                            <div><p class="text-sm text-gray-500">Total Deductions</p><p class="font-bold text-lg">₹<?php echo number_format($total_deductions, 2); ?></p></div>
                            <div class="text-orange-600"><p class="text-sm">Balance to Vehicle</p><p class="font-bold text-lg">₹<?php echo number_format($balance_amount, 2); ?></p></div>
                         </div>
                         <div class="border-t pt-4">
                            <h4 class="font-semibold text-gray-600 mb-2">Settlement Details</h4>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div><p class="text-sm text-gray-500">Damage</p><p class="font-bold text-lg">₹<?php echo number_format($damage_deduction, 2); ?></p></div>
                                <div><p class="text-sm text-gray-500">Shortage</p><p class="font-bold text-lg">₹<?php echo number_format($shortage_deduction, 2); ?></p></div>
                                <div><p class="text-sm text-gray-500">Payment Date</p><p class="font-bold text-lg"><?php echo isset($payments['Balance Payment']['payment_date']) ? date("d M, Y", strtotime($payments['Balance Payment']['payment_date'])) : 'N/A'; ?></p></div>
                                <div><p class="text-sm text-gray-500">Reference</p><p class="font-bold text-lg"><?php echo htmlspecialchars($payments['Balance Payment']['remarks'] ?? 'N/A'); ?></p></div>
                                <div class="text-green-600"><p class="text-sm">Final Amount Paid</p><p class="font-bold text-lg">₹<?php echo number_format($balance_paid, 2); ?></p></div>
                            </div>
                         </div>
                         <?php else: ?>
                         <p class="text-gray-500 text-center py-4">No payment details have been entered yet.</p>
                         <?php endif; ?>
                    </div>
                    
                    <div class="bg-white p-6 rounded-xl shadow-md">
                        <h3 class="text-lg font-semibold text-gray-800 border-b pb-3 mb-4 flex items-center"><i class="fas fa-plus-circle mr-3 text-gray-400"></i>Add / View Other Charges</h3>
                        <form method="POST" action="view_shipment_details.php?id=<?php echo $shipment_id; ?>">
                            <input type="hidden" name="shipment_id" value="<?php echo $shipment_id; ?>">
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                                <div>
                                    <label for="payment_type" class="block text-sm font-medium text-gray-700">Charge Type</label>
                                    <select name="payment_type" id="payment_type" required class="mt-1 block w-full px-3 py-2 border rounded-md">
                                        <option value="Detention Charge">Detention Charge</option>
                                        <option value="Loading Charge">Loading Charge</option>
                                        <option value="Unloading Charge">Unloading Charge</option>
                                        <option value="Late Fee">Late Fee</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="amount" class="block text-sm font-medium text-gray-700">Amount (₹)</label>
                                    <input type="number" step="0.01" name="amount" id="amount" required class="mt-1 block w-full px-3 py-2 border rounded-md">
                                </div>
                                <div>
                                    <label for="payment_date" class="block text-sm font-medium text-gray-700">Date</label>
                                    <input type="date" name="payment_date" id="payment_date" value="<?php echo date('Y-m-d'); ?>" required class="mt-1 block w-full px-3 py-2 border rounded-md">
                                </div>
                                <div>
                                    <button type="submit" name="add_charge" class="w-full py-2 px-4 border rounded-lg text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">Add Charge</button>
                                </div>
                            </div>
                        </form>
                        <div class="mt-6">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="py-2 px-3 text-left font-semibold">Type</th>
                                        <th class="py-2 px-3 text-left font-semibold">Date</th>
                                        <th class="py-2 px-3 text-right font-semibold">Amount (₹)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <?php if (empty($other_charges)): ?>
                                        <tr><td colspan="3" class="py-4 px-3 text-gray-500 text-center">No additional charges have been added.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($other_charges as $charge): ?>
                                            <tr>
                                                <td class="py-2 px-3"><?php echo htmlspecialchars($charge['payment_type']); ?></td>
                                                <td class="py-2 px-3"><?php echo date("d-m-Y", strtotime($charge['payment_date'])); ?></td>
                                                <td class="py-2 px-3 text-right">₹<?php echo number_format($charge['amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="space-y-6">
                    <div class="bg-white p-6 rounded-xl shadow-md">
                         <h3 class="text-lg font-semibold text-gray-800 border-b pb-3 mb-4 flex items-center"><i class="fas fa-route mr-3 text-gray-400"></i>Route & Goods</h3>
                         <div class="mb-4"><p class="text-sm text-gray-500">Origin</p><p class="font-semibold text-gray-800"><?php echo htmlspecialchars($shipment['origin']); ?></p></div>
                         <div class="mb-4"><p class="text-sm text-gray-500">Destination</p><p class="font-semibold text-gray-800"><?php echo htmlspecialchars($shipment['destination']); ?></p></div>
                         <div class="border-t pt-4"><p class="text-sm text-gray-500">Goods</p><p class="font-semibold text-gray-800"><?php echo htmlspecialchars($shipment['consignment_description']); ?></p><p class="text-sm text-gray-500"><?php echo htmlspecialchars($shipment['quantity']); ?> <?php echo htmlspecialchars($shipment['package_type']); ?></p></div>
                         <div class="border-t pt-4 mt-4 grid grid-cols-2 gap-4"><div><p class="text-sm text-gray-500">Net Wt.</p><p class="font-semibold"><?php echo htmlspecialchars($shipment['net_weight']); ?> <?php echo htmlspecialchars($shipment['net_weight_unit']); ?></p></div><div><p class="text-sm text-gray-500">Chargeable Wt.</p><p class="font-semibold"><?php echo htmlspecialchars($shipment['chargeable_weight']); ?> <?php echo htmlspecialchars($shipment['chargeable_weight_unit']); ?></p></div></div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-md">
                         <h3 class="text-lg font-semibold text-gray-800 border-b pb-3 mb-4 flex items-center"><i class="fas fa-truck mr-3 text-gray-400"></i>Transport Details</h3>
                         <div class="flex justify-between items-center"><div><p class="text-sm text-gray-500">Vehicle No.</p><p class="font-semibold text-gray-800"><?php echo htmlspecialchars($shipment['vehicle_number']); ?></p></div><a href="manage_vehicles.php?action=details&id=<?php echo $shipment['vehicle_id']; ?>" class="text-indigo-600 hover:text-indigo-800 text-sm no-print">View</a></div>
                         <div class="mt-3 flex justify-between items-center"><div><p class="text-sm text-gray-500">Driver</p><p class="font-semibold text-gray-800"><?php echo htmlspecialchars($shipment['driver_name']); ?></p></div><a href="manage_drivers.php?action=details&id=<?php echo $shipment['driver_id']; ?>" class="text-indigo-600 hover:text-indigo-800 text-sm no-print">View</a></div>
                         <div class="mt-3 flex justify-between items-center"><div><p class="text-sm text-gray-500">Broker</p><p class="font-semibold text-gray-800"><?php echo htmlspecialchars($shipment['broker_name'] ?? 'N/A'); ?></p></div><?php if (!empty($shipment['broker_id'])): ?><a href="manage_brokers.php?action=details&id=<?php echo $shipment['broker_id']; ?>" class="text-indigo-600 hover:text-indigo-800 text-sm no-print">View</a><?php endif; ?></div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-md">
                         <h3 class="text-lg font-semibold text-gray-800 border-b pb-3 mb-4 flex items-center"><i class="fas fa-location-dot mr-3 text-gray-400"></i>Tracking History</h3>
                         <div class="space-y-4">
                            <?php if (empty($tracking_history)): ?><p class="text-gray-500">No tracking updates available.</p><?php else: ?><?php foreach ($tracking_history as $item): ?><div class="flex"><div class="flex flex-col items-center mr-4"><div><div class="flex items-center justify-center w-8 h-8 bg-indigo-500 rounded-full text-white"><i class="fas fa-check"></i></div></div><div class="w-px h-full bg-gray-300"></div></div><div class="pb-4"><p class="font-semibold text-gray-800">Updated</p><p class="text-sm text-gray-600"><?php echo htmlspecialchars($item['location']); ?></p><p class="text-xs text-gray-400"><?php echo date("d M Y, h:i A", strtotime($item['created_at'])); ?> by <?php echo htmlspecialchars($item['updated_by_user']); ?></p></div></div><?php endforeach; ?><?php endif; ?>
                            <?php if ($shipment['pod_doc_path']): ?><div class="flex"><div class="flex flex-col items-center mr-4"><div><div class="flex items-center justify-center w-8 h-8 bg-green-500 rounded-full text-white"><i class="fas fa-flag-checkered"></i></div></div></div><div class="pb-4"><p class="font-semibold text-gray-800">Trip Completed</p><p class="text-sm text-gray-600">POD uploaded. <?php echo htmlspecialchars($shipment['pod_remarks']); ?></p><a href="<?php echo htmlspecialchars($shipment['pod_doc_path']); ?>" target="_blank" class="text-sm text-indigo-600 hover:underline">View POD</a></div></div><?php endif; ?>
                         </div>
                    </div>
                </div>
            </div>
            <?php include 'footer.php'; ?>
        </main>
    </div>
</div>

<script>
    // Hide the loader once the entire page is fully loaded
    window.onload = function() {
        const loader = document.getElementById('loader');
        if (loader) {
            loader.style.display = 'none';
        }
    };

    // Mobile sidebar toggle script can go here if needed
</script>
</body>
</html>