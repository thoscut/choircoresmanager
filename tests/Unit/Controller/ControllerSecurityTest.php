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
use OCA\Notenarchiv\Controller\FilesIntegrationController;
use OCA\Notenarchiv\Controller\MemberController;
use OCA\Notenarchiv\Controller\PageController;
use OCA\Notenarchiv\Controller\PracticeAudioController;
use OCA\Notenarchiv\Controller\PublicLinkController;
use OCA\Notenarchiv\Controller\PublicRepertoireController;
use OCA\Notenarchiv\Controller\ReviewController;
use OCA\Notenarchiv\Controller\SettingsController;
use OCA\Notenarchiv\Controller\TaskController;
use OCA\Notenarchiv\Db\AuditEntryMapper;
use OCA\Notenarchiv\Exception\AccessDeniedException;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Exception\ModuleDisabledException;
use OCA\Notenarchiv\Exception\NotFoundException;
use OCA\Notenarchiv\Exception\NotSharedException;
use OCA\Notenarchiv\Exception\UnknownLinkException;
use OCA\Notenarchiv\Middleware\ModuleMiddleware;
use OCA\Notenarchiv\Service\AppSettings;
use OCA\Notenarchiv\Service\ArchiveAccess;
use OCA\Notenarchiv\Service\CatalogService;
use OCA\Notenarchiv\Service\ConcertCalendarService;
use OCA\Notenarchiv\Service\CorrectionService;
use OCA\Notenarchiv\Service\DueService;
use OCA\Notenarchiv\Service\ExportService;
use OCA\Notenarchiv\Service\FilesIntegrationService;
use OCA\Notenarchiv\Service\FileSyncService;
use OCA\Notenarchiv\Service\FullBackupService;
use OCA\Notenarchiv\Service\ImportService;
use OCA\Notenarchiv\Service\LinkService;
use OCA\Notenarchiv\Service\MetaService;
use OCA\Notenarchiv\Service\Modules;
use OCA\Notenarchiv\Service\PracticeAudioService;
use OCA\Notenarchiv\Service\PublicRepertoireService;
use OCA\Notenarchiv\Service\RepertoireService;
use OCA\Notenarchiv\Service\ReviewService;
use OCA\Notenarchiv\Service\RightsPolicy;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\ScanService;
use OCA\Notenarchiv\Service\ScoreService;
use OCA\Notenarchiv\Service\SharedLinkService;
use OCA\Notenarchiv\Service\TaskService;
use OCA\Notenarchiv\Service\UserFiles;
use OCA\Notenarchiv\Settings\Admin;
use OCA\Notenarchiv\Tests\Unit\Support\ModuleSwitches;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\SubAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;
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
#[CoversClass(FilesIntegrationController::class)]
#[CoversClass(PageController::class)]
#[CoversClass(SettingsController::class)]
#[CoversClass(TaskController::class)]
#[CoversClass(ApiV1StatusController::class)]
#[CoversClass(ModuleMiddleware::class)]
#[CoversClass(ReviewController::class)]
#[CoversClass(MemberController::class)]
#[CoversClass(PublicLinkController::class)]
#[CoversClass(PublicRepertoireController::class)]
final class ControllerSecurityTest extends TestCase {
	use ModuleSwitches;

	/**
	 * Routes without #[NoAdminRequired]: the settings of the instance. Each
	 * carries #[AuthorizedAdminSetting(settings: Admin::class)]: admins, and
	 * the groups the administration has given the page "Administration →
	 * Music archive" (Administration privileges), nobody else.
	 */
	private const SETTINGS = [
		'SettingsController::index', 'SettingsController::update', 'SettingsController::groups', 'SettingsController::access',
		'EventController::setThresholds', 'ModuleController::settings', 'ModuleController::update',
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
		'ApiController::audit',
		'SettingsController::index', 'SettingsController::update', 'SettingsController::groups', 'SettingsController::access',
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
		// The scan beside the score is opened by the browser itself (frame or window).
		'ReviewController::scan',
		// The public pages (milestone 0.7) are opened by the browser, without login.
		'PublicLinkController::show', 'PublicRepertoireController::index',
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
		'CalendarController::addUpcoming', 'SettingsController::access',
		'ReviewController::ingest', 'ReviewController::correct', 'ReviewController::scan',
		// answers only for files the account sees; limited against trying file ids all the same
		'FilesIntegrationController::copyOfFile',
		// the routes of the choir members and the public pages (milestone 0.7)
		'MemberController::score', 'MemberController::practiceAudio',
		'PublicLinkController::show', 'PublicRepertoireController::index',
	];

