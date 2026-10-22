<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Controller;

use OCA\Notenarchiv\AppInfo\Application;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCA\Notenarchiv\Service\PersonImportService;
use OCA\Notenarchiv\Service\PersonService;
use OCA\Notenarchiv\Service\ProtectionText;
use OCA\Notenarchiv\Service\RightsService;
use OCA\Notenarchiv\Service\Roles;
use OCA\Notenarchiv\Service\UserFiles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Register of persons, contributions per version, the protection proposal
 * and its resubmission list, and the import of a research list (milestone
 * 0.5, see docs/api.md). Writing needs the role librarian; the services check
 * it.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class PersonController extends Controller {
	use RunsActions;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private Roles $roles,
		private PersonService $persons,
		private PersonImportService $personImport,
		private RightsService $rights,
		private UserFiles $userFiles,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/persons')]
	public function list(?string $q = null): JSONResponse {
		return $this->run(function (string $uid) use ($q) {
			$this->roles->requireAccess($uid);
			return $this->persons->list($q);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/persons/{id}', requirements: ['id' => '\d+'])]
	public function detail(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->roles->requireAccess($uid);
			return $this->persons->detail($id);
		});
	}

	/**
	 * @param array<string, mixed> $person
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/persons')]
	public function create(array $person = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->persons->serialize($this->persons->create($person, $uid)));
	}

	/**
	 * @param array<string, mixed> $person
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/persons/{id}', requirements: ['id' => '\d+'])]
	public function update(int $id, array $person = [], ?string $reason = null): JSONResponse {
		return $this->run(fn (string $uid) => $this->persons->serialize($this->persons->update($id, $person, $uid, $reason)));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/persons/{id}/checked', requirements: ['id' => '\d+'])]
	public function checked(int $id): JSONResponse {
		return $this->run(fn (string $uid) => $this->persons->serialize($this->persons->markChecked($id, $uid)));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/persons/{id}', requirements: ['id' => '\d+'])]
	public function delete(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->persons->delete($id, $uid);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/versions/{id}/contributions', requirements: ['id' => '\d+'])]
	public function contributions(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->roles->requireAccess($uid);
			$year = (int)substr($this->rights->today($uid), 0, 4);
			return [
				'contributions' => $this->persons->forVersion($id),
				'protection' => ProtectionText::apply($this->l, $this->persons->protection($id, $year)),
			];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/versions/{id}/contributions', requirements: ['id' => '\d+'])]
	public function addContribution(int $id, int $personId = 0, string $role = 'unspecified', bool $textForComposition = false): JSONResponse {
		return $this->run(fn (string $uid) => $this->persons->addContribution($id, $personId, $role, $textForComposition, $uid));
	}

	/**
	 * @param array<string, mixed> $contribution
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/contributions/{id}', requirements: ['id' => '\d+'])]
	public function updateContribution(int $id, array $contribution = []): JSONResponse {
		return $this->run(fn (string $uid) => $this->persons->updateContribution($id, $contribution, $uid));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/contributions/{id}', requirements: ['id' => '\d+'])]
	public function removeContribution(int $id): JSONResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->persons->removeContribution($id, $uid);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/protection/review')]
	public function review(): JSONResponse {
		return $this->run(function (string $uid) {
			$this->roles->requireAccess($uid);
			$rows = [];
			foreach ($this->persons->review($uid) as $row) {
				$rows[] = ['protection' => ProtectionText::apply($this->l, $row['protection'])] + $row;
			}
			return $rows;
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/persons/import')]
	public function import(int $fileId = 0, bool $dryRun = true): JSONResponse {
		return $this->run(function (string $uid) use ($fileId, $dryRun) {
			$this->roles->requireRole($uid, [Roles::LIBRARIAN]);
			$file = $this->userFiles->read($uid, $fileId, UserFiles::MAX_IMPORT_BYTES,
				new InvalidInputException('The selected CSV file is not accessible.'));
			$report = $this->personImport->import($file['content'], $file['name'], $uid, $dryRun);
			return ['warnings' => $this->translateWarnings($report['warnings'])] + $report;
		});
	}
}
