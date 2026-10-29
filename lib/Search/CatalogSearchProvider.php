<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cantoria\Search;

use OCA\Cantoria\AppInfo\Application;
use OCA\Cantoria\Service\CatalogSearch;
use OCA\Cantoria\Service\Fields;
use OCA\Cantoria\Service\Modules;
use OCA\Cantoria\Service\RightsPolicy;
use OCA\Cantoria\Service\Roles;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;

/**
 * Copies of the music archive in the search of Nextcloud (module
 * "unified_search", milestone 0.8): by shelf mark, title and person. A
 * result opens the copy in the app (`?copy=`).
 *
 * Only accounts with a role of the catalog (or admin rights, which read the
 * app as well) find anything; the provider is hidden for everyone else and
 * while the module is off. Internal preservation copies only for the roles
 * that may view them. Results come in pages (limit and cursor, as Nextcloud
 * asks for); searches are limited per account like the API of the app.
 */
class CatalogSearchProvider implements IProvider {
	/** Largest page, whatever the client asks for */
	public const MAX_LIMIT = 50;
	/** Longest search term looked at (characters) */
	public const MAX_TERM = 100;
	/** Searches per account in RATE_PERIOD seconds (typing searches once per pause) */
	public const RATE_LIMIT = 300;
	public const RATE_PERIOD = 600;

	/**
	 * The roles that read the catalog. Listed by name on purpose instead of
	 * Roles::hasAccess(): a role added later (e.g. choir members, who see
	 * only excerpts released to them) finds nothing here until it is added.
	 */
	public const CATALOG_ROLES = [Roles::LIBRARIAN, Roles::OPERATIONS, Roles::CONDUCTOR, Roles::CONTRIBUTOR, Roles::READER];

	public function __construct(
		private CatalogSearch $search,
		private Modules $modules,
		private Roles $roles,
		private RightsPolicy $policy,
		private IUserSession $session,
		private IURLGenerator $url,
		private ILimiter $limiter,
		private IL10N $l,
	) {
	}

	#[\Override]
	public function getId(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('Cantoria');
	}

	/**
	 * Hidden (null) while the module is off and for accounts that may not
	 * search the catalog; first place inside the app.
	 */
	#[\Override]
	public function getOrder(string $route, array $routeParameters): ?int {
		$user = $this->session->getUser();
		if ($user === null || !$this->maySearch($user->getUID())) {
			return null;
		}
		return str_starts_with($route, Application::APP_ID . '.') ? -1 : 35;
	}

	#[\Override]
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$name = $this->getName();
		$uid = $user->getUID();
		$term = trim(mb_substr($query->getTerm(), 0, self::MAX_TERM));
		if (!$this->maySearch($uid) || mb_strlen($term) < 2) {
			return SearchResult::complete($name, []);
		}
		try {
			$this->limiter->registerUserRequest('cantoria-search', self::RATE_LIMIT, self::RATE_PERIOD, $user);
		} catch (IRateLimitExceededException) {
			return SearchResult::complete($name, []);
		}
		$cursor = $query->getCursor();
		$offset = is_int($cursor) || (is_string($cursor) && ctype_digit($cursor)) ? max(0, (int)$cursor) : 0;
		$limit = max(1, min($query->getLimit(), self::MAX_LIMIT));

		$hits = $this->search->find($term, $this->policy->mayViewPreservationCopies($uid));
		$page = array_slice($hits, $offset, $limit);
		$app = $this->url->linkToRoute(Application::APP_ID . '.page.index');
		$icon = $this->url->imagePath(Application::APP_ID, 'app-dark.svg');
		$entries = [];
		foreach ($page as $hit) {
			$subline = array_filter([
				$hit['composer'],
				$hit['arranger'],
				$hit['status'] !== 'active' ? $this->l->t(Fields::COPY_STATUS[$hit['status']] ?? $hit['status']) : null,
				$hit['preservationCopy'] ? $this->l->t('Internal preservation copy') : null,
			], fn (?string $s): bool => $s !== null && trim($s) !== '');
			$entry = new SearchResultEntry('', $hit['shelfmark'] . ' · ' . ($hit['title'] ?? $this->l->t('(no title)')),
				implode(' · ', $subline), $app . '?copy=' . $hit['copyId'], $icon);
			$entry->addAttribute('copyId', (string)$hit['copyId']);
			$entries[] = $entry;
		}
		return SearchResult::paginated($name, $entries, $offset + count($page));
	}

	/**
	 * Whether the account may search the catalog: the module is on and it
	 * holds a role of the catalog, or admin rights.
	 */
	public function maySearch(string $uid): bool {
		return $this->modules->isEnabled(Modules::UNIFIED_SEARCH)
			&& ($this->roles->hasAnyRole($uid, self::CATALOG_ROLES) || $this->roles->isAdmin($uid));
	}
}
