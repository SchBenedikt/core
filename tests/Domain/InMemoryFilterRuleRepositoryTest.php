<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Filter\FilterRuleRepository;
use AlohaSocial\Core\Domain\Filter\InMemoryFilterRuleRepository;

final class InMemoryFilterRuleRepositoryTest extends FilterRuleRepositoryTestCase
{
	private FilterRuleRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryFilterRuleRepository();
	}

	protected function repository(): FilterRuleRepository
	{
		return $this->repository;
	}
}
