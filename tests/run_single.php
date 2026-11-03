<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Support/TestRunner.php';
require __DIR__ . '/Support/Fakes.php';
require __DIR__ . '/Support/QueryCountingPDO.php';
require __DIR__ . '/Support/TestDatabase.php';

$runner = new TestRunner();

require __DIR__ . '/Regression/PaginationEnhancementTest.php';
require __DIR__ . '/Regression/ReportAccessTest.php';
require __DIR__ . '/Regression/TablePresentationTest.php';
require __DIR__ . '/Regression/MyWorkPolicyAccessTest.php';

exit($runner->run());
