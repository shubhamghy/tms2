<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($invoice_id === 0) {
    die("Error: No invoice ID provided.");
}

// Fetch Company Details
$company_details = $mysqli->query("SELECT * FROM company_details WHERE id = 1")->fetch_assoc();

// Fetch Invoice Details
$sql_invoice = "SELECT i.*, p.name as consignor_name, p.address as consignor_address, p.gst_no as consignor_gst, p.pan_no as consignor_pan, p.state as place_of_supply FROM invoices i JOIN parties p ON i.consignor_id = p.id WHERE i.id = ?";
$stmt_invoice = $mysqli->prepare($sql_invoice);
$stmt_invoice->bind_param("i", $invoice_id);
$stmt_invoice->execute();
$invoice = $stmt_invoice->get_result()->fetch_assoc();
$stmt_invoice->close();

if (!$invoice) {
    die("Error: Invoice not found.");
}

$sql_items = "SELECT 
                s.id AS shipment_id, s.consignment_no, s.consignment_date, s.quantity, s.package_type, s.chargeable_weight, s.chargeable_weight_unit, v.vehicle_number, s.origin, s.destination,
                MAX(CASE WHEN sp.payment_type = 'Billing Rate' THEN sp.rate END) AS billing_rate,
                MAX(CASE WHEN sp.payment_type = 'Billing Rate' THEN sp.billing_method END) AS billing_method,
                SUM(CASE WHEN sp.payment_type = 'Billing Rate' THEN sp.amount ELSE 0 END) AS billing_amount,
                SUM(CASE WHEN sp.payment_type = 'Detention Charge' THEN sp.amount ELSE 0 END) AS detention_amount,
                (SELECT GROUP_CONCAT(si.invoice_no SEPARATOR ', ') FROM shipment_invoices si WHERE si.shipment_id = s.id) AS customer_invoice_no
              FROM invoice_items ii
              JOIN shipments s ON ii.shipment_id = s.id
              LEFT JOIN shipment_payments sp ON s.id = sp.shipment_id
              LEFT JOIN vehicles v ON s.vehicle_id = v.id
              WHERE ii.invoice_id = ?
              GROUP BY s.id, s.consignment_no, s.consignment_date, s.quantity, s.package_type, s.chargeable_weight, s.chargeable_weight_unit, v.vehicle_number, s.origin, s.destination";

$stmt_items = $mysqli->prepare($sql_items);
$stmt_items->bind_param("i", $invoice_id);
$stmt_items->execute();
$invoice_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_items->close();


// --- START: NEW FUNCTION TO CONVERT NUMBER TO WORDS (INDIAN SYSTEM) ---
function numberToWords($number) {
    $no = floor($number);
    $paise = round($number - $no, 2) * 100;
    $hundred = null;
    $digits_length = strlen($no);
    $i = 0;
    $str = array();
    $words = array(
        0 => '', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine',
        10 => 'ten', 11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen',
        20 => 'twenty', 30 => 'thirty', 40 => 'forty', 50 => 'fifty', 60 => 'sixty', 70 => 'seventy', 80 => 'eighty', 90 => 'ninety'
    );
    $digits = array('', 'hundred', 'thousand', 'lakh', 'crore');

    while ($i < $digits_length) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += $divider == 10 ? 1 : 2;
        if ($number) {
            $plural = (($counter = count($str)) && $number > 9) ? 's' : '';
            $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
            $str[] = ($number < 21) ? $words[$number] . " " . $digits[$counter] . $plural . " " . $hundred : $words[floor($number / 10) * 10] . " " . $words[$number % 10] . " " . $digits[$counter] . $plural . " " . $hundred;
        } else $str[] = null;
    }

    $rupees = implode('', array_reverse($str));
    $paise_word = '';
    if ($paise > 0) {
        $paise_word = " and " . ($words[(int)($paise / 10) * 10] ?? '') . " " . ($words[$paise % 10] ?? '') . " Paise";
    }

    return ucwords($rupees) . "Rupees" . $paise_word . " Only";
}
// --- END: NEW FUNCTION ---


// --- TOTALS CALCULATION UPDATED (IGST REMOVED) ---
$sub_total = 0;
$total_detention = 0;
foreach ($invoice_items as $item) {
    $sub_total += $item['billing_amount'];
    $total_detention += $item['detention_amount'];
}

