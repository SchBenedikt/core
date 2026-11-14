<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

final class QuoteNotFoundException extends RuntimeException
{
	public function __construct(public readonly string $quoteId)
	{
		parent::__construct('The quote authorization does not exist or is no longer valid.');
	}
}
