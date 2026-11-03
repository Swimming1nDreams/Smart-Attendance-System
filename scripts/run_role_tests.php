<?php

declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';
require __DIR__ . '/../tests/Support/TestRunner.php';
require __DIR__ . '/../tests/Support/Fakes.php';
require __DIR__ . '/../tests/Support/QueryCountingPDO.php';
require __DIR__ . '/../tests/Support/TestDatabase.php';

$runner = new TestRunner();

require __DIR__ . '/../tests/Unit/RoleValidationTest.php';
require __DIR__ . '/../tests/Integration/AuthDatabaseIntegrationTest.php';
require __DIR__ . '/../tests/Integration/RoleAccessTest.php';

exit($runner->run());
