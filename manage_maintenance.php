<?php
session_start();
require_once "config.php";

// Access Control: Admin and Manager only
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("location: dashboard.php");
    exit;
}

$form_message = "";
$edit_mode = false;
$add_mode = false;
$log_data = ['id' => '', 'vehicle_id' => '', 'service_date' => date('Y-m-d'), 'service_type' => '', 'odometer_reading' => '', 'service_cost' => '', 'vendor_name' => '', 'description' => '', 'next_service_date' => null];
$service_types = ['General Service', 'Oil Change', 'Tyre Replacement', 'Brake Repair', 'Engine Work', 'Accident Repair', 'Other'];

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = intval($_POST['id'] ?? 0);
    $vehicle_id = intval($_POST['vehicle_id']);
    $service_date = $_POST['service_date'];
    $service_type = trim($_POST['service_type']);
    $odometer = intval($_POST['odometer_reading']);
    $cost = (float)$_POST['service_cost'];
    $vendor = trim($_POST['vendor_name']);
    $description = trim($_POST['description']);
    $next_service_date = !empty($_POST['next_service_date']) ? $_POST['next_service_date'] : null;
    $branch_id = $_SESSION['branch_id'];
    $created_by = $_SESSION['id'];

    if ($id > 0) { // Update
        $sql = "UPDATE maintenance_logs SET vehicle_id=?, service_date=?, service_type=?, odometer_reading=?, service_cost=?, vendor_name=?, description=?, next_service_date=? WHERE id=? AND branch_id=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("issidsssii", $vehicle_id, $service_date, $service_type, $odometer, $cost, $vendor, $description, $next_service_date, $id, $branch_id);
    } else { // Insert
        $sql = "INSERT INTO maintenance_logs (vehicle_id, service_date, service_type, odometer_reading, service_cost, vendor_name, description, next_service_date, branch_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("issidsssii", $vehicle_id, $service_date, $service_type, $odometer, $cost, $vendor, $description, $next_service_date, $branch_id, $created_by);
    }

    if ($stmt->execute()) {
        $form_message = '<div class="p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50">Maintenance log saved successfully!</div>';
        $add_mode = $edit_mode = false;
    } else {
        $form_message = '<div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50">Error: ' . $stmt->error . '</div>';
    }
    $stmt->close();
}

// Handle GET Actions
if (isset($_GET['action'])) {
    $id = intval($_GET['id'] ?? 0);
    if ($_GET['action'] == 'add') { $add_mode = true; }
    elseif ($_GET['action'] == 'edit' && $id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM maintenance_logs WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $log_data = $result->fetch_assoc();
            $edit_mode = true;
        }
        $stmt->close();
    }
}

// Data Fetching for Lists/Dropdowns
$maintenance_logs = [];
$vehicles = $mysqli->query("SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number ASC")->fetch_all(MYSQLI_ASSOC);