	/**
	 * The only public routes (#[PublicPage], milestone 0.7) and their module:
	 * the page of a public link and the public repertoire page. Each is the
	 * only route of its controller, so that the exception cannot grow by a
	 * method added later.
	 */
	private const PUBLIC_ROUTES = [
		'PublicLinkController::show' => 'public_links',
		'PublicRepertoireController::index' => 'public_repertoire',
	];

	/**
	 * The routes a choir member without an archive role reaches (milestone
	 * 0.7): the app page, which shows them only "Shared with me", and the
	 * routes of MemberController. Every other route checks for an archive
	 * role: Roles::hasAccess() and Roles::rolesOf() leave the choir members
	 * out (testChoirMembersHoldNoArchiveRole).
	 */
	private const MEMBER_ROUTES = [
		'PageController::index' => [],
		'MemberController::score' => ['member_links'],
		'MemberController::practiceAudio' => ['member_links', 'member_practice_audio'],
	];

	/**
	 * Routes of the API for agents (OCS, api/v1) and the route of the UI
	 * that does the same, or null for a function the UI has without a
	 * server call (the text reference). Each API route needs the module
	 * `agent_api` plus exactly the modules of its counterpart.
	 *
	 * The review of transcriptions has no counterpart for deciding review
	 * points (ReviewController::decide) or saving corrections
	 * (ReviewController::correct): people do that in the app.
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
		'ApiV1ReviewController::review' => 'ReviewController::review',
		'ApiV1ReviewController::orders' => 'ReviewController::orders',
		'ApiV1ReviewController::submitReport' => 'ReviewController::ingest',
	];

	/**
	 * Routes of the UI that decide or change what exists: the API for agents
	 * must never offer them (guideline 1, people decide). Checked by their
	 * path, so that no API route reaches them under another name.
	 */
	private const NEVER_FOR_AGENTS = [
		'ReviewController::decide', 'ReviewController::correct',
	];

	/**
	 * @return array<string, array{verb: string, url: string, ocs: bool, attributes: list<class-string>, settings: list<string>}> "Class::method" => route
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
					// The settings whose holders may call the route besides admins
					'settings' => array_map(fn (\ReflectionAttribute $a): string => $a->newInstance()->getSettings(),
						$method->getAttributes(AuthorizedAdminSetting::class)),
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

	/**
	 * Agents read, ask, answer, propose and hand in reports; they never
	 * decide a review point, save a correction or confirm anything. The
	 * writing routes of api/v1 are exactly these four.
	 */
	#[CoversNothing]
	public function testNoApiV1RouteDecidesOrCorrects(): void {
		$routes = self::routes();
		foreach (self::NEVER_FOR_AGENTS as $name) {
			self::assertArrayHasKey($name, $routes, 'the route of the UI exists');
			self::assertNotContains($name, self::API_V1, $name . ' has no counterpart in api/v1');
		}
		$writing = array_filter($routes, fn (array $route): bool => $route['ocs'] && $route['verb'] !== 'GET');
		foreach ($writing as $name => $route) {
			self::assertDoesNotMatchRegularExpression('/decision|decide|corrections|confirm|valid|licen|close/', $route['url'], $name);
		}
		$names = array_keys($writing);
		sort($names);
		self::assertSame(['ApiV1ProposalController::propose', 'ApiV1ReviewController::submitReport', 'ApiV1TaskController::answer',
			'ApiV1TaskController::createTask'], $names, 'the only writing routes of api/v1');
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

	/**
	 * Every route needs a login, except the two public pages: each needs its
	 * module, is a plain GET, limited per address and per account, protected
	 * against guessing (brute force) and never an OCS or CORS route.
	 */
	#[CoversNothing]
	public function testOnlyTheSharingPagesArePublic(): void {
		$routes = self::routes();
		$public = array_keys(array_filter($routes, fn (array $route): bool => in_array(PublicPage::class, $route['attributes'], true)));
		sort($public);
		$expected = array_keys(self::PUBLIC_ROUTES);
		sort($expected);
		self::assertSame($expected, $public, 'the only public routes');
		foreach (self::PUBLIC_ROUTES as $name => $module) {
			$route = $routes[$name];
			[$class, $method] = explode('::', $name);
			self::assertSame('GET', $route['verb'], $name);
			self::assertFalse($route['ocs'], $name);
			self::assertSame([$module], ModuleMiddleware::required('OCA\\Notenarchiv\\Controller\\' . $class, $method), $name . ' needs its module');
			self::assertContains(AnonRateLimit::class, $route['attributes'], $name . ' is limited per address');
			self::assertContains(UserRateLimit::class, $route['attributes'], $name . ' is limited per account');
			self::assertContains(BruteForceProtection::class, $route['attributes'], $name);
			self::assertNotContains(CORS::class, $route['attributes'], $name);
			$others = array_filter(array_keys($routes), fn (string $other): bool => str_starts_with($other, $class . '::') && $other !== $name);
			self::assertSame([], array_values($others), $class . ' has no other route');
		}
	}

	/**
	 * Choir members are no archive role: an account that is only in a group
	 * of the choir members has no access to the archive, so every route that
	 * checks Roles::hasAccess(), requireAccess() or requireRole() refuses it.
	 */
	public function testChoirMembersHoldNoArchiveRole(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturnCallback(fn (string $app, string $key): array => $key === 'groups_member' ? ['choir'] : []);
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => $uid === 'singer' && $group === 'choir');
		$roles = new Roles($config, $groups, $this->createStub(IUserManager::class), $this->createStub(ISettingsManager::class), $this->createStub(LoggerInterface::class));
		self::assertTrue($roles->isMember('singer'));
		self::assertSame([], $roles->rolesOf('singer'));
		self::assertFalse($roles->hasAccess('singer'));
		self::assertNotContains(Roles::MEMBER, Roles::ALL);
		self::assertContains(Roles::MEMBER, Roles::GROUP_ROLES);
		$this->expectException(AccessDeniedException::class);
		$roles->requireAccess('singer');
	}

