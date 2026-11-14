<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Search\PdoSearchIndex;
use AlohaSocial\Core\Domain\Search\SearchIndex;
use AlohaSocial\Core\Domain\Search\SearchIndexSchema;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoSearchIndexTest extends SearchIndexRepositoryTestCase
{
	private PdoConnection $db;

	protected function setUp(): void
	{
		$this->db = new PdoConnection(new PDO('sqlite::memory:'));
		SearchIndexSchema::create($this->db);
	}

	protected function index(): SearchIndex
	{
		return new PdoSearchIndex($this->db);
	}
}
