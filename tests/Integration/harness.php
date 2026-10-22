<?php

declare(strict_types=1);

/**
 * Shared runner of the integration tests: loads Nextcloud, counts the checks
 * and makes sure that the exit code tells the truth.
 *
 * Any failure ends the script with exit code 1: a failed check, an uncaught
 * exception or error, a fatal PHP error, or a script that stops before
 * calling finish(). Nextcloud's own error handling would otherwise let a
 * script end with exit code 0 after an uncaught exception.
 *
 * Usage in a test script:
 *   require __DIR__ . '/harness.php';
 *   [$root, $uid] = IntegrationTest::start($argv, ['nextcloud-root', 'uid']);
 *   $check = IntegrationTest::check(...);
 *   ...
 *   IntegrationTest::finish();
 */
final class IntegrationTest {
	private static int $checks = 0;
	private static int $failures = 0;
	private static bool $finished = false;

	/**
	 * Checks the arguments and loads Nextcloud with all apps.
	 *
	 * @param list<string> $argv the script's $argv
	 * @param non-empty-list<string> $names names of the expected arguments; the first is the Nextcloud root
	 * @return non-empty-list<string> the arguments without the script name
	 */
	public static function start(array $argv, array $names): array {
		$script = $argv[0] ?? 'test.php';
		$arguments = array_slice($argv, 1);
		if (count($arguments) < count($names) || in_array('', $arguments, true)) {
			fwrite(STDERR, 'usage: php ' . $script . ' ' . implode(' ', array_map(fn (string $n): string => '<' . $n . '>', $names)) . "\n");
			exit(2);
		}
		require $arguments[0] . '/lib/base.php';
		\OC_App::loadApps();
		// Registered after Nextcloud's handlers, so that these decide the exit code.
		set_exception_handler([self::class, 'uncaught']);
		register_shutdown_function([self::class, 'shutdown']);
		return $arguments;
	}

	public static function check(string $name, bool $ok, string $detail = ''): void {
		self::$checks++;
		echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($detail !== '' ? ' — ' . $detail : '') . "\n";
		if (!$ok) {
			self::$failures++;
		}
	}

	/**
	 * Runs $f and returns the user-facing error it throws (with its
	 * parameters filled in), or null when it does not throw one. Any other
	 * exception is not caught.
	 */
	public static function throws(callable $f): ?string {
		try {
			$f();
		} catch (\OCA\Notenarchiv\Exception\UserFacingException $e) {
			return vsprintf($e->getMessage(), $e->getParameters());
		}
		return null;
	}

	/**
	 * Records an exception that escaped a test section as a failure, so that
	 * the remaining sections can still run.
	 */
	public static function unexpected(\Throwable $e): void {
		self::check('no unexpected error', false, self::describe($e));
	}

	/**
	 * Prints the summary and ends the script: exit code 0 only when at least
	 * one check ran and none failed.
	 */
	public static function finish(): never {
		self::$finished = true;
		if (self::$checks === 0) {
			self::check('at least one check ran', false);
		}
		echo self::$failures === 0
			? "\nall " . self::$checks . " checks passed\n"
			: "\n" . self::$failures . ' of ' . self::$checks . " checks failed\n";
		exit(self::$failures === 0 ? 0 : 1);
	}

	/**
	 * Exception handler: an uncaught exception fails the run.
	 */
	public static function uncaught(\Throwable $e): void {
		self::unexpected($e);
		self::finish();
	}

	/**
	 * Shutdown handler: a fatal error or a script that stopped without
	 * finish() fails the run.
	 */
	public static function shutdown(): void {
		if (self::$finished) {
			return;
		}
		$error = error_get_last();
		$fatal = $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
		self::check($fatal ? 'no fatal error' : 'the test ran to its end', false,
			$fatal ? $error['message'] . ' at ' . $error['file'] . ':' . $error['line'] : 'finish() was not reached');
		self::finish();
	}

	private static function describe(\Throwable $e): string {
		return get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
	}
}