	/**
	 * The routes a choir member reaches are exactly MEMBER_ROUTES: the routes
	 * of MemberController (with their modules, rate limits, CSRF check, never
	 * public) and the app page; no other controller asks Roles::isMember().
	 */
	#[CoversNothing]
	public function testOnlyTheMemberRoutesLetChoirMembersIn(): void {
		$routes = self::routes();
		$memberController = array_values(array_filter(array_keys($routes), fn (string $name): bool => str_starts_with($name, 'MemberController::')));
		sort($memberController);
		$expected = array_values(array_filter(array_keys(self::MEMBER_ROUTES), fn (string $name): bool => str_starts_with($name, 'MemberController::')));
		sort($expected);
		self::assertSame($expected, $memberController);
		foreach (self::MEMBER_ROUTES as $name => $modules) {
			self::assertArrayHasKey($name, $routes);
			[$class, $method] = explode('::', $name);
			$required = ModuleMiddleware::required('OCA\\Notenarchiv\\Controller\\' . $class, $method);
			sort($required);
			self::assertSame($modules, $required, $name);
			self::assertNotContains(PublicPage::class, $routes[$name]['attributes'], $name);
			if ($class === 'MemberController') {
				self::assertContains(UserRateLimit::class, $routes[$name]['attributes'], $name);
				self::assertNotContains(NoCSRFRequired::class, $routes[$name]['attributes'], $name . ' keeps the CSRF check');
			}
		}
		foreach (glob(__DIR__ . '/../../../lib/Controller/*.php') ?: [] as $file) {
			$asksForMembers = str_contains((string)file_get_contents($file), 'isMember(')
				|| str_contains((string)file_get_contents($file), '->forMember(') || str_contains((string)file_get_contents($file), '->memberScore(');
			self::assertSame(in_array(basename($file, '.php'), ['PageController', 'MemberController'], true), $asksForMembers,
				basename($file) . ': only the app page and MemberController serve choir members');
		}
	}

	private function pageController(bool $access, bool $member, bool $moduleOn, ?SharedLinkService $shared = null): PageController {
		$roles = $this->createStub(Roles::class);
		$roles->method('hasAccess')->willReturn($access);
		$roles->method('isMember')->willReturn($member);
		$meta = $this->createStub(MetaService::class);
		$meta->method('forUser')->willReturn([]);
		return new PageController($this->createStub(IRequest::class), 'singer', $roles, $meta, $this->createStub(IInitialState::class),
			$this->l10n(), $moduleOn ? $this->modulesWithout() : $this->modulesWithout([Modules::MEMBER_LINKS]),
			$shared ?? $this->createStub(SharedLinkService::class));
	}

