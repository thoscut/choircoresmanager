<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Tests\Unit\Controller;

use OCA\Notenarchiv\Controller\ApiController;
use OCA\Notenarchiv\Controller\ApiV1StatusController;
use OCA\Notenarchiv\Controller\CalendarController;
use OCA\Notenarchiv\Controller\PageController;
use OCA\Notenarchiv\Controller\PracticeAudioController;
use OCA\Notenarchiv\Controller\TaskController;
use OCA\Notenarchiv\Db\AuditEntryMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\ModuleDisabledException;
use OCA\Notenarchiv\Exception\UnknownLinkException;
use OCA\Notenarchiv\Middleware\ModuleMiddleware;
use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\ConcertCalendarService;
use OCA\Notenarchiv\Service\DueService;
use OCA\Notenarchiv\Service\ExportService;
use OCA\Notenarchiv\Service\FileSyncService;
use OCA\Notenarchiv\Service\ImportService;
use OCA\Notenarchiv\Service\LinkService;
use OCA\Notenarchiv\Service\MetaService;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\PracticeAudioService;
use OCA\Notenarchiv\Service\RightsPolicy;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\ScoreService;
use OCA\Notenarchiv\Service\TaskService;
use OCA\Notenarchiv\Service\UserFiles;
use OCA\Notenarchiv\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
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
 * call what, CSRF, rate limits, brute-force protection, modules), response
 * headers of user files and the content security policy of the app page.
 */
#[CoversClass(ApiController::class)]
#[CoversClass(CalendarController::class)]
#[CoversClass(PageController::class)]
#[CoversClass(TaskController::class)]
#[CoversClass(ApiV1StatusController::class)]
#[CoversClass(ModuleMiddleware::class)]
final class ControllerSecurityTest extends TestCase {
	use ModuleSwitches;

	/** Routes without #[NoAdminRequired]: instance settings, for admins only */
	private const ADMIN_ONLY = [
		'ApiController::settings', 'ApiController::updateSettings', 'EventController::setThresholds',
		'ModuleController::settings', 'ModuleController::update',
	];

	/**
	 * Routes of the core, always on (ROADMAP, guideline 12): catalog, start
	 * page, file sync and manual file links, roles, audit log, import,
	 * export, backup, settings and the personal view. Every other route
	 * names its module (attribute RequiresModule).
	 */
	private const CORE = [
		'PageController::index',
		'ApiController::meta', 'ApiController::entries', 'ApiController::entry', 'ApiController::update',
		'ApiController::import', 'ApiController::fileSync', 'ApiController::export', 'ApiController::exportStore',
		'ApiController::audit', 'ApiController::settings', 'ApiController::updateSettings',
		'WorkflowController::backup', 'WorkflowController::fullBackup', 'WorkflowController::restore',
		'TaskController::due',
		'MaintenanceController::linkFile', 'MaintenanceController::unlinkFile',
		'ModuleController::settings', 'ModuleController::update', 'ModuleController::personalView',
	];

	/**
	 * Plain GET downloads (links, no CSRF token) that change nothing. The CSV
	 * files of the statistics and the annual report and the calendar files of
	 * concerts are such downloads: they only read, like the song order, need a
	 * logged-in account with access and are rate limited. Adding entries to a
	 * calendar (POST) keeps the CSRF check.
	 */
	private const WITHOUT_CSRF = [
		'PageController::index', 'ApiController::export', 'WorkflowController::fullBackup', 'EventController::songOrder',
		'StatisticsController::csv', 'StatisticsController::reportCsv',
		'CalendarController::concertFile', 'CalendarController::upcomingFile',
	];

	/** Expensive endpoints: limited per user (the statistics aggregate the whole archive) */
	private const RATE_LIMITED = [
		'ApiController::import', 'ApiController::fileSync', 'ApiController::export', 'ApiController::exportStore',
		'WorkflowController::backup', 'WorkflowController::fullBackup', 'WorkflowController::restore',
		'EventController::songOrder', 'EventController::importPerformances', 'PersonController::import',
		'PracticeAudioController::authorize',
		'StatisticsController::overview', 'StatisticsController::report', 'StatisticsController::csv',
		'StatisticsController::reportCsv',
		'CalendarController::concertFile', 'CalendarController::upcomingFile', 'CalendarController::addConcert',
		'CalendarController::addUpcoming',
	];

