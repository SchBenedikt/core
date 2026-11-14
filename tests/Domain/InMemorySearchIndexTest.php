<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Search\InMemorySearchIndex;
use AlohaSocial\Core\Domain\Search\SearchIndex;

final class InMemorySearchIndexTest extends SearchIndexRepositoryTestCase
{
	protected function index(): SearchIndex
	{
		return new InMemorySearchIndex();
	}
}