	public function testChoirMembersGetOnlyTheirPage(): void {
		$shared = $this->createMock(SharedLinkService::class);
		$shared->expects(self::once())->method('forMember')->with('singer')->willReturn([]);
		$response = $this->pageController(false, true, true, $shared)->index();
		self::assertSame('member', $response->getTemplateName(), 'the page "Shared with me", not the archive');
		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		self::assertStringContainsString("'wasm-unsafe-eval'", $policy);
		self::assertStringNotContainsString("'unsafe-eval'", $policy);
		self::assertStringNotContainsString('frame-src', $policy, 'no scan beside the score for choir members');

		$off = $this->pageController(false, true, false)->index();
		self::assertSame(Http::STATUS_FORBIDDEN, $off->getStatus(), 'module switched off: no page');
		$stranger = $this->pageController(false, false, true)->index();
		self::assertSame(Http::STATUS_FORBIDDEN, $stranger->getStatus());
		$both = $this->pageController(true, true, true)->index();
		self::assertSame('index', $both->getTemplateName(), 'an archive role gets the archive');
	}

	private function publicLinkController(?array $excerpt): PublicLinkController {
		$shared = $this->createStub(SharedLinkService::class);
		$shared->method('publicExcerpt')->willReturn($excerpt);
		return new PublicLinkController($this->createStub(IRequest::class), $shared, $this->createStub(IInitialState::class), $this->l10n(),
			$this->createStub(LoggerInterface::class));
	}

	/**
	 * Unknown, expired, revoked or withdrawn links, a malformed token and a
	 * switched-off module get the same 404 page, marked for the brute-force
	 * protection, without a reason.
	 */
	public function testPublicLinksAnswerTheSame404ForEveryFailure(): void {
		$excerpt = ['title' => 'Der Mond ist aufgegangen', 'composer' => 'J. A. P. Schulz', 'measureFrom' => '1', 'measureTo' => '4',
			'voice' => 'Alto', 'verse' => 1, 'tempo' => null, 'canPlay' => true, 'xml' => '<score-partwise/>'];
		// The page itself needs the running server (PublicTemplateResponse);
		// the integration test opens it. Its policy: as the app page, without frames.
		$policy = PublicLinkController::policy()->buildPolicy();
		self::assertStringContainsString("'wasm-unsafe-eval'", $policy);
		self::assertStringNotContainsString("'unsafe-eval'", $policy);
		self::assertStringNotContainsString('http', $policy, 'no external sources');
		self::assertStringNotContainsString('frame-src', $policy);

		$failures = [
			'unknown or refused' => $this->publicLinkController(null)->show(str_repeat('a', 32)),
			'malformed' => $this->publicLinkController($excerpt)->show('../../etc'),
		];
		$controller = $this->publicLinkController($excerpt);
		$middleware = new ModuleMiddleware($this->modulesWithout([Modules::PUBLIC_LINKS]), $this->l10n());
		try {
			$middleware->beforeController($controller, 'show');
			self::fail('the module is off');
		} catch (ModuleDisabledException $e) {
			$failures['module off'] = $middleware->afterException($controller, 'show', $e);
		}
		foreach ($failures as $case => $response) {
			self::assertInstanceOf(NotFoundResponse::class, $response, $case);
			self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $case);
			self::assertTrue($response->isThrottled(), $case . ' counts as a failed attempt');
			self::assertSame([], $response instanceof TemplateResponse ? $response->getParams() : null, $case . ': no reason given');
		}

