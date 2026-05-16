<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Parsing\Dbml\JsonSnapshotStore;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;

it('writes and reads a snapshot', function () {
    $dir = sys_get_temp_dir().'/snapshot_test_'.uniqid();
    mkdir($dir, 0755, true);
    $dbmlPath = $dir.'/schema.dbml';
    file_put_contents($dbmlPath, '');

    $store = new JsonSnapshotStore;
    $payload = [
        'version' => 1,
        'generated_at' => '2026-05-16T10:00:00Z',
        'tables' => [],
        'enums' => [],
        'refs' => [],
    ];

    $store->write($dbmlPath, $payload);

    expect($store->exists($dbmlPath))->toBeTrue();

    $schema = $store->read($dbmlPath);
    expect($schema)->toBeInstanceOf(Schema::class);

    // cleanup
    unlink($dir.'/.dbml-sync.json');
    unlink($dbmlPath);
    rmdir($dir);
});

it('returns null when no snapshot exists', function () {
    $dir = sys_get_temp_dir().'/snapshot_test_'.uniqid();
    mkdir($dir, 0755, true);
    $dbmlPath = $dir.'/schema.dbml';
    file_put_contents($dbmlPath, '');

    $store = new JsonSnapshotStore;

    expect($store->exists($dbmlPath))->toBeFalse();
    expect($store->read($dbmlPath))->toBeNull();

    // cleanup
    unlink($dbmlPath);
    rmdir($dir);
});

it('throws on version mismatch', function () {
    $dir = sys_get_temp_dir().'/snapshot_test_'.uniqid();
    mkdir($dir, 0755, true);
    $dbmlPath = $dir.'/schema.dbml';
    file_put_contents($dbmlPath, '');

    // Write an incompatible snapshot directly (bypassing write() which always stamps version=1)
    $payload = [
        'version' => 99,
        'generated_at' => '2026-05-16T10:00:00Z',
        'tables' => [],
        'enums' => [],
        'refs' => [],
    ];
    file_put_contents($dir.'/.dbml-sync.json', json_encode($payload, JSON_PRETTY_PRINT));

    $store = new JsonSnapshotStore;
    $store->read($dbmlPath);
})->throws(RuntimeException::class, 'Incompatible snapshot version');

it('stores snapshot adjacent to DBML file', function () {
    $dir = sys_get_temp_dir().'/snapshot_test_'.uniqid();
    mkdir($dir, 0755, true);
    $dbmlPath = $dir.'/schema.dbml';
    file_put_contents($dbmlPath, '');

    $store = new JsonSnapshotStore;
    $store->write($dbmlPath, [
        'version' => 1,
        'generated_at' => '2026-05-16T10:00:00Z',
        'tables' => [],
        'enums' => [],
        'refs' => [],
    ]);

    expect(file_exists($dir.'/.dbml-sync.json'))->toBeTrue();

    // cleanup
    unlink($dir.'/.dbml-sync.json');
    unlink($dbmlPath);
    rmdir($dir);
});