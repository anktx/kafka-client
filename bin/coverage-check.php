<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Node\File;

require dirname(__DIR__) . '/vendor/autoload.php';

$dumpFile = $argv[1] ?? dirname(__DIR__) . '/.phpunit.cache/coverage.cov';

if (!is_file($dumpFile)) {
    fwrite(\STDERR, sprintf("Coverage dump %s not found. Run `composer coverage` first.\n", $dumpFile));

    exit(1);
}

$coverage = require $dumpFile;

if (!$coverage instanceof CodeCoverage) {
    fwrite(\STDERR, sprintf("File %s is not a PHPUnit --coverage-php dump.\n", $dumpFile));

    exit(1);
}

$report = $coverage->getReport();
$totalExecutableLines = $report->numberOfExecutableLines();

if ($totalExecutableLines === 0) {
    // Пустой отчёт (0/0) без этой проверки проходит гейт «впустую»:
    // например, при сломанном source-фильтре phpunit или устаревшем дампе.
    fwrite(\STDERR, "Coverage report contains no executable lines, the gate would pass vacuously.\n");
    fwrite(\STDERR, "Check that the phpunit source config includes src/ and the dump is fresh.\n");

    exit(1);
}

$uncoveredLines = [];

foreach ($report as $node) {
    if (!$node instanceof File) {
        continue;
    }

    $uncovered = array_keys(array_filter(
        $node->lineCoverageData(),
        static fn(?array $coveringTests): bool => $coveringTests !== null && $coveringTests === [],
    ));
    sort($uncovered);

    if ($uncovered !== []) {
        $uncoveredLines[$node->pathAsString()] = $uncovered;
    }
}

printf(
    "Line coverage: %d/%d (%s)\n",
    $report->numberOfExecutedLines(),
    $totalExecutableLines,
    $report->percentageOfExecutedLines()->asString(),
);

if ($uncoveredLines === []) {
    exit(0);
}

fwrite(\STDERR, "Line coverage gate failed: 100% required, uncovered executable lines found:\n");

foreach ($uncoveredLines as $file => $lines) {
    fwrite(\STDERR, sprintf("  %s: %s\n", $file, implode(', ', $lines)));
}

exit(1);
