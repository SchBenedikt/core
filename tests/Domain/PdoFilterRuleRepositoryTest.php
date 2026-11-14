<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Filter\FilterRuleRepository;
use AlohaSocial\Core\Domain\Filter\FilterSchema;
use AlohaSocial\Core\Domain\Filter\PdoFilterRuleRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoFilterRuleRepositoryTest extends FilterRuleRepositoryTestCase
{
	private FilterRuleRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		FilterSchema::create($connection);

		$this->repository = new PdoFilterRuleRepository($connection);
	}

	protected function repository(): FilterRuleRepository
	{
		return $this->repository;
	}
}
