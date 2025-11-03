<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

$consignor_id = isset($_GET['consignor_id']) ? intval($_GET['consignor_id']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$billable_shipments = [];

if ($consignor_id > 0 && !empty($date_from) && !empty($date_to)) {
    $sql = "SELECT s.id, s.consignment_no, s.consignment_date, s.origin, s.destination, sp.amount as billing_rate
            FROM shipments s
            JOIN shipment_payments sp ON s.id = sp.shipment_id
            LEFT JOIN invoice_items ii ON s.id = ii.shipment_id
            WHERE s.consignor_id = ? 
            AND s.consignment_date BETWEEN ? AND ?
            AND sp.payment_type = 'Billing Rate'
            AND ii.id IS NULL
            ORDER BY s.consignment_date ASC";
    
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("iss", $consignor_id, $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $billable_shipments[] = $row;
        }
        $stmt->close();
    }
}

$consignors = $mysqli->query("SELECT id, name FROM parties WHERE party_type IN ('Consignor', 'Both') AND is_active = 1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Invoice - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style> 
        body { font-family: 'Inter', sans-serif; }
        .select2-container--default .select2-selection--single { height: 42px; border: 1px solid #d1d5db; border-radius: 0.375rem; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 40px; padding-left: 0.75rem; }
    </style>
</head>
<body class="bg-gray-100">
    <div id="loader" class="fixed inset-0 bg-white bg-opacity-75 z-50 flex items-center justify-center">
    <div class="fas fa-spinner fa-spin fa-3x text-indigo-600"></div>
</div>
    <div class="flex h-screen bg-gray-100">
        <?php include 'sidebar.php'; ?>
        
        <div class="flex flex-col flex-1 relative">
            <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden md:hidden"></div>
            
            <header class="bg-white shadow-sm border-b border-gray-200">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                        <button id="sidebar-toggle" class="text-gray-500 hover:text-gray-600 md:hidden">
                            <i class="fas fa-bars fa-lg"></i>
                        </button>
                        <h1 class="text-xl font-semibold text-gray-800">Generate New Invoice</h1>
                        <a href="logout.php" class="text-gray-500 hover:text-red-600"><i class="fas fa-sign-out-alt fa-lg"></i></a>
                    </div>
                </div>
            </header>
            <div class="p-4 md:p-8">
                <div class="bg-white p-8 rounded-lg shadow-md mb-8">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">Generate New Invoice</h2>
                    <form method="GET" class="bg-gray-50 p-4 rounded-lg">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label for="consignor_id" class="block text-sm font-medium text-gray-700">Consignor</label>
                                <select name="consignor_id" id="consignor_id" class="searchable-select mt-1 block w-full" required>
                                    <option value="">Select Consignor</option>
                                    <?php foreach($consignors as $c): ?><option value="<?php echo $c['id']; ?>" <?php if($consignor_id == $c['id']) echo 'selected'; ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="date_from" class="block text-sm font-medium text-gray-700">From Date</label>
                                <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm" required>
                            </div>
                             <div>
                                <label for="date_to" class="block text-sm font-medium text-gray-700">To Date</label>
                                <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm" required>
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="py-2 px-6 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">Find Shipments</button>
                            </div>
                        </div>
                    </form>
                </div>

                <?php if (!empty($billable_shipments)): ?>
                <form action="generate_invoices.php" method="POST">
                    <div class="bg-white p-8 rounded-lg shadow-md">
                        <h2 class="text-xl font-bold text-gray-800 mb-6">Select Shipments to Invoice</h2>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left w-12"><input type="checkbox" id="select-all"></th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">LR No.</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Route</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium uppercase">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($billable_shipments as $shipment): ?>
                                    <tr>
                                        <td class="px-6 py-4"><input type="checkbox" name="shipment_ids[]" value="<?php echo $shipment['id']; ?>" class="shipment-checkbox"></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium"><?php echo htmlspecialchars($shipment['consignment_no']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date("d-m-Y", strtotime($shipment['consignment_date'])); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($shipment['origin'] . ' to ' . $shipment['destination']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo htmlspecialchars(number_format($shipment['billing_rate'], 2)); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-6 flex justify-between items-center">
                             <div>
                                <label for="invoice_no" class="block text-sm font-medium text-gray-700">Invoice Number</label>
                                <input type="text" name="invoice_no" id="invoice_no" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm" required>
                             </div>
                             <div>
                                 <input type="hidden" name="consignor_id" value="<?php echo $consignor_id; ?>">
                                 <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                                 <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                                <button type="submit" class="py-2 px-8 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">Generate Invoice for Selected</button>
                             </div>
                        </div>
                    </div>
                </form>
                <?php elseif ($_SERVER["REQUEST_METHOD"] == "GET" && !empty($consignor_id)): ?>
                <div class="bg-white p-8 rounded-lg shadow-md">
                    <p class="text-center text-gray-500">No billable shipments found for the selected criteria.</p>
                </div>
                <?php endif; ?>
            </div>
             <?php include 'footer.php'; ?>
        </div>
    </div>
    <script>
        $(document).ready(function() {
            // Your existing toggle script is perfect, no changes needed here.
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebar-toggle');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            const sidebarClose = document.getElementById('close-sidebar-btn'); // Assuming this ID is in sidebar.php

            function toggleSidebar() {
                if (sidebar && sidebarOverlay) {
                    sidebar.classList.toggle('-translate-x-full');
                    sidebarOverlay.classList.toggle('hidden');
                }
            }

            if (sidebarToggle) { sidebarToggle.addEventListener('click', toggleSidebar); }
            if (sidebarClose) { sidebarClose.addEventListener('click', toggleSidebar); }
            if (sidebarOverlay) { sidebarOverlay.addEventListener('click', toggleSidebar); }

            
            $('.searchable-select').select2({ width: '100%' });

            $('#select-all').on('click', function() {
                $('.shipment-checkbox').prop('checked', this.checked);
            });
        });
    </script>
      <script>
    // Hide the loader once the entire page is fully loaded
    window.onload = function() {
        const loader = document.getElementById('loader');
        if (loader) {
            loader.style.display = 'none';
        }
    };
</script>
</body>
</html>