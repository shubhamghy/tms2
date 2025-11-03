<?php
session_start();
require_once "config.php";

// Redirect if not logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

// Pagination variables
$limit = 9; // Number of records per page (multiple of 3 for card layout)
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filter and Search variables
$search_term = $_GET['search'] ?? '';
$consignor_filter = $_GET['consignor_id'] ?? '';
$consignee_filter = $_GET['consignee_id'] ?? '';
$vehicle_filter = $_GET['vehicle_id'] ?? '';
$start_date_filter = $_GET['start_date'] ?? '';
$end_date_filter = $_GET['end_date'] ?? '';

// Build WHERE clause for filtering
$where_clauses = [];


// ✅ --- START: ROLE-BASED FILTERING LOGIC ---

// 1. Define your session variable keys here.
//    (These should match what you set in your login script)
$role_session_key = 'role';
$branch_session_key = 'branch_id';

// 2. Get the user's role and branch from the session.
$user_role = $_SESSION[$role_session_key] ?? null;
$user_branch_id = isset($_SESSION[$branch_session_key]) ? intval($_SESSION[$branch_session_key]) : null;

// 3. Apply the filter ONLY if the user is NOT an admin AND has a valid branch ID.
if ($user_role !== 'admin' && !empty($user_branch_id)) {
    $where_clauses[] = "s.branch_id = $user_branch_id";
}

// ✅ --- END: ROLE-BASED FILTERING LOGIC ---


if (!empty($search_term)) {
    $escaped_term = $mysqli->real_escape_string($search_term);
    $where_clauses[] = "(s.consignment_no LIKE '%$escaped_term%' OR v.vehicle_number LIKE '%$escaped_term%' OR p_consignor.name LIKE '%$escaped_term%' OR p_consignee.name LIKE '%$escaped_term%')";
}
if (!empty($consignor_filter)) { $where_clauses[] = "s.consignor_id = " . intval($consignor_filter); }
if (!empty($consignee_filter)) { $where_clauses[] = "s.consignee_id = " . intval($consignee_filter); }
if (!empty($vehicle_filter)) { $where_clauses[] = "s.vehicle_id = " . intval($vehicle_filter); }
if (!empty($start_date_filter)) { $where_clauses[] = "s.consignment_date >= '" . $mysqli->real_escape_string($start_date_filter) . "'"; }
if (!empty($end_date_filter)) { $where_clauses[] = "s.consignment_date <= '" . $mysqli->real_escape_string($end_date_filter) . "'"; }

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);
}

// Get total records for pagination
$total_records_sql = "SELECT COUNT(s.id) FROM shipments s
                      LEFT JOIN parties p_consignor ON s.consignor_id = p_consignor.id
                      LEFT JOIN parties p_consignee ON s.consignee_id = p_consignee.id
                      LEFT JOIN vehicles v ON s.vehicle_id = v.id
                      $where_sql";
$total_result = $mysqli->query($total_records_sql);
$total_records = $total_result->fetch_row()[0];
$total_pages = ceil($total_records / $limit);

// Fetch shipments for the current page
$sql = "SELECT s.id, s.consignment_no, s.consignment_date, s.status, s.origin, s.destination,
               p_consignor.name as consignor_name,
               p_consignee.name as consignee_name,
               b.name as broker_name,
               v.vehicle_number
        FROM shipments s
        LEFT JOIN parties p_consignor ON s.consignor_id = p_consignor.id
        LEFT JOIN parties p_consignee ON s.consignee_id = p_consignee.id
        LEFT JOIN brokers b ON s.broker_id = b.id
        LEFT JOIN vehicles v ON s.vehicle_id = v.id
        $where_sql
        ORDER BY s.consignment_date DESC, s.id DESC
        LIMIT $limit OFFSET $offset";
$result = $mysqli->query($sql);
$shipments = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $shipments[] = $row;
    }
}

// Fetch data for dropdowns
$consignors = $mysqli->query("SELECT id, name FROM parties WHERE party_type IN ('Consignor', 'Both') AND is_active = 1 ORDER BY name");
$consignees = $mysqli->query("SELECT id, name FROM parties WHERE party_type IN ('Consignee', 'Both') AND is_active = 1 ORDER BY name");
$vehicles = $mysqli->query("SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number");

