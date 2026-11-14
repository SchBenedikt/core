<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Filter;

use RuntimeException;

/**
 * A filter that does not exist or belongs to another reader.
 *
 * Both cases answer 404 on purpose so the route cannot be used to probe
 * whether another account has a rule with a guessed id.
 */
final class FilterNotFoundException extends RuntimeException
{
	public function __construct(string $id)
	{
		parent::__construct('The filter ' . $id . ' does not exist.');
	}
}
