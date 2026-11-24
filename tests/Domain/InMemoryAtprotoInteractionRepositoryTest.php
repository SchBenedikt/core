<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionRepository;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoInteractionRepository;

final class InMemoryAtprotoInteractionRepositoryTest extends AtprotoInteractionRepositoryTestCase
{
	private AtprotoInteractionRepository $store;

	protected function setUp(): void
	{
		$this->store = new InMemoryAtprotoInteractionRepository();
	}

	protected function repository(): AtprotoInteractionRepository
	{
		return $this->store;
	}
}
