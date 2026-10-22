<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Tests\Unit\Controller;

use OCA\Notenarchiv\Controller\ApiController;
use OCA\Notenarchiv\Controller\PageController;
use OCA\Notenarchiv\Controller\TaskController;
use OCA\Notenarchiv\Db\AuditEntryMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\UnknownLinkException;
use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\DueService;
use OCA\Notenarchiv\Service\ExportService;
use OCA\Notenarchiv\Service\FileSyncService;
use OCA\Notenarchiv\Service\ImportService;
use OCA\Notenarchiv\Service\LinkService;
use OCA\Notenarchiv\Service\MetaService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\ScoreService;
use OCA\Notenarchiv\Service\TaskService;
use OCA\Notenarchiv\Service\UserFiles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Security-relevant properties of the controllers: route attributes (who may
 * call what, CSRF, rate limits, brute-force protection), response headers of
 * user files and the content security policy of the app page.
 */
#[CoversClass(ApiController::class)]
#[CoversClass(PageController::class)]
#[CoversClass(TaskController::class)]
final class ControllerSecurityTest extends TestCase {
	/** Routes without #[NoAdminRequired]: instance settings, for admins only */
	private const ADMIN_ONLY = [
		'ApiController::settings', 'ApiController::updateSettings', 'EventController::setThresholds',
	];

	/**
	 * Plain GET downloads (links, no CSRF token) that change nothing. The CSV
	 * files of the statistics and the annual report are such downloads: they
	 * only read, like the song order.
	 */
	private const WITHOUT_CSRF = [
		'PageController::index', 'ApiController::export', 'WorkflowController::fullBackup', 'EventController::songOrder',
		'StatisticsController::csv', 'StatisticsController::reportCsv',
	];

	/** Expensive endpoints: limited per user (the statistics aggregate the whole archive) */
	private const RATE_LIMITED = [
		'ApiController::import', 'ApiController::fileSync', 'ApiController::export', 'ApiController::exportStore',
		'WorkflowController::backup', 'WorkflowController::fullBackup', 'WorkflowController::restore',
		'EventController::songOrder', 'EventController::importPerformances', 'PersonController::import',
		'PracticeAudioController::authorize',
		'StatisticsController::overview', 'StatisticsController::report', 'StatisticsController::csv',
		'StatisticsController::reportCsv',
	];