	/**
	 * Routes of the API for agents (OCS, api/v1) and the route of the UI
	 * that does the same, or null for a function the UI has without a
	 * server call (the text reference). Each API route needs the module
	 * `agent_api` plus exactly the modules of its counterpart.
	 *
	 * API-V1-EXTENSION (milestone 0.6): the routes of review points
	 * (Prüfpunkte) and correction orders are added here with their
	 * counterparts.
	 */
	private const API_V1 = [
		'ApiV1StatusController::modules' => 'ApiController::meta',
		'ApiV1CatalogController::search' => 'ApiController::entries',
		'ApiV1CatalogController::copy' => 'ApiController::entry',
		'ApiV1CatalogController::reference' => null,
		'ApiV1TaskController::due' => 'TaskController::due',
		'ApiV1TaskController::tasks' => 'TaskController::tasks',
		'ApiV1TaskController::createTask' => 'TaskController::createTask',
		'ApiV1TaskController::answer' => 'TaskController::answer',
		'ApiV1ProposalController::proposals' => 'WorkflowController::proposals',
		'ApiV1ProposalController::propose' => 'WorkflowController::propose',
		'ApiV1ConcertController::concerts' => 'EventController::list',
		'ApiV1ConcertController::concert' => 'EventController::detail',
		'ApiV1StatisticsController::statistics' => 'StatisticsController::overview',
	];