$net_amount = $sub_total + $total_detention;
$amount_in_words = numberToWords($net_amount);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo htmlspecialchars($invoice['invoice_no']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        @media print {
            body { background-color: #fff; }
            .no-print { display: none; }
            .print-area { border: none; box-shadow: none; margin: 0; }
        }
    </style>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto">
        <div class="text-center mb-8 no-print">
            <h1 class="text-3xl font-bold">Invoice Preview</h1>
            <div class="mt-4">
                <button onclick="window.print()" class="bg-indigo-600 text-white font-bold py-2 px-6 rounded-lg shadow-md hover:bg-indigo-700">
                    <i class="fas fa-print mr-2"></i> Print Invoice
                </button>
                <button id="download-pdf-btn" class="bg-green-600 text-white font-bold py-2 px-6 rounded-lg shadow-md hover:bg-green-700 ml-4">
                    <i class="fas fa-file-pdf mr-2"></i> Download as PDF
                </button>
                <a href="manage_invoices.php" class="bg-gray-200 text-gray-800 font-bold py-2 px-6 rounded-lg shadow-md hover:bg-gray-300 ml-4">
                    <i class="fas fa-plus-circle mr-2"></i> New Invoice
                </a>
            </div>
        </div>
        <div id="invoice-content" class="bg-white p-8 border border-gray-300 shadow-lg print-area">
            <header class="grid grid-cols-2 gap-4 pb-4 border-b-2 border-black">
                <div>
                    <?php if(!empty($company_details['logo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($company_details['logo_path']); ?>" alt="Company Logo" class="h-20">
                    <?php endif; ?>
                    <h1 class="text-2xl font-bold text-[#2B347A]-600 mt-2"><?php echo htmlspecialchars($company_details['name'] ?? ''); ?></h1>
                    <p class="text-xs"><?php echo htmlspecialchars($company_details['address'] ?? ''); ?></p>
                    <p class="text-xs"><?php echo htmlspecialchars($company_details['gst_no'] ?? ''); ?></p>
                </div>
                <div class="text-right">
                    <h2 class="text-3xl font-bold">INVOICE</h2>
                    <p class="text-sm mt-2"><strong class="font-semibold">Email:</strong> <?php echo htmlspecialchars($company_details['email']); ?></p>
                    <p class="text-sm"><strong class="font-semibold">Web:</strong> <?php echo htmlspecialchars($company_details['website'] ?? ''); ?></p>
                    <p class="text-sm"><strong class="font-semibold">Contact:</strong> <?php echo htmlspecialchars($company_details['contact_number_1'] ?? ''); ?> , <?php echo htmlspecialchars($company_details['contact_number_2'] ?? '');?></p>
                </div>
            </header>
            <div class="grid grid-cols-2 gap-4 mt-4 text-sm">
                <div class="border p-2">
                    <h3 class="font-bold">To,</h3>
                    <p><?php echo htmlspecialchars($invoice['consignor_name']); ?></p>
                    <p><?php echo nl2br(htmlspecialchars($invoice['consignor_address'])); ?></p>
                    <p><strong>GSTIN:</strong> <?php echo htmlspecialchars($invoice['consignor_gst']); ?></p>
                    <p><strong>PAN:</strong> <?php echo htmlspecialchars($invoice['consignor_pan']); ?></p>
                </div>
                <div class="border p-2">
                   <p class="text-sm mt-2"><strong class="font-semibold">Invoice No:</strong> <?php echo htmlspecialchars($invoice['invoice_no']); ?></p>
                    <p class="text-sm"><strong class="font-semibold">Date:</strong> <?php echo date("d/m/Y", strtotime($invoice['invoice_date'])); ?></p>
                    <p class="text-sm"><strong class="font-semibold">Period:</strong> <?php echo date("d/m/Y", strtotime($invoice['from_date'])) . ' to ' . date("d/m/Y", strtotime($invoice['to_date'])); ?></p>
                    <p><strong>Place of Supply:</strong> <?php echo htmlspecialchars($invoice['place_of_supply']); ?></p>
                </div>
            </div>

            <p class="my-4 text-sm">Transportation charges as per detail given below:--</p>

            <table class="w-full text-xs border-collapse border border-black">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border border-black p-1">Sl No.</th>
                        <th class="border border-black p-1">LR No.</th>
                        <th class="border border-black p-1">LR Date</th>
                        <th class="border border-black p-1">Invoice No.</th>
                        <th class="border border-black p-1">Vehicle No.</th>
                        <th class="border border-black p-1">Route</th>
                        <th class="border border-black p-1">Qty</th>
                        <th class="border border-black p-1">Charge Wt.</th>
                        <th class="border border-black p-1">Rate</th>
                        <th class="border border-black p-1 text-right">Freight (₹)</th>
                        <th class="border border-black p-1 text-right">Detention (₹)</th>
                        <th class="border border-black p-1">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach($invoice_items as $item): ?>
                    <tr>
                        <td class="border border-black p-1 text-center"><?php echo $i++; ?></td>
                        <td class="border border-black p-1"><?php echo htmlspecialchars($item['consignment_no']); ?></td>
                        <td class="border border-black p-1"><?php echo date("d-m-Y", strtotime($item['consignment_date'])); ?></td>
                        <td class="border border-black p-1"><?php echo htmlspecialchars($item['customer_invoice_no']); ?></td>
                        <td class="border border-black p-1"><?php echo htmlspecialchars($item['vehicle_number']); ?></td>
                        <td class="border border-black p-1"><?php echo htmlspecialchars($item['origin'] . ' - ' . $item['destination']); ?></td>
                        <td class="border border-black p-1"><?php echo htmlspecialchars($item['quantity'] . ' ' . $item['package_type']); ?></td>
                        <td class="border border-black p-1"><?php echo htmlspecialchars($item['chargeable_weight'] . ' ' . $item['chargeable_weight_unit']); ?></td>
                        <td class="border border-black p-1">
                            <?php
                                $rate_text = '-';
                                if (!empty($item['billing_rate'])) {
                                    $rate_text = '₹' . number_format($item['billing_rate'], 2);
                                    if (!empty($item['billing_method'])) {
                                        $rate_text .= ' (' . $item['billing_method'] . ')';
                                    }
                                }
                                echo htmlspecialchars($rate_text);
                            ?>
                        </td>
                        <td class="border border-black p-1 text-right"><?php echo htmlspecialchars(number_format($item['billing_amount'], 2)); ?></td>
                        <td class="border border-black p-1 text-right"><?php echo $item['detention_amount'] > 0 ? htmlspecialchars(number_format($item['detention_amount'], 2)) : '-'; ?></td>
                        <td class="border border-black p-1"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="font-semibold">
                    <tr>
                        <td colspan="11" class="border border-black p-1 text-right">Sub Total:</td>
                        <td class="border border-black p-1 text-right">₹<?php echo htmlspecialchars(number_format($sub_total, 2)); ?></td>
                    </tr>
                    <tr>
                        <td colspan="11" class="border border-black p-1 text-right">Total Detention Charges:</td>
                        <td class="border border-black p-1 text-right">₹<?php echo htmlspecialchars(number_format($total_detention, 2)); ?></td>
                    </tr>
                    <tr class="bg-gray-100">
                        <td colspan="11" class="border border-black p-1 text-right">Net Amount:</td>
                        <td class="border border-black p-1 text-right">₹<?php echo htmlspecialchars(number_format($net_amount, 2)); ?></td>
                    </tr>
                </tfoot>
            </table>

            <div class="mt-4 text-sm">
                <p><strong>Amount in Words:</strong> <?php echo $amount_in_words; ?></p>
                <p>GST is payable on Reverse Charge: Yes</p>
            </div>

            <footer class="mt-12 text-xs">
                <p><strong>Terms and Conditions:</strong></p>
                <p>(1) Please Make all payments by Cheq/RTGS/NEFT/D.D drawn in favour of <?php echo htmlspecialchars($company_details['name'] ?? ''); ?> only against official money receipt.</p>
                <p>(2) ENCL. Signed Acknowledgment</p>
                <div class="flex justify-between mt-16">
                    <p>Amount of GST subject to Reverse Charge</p>
                    <p class="border-t border-black px-8 pt-1">Authorised Signatory</p>
                </div>
            </footer>
        </div>
    </div>
    <script>
        document.getElementById('download-pdf-btn').addEventListener('click', function () {
            const invoiceElement = document.getElementById('invoice-content');
            const invoiceNumber = "<?php echo htmlspecialchars($invoice['invoice_no']); ?>";
            
            html2canvas(invoiceElement, { scale: 2 }).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const { jsPDF } = window.jspdf;
                
                const pdf = new jsPDF('p', 'mm', 'a4');
                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = pdf.internal.pageSize.getHeight();
                
                const canvasWidth = canvas.width;
                const canvasHeight = canvas.height;
                const canvasAspectRatio = canvasWidth / canvasHeight;
                
                let imgWidth = pdfWidth - 20;
                let imgHeight = imgWidth / canvasAspectRatio;

                if (imgHeight > pdfHeight - 20) {
                    imgHeight = pdfHeight - 20;
                    imgWidth = imgHeight * canvasAspectRatio;
                }
                
                const x = (pdfWidth - imgWidth) / 2;
                const y = 10;

                pdf.addImage(imgData, 'PNG', x, y, imgWidth, imgHeight);
                pdf.save(`Invoice-${invoiceNumber}.pdf`);
            });
        });
    </script>
</body>
</html>