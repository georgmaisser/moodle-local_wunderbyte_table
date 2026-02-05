<?php
/**
 * Performance benchmark for security changes
 * Run from CLI: php benchmark_security.php
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

global $USER, $CFG;

// Ensure we have a user context
if (!isset($USER->id)) {
    $USER = get_admin();
}

if (!function_exists('sesskey')) {
    function sesskey() {
        return 'test_session_key_12345';
    }
}

// Benchmark parameters
$iterations = 1000;

echo "=== Wunderbyte Table Security Overhead Benchmark ===\n\n";

// 1. HMAC Hash Generation (return_encoded_table)
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $secret = hash('sha256', $CFG->passwordsaltmain . 'wunderbyte_table_cache');
    $basedata = "testid_" . $i . "capabilityvalue" . $USER->id . sesskey() . time();
    $signature = hash_hmac('sha256', $basedata, $secret);
    $hash = substr($signature, 0, 40);
}
$hmac_time = microtime(true) - $start;
$hmac_avg = ($hmac_time / $iterations) * 1000;

// 2. Input Validation (preg_match)
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $hash = str_pad(dechex($i), 40, '0', STR_PAD_LEFT);
    preg_match('/^[a-f0-9]{40}$/i', $hash);
}
$regex_time = microtime(true) - $start;
$regex_avg = ($regex_time / $iterations) * 1000;

// 3. Metadata Array Creation
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $metadata = [
        'userid' => $USER->id,
        'sesskey' => sesskey(),
        'timestamp' => time(),
        'classname' => 'local_wunderbyte_table\\wunderbyte_table',
        'signature' => str_repeat('a', 64),
        'idstring' => 'testid_' . $i,
        'requirecapability' => 'local/wunderbyte_table:canaccess',
        'requirelogin' => true,
    ];
}
$metadata_time = microtime(true) - $start;
$metadata_avg = ($metadata_time / $iterations) * 1000;

// 4. Signature Verification (hash_equals)
$sig1 = hash_hmac('sha256', 'test', 'secret');
$sig2 = hash_hmac('sha256', 'test', 'secret');
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    hash_equals($sig1, $sig2);
}
$equals_time = microtime(true) - $start;
$equals_avg = ($equals_time / $iterations) * 1000;

// 5. Class Whitelist Check
$allowedclasses = [
    'local_wunderbyte_table\\wunderbyte_table',
    'mod_booking\\table\\bookingoptions_table',
    'mod_booking\\table\\teachers_table',
];
$classname = 'local_wunderbyte_table\\wunderbyte_table';
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    in_array($classname, $allowedclasses, true);
}
$whitelist_time = microtime(true) - $start;
$whitelist_avg = ($whitelist_time / $iterations) * 1000;

// 6. Complete Validation Flow (realistic scenario)
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    // Input validation
    $hash = str_pad(dechex($i), 40, '0', STR_PAD_LEFT);
    if (!preg_match('/^[a-f0-9]{40}$/i', $hash)) {
        continue;
    }

    // Metadata checks
    $metadata = [
        'userid' => $USER->id,
        'sesskey' => sesskey(),
        'timestamp' => time(),
        'signature' => str_repeat('a', 64),
    ];

    if ($metadata['userid'] != $USER->id) {
        continue;
    }

    if ($metadata['sesskey'] !== sesskey()) {
        continue;
    }

    // Whitelist check
    if (!in_array($classname, $allowedclasses, true)) {
        continue;
    }

    // Signature verification
    hash_equals($sig1, $sig2);
}
$complete_time = microtime(true) - $start;
$complete_avg = ($complete_time / $iterations) * 1000;

// Output results
echo sprintf("%-40s %10s %15s\n", "Operation", "Total (ms)", "Avg (ms)");
echo str_repeat("-", 67) . "\n";
echo sprintf("%-40s %10.2f %15.4f\n", "HMAC Hash Generation", $hmac_time * 1000, $hmac_avg);
echo sprintf("%-40s %10.2f %15.4f\n", "Regex Input Validation", $regex_time * 1000, $regex_avg);
echo sprintf("%-40s %10.2f %15.4f\n", "Metadata Array Creation", $metadata_time * 1000, $metadata_avg);
echo sprintf("%-40s %10.2f %15.4f\n", "hash_equals() Comparison", $equals_time * 1000, $equals_avg);
echo sprintf("%-40s %10.2f %15.4f\n", "Whitelist Array Check", $whitelist_time * 1000, $whitelist_avg);
echo str_repeat("-", 67) . "\n";
echo sprintf("%-40s %10.2f %15.4f\n", "Complete Validation Flow", $complete_time * 1000, $complete_avg);
echo str_repeat("=", 67) . "\n\n";

// Analysis
$total_per_request = $complete_avg;
$baseline = 0.05;
$requests_per_second_without = 1000 / $baseline; // Assume 0.05ms baseline
$requests_per_second_with = 1000 / ($baseline + $total_per_request);
$throughput_impact = (($requests_per_second_without - $requests_per_second_with) / $requests_per_second_without) * 100;

echo "=== Performance Analysis ===\n\n";
echo "Overhead per table instantiation: " . number_format($complete_avg, 4) . " ms\n";
echo "Percentage overhead (baseline 0.05ms): " . number_format(($complete_avg / $baseline) * 100, 1) . "%\n\n";

echo "=== Real-World Impact ===\n\n";

// Scenario 1: Single page load with 1 table
$page_overhead_1table = $complete_avg;
echo "Scenario 1: Single table on page\n";
echo "  - Additional latency: ~" . number_format($page_overhead_1table, 3) . " ms\n";
echo "  - User perceptible: " . ($page_overhead_1table > 100 ? "YES" : "NO") . " (threshold: 100ms)\n\n";

// Scenario 2: AJAX reload (most common)
$ajax_overhead = $complete_avg;
echo "Scenario 2: AJAX table reload\n";
echo "  - Additional latency: ~" . number_format($ajax_overhead, 3) . " ms\n";
echo "  - Impact on UX: " . ($ajax_overhead > 50 ? "Noticeable" : "Negligible") . "\n\n";

// Scenario 3: High traffic site
$requests_per_hour = 10000;
$total_overhead_per_hour = ($complete_avg / 1000) * $requests_per_hour;
echo "Scenario 3: High traffic (10,000 table loads/hour)\n";
echo "  - Total overhead per hour: " . number_format($total_overhead_per_hour, 2) . " seconds\n";
echo "  - CPU time consumed: ~" . number_format($total_overhead_per_hour / 3600 * 100, 3) . "% of one core\n\n";

echo "=== Recommendation ===\n\n";
if ($complete_avg < 1.0) {
    echo "✓ EXCELLENT: Overhead is negligible (< 1ms)\n";
    echo "  No performance concerns. Security benefits far outweigh minimal cost.\n";
} else if ($complete_avg < 5.0) {
    echo "✓ GOOD: Overhead is acceptable (< 5ms)\n";
    echo "  Minimal impact on user experience. Proceed with confidence.\n";
} else if ($complete_avg < 10.0) {
    echo "⚠ MODERATE: Overhead is noticeable (< 10ms)\n";
    echo "  Consider caching strategy optimization if performance is critical.\n";
} else {
    echo "✗ HIGH: Overhead is significant (> 10ms)\n";
    echo "  Review implementation or consider alternative approaches.\n";
}

echo "\n=== Notes ===\n";
echo "- Benchmarks run with {$iterations} iterations\n";
echo "- Times are averages and may vary based on server load\n";
echo "- Validation only runs on cache retrieval, not on every table access\n";
echo "- HMAC generation only runs when creating new cache entries\n";
echo "- Most operations benefit from CPU cache and are highly optimized\n\n";
