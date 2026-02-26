<?php
/**
 * E2E Test Configuration - OneMundo Marketplace
 * Configuração centralizada para todos os testes e2e
 */

define('E2E_TEST_MODE', true);
define('E2E_BASE_URL', 'http://localhost');
define('E2E_BORAUM_URL', 'http://76.13.164.237');

// Database config
define('E2E_DB_HOST', '147.93.12.236');
define('E2E_DB_NAME', 'love1');
define('E2E_DB_USER', 'love1');
define('E2E_DB_PASS', 'Aleff2009@');

// Test timeouts
define('E2E_TIMEOUT_SHORT', 5);
define('E2E_TIMEOUT_MEDIUM', 15);
define('E2E_TIMEOUT_LONG', 30);

class E2ETestRunner {
    private $pdo;
    private $results = [];
    private $currentTest = '';
    private $startTime;
    private $testCount = 0;
    private $passCount = 0;
    private $failCount = 0;

    public function __construct() {
        $this->pdo = new PDO(
            "mysql:host=" . E2E_DB_HOST . ";dbname=" . E2E_DB_NAME . ";charset=utf8mb4",
            E2E_DB_USER,
            E2E_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->startTime = microtime(true);
    }

    public function getDB() {
        return $this->pdo;
    }

    public function startTest($name) {
        $this->currentTest = $name;
        $this->testCount++;
        echo "\n🧪 Testing: $name\n";
    }

    public function assert($condition, $message) {
        if ($condition) {
            $this->passCount++;
            echo "  ✅ PASS: $message\n";
            $this->results[] = ['test' => $this->currentTest, 'status' => 'pass', 'message' => $message];
            return true;
        } else {
            $this->failCount++;
            echo "  ❌ FAIL: $message\n";
            $this->results[] = ['test' => $this->currentTest, 'status' => 'fail', 'message' => $message];
            return false;
        }
    }

    public function assertEquals($expected, $actual, $message) {
        $condition = $expected === $actual;
        if (!$condition) {
            $message .= " (expected: " . json_encode($expected) . ", got: " . json_encode($actual) . ")";
        }
        return $this->assert($condition, $message);
    }

    public function assertNotEmpty($value, $message) {
        return $this->assert(!empty($value), $message);
    }

    public function assertArrayHasKey($key, $array, $message) {
        return $this->assert(isset($array[$key]), $message);
    }

    public function assertGreaterThan($expected, $actual, $message) {
        return $this->assert($actual > $expected, $message . " ($actual > $expected)");
    }

    public function assertContains($needle, $haystack, $message) {
        if (is_array($haystack)) {
            return $this->assert(in_array($needle, $haystack), $message);
        }
        return $this->assert(strpos($haystack, $needle) !== false, $message);
    }

    public function httpRequest($method, $url, $data = [], $headers = []) {
        $ch = curl_init();

        $fullUrl = E2E_BASE_URL . $url;

        if ($method === 'GET' && !empty($data)) {
            $fullUrl .= '?' . http_build_query($data);
        }

        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, E2E_TIMEOUT_MEDIUM);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
            $headers[] = 'Content-Type: application/json';
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'code' => $httpCode,
            'body' => $response,
            'json' => json_decode($response, true),
            'error' => $error
        ];
    }

    public function printSummary() {
        $elapsed = round(microtime(true) - $this->startTime, 2);

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "📊 E2E TEST SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        echo "Total Tests: {$this->testCount}\n";
        echo "✅ Passed: {$this->passCount}\n";
        echo "❌ Failed: {$this->failCount}\n";
        echo "⏱️  Time: {$elapsed}s\n";
        echo str_repeat("=", 60) . "\n";

        if ($this->failCount > 0) {
            echo "\n❌ FAILED TESTS:\n";
            foreach ($this->results as $r) {
                if ($r['status'] === 'fail') {
                    echo "  - [{$r['test']}] {$r['message']}\n";
                }
            }
        }

        return $this->failCount === 0;
    }

    public function getResults() {
        return $this->results;
    }
}

// Helper functions
function generateTestPhone() {
    return '11' . rand(900000000, 999999999);
}

function generateTestEmail() {
    return 'test_' . time() . '_' . rand(1000, 9999) . '@test.com';
}

function generateTestCPF() {
    $cpf = '';
    for ($i = 0; $i < 11; $i++) {
        $cpf .= rand(0, 9);
    }
    return $cpf;
}
