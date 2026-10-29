<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Tests\Unit\Search;

use OCA\Cantoria\Db\Contribution;
use OCA\Cantoria\Db\ContributionMapper;
use OCA\Cantoria\Db\Copy;
use OCA\Cantoria\Db\CopyMapper;
use OCA\Cantoria\Db\Person;
use OCA\Cantoria\Db\PersonMapper;
use OCA\Cantoria\Db\Work;
use OCA\Cantoria\Db\WorkMapper;
use OCA\Cantoria\Db\WorkVersion;
use OCA\Cantoria\Db\WorkVersionMapper;
use OCA\Cantoria\Search\CatalogSearchProvider;
use OCA\Cantoria\Service\CatalogSearch;
use OCA\Cantoria\Service\CatalogService;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\Roles;
use OCA\Cantoria\Service\Sort;
use OCA\Cantoria\Tests\Unit\Support\ModuleSwitches;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Search\ISearchQuery;
use OCP\Security\RateLimiting\ILimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The search of Nextcloud: only accounts with a role of the catalog find
 * copies, internal preservation copies only for the roles that may view
 * them, nothing while the module is off; results come in pages.
 */
#[CoversClass(CatalogSearchProvider::class)]
#[CoversClass(CatalogSearch::class)]
#[UsesClass(Sort::class)]
#[UsesClass(CatalogService::class)]
final class CatalogSearchProviderTest extends TestCase {
	use ModuleSwitches;

	private static function copy(int $id, int $versionId, int $binder, int $number, string $status = 'active'): Copy {
		$copy = new Copy();
		$copy->setId($id);
		$copy->setVersionId($versionId);
		$copy->setBinder($binder);
		$copy->setNumber($number);
		$copy->setShelfmark($binder . '-' . $number);
		$copy->setStatus($status);
		return $copy;
	}

	private function search(Modules $modules): CatalogSearch {
		$works = [];
		foreach ([1 => ['Der Mond ist aufgegangen', 'Johann Abraham Peter Schulz'], 2 => ['Ännchen von Tharau', 'Friedrich Silcher'],
			3 => ['Locus iste', 'Anton Bruckner']] as $id => [$title, $composer]) {
			$work = new Work();
			$work->setId($id);
			$work->setTitle($title);
			$work->setComposer($composer);
			$works[$id] = $work;
		}
		$versions = [];
		foreach ([1 => 1, 2 => 2, 3 => 1, 4 => 3] as $id => $workId) {
			$version = new WorkVersion();
			$version->setId($id);
			$version->setWorkId($workId);
			$version->setPreservationCopy($id === 3);
			$versions[$id] = $version;
		}
		$copies = $this->createStub(CopyMapper::class);
		$copies->method('findAll')->willReturn([self::copy(11, 1, 1, 1), self::copy(21, 2, 2, 1), self::copy(31, 3, 3, 1),
			self::copy(15, 1, 1, 5, 'retired'), self::copy(41, 4, 4, 2)]);
		$versionMapper = $this->createStub(WorkVersionMapper::class);
		$versionMapper->method('findAllById')->willReturn($versions);
		$workMapper = $this->createStub(WorkMapper::class);
		$workMapper->method('findAllById')->willReturn($works);
		$person = new Person();
		$person->setId(9);
		$person->setName('Matthias Claudius');
		$persons = $this->createStub(PersonMapper::class);
		$persons->method('findAllById')->willReturn([9 => $person]);
		$contribution = new Contribution();
		$contribution->setVersionId(1);
		$contribution->setPersonId(9);
		$contribution->setRole('lyricist');
		$contributions = $this->createStub(ContributionMapper::class);
		$contributions->method('findAllByVersion')->willReturn([1 => [$contribution]]);
		return new CatalogSearch($copies, $versionMapper, $workMapper, $persons, $contributions, $modules);
	}

	/**
	 * @param list<string> $roles roles of the account
	 */
	private function provider(array $roles, Modules $modules, bool $admin = false): CatalogSearchProvider {
		$roleService = $this->createStub(Roles::class);
		$roleService->method('hasAnyRole')->willReturnCallback(fn (string $uid, array $allowed): bool => array_intersect($roles, $allowed) !== []);
		$roleService->method('isAdmin')->willReturn($admin);
		$policy = $this->createStub(RightsPolicy::class);
		$policy->method('mayViewPreservationCopies')->willReturn(array_intersect($roles, RightsPolicy::PRESERVATION_COPY_VIEWERS) !== []);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('lena');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$url = $this->createStub(IURLGenerator::class);
		$url->method('linkToRoute')->willReturn('/apps/cantoria/');
		$url->method('imagePath')->willReturn('/apps/cantoria/img/app-dark.svg');
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return new CatalogSearchProvider($this->search($modules), $modules, $roleService, $policy, $session, $url,
			$this->createStub(ILimiter::class), $l);
	}

	private function query(string $term, int $limit = 5, int|string|null $cursor = null): ISearchQuery {
		$query = $this->createStub(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);
		return $query;
	}

