<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Service;

use OCA\Notenarchiv\Exception\InvalidInputException;
use OCP\ITempManager;

/**
 * Reads parts of an untrusted ZIP archive (.xlsx, .mxl) with limits, so that
 * a small upload cannot unpack into gigabytes (ZIP bomb): a maximum number of
 * entries, a maximum unpacked size per part and in total. The size recorded
 * in the archive is checked before unpacking, and no more than that size is
 * read.
 *
 * Callers pass the exceptions to throw, with their own wording.
 */
final class BoundedZip {
	private int $bytesRead = 0;

	private function __construct(
		private \ZipArchive $zip,
		private string $path,
		private int $maxPartBytes,
		private int $maxTotalBytes,
		private InvalidInputException $tooLarge,
		private InvalidInputException $damaged,
	) {
	}

	/**
	 * Opens the archive, or returns null when the content is no ZIP archive.
	 * Close it with close() in all cases.
	 *
	 * @throws InvalidInputException when the archive has too many entries
	 */
	public static function open(ITempManager $tempManager, string $content, int $maxEntries, int $maxPartBytes, int $maxTotalBytes,
		InvalidInputException $tooLarge, InvalidInputException $damaged): ?self {
		$path = $tempManager->getTemporaryFile('.zip');
		if ($path === false) {
			throw new \RuntimeException('No temporary file');
		}
		file_put_contents($path, $content);
		$zip = new \ZipArchive();
		if ($content === '' || $zip->open($path) !== true) {
			@unlink($path);
			return null;
		}
		$archive = new self($zip, $path, $maxPartBytes, $maxTotalBytes, $tooLarge, $damaged);
		if ($zip->numFiles > $maxEntries) {
			$archive->close();
			throw $tooLarge;
		}
		return $archive;
	}

	public function has(string $name): bool {
		return $this->zip->locateName($name) !== false;
	}

	/**
	 * @return list<string> names of all entries
	 */
	public function names(): array {
		$names = [];
		for ($i = 0; $i < $this->zip->numFiles; $i++) {
			$name = $this->zip->getNameIndex($i);
			if ($name !== false) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Unpacked content of a part, or null when the archive has no such part.
	 *
	 * @throws InvalidInputException when the part is too large or cannot be read
	 */
	public function part(string $name): ?string {
		$stat = $this->zip->statName($name);
		if ($stat === false) {
			return null;
		}
		$size = (int)$stat['size'];
		if ($size > $this->maxPartBytes || $this->bytesRead + $size > $this->maxTotalBytes) {
			throw $this->tooLarge;
		}
		if ($size === 0) {
			return '';
		}
		$data = $this->zip->getFromName($name, $size);
		if ($data === false) {
			throw $this->damaged;
		}
		$this->bytesRead += strlen($data);
		return $data;
	}

	public function close(): void {
		$this->zip->close();
		@unlink($this->path);
	}
}