	/**
	 * @return array<string, array{verb: string, url: string, ocs: bool, attributes: list<class-string>}> "Class::method" => route
	 */
	private static function routes(): array {
		$routes = [];
		foreach (glob(__DIR__ . '/../../../lib/Controller/*Controller.php') ?: [] as $file) {
			$class = 'OCA\\Notenarchiv\\Controller\\' . basename($file, '.php');
			self::assertTrue(is_subclass_of($class, Controller::class), $class);
			foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
				$route = $method->getAttributes(FrontpageRoute::class)[0] ?? $method->getAttributes(ApiRoute::class)[0] ?? null;
				if ($route === null) {
					continue;
				}
				$routes[basename($file, '.php') . '::' . $method->getName()] = [
					'verb' => $route->newInstance()->getVerb(),
					'url' => $route->newInstance()->getUrl(),
					'ocs' => $route->getName() === ApiRoute::class,
					'attributes' => array_map(fn (\ReflectionAttribute $a): string => $a->getName(), $method->getAttributes()),
				];
			}
		}
		self::assertGreaterThan(90, count($routes), 'all controllers were found');
		return $routes;
	}

	/**
	 * Every route of the API for agents: an OCS controller under /api/v1/,
	 * the module `agent_api` plus the modules of the same function in the
	 * UI, for every account with a role (#[NoAdminRequired]; the services
	 * check the roles), limited per account, never public.
	 *
	 * None skips the CSRF check (#[NoCSRFRequired]), and none needs to:
	 * Nextcloud's SecurityMiddleware lets a request to an OCS controller pass
	 * without a CSRF token only when it carries the header
	 * `OCS-APIRequest: true` (or a Bearer token). A foreign web page cannot
	 * send that header with the cookies of a logged-in browser without a
	 * CORS preflight, which these routes do not allow (no #[CORS]). With
	 * #[NoCSRFRequired], such a page could make a logged-in browser create
	 * tasks or proposals with a plain form.
	 */
	public function testEveryApiV1RouteNeedsTheInterfaceItsModuleARoleAndALimit(): void {
		$routes = self::routes();
		$ocs = array_keys(array_filter($routes, fn (array $route): bool => $route['ocs']));
		sort($ocs);
		$expected = array_keys(self::API_V1);
		sort($expected);
		self::assertSame($expected, $ocs, 'every OCS route is listed in API_V1');

		foreach (self::API_V1 as $name => $counterpart) {
			$route = $routes[$name];
			[$class, $method] = explode('::', $name);
			self::assertTrue(is_subclass_of('OCA\\Notenarchiv\\Controller\\' . $class, OCSController::class), $name . ' is an OCS controller');
			self::assertStringStartsWith('/api/v1/', $route['url'], $name);
			self::assertContains(NoAdminRequired::class, $route['attributes'], $name);
			self::assertContains(UserRateLimit::class, $route['attributes'], $name . ' is rate limited');
			self::assertNotContains(NoCSRFRequired::class, $route['attributes'], $name . ' keeps the CSRF check (OCS header)');
			self::assertNotContains(PublicPage::class, $route['attributes'], $name);
			self::assertNotContains(CORS::class, $route['attributes'], $name);

			$modules = ModuleMiddleware::required('OCA\\Notenarchiv\\Controller\\' . $class, $method);
			$expectedModules = [Modules::AGENT_API];
			if ($counterpart !== null) {
				self::assertArrayHasKey($counterpart, $routes, 'counterpart of ' . $name);
				[$counterClass, $counterMethod] = explode('::', $counterpart);
				$expectedModules = [...$expectedModules, ...ModuleMiddleware::required('OCA\\Notenarchiv\\Controller\\' . $counterClass, $counterMethod)];
			}
			sort($modules);
			sort($expectedModules);
			self::assertSame($expectedModules, $modules, $name . ': agent_api plus the module of the function');
		}
	}

	public function testASwitchedOffApiV1RouteAnswers404WithOcsMeta(): void {
		$l = $this->l10n();
		$controller = new ApiV1StatusController($this->createStub(IRequest::class), 'agent', $this->createStub(Roles::class),
			$this->modulesWithout(), $this->createStub(RightsPolicy::class), $l, $this->createStub(LoggerInterface::class));
		(new ModuleMiddleware($this->modulesWithout(), $l))->beforeController($controller, 'modules');

		$middleware = new ModuleMiddleware($this->modulesWithout([Modules::AGENT_API]), $l);
		try {
			$middleware->beforeController($controller, 'modules');
			self::fail('the interface for agents is off');
		} catch (ModuleDisabledException $e) {
			try {
				$middleware->afterException($controller, 'modules', $e);
				self::fail('an OCS route gets an OCS exception, which Nextcloud turns into the OCS envelope');
			} catch (OCSNotFoundException $ocs) {
				self::assertSame(Http::STATUS_NOT_FOUND, $ocs->getCode());
				self::assertSame('This function is switched off.', $ocs->getMessage());
			}
		}
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

	public function testEveryRouteIsCoreOrBelongsToAModule(): void {
		$routes = self::routes();
		foreach (self::CORE as $name) {
			self::assertArrayHasKey($name, $routes, 'core route exists');
		}
		foreach (array_keys($routes) as $name) {
			[$class, $method] = explode('::', $name);
			$modules = ModuleMiddleware::required('OCA\\Notenarchiv\\Controller\\' . $class, $method);
			if (in_array($name, self::CORE, true)) {
				self::assertSame([], $modules, $name . ' belongs to the core');
				continue;
			}
			self::assertNotSame([], $modules, $name . ' is neither core nor part of a module');
			foreach ($modules as $module) {
				self::assertTrue(Modules::exists($module), $name . ': unknown module ' . $module);
				self::assertFalse(Modules::isPlanned($module), $name . ': planned module ' . $module);
			}
		}
	}

	public function testRoutesOfASwitchedOffModuleAnswer404(): void {
		$l = $this->l10n();
		$controller = new PracticeAudioController($this->createStub(IRequest::class), 'carla', $this->createStub(PracticeAudioService::class),
			$l, $this->createStub(LoggerInterface::class));

		$middleware = new ModuleMiddleware($this->modulesWithout(), $l);
		$middleware->beforeController($controller, 'authorize');

		$middleware = new ModuleMiddleware($this->modulesWithout(['playback']), $l);
		try {
			$middleware->beforeController($controller, 'authorize');
			self::fail('practice audio needs playback');
		} catch (ModuleDisabledException $e) {
			$response = $middleware->afterException($controller, 'authorize', $e);
			self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			self::assertInstanceOf(JSONResponse::class, $response);
			self::assertSame(['error' => 'This function is switched off.'], $response->getData());
		}

		$other = new \RuntimeException('not mine');
		$this->expectExceptionObject($other);
		$middleware->afterException($controller, 'authorize', $other);
	}

	public function testCoreRoutesStayOnWithEveryModuleOff(): void {
		$middleware = new ModuleMiddleware($this->modules(array_fill_keys(Modules::switchable(), false)), $this->l10n());
		$controller = new TaskController($this->createStub(IRequest::class), 'carla', $this->createStub(Roles::class),
			$this->createStub(TaskService::class), $this->createStub(LinkService::class), $this->createStub(DueService::class), $this->l10n(),
			$this->createStub(LoggerInterface::class));
		$middleware->beforeController($controller, 'due');
		$this->expectException(ModuleDisabledException::class);
		$middleware->beforeController($controller, 'tasks');
	}

	public function testASwitchedOffPartOfAnActionAnswers404(): void {
		$practiceAudio = $this->createStub(PracticeAudioService::class);
		$practiceAudio->method('authorize')->willThrowException(new ModuleDisabledException('practice_audio'));
		$controller = new PracticeAudioController($this->createStub(IRequest::class), 'carla', $practiceAudio, $this->l10n(),
			$this->createStub(LoggerInterface::class));
		$response = $controller->authorize(3);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'This function is switched off.'], $response->getData());
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

	public function testCalendarFilesAreRefusedWithoutAccess(): void {
		$calendar = $this->createStub(ConcertCalendarService::class);
		$calendar->method('upcomingFile')->willThrowException(new AccessDeniedException('You have no access to the music archive.'));
		$controller = new CalendarController($this->createStub(IRequest::class), 'stranger', $calendar, $this->l10n(),
			$this->createStub(LoggerInterface::class));
		$response = $controller->upcomingFile();
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(['error' => 'You have no access to the music archive.'], $response->getData());

		$loggedOut = new CalendarController($this->createStub(IRequest::class), null, $calendar, $this->l10n(),
			$this->createStub(LoggerInterface::class));
		self::assertSame(Http::STATUS_UNAUTHORIZED, $loggedOut->upcomingFile()->getStatus());
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
