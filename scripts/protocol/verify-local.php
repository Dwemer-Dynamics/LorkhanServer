<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifest = json_decode(file_get_contents($root . '/protocol/MANIFEST.json'), true, 64, JSON_THROW_ON_ERROR);
$seen = [];
foreach ($manifest['files'] as $entry) {
    $relative = preg_replace('#^lorkhan/#', '', $entry['path']);
    $file = $root . '/protocol/' . $relative;
    if (!is_file($file)) throw new RuntimeException('Missing protocol file: ' . $relative);
    if (isset($seen[$relative])) throw new RuntimeException('Duplicate manifest path: ' . $relative);
    $seen[$relative] = true;
    if (filesize($file) !== $entry['bytes']) throw new RuntimeException('Byte count mismatch: ' . $relative);
    if (!hash_equals($entry['sha256'], hash_file('sha256', $file))) throw new RuntimeException('Checksum mismatch: ' . $relative);
    json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
}
$actual = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/protocol', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'json' && !in_array($file->getFilename(), ['MANIFEST.json'], true)) {
        $actual[str_replace($root . '/protocol/', '', $file->getPathname())] = true;
    }
}
if (array_diff_key($actual, $seen) || array_diff_key($seen, $actual)) throw new RuntimeException('Manifest inventory mismatch.');
fwrite(STDOUT, count($seen) . " protocol files match local manifest\n");
