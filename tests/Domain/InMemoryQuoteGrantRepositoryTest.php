<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\InMemoryQuoteGrantRepository;
use AlohaSocial\Core\Domain\Post\QuoteGrantRepository;

final class InMemoryQuoteGrantRepositoryTest extends QuoteGrantRepositoryTestCase
{
	protected function repository(): QuoteGrantRepository
	{
		return new InMemoryQuoteGrantRepository();
	}
}
