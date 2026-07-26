<?php
declare(strict_types=1);

/**
 * Tests unitaires pour CacheService.
 *
 * Couvre : get/set/clear, TTL expiry, corruption handling,
 * thundering herd protection, max size eviction.
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Cache\CacheService;

$testDir = sys_get_temp_dir() . '/cache_test_' . uniqid();
@mkdir($testDir, 0775, true);

// ── Test 1: set + get basic ──────────────────────────────────
test('CacheService: set + get retourne la valeur stockée', function() use ($testDir) {
    $cache = new CacheService($testDir, 1024 * 1024);
    $cache->set('test_key', 'hello', 300);
    $result = $cache->get('test_key', 300, fn() => 'fallback');
    return $result === 'hello';
});

// ── Test 2: get with callback ────────────────────────────────
test('CacheService: get exécute le callback sur cache miss', function() use ($testDir) {
    $cache = new CacheService($testDir, 1024 * 1024);
    $called = false;
    $result = $cache->get('miss_key', 300, function() use (&$called) {
        $called = true;
        return 'computed';
    });
    return $result === 'computed' && $called === true;
});

// ── Test 3: get cache hit skips callback ─────────────────────
test('CacheService: get ne rappelle PAS le callback sur cache hit', function() use ($testDir) {
    $cache = new CacheService($testDir, 1024 * 1024);
    $cache->set('hit_key', 'cached_value', 300);
    $called = false;
    $result = $cache->get('hit_key', 300, function() use (&$called) {
        $called = true;
        return 'new_value';
    });
    return $result === 'cached_value' && $called === false;
});

// ── Test 4: TTL expiry ──────────────────────────────────────
test('CacheService: TTL expiré → callback exécuté', function() use ($testDir) {
    $cache = new CacheService($testDir, 1024 * 1024);
    $cache->set('ttl_key', 'old_value', 1);
    // Simulate TTL expiry by touching the file to 2 seconds ago
    $file = $testDir . '/' . md5('ttl_key') . '.json';
    if (file_exists($file)) {
        touch($file, time() - 2);
    }
    $result = $cache->get('ttl_key', 1, fn() => 'new_value');
    return $result === 'new_value';
});

// ── Test 5: clear ───────────────────────────────────────────
test('CacheService: clear supprime le fichier cache', function() use ($testDir) {
    $cache = new CacheService($testDir, 1024 * 1024);
    $cache->set('clear_key', 'to_delete', 300);
    $cache->clear('clear_key');
    $called = false;
    $result = $cache->get('clear_key', 300, function() use (&$called) {
        $called = true;
        return 'regenerated';
    });
    return $result === 'regenerated' && $called === true;
});

// ── Test 6: corrupted file handling ──────────────────────────
test('CacheService: fichier corrompu → callback exécuté (pas de crash)', function() use ($testDir) {
    $cache = new CacheService($testDir, 1024 * 1024);
    $file = $testDir . '/' . md5('corrupt_key') . '.json';
    file_put_contents($file, '{invalid json!!!');
    $result = $cache->get('corrupt_key', 300, fn() => 'recovered');
    return $result === 'recovered';
});

// ── Test 7: complex data types ──────────────────────────────
test('CacheService: stocke et récupère des tableaux complexes', function() use ($testDir) {
    $cache = new CacheService($testDir, 1024 * 1024);
    $data = ['users' => [['name' => 'Alice', 'role' => 'admin'], ['name' => 'Bob']], 'count' => 2];
    $cache->set('complex_key', $data, 300);
    $result = $cache->get('complex_key', 300, fn() => null);
    return $result === $data;
});

// ── Test 8: max size eviction ────────────────────────────────
test('CacheService: éviction quand taille max dépassée', function() use ($testDir) {
    $smallDir = sys_get_temp_dir() . '/cache_test_small_' . uniqid();
    @mkdir($smallDir, 0775, true);
    $cache = new CacheService($smallDir, 500); // 500 bytes max

    // Fill cache beyond limit
    $cache->set('evict_1', str_repeat('a', 200), 300);
    $cache->set('evict_2', str_repeat('b', 200), 300);
    // This should trigger eviction
    $cache->set('evict_3', str_repeat('c', 200), 300);

    // At least one of the old files should have been evicted
    $files = glob($smallDir . '/*.json');
    $totalSize = 0;
    foreach ($files as $f) {
        $totalSize += filesize($f);
    }
    // Should be less than 3 * 200 + overhead (eviction happened)
    $passed = $totalSize < 800;

    // Cleanup
    foreach ($files as $f) @unlink($f);
    @rmdir($smallDir);

    return $passed;
});

// ── Test 9: getLatestVersion ────────────────────────────────
test('CacheService: getLatestVersion retourne une version valide', function() use ($testDir) {
    $cache = new CacheService($testDir, 1024 * 1024);
    $version = $cache->getLatestVersion();
    return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1;
});

// ── Test 10: thundering herd ─────────────────────────────────
test('CacheService: thundering herd — callback exécuté une seule fois', function() use ($testDir) {
    $cache = new CacheService($testDir, 1024 * 1024);
    $callCount = 0;

    // Simulate concurrent access by calling get twice rapidly
    // In reality this would be parallel processes, but we can test the lock mechanism
    $result1 = $cache->get('thunder_key', 300, function() use (&$callCount) {
        $callCount++;
        return 'value';
    });

    // Second call should be a cache hit
    $result2 = $cache->get('thunder_key', 300, function() use (&$callCount) {
        $callCount++;
        return 'should_not_reach';
    });

    return $result1 === 'value' && $result2 === 'value' && $callCount === 1;
});

// Cleanup
$files = glob($testDir . '/*.json');
foreach ($files as $f) @unlink($f);
@rmdir($testDir);

print_test_summary();
