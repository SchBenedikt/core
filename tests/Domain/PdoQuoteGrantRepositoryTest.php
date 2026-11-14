<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\PdoQuoteGrantRepository;
use AlohaSocial\Core\Domain\Post\QuoteGrantRepository;
use AlohaSocial\Core\Domain\Post\QuoteGrantSchema;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoQuoteGrantRepositoryTest extends QuoteGrantRepositoryTestCase
{
	protected function repository(): QuoteGrantRepository
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		QuoteGrantSchema::create($connection);

		return new PdoQuoteGrantRepository($connection);
	}
}
