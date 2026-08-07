<?php

declare(strict_types=1);

final class TestRunner
{
    /** @var list<array{name: string, callback: callable}> */
    private array $tests = [];

    public function add(string $name, callable $callback): void
    {
        $this->tests[] = [
            'name' => $name,
            'callback' => $callback,
        ];
    }

    public function run(): int
    {
        $failures = [];
        $skipped = [];
        $passed = 0;

        foreach ($this->tests as $test) {
            try {
                ($test['callback'])();
                $passed++;
            } catch (TestSkipped $exception) {
                $skipped[] = [
                    'name' => $test['name'],
                    'message' => $exception->getMessage(),
                ];
            } catch (Throwable $exception) {
                $failures[] = [
                    'name' => $test['name'],
                    'message' => $exception->getMessage(),
                ];
            }
        }

        foreach ($failures as $failure) {
            fwrite(STDERR, "[FAIL] {$failure['name']}\n{$failure['message']}\n\n");
        }

        foreach ($skipped as $skip) {
            echo "[SKIP] {$skip['name']}: {$skip['message']}\n";
        }

        $total = count($this->tests);
        $failed = count($failures);
        echo sprintf("SAPAS regression tests: %d passed, %d failed, %d skipped, %d total\n", $passed, $failed, count($skipped), $total);

        return $failed === 0 ? 0 : 1;
    }
}

final class TestSkipped extends RuntimeException
{
}

function test(string $name, callable $callback): void
{
    global $runner;

    if (!$runner instanceof TestRunner) {
        throw new RuntimeException('Test runner has not been initialized.');
    }

    $runner->add($name, $callback);
}

function skip_test(string $message): void
{
    throw new TestSkipped($message);
}

function assert_true(bool $condition, string $message = 'Expected condition to be true.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_false(bool $condition, string $message = 'Expected condition to be false.'): void
{
    if ($condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = sprintf('Expected %s, got %s.', var_export($expected, true), var_export($actual, true));
        throw new RuntimeException($message === '' ? $detail : $message . ' ' . $detail);
    }
}

function assert_null(mixed $actual, string $message = 'Expected value to be null.'): void
{
    if ($actual !== null) {
        throw new RuntimeException($message . ' Got ' . var_export($actual, true) . '.');
    }
}

function assert_not_null(mixed $actual, string $message = 'Expected value not to be null.'): void
{
    if ($actual === null) {
        throw new RuntimeException($message);
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message === '' ? sprintf('Expected to find "%s".', $needle) : $message);
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message === '' ? sprintf('Did not expect to find "%s".', $needle) : $message);
    }
}

function call_private(object $object, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $arguments);
}

function assert_method_contains(string $file, string $method, string $needle): void
{
    assert_contains($needle, method_source($file, $method), sprintf('Method %s should contain %s.', $method, $needle));
}

function method_source(string $file, string $method): string
{
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException(sprintf('Unable to read %s.', $file));
    }

    $methodOffset = strpos($source, 'function ' . $method . '(');
    if ($methodOffset === false) {
        throw new RuntimeException(sprintf('Method %s was not found in %s.', $method, $file));
    }

    $nextMethodOffset = strpos($source, "\n    public function ", $methodOffset + 1);
    $nextProtectedOffset = strpos($source, "\n    protected function ", $methodOffset + 1);
    $nextPrivateOffset = strpos($source, "\n    private function ", $methodOffset + 1);
    $offsets = array_filter([$nextMethodOffset, $nextProtectedOffset, $nextPrivateOffset], static fn ($offset): bool => $offset !== false);
    $endOffset = $offsets === [] ? strlen($source) : min($offsets);

    return substr($source, $methodOffset, $endOffset - $methodOffset);
}

/** @param list<string> $needles */
function assert_method_order(string $file, string $method, array $needles): void
{
    $methodBody = method_source($file, $method);
    $previousOffset = -1;

    foreach ($needles as $needle) {
        $offset = strpos($methodBody, $needle);
        if ($offset === false) {
            throw new RuntimeException(sprintf('Method %s should contain %s.', $method, $needle));
        }
        if ($offset <= $previousOffset) {
            throw new RuntimeException(sprintf('Method %s should place %s after the preceding security step.', $method, $needle));
        }

        $previousOffset = $offset;
    }
}
