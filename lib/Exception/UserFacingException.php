<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Exception;

/**
 * Exception whose message is shown to the user. The message is an English
 * source string with `%s` placeholders; the controller translates it.
 */
abstract class UserFacingException extends \Exception {
	/**
	 * @param list<string|int> $parameters
	 */
	public function __construct(
		string $message,
		private array $parameters = [],
	) {
		parent::__construct($message);
	}

	/**
	 * @return list<string|int>
	 */
	public function getParameters(): array {
		return $this->parameters;
	}
}