		$repertoire = new PublicRepertoireController($this->createStub(IRequest::class), $this->createStub(PublicRepertoireService::class),
			$this->l10n(), $this->createStub(LoggerInterface::class));
		$middleware = new ModuleMiddleware($this->modulesWithout([Modules::PUBLIC_REPERTOIRE]), $this->l10n());
		try {
			$middleware->beforeController($repertoire, 'index');
			self::fail('the module is off');
		} catch (ModuleDisabledException $e) {
			self::assertInstanceOf(NotFoundResponse::class, $middleware->afterException($repertoire, 'index', $e));
		}
		$service = $this->createStub(PublicRepertoireService::class);
		$service->method('entries')->willThrowException(new \RuntimeException('database gone'));
		$broken = new PublicRepertoireController($this->createStub(IRequest::class), $service, $this->l10n(), $this->createStub(LoggerInterface::class));
		self::assertInstanceOf(NotFoundResponse::class, $broken->index(), 'an error tells nothing either');
	}

	public function testMemberRoutesRefuseOthersAndThrottleUnknownTokens(): void {
		$shared = $this->createStub(SharedLinkService::class);
		$shared->method('memberScore')->willReturnCallback(function (string $token, string $uid): string {
			if ($uid !== 'singer') {
				throw new AccessDeniedException('This page is only for choir members. Roles are linked to groups; please ask an administrator.');
			}
			if ($token === str_repeat('u', 32)) {
				throw new UnknownLinkException('This link does not exist.');
			}
			return '<score-partwise/>';
		});
		$controller = fn (?string $uid): MemberController => new MemberController($this->createStub(IRequest::class), $uid, $shared,
			$this->createStub(PracticeAudioService::class), $this->l10n(), $this->createStub(LoggerInterface::class));

		$response = $controller('singer')->score(str_repeat('a', 32));
		$headers = self::headers($response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('attachment; filename="excerpt.musicxml"', $headers['Content-Disposition'] ?? null);
		self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
		self::assertStringContainsString("default-src 'none'", $response->getContentSecurityPolicy()->buildPolicy());

		self::assertSame(Http::STATUS_FORBIDDEN, $controller('librarian')->score(str_repeat('a', 32))->getStatus(), 'no choir member');
		$unknown = $controller('singer')->score(str_repeat('u', 32));
		self::assertSame(Http::STATUS_BAD_REQUEST, $unknown->getStatus());
		self::assertTrue($unknown->isThrottled());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller(null)->score(str_repeat('a', 32))->getStatus());
	}

	#[CoversNothing]
	public function testOnlyTheSettingsLackNoAdminRequired(): void {
		$routes = self::routes();
		$withoutNoAdmin = array_keys(array_filter($routes, fn (array $r): bool => !in_array(NoAdminRequired::class, $r['attributes'], true)));
		sort($withoutNoAdmin);
		$expected = self::SETTINGS;
		sort($expected);
		self::assertSame($expected, $withoutNoAdmin);
	}

	#[CoversNothing]
	public function testTheSettingsCanBeDelegatedAndNothingElse(): void {
		foreach (self::routes() as $name => $route) {
			if (in_array($name, self::SETTINGS, true)) {
				// Admins and the holders of the settings page, nobody else
				self::assertSame([Admin::class], $route['settings'], $name);
				self::assertNotContains(NoAdminRequired::class, $route['attributes'], $name);
				self::assertNotContains(SubAdminRequired::class, $route['attributes'], $name);
				continue;
			}
			self::assertSame([], $route['settings'], $name . ' is no setting');
		}
		// Restoring a backup stays with admins and the catalog role: giving the
		// settings page to a group does not include it.
		self::assertSame([], self::routes()['WorkflowController::restore']['settings']);
	}

	public function testTheSettingsRefuseWhomTheyWereNotGiven(): void {
		$roles = $this->createStub(Roles::class);
		$roles->method('requireSettingsAccess')->willThrowException(new AccessDeniedException('Only administrators may change this.'));
		$controller = new SettingsController($this->createStub(IRequest::class), 'carla', $roles, $this->createStub(AppSettings::class),
			$this->createStub(ArchiveAccess::class), $this->modulesWithout(), $this->createStub(RepertoireService::class),
			$this->createStub(FullBackupService::class), $this->createStub(IGroupManager::class), $this->l10n(),
			$this->createStub(LoggerInterface::class));
		$responses = [$controller->index(), $controller->update(['librarian' => ['everyone']]), $controller->groups('a'), $controller->access()];
		foreach ($responses as $response) {
			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			self::assertSame(['error' => 'Only administrators may change this.'], $response->getData());
		}
	}

	public function testTheSettingsTellWhetherARestoreIsPossibleOnlyToAdmins(): void {
		$roles = $this->createStub(Roles::class);
		$roles->method('groups')->willReturnCallback(fn (string $role): array => $role === Roles::READER ? ['board', 'deleted'] : []);
		$roles->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'root');
		$backup = $this->createStub(FullBackupService::class);
		$backup->method('counts')->willReturn(['notarch_copies' => 0, 'notarch_files' => 0, 'notarch_audit' => 0]);
		$board = $this->createStub(IGroup::class);
		$board->method('getGID')->willReturn('board');
		$board->method('getDisplayName')->willReturn('Board');
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('get')->willReturnCallback(fn (string $id): ?IGroup => $id === 'board' ? $board : null);
		$groups->method('search')->willReturn([$board]);
		$controller = fn (string $uid): SettingsController => new SettingsController($this->createStub(IRequest::class), $uid, $roles,
			$this->createStub(AppSettings::class), $this->createStub(ArchiveAccess::class), $this->modulesWithout(['repertoire']),
			$this->createStub(RepertoireService::class), $backup, $groups, $this->l10n(), $this->createStub(LoggerInterface::class));

		$data = $controller('root')->index()->getData();
		self::assertIsArray($data);
		self::assertTrue($data['admin']);
		self::assertTrue($data['catalogEmpty']);
		self::assertNull($data['repertoire'], 'the thresholds belong to the module "repertoire"');
		self::assertSame(Roles::LABELS, $data['roleLabels']);
		self::assertSame(['board', 'deleted'], $data['roles'][Roles::READER] ?? null);
		self::assertEquals((object)['board' => 'Board', 'deleted' => 'deleted'], $data['groupNames']);
		// For the setup steps of the page
		self::assertSame(['deleted'], $data['groupsMissing']);
		self::assertSame(['copies' => 0, 'files' => 0], $data['catalog']);

		$delegated = $controller('anna')->index()->getData();
		self::assertIsArray($delegated);
		self::assertFalse($delegated['admin']);
		self::assertNull($delegated['catalogEmpty']);

		// Groups to choose, also for the groups given the settings page
		self::assertSame([['id' => 'board', 'displayName' => 'Board']], $controller('anna')->groups('bo')->getData());
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

	public function testTheFilesActionRouteIsPartOfItsModule(): void {
		$route = self::routes()['FilesIntegrationController::copyOfFile'] ?? null;
		self::assertNotNull($route);
		self::assertSame('GET', $route['verb']);
		self::assertContains(NoAdminRequired::class, $route['attributes'], 'any account with access, checked by the method');
		self::assertNotContains(NoCSRFRequired::class, $route['attributes'], 'called by a script with the request token');
		self::assertContains(UserRateLimit::class, $route['attributes']);
		self::assertSame([Modules::FILES_INTEGRATION], ModuleMiddleware::required(FilesIntegrationController::class, 'copyOfFile'));

		$controller = $this->filesController('carla', true, null);
		(new ModuleMiddleware($this->modulesWithout(), $this->l10n()))->beforeController($controller, 'copyOfFile');
		$middleware = new ModuleMiddleware($this->modulesWithout([Modules::FILES_INTEGRATION]), $this->l10n());
		$this->expectException(ModuleDisabledException::class);
		$middleware->beforeController($controller, 'copyOfFile');
	}

	/**
	 * @param array{copyId: int, shelfmark: string, role: string, score: bool}|null $copy answer of the service, null: not listed
	 */
	private function filesController(?string $uid, bool $access, ?array $copy): FilesIntegrationController {
		$roles = $this->createStub(Roles::class);
		$roles->method('requireAccess')->willReturnCallback(function () use ($access): void {
			if (!$access) {
				throw new AccessDeniedException('You have no access to the music archive.');
			}
		});
		$service = $this->createStub(FilesIntegrationService::class);
		if ($copy === null) {
			$service->method('copyOf')->willThrowException(new NotFoundException('This file is not listed in the music archive.'));
		} else {
			$service->method('copyOf')->willReturn($copy);
		}
		return new FilesIntegrationController($this->createStub(IRequest::class), $uid, $roles, $service, $this->l10n(),
			$this->createStub(LoggerInterface::class));
	}

	public function testTheFilesActionAnswersWithTheCopyOr404(): void {
		$copy = ['copyId' => 35, 'shelfmark' => '2-1', 'role' => 'musicxml', 'score' => true];
		$response = $this->filesController('carla', true, $copy)->copyOfFile(4711);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($copy, $response->getData());

		$response = $this->filesController('carla', true, null)->copyOfFile(4711);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'This file is not listed in the music archive.'], $response->getData());

		$response = $this->filesController('stranger', false, $copy)->copyOfFile(4711);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), 'no access: refused before any file is looked at');
		self::assertSame(['error' => 'You have no access to the music archive.'], $response->getData());

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->filesController(null, true, $copy)->copyOfFile(4711)->getStatus());
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

	private function apiController(ScoreService $scores): ApiController {
		return new ApiController($this->createStub(IRequest::class), 'reader', $this->createStub(Roles::class), $this->createStub(MetaService::class),
			$this->createStub(CatalogService::class), $this->createStub(ImportService::class), $this->createStub(ExportService::class),
			$this->createStub(FileSyncService::class), $this->createStub(AuditEntryMapper::class),
			$this->createStub(UserFiles::class), $this->createStub(ITimeFactory::class), $this->createStub(IDateTimeZone::class), $scores,
			$this->l10n(), $this->createStub(LoggerInterface::class));
	}

	public function testAScoreInAFolderThatIsNotSharedSaysSo(): void {
		$scores = $this->createStub(ScoreService::class);
		$scores->method('content')->willThrowException(new NotSharedException('The file lies in a folder that is not shared with your account.'));
		$response = $this->apiController($scores)->fileContent(7);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertInstanceOf(JSONResponse::class, $response);
		// The UI adds the way to the access check for those who may change the settings
		self::assertSame(['error' => 'The file lies in a folder that is not shared with your account.', 'reason' => 'not_shared'], $response->getData());

		$other = $this->createStub(ScoreService::class);
		$other->method('content')->willThrowException(new AccessDeniedException('Internal preservation copy.'));
		$response = $this->apiController($other)->fileContent(7);
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(['error' => 'Internal preservation copy.'], $response->getData());
	}

	public function testScoreFilesAreNeverShownInline(): void {
		$scores = $this->createStub(ScoreService::class);
		$scores->method('content')->willReturn(['content' => '<score-partwise/>', 'mime' => 'application/vnd.recordare.musicxml+xml',
			'name' => '5-01_<script>.musicxml', 'type' => 'musicxml']);

		$response = $this->apiController($scores)->fileContent(7);
		$headers = self::headers($response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertIsString($headers['Content-Disposition'] ?? null);
		self::assertSame('attachment; filename="score.musicxml"', $headers['Content-Disposition']);
		self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
		self::assertSame('application/vnd.recordare.musicxml+xml', $headers['Content-Type'] ?? null);
		self::assertStringContainsString("default-src 'none'", $response->getContentSecurityPolicy()->buildPolicy());
		self::assertSame('<score-partwise/>', $response->render());
	}

	private function reviewController(ScanService $scans): ReviewController {
		return new ReviewController($this->createStub(IRequest::class), 'reader', $this->createStub(ReviewService::class),
			$this->createStub(CorrectionService::class), $scans, $this->l10n(), $this->createStub(LoggerInterface::class));
	}

	public function testScansAreOnlyShownByThePdfViewer(): void {
		$scans = $this->createStub(ScanService::class);
		$scans->method('scan')->willReturn($this->createStub(File::class));
		$response = $this->reviewController($scans)->scan(9);
		$headers = self::headers($response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('application/pdf', $headers['Content-Type'] ?? null);
		self::assertSame('inline; filename="scan.pdf"', $headers['Content-Disposition'] ?? null);
		self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		self::assertStringContainsString("default-src 'none'", $policy);
		self::assertStringContainsString("object-src 'self'", $policy, 'the browser\'s own PDF viewer');
		self::assertStringContainsString("frame-ancestors 'self'", $policy, 'framed by the app only');
		self::assertStringNotContainsString('http', $policy, 'no external sources');
	}

	public function testAScanIsRefusedWithoutTheRightToView(): void {
		$scans = $this->createStub(ScanService::class);
		$scans->method('scan')->willThrowException(new AccessDeniedException('Internal preservation copy: only the catalog librarian and the conductor may view it.'));
		$response = $this->reviewController($scans)->scan(9);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertInstanceOf(JSONResponse::class, $response);
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
		$response = $this->pageController(true, false, true)->index();
		self::assertInstanceOf(TemplateResponse::class, $response);
		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		self::assertStringContainsString("'wasm-unsafe-eval'", $policy);
		self::assertStringNotContainsString("'unsafe-eval'", $policy);
		self::assertStringNotContainsString('http', $policy, 'no external sources');
		self::assertStringContainsString("frame-src 'self'", $policy, 'the scan beside the score, from the app itself');
	}
}
