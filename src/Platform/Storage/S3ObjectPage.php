<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Storage;

use InvalidArgumentException;

/** One bounded S3 listing page. */
final readonly class S3ObjectPage
{
	/**
	 * @param list<string> $keys full object keys in the configured bucket
	 */
	public function __construct(
		public array $keys,
		public ?string $nextContinuationToken,
	) {
		if (!array_is_list($keys)) {
			throw new InvalidArgumentException('S3 object keys must be a list.');
		}
		foreach ($keys as $key) {
			if (!is_string($key) || $key === '') {
				throw new InvalidArgumentException('S3 object keys must be non-empty strings.');
			}
		}
		if ($nextContinuationToken === '') {
			throw new InvalidArgumentException('An S3 continuation token must be null or non-empty.');
		}
	}
}