if (!$add_mode && !$edit_mode) {
    // Search & Pagination for list view
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $records_per_page = 9;
    $offset = ($page - 1) * $records_per_page;
    $search_term = trim($_GET['search'] ?? '');
    
    $where_sql = " WHERE m.branch_id = ?";
    $params = [$_SESSION['branch_id']];
    $types = "i";

    if (!empty($search_term)) {
        $like_term = "%{$search_term}%";
        $where_sql .= " AND (v.vehicle_number LIKE ? OR m.service_type LIKE ? OR m.vendor_name LIKE ?)";
        array_push($params, $like_term, $like_term, $like_term);
        $types .= "sss";
    }

    $count_sql = "SELECT COUNT(m.id) FROM maintenance_logs m LEFT JOIN vehicles v ON m.vehicle_id = v.id" . $where_sql;
    $stmt_count = $mysqli->prepare($count_sql);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $stmt_count->close();
    $total_pages = ceil($total_records / $records_per_page);

    $list_sql = "SELECT m.*, v.vehicle_number FROM maintenance_logs m JOIN vehicles v ON m.vehicle_id = v.id" . $where_sql . " ORDER BY m.service_date DESC, m.id DESC LIMIT ? OFFSET ?";
    $params[] = $records_per_page; $types .= "i";
    $params[] = $offset; $types .= "i";
    
    $stmt_list = $mysqli->prepare($list_sql);
    $bind_params = [];
    $bind_params[] = $types;
    foreach ($params as $key => $value) {
        $bind_params[] = &$params[$key];
    }
    call_user_func_array([$stmt_list, 'bind_param'], $bind_params);
    
    $stmt_list->execute();
    $maintenance_logs = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_list->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Maintenance - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .select2-container--default .select2-selection--single { height: 42px; border-radius: 0.5rem; border: 1px solid #d1d5db; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 40px; padding-left: 0.75rem; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen bg-gray-100">
        <?php include 'sidebar.php'; ?>
        <div class="flex flex-col flex-1 relative">
             <header class="bg-white shadow-sm border-b border-gray-200">
                <div class="mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between items-center h-16">
                         <button id="sidebar-toggle" class="text-gray-500 hover:text-gray-600 md:hidden"><i class="fas fa-bars fa-lg"></i></button>
                        <h1 class="text-xl font-semibold text-gray-800">Service & Maintenance</h1>
                        <a href="logout.php" class="text-gray-500 hover:text-red-600"><i class="fas fa-sign-out-alt fa-lg"></i></a>
                    </div>
                </div>
            </header>
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-4 md:p-8 [--webkit-overflow-scrolling:touch]">
                <?php if(!empty($form_message)) echo $form_message; ?>

                <?php if ($add_mode || $edit_mode): ?>
                <div class="bg-white p-6 sm:p-8 rounded-xl shadow-md">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6"><?php echo $edit_mode ? 'Edit Maintenance Log' : 'Add New Maintenance Log'; ?></h2>
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="id" value="<?php echo $log_data['id']; ?>">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div><label class="block text-sm font-medium">Vehicle <span class="text-red-500">*</span></label><select name="vehicle_id" class="searchable-select mt-1 block w-full" required><option value="">Select Vehicle</option><?php foreach($vehicles as $v): ?><option value="<?php echo $v['id']; ?>" <?php if($log_data['vehicle_id'] == $v['id']) echo 'selected'; ?>><?php echo htmlspecialchars($v['vehicle_number']); ?></option><?php endforeach; ?></select></div>
                            <div><label class="block text-sm font-medium">Service Date <span class="text-red-500">*</span></label><input type="date" name="service_date" value="<?php echo htmlspecialchars($log_data['service_date']); ?>" class="mt-1 block w-full px-3 py-2 border rounded-lg" required></div>
                            <div><label class="block text-sm font-medium">Service Type <span class="text-red-500">*</span></label><select name="service_type" class="mt-1 block w-full px-3 py-2 border rounded-lg bg-white" required><?php foreach($service_types as $type): ?><option value="<?php echo $type; ?>" <?php if($log_data['service_type'] == $type) echo 'selected'; ?>><?php echo $type; ?></option><?php endforeach; ?></select></div>
                            <div><label class="block text-sm font-medium">Odometer Reading</label><input type="number" name="odometer_reading" value="<?php echo htmlspecialchars($log_data['odometer_reading']); ?>" class="mt-1 block w-full px-3 py-2 border rounded-lg"></div>
                            <div><label class="block text-sm font-medium">Service Cost <span class="text-red-500">*</span></label><input type="number" step="0.01" name="service_cost" value="<?php echo htmlspecialchars($log_data['service_cost']); ?>" class="mt-1 block w-full px-3 py-2 border rounded-lg" required></div>
                            <div><label class="block text-sm font-medium">Vendor / Garage</label><input type="text" name="vendor_name" value="<?php echo htmlspecialchars($log_data['vendor_name']); ?>" class="mt-1 block w-full px-3 py-2 border rounded-lg"></div>
                            <div class="md:col-span-2"><label class="block text-sm font-medium">Description</label><textarea name="description" rows="2" class="mt-1 block w-full px-3 py-2 border rounded-lg"><?php echo htmlspecialchars($log_data['description']); ?></textarea></div>
                            <div><label class="block text-sm font-medium">Next Service Due</label><input type="date" name="next_service_date" value="<?php echo htmlspecialchars($log_data['next_service_date']); ?>" class="mt-1 block w-full px-3 py-2 border rounded-lg"></div>
                        </div>
                        <div class="mt-6 flex justify-end space-x-3"><a href="manage_maintenance.php" class="py-2 px-4 border rounded-md">Cancel</a><button type="submit" class="py-2 px-6 bg-indigo-600 text-white rounded-md">Save Log</button></div>
                    </form>
                </div>
                <?php else: ?>
                <div class="space-y-6">
                    <div class="flex justify-between items-center"><h2 class="text-2xl font-bold text-gray-800">Maintenance History</h2><a href="manage_maintenance.php?action=add" class="py-2 px-4 bg-indigo-600 text-white rounded-lg"><i class="fas fa-plus mr-2"></i>Log Service</a></div>
                    <div class="bg-white p-4 rounded-xl shadow-md"><form method="GET"><div class="flex space-x-2"><input type="text" name="search" placeholder="Search by vehicle, service type..." value="<?php echo htmlspecialchars($search_term ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg"><button type="submit" class="py-2 px-4 bg-indigo-600 text-white rounded-lg"><i class="fas fa-search"></i></button><a href="manage_maintenance.php" class="py-2 px-4 bg-gray-100 rounded-lg">Reset</a></div></form></div>
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <?php foreach($maintenance_logs as $log): ?>
                        <div class="bg-white rounded-xl shadow-md p-6">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800"><?php echo htmlspecialchars($log['service_type']); ?></p>
                                    <h3 class="font-bold text-lg text-gray-800 mt-2"><?php echo htmlspecialchars($log['vehicle_number']); ?></h3>
                                </div>
                                <p class="text-sm text-gray-500"><?php echo date("d M, Y", strtotime($log['service_date'])); ?></p>
                            </div>
                            <div class="mt-4 border-t pt-4 text-sm text-gray-600 space-y-2">
                                <div class="flex justify-between"><p>Cost:</p><p class="font-bold text-lg">₹<?php echo number_format($log['service_cost'], 2); ?></p></div>
                                <div class="flex justify-between"><p>Odometer:</p><p><?php echo htmlspecialchars($log['odometer_reading']); ?> km</p></div>
                                <div class="flex justify-between"><p>Vendor:</p><p><?php echo htmlspecialchars($log['vendor_name'] ?? 'N/A'); ?></p></div>
                                <?php if($log['next_service_date']): ?><div class="flex justify-between text-red-600"><p>Next Due:</p><p class="font-semibold"><?php echo date("d M, Y", strtotime($log['next_service_date'])); ?></p></div><?php endif; ?>
                            </div>
                             <div class="mt-4 pt-4 border-t flex justify-end space-x-3 text-sm font-medium">
                                <a href="?action=edit&id=<?php echo $log['id']; ?>" class="text-green-600 hover:text-green-800">Edit</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    </div>
                <?php endif; ?>
                <?php include 'footer.php'; ?>
            </main>
        </div>
    </div>
    <script>
    $(document).ready(function() {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const sidebarClose = document.getElementById('close-sidebar-btn');

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
    });
    window.addEventListener('load', function() {
        document.getElementById('page-loader').style.display = 'none';
    });
    </script>
</body>
</html>