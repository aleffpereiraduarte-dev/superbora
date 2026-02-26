#!/usr/bin/env php
<?php
/**
 * E2E Test Runner - OneMundo Marketplace
 * Executa todos os testes e2e e gera relatório
 */

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║     OneMundo Marketplace - E2E Test Suite                  ║\n";
echo "║     Testing: Shopper, Delivery, Admin, Full Flow           ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

$startTime = microtime(true);
$testFiles = [
    'test_shopper_complete.php' => '🛒 Shopper App Complete Flow',
    'test_delivery_handoff.php' => '🚗 Delivery & Handoff System',
    'test_admin_panel.php' => '👨‍💼 Admin Panel',
    'test_partner_panel.php' => '🏪 Partner/Market Panel',
    'test_full_flow.php' => '🔄 Complete Purchase to Delivery Flow',
    'test_functional_flows.php' => '⚡ Functional Flows (Simulation)',
    'test_partner_functional.php' => '🛍️ Partner Functional Flows'
];

$allResults = [];
$totalTests = 0;
$totalPass = 0;
$totalFail = 0;

foreach ($testFiles as $file => $description) {
    echo "\n";
    echo "▶ Running: $description\n";
    echo "  File: $file\n";

    $output = [];
    $returnCode = 0;

    // Run test file
    exec("php " . __DIR__ . "/$file 2>&1", $output, $returnCode);

    // Display output
    echo implode("\n", $output) . "\n";

    // Extract results from output
    foreach ($output as $line) {
        if (strpos($line, '✅ Passed:') !== false) {
            preg_match('/Passed: (\d+)/', $line, $matches);
            $totalPass += (int)($matches[1] ?? 0);
        }
        if (strpos($line, '❌ Failed:') !== false) {
            preg_match('/Failed: (\d+)/', $line, $matches);
            $totalFail += (int)($matches[1] ?? 0);
        }
        if (strpos($line, 'Total Tests:') !== false) {
            preg_match('/Total Tests: (\d+)/', $line, $matches);
            $totalTests += (int)($matches[1] ?? 0);
        }
    }

    $allResults[$file] = [
        'description' => $description,
        'return_code' => $returnCode,
        'passed' => $returnCode === 0
    ];
}

$elapsed = round(microtime(true) - $startTime, 2);

// Final Summary
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║                    FINAL TEST SUMMARY                      ║\n";
echo "╠════════════════════════════════════════════════════════════╣\n";
echo "║  Total Test Suites: " . count($testFiles) . str_repeat(" ", 38) . "║\n";
echo "║  Total Assertions: " . str_pad($totalTests, 39) . "║\n";
echo "║  ✅ Passed: " . str_pad($totalPass, 47) . "║\n";
echo "║  ❌ Failed: " . str_pad($totalFail, 47) . "║\n";
echo "║  ⏱️  Total Time: " . str_pad($elapsed . "s", 42) . "║\n";
echo "╠════════════════════════════════════════════════════════════╣\n";

foreach ($allResults as $file => $result) {
    $status = $result['passed'] ? '✅' : '❌';
    $name = substr($result['description'], 0, 45);
    echo "║  $status $name" . str_repeat(" ", 55 - strlen($name)) . "║\n";
}

echo "╚════════════════════════════════════════════════════════════╝\n";

// Overall result
if ($totalFail === 0) {
    echo "\n✅ ALL TESTS PASSED!\n\n";
    exit(0);
} else {
    echo "\n❌ SOME TESTS FAILED - Review output above\n\n";
    exit(1);
}
