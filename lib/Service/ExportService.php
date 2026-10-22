<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Db\AuditEntryMapper;
use OCA\Notenarchiv\Db\CopyMapper;
use OCA\Notenarchiv\Db\WorkMapper;
use OCA\Notenarchiv\Db\WorkVersionMapper;
use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\NotPermittedException;
use OCP\IDateTimeZone;

/**
 * Exports the catalog as CSV.
 *
 * The extended format appends all app fields to the legacy columns; it is the
 * backup from which the catalog can be restored without the developer.
 */
class ExportService {
	public function __construct(
		private WorkMapper $works,
		private WorkVersionMapper $versions,
		private CopyMapper $copies,
		private AppSettings $settings,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private AuditEntryMapper $auditEntries,
		private OdsWriter $odsWriter,
		private PersonService $persons,
		private FullBackupService $fullBackup,
	) {
	}

	public function csv(bool $extended = false): string {
		[$header, $rows] = $this->table($extended);
		return CatalogCsv::write($header, $rows);
	}

	/**
	 * The full backup as spreadsheet: catalog with all fields, the audit log
	 * and the register of persons with their contributions, readable without
	 * the app.
	 */
	public function ods(): string {
		[$header, $rows] = $this->table(true);
		$audit = [['id', 'operation', 'time', 'user_id', 'user_name', 'roles', 'object_type', 'object_id', 'action', 'field', 'old_value', 'new_value', 'reason']];
		foreach ($this->auditEntries->findAllInOrder() as $entry) {
			$data = $entry->jsonSerialize();
			$data['time'] = gmdate('Y-m-d H:i:s', (int)$data['time']) . ' UTC';
			$audit[] = array_map(fn (string $column): string => (string)($data[$column] ?? ''), $audit[0]);
		}
		[$personHeader, $personRows] = $this->persons->table();
		return $this->odsWriter->write([
			['name' => 'Catalog', 'rows' => array_merge([$header], $rows)],
			['name' => 'Audit log', 'rows' => $audit],
			['name' => 'Persons', 'rows' => array_merge([$personHeader], $personRows)],
		]);
	}

	/**
	 * Stores the full backup (extended CSV and ODS) in the export folder.
	 *
	 * @return list<string> stored paths
	 */
	public function storeBackup(string $occasion, string $uid): array {
		$csvPath = $this->store($occasion, $uid, true);
		$odsPath = preg_replace('/\.csv$/', '.ods', $csvPath) ?? $csvPath . '.ods';
		$jsonPath = preg_replace('/_catalog_([^\/]+)\.csv$/', '_backup_$1.json', $csvPath) ?? $csvPath . '.json';
		$folder = $this->settings->exportFolder($uid);
		try {
			if (!$folder->nodeExists(basename($odsPath))) {
				$folder->newFile(basename($odsPath), $this->ods());
			}
			if (!$folder->nodeExists(basename($jsonPath))) {
				$folder->newFile(basename($jsonPath), $this->fullBackup->export());
			}
		} catch (NotPermittedException) {
			throw new InvalidInputException('No write permission for exports in the archive folder.');
		}
		return [$csvPath, $odsPath, $jsonPath];
	}

	/**
	 * @return array{0: list<string>, 1: list<list<string>>}
	 */
	private function table(bool $extended): array {
		$columns = CatalogCsv::columns($extended);
		$works = $this->works->findAllById();
		$versions = $this->versions->findAllById();

		$rows = [];
		foreach ($this->copies->findAll() as $copy) {
			$version = $versions[$copy->getVersionId()] ?? null;
			$work = $version === null ? null : ($works[$version->getWorkId()] ?? null);
			$objects = [Fields::COPY => $copy, Fields::VERSION => $version, Fields::WORK => $work];
			$row = [];
			foreach ($columns as $header => [$level, $field]) {
				$object = $objects[$level];
				$row[] = $object === null ? '' : CatalogCsv::toCsv($header, $object->getValue($field));
			}
			$rows[] = $row;
		}
		return [array_keys($columns), $rows];
	}

	/**
	 * Stores a dated export in the export folder and returns its path relative
	 * to the archive folder. Existing files are never overwritten.
	 */
	public function store(string $occasion, string $uid, bool $extended = true): string {
		$folder = $this->settings->exportFolder($uid);
		$occasion = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $occasion) ?? 'export', '-');
		$now = $this->time->getDateTime('now', $this->timeZone->getTimeZone(false, $uid));
		$base = $now->format('Ymd-His') . '_catalog_' . $occasion;
		$name = $base . '.csv';
		for ($i = 2; $folder->nodeExists($name); $i++) {
			$name = $base . '-' . $i . '.csv';
		}
		try {
			$folder->newFile($name, $this->csv($extended));
		} catch (NotPermittedException) {
			throw new InvalidInputException('No write permission for exports in the archive folder.');
		}
		return AppSettings::EXPORT_FOLDER . '/' . $name;
	}
}
