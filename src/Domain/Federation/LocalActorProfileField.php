<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use InvalidArgumentException;

use function mb_strlen;
use function trim;

/**
 * One profile field the host exposes as an ActivityPub `PropertyValue`.
 *
 * `verified` is set by the host after it checked a rel=me link. The core never
 * fetches an arbitrary profile URL just because it appears in a field; an
 * unverified field is serialized without a `rel`.
 */
final readonly class LocalActorProfileField
{
	public const MAX_NAME_LENGTH = 255;

	public const MAX_VALUE_LENGTH = 1024;

	public function __construct(
		public string $name,
		public string $value,
		public bool $verified = false,
	) {
		$name = trim($name);
		$value = trim($value);

		if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new InvalidArgumentException('A profile field needs a bounded name.');
		}

		if ($value === '' || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
			throw new InvalidArgumentException('A profile field needs a bounded value.');
		}
	}

	/**
	 * @return array{name: string, value: string, verified: bool}
	 */
	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'value' => $this->value,
			'verified' => $this->verified,
		];
	}
}
