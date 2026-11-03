<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Support/TestRunner.php';
require __DIR__ . '/Support/Fakes.php';
require __DIR__ . '/Support/QueryCountingPDO.php';
require __DIR__ . '/Support/TestDatabase.php';

$runner = new TestRunner();
require __DIR__ . '/Regression/ProfileAvatarIntegrationTest.php';
exit($runner->run());