// Function to get status badge colors
function getStatusBadge($status) {
    $colors = [
        'Booked' => 'bg-blue-100 text-blue-800',
        'Billed' => 'bg-indigo-100 text-indigo-800',
        'Pending Payment' => 'bg-yellow-100 text-yellow-800',
        'Reverify' => 'bg-orange-100 text-orange-800',
        'In Transit' => 'bg-cyan-100 text-cyan-800',
        'Reached' => 'bg-teal-100 text-teal-800',
        'Delivered' => 'bg-green-100 text-green-800',
        'Completed' => 'bg-gray-100 text-gray-800',
    ];
    $color_class = $colors[$status] ?? 'bg-gray-100 text-gray-800';
    return "<span class='px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full {$color_class}'>" . htmlspecialchars($status) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Bookings - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .select2-container--default .select2-selection--single { height: 42px; border-radius: 0.5rem; border: 1px solid #d1d5db; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 40px; padding-left: 1rem; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px; }
    </style>
</head>
<body class="bg-gray-100">

<div id="loader" class="fixed inset-0 bg-white bg-opacity-75 z-50 flex items-center justify-center">
    <div class="fas fa-spinner fa-spin fa-3x text-indigo-600"></div>
</div>
<div class="flex h-screen bg-gray-100 overflow-hidden">
    <?php include 'sidebar.php'; ?>
     <div class="flex flex-col flex-1 relative">
        <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden md:hidden"></div>
        
        <header class="bg-white shadow-sm border-b border-gray-200">
            <div class="mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <button id="sidebar-toggle" class="text-gray-500 hover:text-gray-600 md:hidden">
                        <i class="fas fa-bars fa-lg"></i>
                    </button>
                    <h1 class="text-xl font-semibold text-gray-800">View Bookings</h1>
                    <a href="logout.php" class="text-gray-500 hover:text-red-600"><i class="fas fa-sign-out-alt fa-lg"></i></a>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-4 md:p-8">
            <div class="bg-white p-6 rounded-xl shadow-md mb-6">
                 <form method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                    <div class="lg:col-span-2">
                        <label for="search" class="block text-sm font-medium text-gray-700">Search</label>
                        <input type="text" id="search" name="search" placeholder="Search CN, Vehicle, Party..." value="<?php echo htmlspecialchars($search_term); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                     <div>
                        <label for="consignor_id" class="block text-sm font-medium text-gray-700">Consignor</label>
                        <select id="consignor_id" name="consignor_id" class="select2-filter mt-1 block w-full"><option value="">All Consignors</option><?php while($row = $consignors->fetch_assoc()) echo "<option value='{$row['id']}' ".($consignor_filter == $row['id'] ? 'selected' : '').">".htmlspecialchars($row['name'])."</option>"; ?></select>
                    </div>
                    <div>
                        <label for="consignee_id" class="block text-sm font-medium text-gray-700">Consignee</label>
                        <select id="consignee_id" name="consignee_id" class="select2-filter mt-1 block w-full"><option value="">All Consignees</option><?php while($row = $consignees->fetch_assoc()) echo "<option value='{$row['id']}' ".($consignee_filter == $row['id'] ? 'selected' : '').">".htmlspecialchars($row['name'])."</option>"; ?></select>
                    </div>
                     <div>
                        <label for="vehicle_id" class="block text-sm font-medium text-gray-700">Vehicle</label>
                        <select id="vehicle_id" name="vehicle_id" class="select2-filter mt-1 block w-full"><option value="">All Vehicles</option><?php while($row = $vehicles->fetch_assoc()) echo "<option value='{$row['id']}' ".($vehicle_filter == $row['id'] ? 'selected' : '').">".htmlspecialchars($row['vehicle_number'])."</option>"; ?></select>
                    </div>
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-gray-700">From Date</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date_filter); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm">
                    </div>
                    <div>
                        <label for="end_date" class="block text-sm font-medium text-gray-700">To Date</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date_filter); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm">
                    </div>
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="w-full inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-700">Filter</button>
                        <a href="view_bookings.php" class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50">Reset</a>
                    </div>
                </form>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($shipments as $shipment): ?>
                <div class="bg-white rounded-xl shadow-md overflow-hidden transition hover:shadow-lg">
                    <div class="p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <a href="view_shipment_details.php?id=<?php echo $shipment['id']; ?>" class="text-indigo-600 hover:text-indigo-800 font-bold text-lg"><?php echo htmlspecialchars($shipment['consignment_no']); ?></a>
                                <p class="text-sm text-gray-500 mt-1"><?php echo date("d M, Y", strtotime($shipment['consignment_date'])); ?></p>
                            </div>
                            <div class="relative" x-data="{ open: false }">
                                <button @click="open = !open" class="text-gray-500 hover:text-gray-700 focus:outline-none">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg z-10" x-cloak>
                                    <a href="view_shipment_details.php?id=<?php echo $shipment['id']; ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Details</a>
                                    <a href="print_lr_landscape.php?id=<?php echo $shipment['id']; ?>" target="_blank" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Print LR</a>
                                    <a href="booking.php?action=edit&id=<?php echo $shipment['id']; ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Edit</a>
                                    <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <a href="view_bookings.php?action=delete&id=<?php echo $shipment['id']; ?>" onclick="return confirm('Are you sure you want to delete this booking?');" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50">Delete</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <?php echo getStatusBadge($shipment['status']); ?>
                        </div>
                        <div class="mt-4 border-t border-gray-200 pt-4">
                            <div class="flex items-start mb-3">
                                <i class="fas fa-user-tie text-gray-400 w-5 text-center mr-3 mt-1"></i>
                                <div>
                                    <p class="text-xs text-gray-500">Consignor</p>
                                    <p class="font-medium text-gray-800"><?php echo htmlspecialchars($shipment['consignor_name']); ?></p>
                                </div>
                            </div>
                             <div class="flex items-start">
                                <i class="fas fa-building text-gray-400 w-5 text-center mr-3 mt-1"></i>
                                <div>
                                    <p class="text-xs text-gray-500">Consignee</p>
                                    <p class="font-medium text-gray-800"><?php echo htmlspecialchars($shipment['consignee_name']); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 border-t border-gray-200 pt-4">
                            <div class="flex items-center text-gray-700">
                                <i class="fas fa-map-marker-alt w-5 text-center mr-3 text-red-500"></i>
                                <p class="font-semibold truncate"><?php echo htmlspecialchars($shipment['origin']); ?></p>
                                <i class="fas fa-long-arrow-alt-right mx-3 text-gray-400"></i>
                                <i class="fas fa-map-marker-alt w-5 text-center mr-3 text-green-500"></i>
                                <p class="font-semibold truncate"><?php echo htmlspecialchars($shipment['destination']); ?></p>
                            </div>
                        </div>
                         <div class="mt-4 border-t border-gray-200 pt-4 text-sm text-gray-600">
                            <p><i class="fas fa-truck w-5 text-center mr-3 text-gray-400"></i> <?php echo htmlspecialchars($shipment['vehicle_number'] ?? 'N/A'); ?></p>
                            <p><i class="fas fa-user-tie w-5 text-center mr-3 text-gray-400 mt-2"></i> <?php echo htmlspecialchars($shipment['broker_name'] ?? 'N/A'); ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                 <?php if (empty($shipments)): ?>
                    <div class="md:col-span-2 lg:col-span-3 text-center py-10">
                        <i class="fas fa-search fa-3x text-gray-300"></i>
                        <p class="mt-4 text-gray-500">No bookings found for the selected criteria.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mt-6 flex justify-between items-center">
                <span class="text-sm text-gray-700">Showing <?php echo $total_records > 0 ? $offset + 1 : 0; ?> to <?php echo min($offset + $limit, $total_records); ?> of <?php echo $total_records; ?> results</span>
                <div class="flex items-center space-x-1">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query($_GET, '', '&'); ?>" class="px-3 py-2 text-gray-500 bg-white border rounded-md hover:bg-gray-100"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query($_GET, '', '&'); ?>" class="px-3 py-2 text-gray-500 bg-white border rounded-md hover:bg-gray-100"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php include 'footer.php'; ?>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>
<script>
    $(document).ready(function() {
        $('.select2-filter').select2({ width: '100%' });
    });
    // Mobile sidebar toggle
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const sidebarClose = document.getElementById('sidebar-close'); // Assuming this is in sidebar.php

    function toggleSidebar() {
        sidebar.classList.toggle('-translate-x-full');
        sidebarOverlay.classList.toggle('hidden');
    }

    if (sidebarToggle) { sidebarToggle.addEventListener('click', toggleSidebar); }
    if (sidebarClose) { sidebarClose.addEventListener('click', toggleSidebar); }
    if (sidebarOverlay) { sidebarOverlay.addEventListener('click', toggleSidebar); }
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