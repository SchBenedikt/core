<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

/**
 * Input that does not satisfy the contract, with one message per field.
 *
 * The field map is what the API returns as `error.fields`, so a client can
 * point at the offending input instead of showing a generic failure.
 */
final class ValidationException extends RuntimeException
{
	/**
	 * @var array<string, string>
	 */
	private readonly array $fieldErrors;

	/**
	 * @param array<string, string> $fields
	 */
	public function __construct(array $fields, string $message = 'The request is not valid.')
	{
		$this->fieldErrors = $fields;

		parent::__construct($message);
	}

	/**
	 * @return array<string, string>
	 */
	public function fields(): array
	{
		return $this->fieldErrors;
	}
}
