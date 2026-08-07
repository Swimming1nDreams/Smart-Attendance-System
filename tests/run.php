<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Support/TestRunner.php';
require __DIR__ . '/Support/Fakes.php';
require __DIR__ . '/Support/QueryCountingPDO.php';
require __DIR__ . '/Support/TestDatabase.php';

$runner = new TestRunner();

$testFiles = array_merge(
    glob(__DIR__ . '/Unit/*Test.php') ?: [],
    glob(__DIR__ . '/Regression/*Test.php') ?: [],
    glob(__DIR__ . '/Integration/*Test.php') ?: []
);

sort($testFiles);

foreach ($testFiles as $testFile) {
    require $testFile;
}

exit($runner->run());
