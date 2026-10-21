<?php

declare(strict_types=1);

namespace OCA\OrchestraScoresManager\Utility;

class FileNameHelper {
	/**
	 * Turn an uploaded file name into a safe name for display.
	 *
	 * @param string $name The uploaded file name
	 * @param string $fallback Name used if nothing is left after sanitizing
	 */
	public static function sanitizeDisplayName(string $name, string $fallback): string {
		$name = basename(str_replace('\\', '/', $name));
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		$name = mb_substr(trim($name), 0, 255);
		return $name === '' ? $fallback : $name;
	}
}