	/**
	 * @return array<string, array{verb: string, attributes: list<class-string>}> "Class::method" => route
	 */
	private static function routes(): array {
		$routes = [];
		foreach (glob(__DIR__ . '/../../../lib/Controller/*Controller.php') ?: [] as $file) {
			$class = 'OCA\\Notenarchiv\\Controller\\' . basename($file, '.php');
			self::assertTrue(is_subclass_of($class, Controller::class), $class);
			foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
				$route = $method->getAttributes(FrontpageRoute::class)[0] ?? null;
				if ($route === null) {
					continue;
				}
				$routes[basename($file, '.php') . '::' . $method->getName()] = [
					'verb' => $route->newInstance()->getVerb(),
					'attributes' => array_map(fn (\ReflectionAttribute $a): string => $a->getName(), $method->getAttributes()),
				];
			}
		}
		self::assertGreaterThan(80, count($routes), 'all controllers were found');
		return $routes;
	}

	#[CoversNothing]
	public function testNoRouteIsPublic(): void {
		foreach (self::routes() as $name => $route) {
			self::assertNotContains(PublicPage::class, $route['attributes'], $name);
		}
	}

	#[CoversNothing]
	public function testOnlyAdminSettingsLackNoAdminRequired(): void {
		$adminOnly = array_keys(array_filter(self::routes(), fn (array $r): bool => !in_array(NoAdminRequired::class, $r['attributes'], true)));
		sort($adminOnly);
		$expected = self::ADMIN_ONLY;
		sort($expected);
		self::assertSame($expected, $adminOnly);
	}

	#[CoversNothing]
	public function testCsrfIsOnlySkippedForPlainDownloads(): void {
		$withoutCsrf = array_filter(self::routes(), fn (array $r): bool => in_array(NoCSRFRequired::class, $r['attributes'], true));
		foreach ($withoutCsrf as $name => $route) {
			self::assertSame('GET', $route['verb'], $name . ' changes state and must keep the CSRF check');
		}
		$names = array_keys($withoutCsrf);
		sort($names);
		$expected = self::WITHOUT_CSRF;
		sort($expected);
		self::assertSame($expected, $names);
	}

	#[CoversNothing]
	public function testExpensiveEndpointsAreRateLimited(): void {
		$routes = self::routes();
		foreach (self::RATE_LIMITED as $name) {
			self::assertArrayHasKey($name, $routes);
			self::assertContains(UserRateLimit::class, $routes[$name]['attributes'], $name);
		}
	}

	#[CoversNothing]
	public function testResolvingALinkHasBruteForceProtection(): void {
		self::assertContains(BruteForceProtection::class, self::routes()['TaskController::resolveLink']['attributes']);
	}

	private function taskController(LinkService $links): TaskController {
		return new TaskController($this->createStub(IRequest::class), 'conductor', $this->createStub(Roles::class),
			$this->createStub(TaskService::class), $links, $this->createStub(DueService::class), $this->l10n(),
			$this->createStub(LoggerInterface::class));
	}

	private function l10n(): IL10N {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return $l;
	}

	public function testAnUnknownTokenIsThrottled(): void {
		$links = $this->createStub(LinkService::class);
		$links->method('resolve')->willThrowException(new UnknownLinkException('This link does not exist.'));
		$response = $this->taskController($links)->resolveLink(str_repeat('a', 32));
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'This link does not exist.'], $response->getData());
		self::assertTrue($response->isThrottled());
	}

	public function testAnExpiredLinkIsNotThrottled(): void {
		$links = $this->createStub(LinkService::class);
		$links->method('resolve')->willThrowException(new InvalidInputException('This link has expired.'));
		$response = $this->taskController($links)->resolveLink(str_repeat('a', 32));
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertFalse($response->isThrottled());
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function headers(Response $response): array {
		// Response::getHeaders() needs a running server; the headers set by the
		// controller are enough here.
		$headers = (new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
		self::assertIsArray($headers);
		return $headers;
	}

	public function testScoreFilesAreNeverShownInline(): void {
		$roles = $this->createStub(Roles::class);
		$scores = $this->createStub(ScoreService::class);
		$scores->method('content')->willReturn(['content' => '<score-partwise/>', 'mime' => 'application/vnd.recordare.musicxml+xml',
			'name' => '5-01_<script>.musicxml', 'type' => 'musicxml']);
		$controller = new ApiController($this->createStub(IRequest::class), 'reader', $roles, $this->createStub(MetaService::class),
			$this->createStub(CatalogService::class), $this->createStub(ImportService::class), $this->createStub(ExportService::class),
			$this->createStub(FileSyncService::class), $this->createStub(AppSettings::class), $this->createStub(AuditEntryMapper::class),
			$this->createStub(UserFiles::class), $this->createStub(ITimeFactory::class), $this->createStub(IDateTimeZone::class), $scores,
			$this->l10n(), $this->createStub(LoggerInterface::class));

		$response = $controller->fileContent(7);
		$headers = self::headers($response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertIsString($headers['Content-Disposition'] ?? null);
		self::assertSame('attachment; filename="score.musicxml"', $headers['Content-Disposition']);
		self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
		self::assertSame('application/vnd.recordare.musicxml+xml', $headers['Content-Type'] ?? null);
		self::assertStringContainsString("default-src 'none'", $response->getContentSecurityPolicy()->buildPolicy());
		self::assertSame('<score-partwise/>', $response->render());
	}

	public function testTheAppPageAllowsWebAssemblyButNoScriptEval(): void {
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAccess')->willReturn(true);
		$meta = $this->createStub(MetaService::class);
		$meta->method('forUser')->willReturn([]);
		$controller = new PageController($this->createStub(IRequest::class), 'reader', $roles, $meta,
			$this->createStub(IInitialState::class), $this->l10n());

		$response = $controller->index();
		self::assertInstanceOf(TemplateResponse::class, $response);
		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		self::assertStringContainsString("'wasm-unsafe-eval'", $policy);
		self::assertStringNotContainsString("'unsafe-eval'", $policy);
		self::assertStringNotContainsString('http', $policy, 'no external sources');
	}
}
