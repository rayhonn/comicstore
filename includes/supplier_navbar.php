<?php
$supplier_current = basename($_SERVER['PHP_SELF']);
?>
<nav class="bg-[#0f1b2e] text-white">
    <div class="max-w-6xl mx-auto px-6">
        <div class="flex items-center justify-between h-16">
            <div class="flex items-center gap-8">
                <div class="text-lg font-black">
                    MANGA<span class="text-blue-400">VAULT</span>
                    <span class="text-xs text-blue-300 font-normal ml-1">Supplier Portal</span>
                </div>
                <div class="flex items-center gap-6">
                    <a href="dashboard.php" class="text-sm <?= $supplier_current === 'dashboard.php' ? 'text-blue-400 font-semibold' : 'text-gray-300 hover:text-white' ?> transition-colors">Dashboard</a>
                    <a href="rfq.php" class="text-sm <?= $supplier_current === 'rfq.php' ? 'text-blue-400 font-semibold' : 'text-gray-300 hover:text-white' ?> transition-colors">RFQs</a>
                    <a href="quotations.php" class="text-sm <?= $supplier_current === 'quotations.php' ? 'text-blue-400 font-semibold' : 'text-gray-300 hover:text-white' ?> transition-colors">My Quotations</a>
                    <a href="purchase_orders.php" class="text-sm <?= $supplier_current === 'purchase_orders.php' ? 'text-blue-400 font-semibold' : 'text-gray-300 hover:text-white' ?> transition-colors">Purchase Orders</a>
                    <a href="invoices.php" class="text-sm <?= $supplier_current === 'invoices.php' ? 'text-blue-400 font-semibold' : 'text-gray-300 hover:text-white' ?> transition-colors">Invoices</a>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-sm text-gray-300"><?= htmlspecialchars($_SESSION['supplier_name'] ?? '') ?></span>
                <a href="logout.php" class="bg-gray-700 hover:bg-gray-600 text-white text-xs font-semibold px-4 py-2 rounded-lg transition-colors">Logout</a>
            </div>
        </div>
    </div>
</nav>