	/**
	 * @return array{entries: list<array{title: string, subline: string, resourceUrl: string}>, isPaginated: bool, cursor: int|string|null}
	 */
	private function results(CatalogSearchProvider $provider, string $term, int $limit = 5, int|string|null $cursor = null): array {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('lena');
		/** @var array{entries: list<array{title: string, subline: string, resourceUrl: string}>, isPaginated: bool, cursor: int|string|null} */
		return json_decode((string)json_encode($provider->search($user, $this->query($term, $limit, $cursor))), true);
	}

	public function testFindsByTitleComposerAndPerson(): void {
		$provider = $this->provider([Roles::READER], $this->modulesWithout());
		$titles = fn (array $result): array => array_column($result['entries'], 'title');
		self::assertSame(['1-1 · Der Mond ist aufgegangen', '1-5 · Der Mond ist aufgegangen'], $titles($this->results($provider, 'mond')),
			'active copies first');
		self::assertSame(['2-1 · Ännchen von Tharau'], $titles($this->results($provider, 'annchen silcher')), 'accents and several words');
		self::assertSame(['1-1 · Der Mond ist aufgegangen', '1-5 · Der Mond ist aufgegangen'], $titles($this->results($provider, 'Claudius')),
			'persons of the register');
		self::assertSame(['1-5 · Der Mond ist aufgegangen'], $titles($this->results($provider, '1 / 05')), 'shelf mark, any status');
		$result = $this->results($provider, '1-1');
		self::assertSame('/apps/cantoria/?copy=11', $result['entries'][0]['resourceUrl']);
		self::assertStringContainsString('Retired', $this->results($provider, '1-5')['entries'][0]['subline']);
	}

	public function testPersonsOfTheRegisterOnlyWithTheirModule(): void {
		$provider = $this->provider([Roles::READER], $this->modulesWithout(['persons']));
		self::assertSame([], $this->results($provider, 'Claudius')['entries']);
	}

	public function testPreservationCopiesOnlyForTheRolesThatMayViewThem(): void {
		$reader = $this->provider([Roles::READER], $this->modulesWithout());
		self::assertSame([], $this->results($reader, '3-1')['entries']);
		self::assertNotContains('3-1 · Der Mond ist aufgegangen', array_column($this->results($reader, 'mond')['entries'], 'title'));
		$librarian = $this->provider([Roles::LIBRARIAN], $this->modulesWithout());
		$entries = $this->results($librarian, '3-1')['entries'];
		self::assertSame(['3-1 · Der Mond ist aufgegangen'], array_column($entries, 'title'));
		self::assertStringContainsString('Internal preservation copy', $entries[0]['subline']);
	}

	public function testAccountsWithoutACatalogRoleFindNothing(): void {
		// e.g. choir members, who only see excerpts released to them
		$member = $this->provider(['member'], $this->modulesWithout());
		self::assertNull($member->getOrder('files.view.index', []), 'the provider is hidden');
		self::assertSame([], $this->results($member, 'mond')['entries']);
		$nobody = $this->provider([], $this->modulesWithout());
		self::assertSame([], $this->results($nobody, 'mond')['entries']);
		$admin = $this->provider([], $this->modulesWithout(), true);
		self::assertCount(2, $this->results($admin, 'mond')['entries'], 'admins read the app as well');
	}

	public function testNothingWhileTheModuleIsOff(): void {
		$provider = $this->provider([Roles::LIBRARIAN], $this->modulesWithout([Modules::UNIFIED_SEARCH]));
		self::assertNull($provider->getOrder('files.view.index', []));
		self::assertSame([], $this->results($provider, 'mond')['entries']);
		$on = $this->provider([Roles::LIBRARIAN], $this->modulesWithout());
		self::assertSame(-1, $on->getOrder('cantoria.page.index', []));
		self::assertIsInt($on->getOrder('files.view.index', []));
	}

	public function testResultsComeInPages(): void {
		$provider = $this->provider([Roles::LIBRARIAN], $this->modulesWithout());
		$first = $this->results($provider, 'der', 2);
		self::assertTrue($first['isPaginated']);
		self::assertCount(2, $first['entries']);
		self::assertSame(2, $first['cursor']);
		$second = $this->results($provider, 'der', 2, $first['cursor']);
		self::assertCount(1, $second['entries']);
		self::assertSame(3, $second['cursor']);
		self::assertCount(3, $this->results($provider, 'der', 500)['entries'], 'the limit is capped, not refused');
		self::assertCount(2, $this->results($provider, 'der', 2, 'nonsense')['entries'], 'an invalid cursor starts at the beginning');
	}

	public function testTooShortTermsFindNothing(): void {
		$provider = $this->provider([Roles::LIBRARIAN], $this->modulesWithout());
		self::assertSame([], $this->results($provider, 'm')['entries']);
		self::assertSame([], $this->results($provider, '   ')['entries']);
	}
}
