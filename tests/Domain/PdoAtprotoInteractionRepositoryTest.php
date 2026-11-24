<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionSchema;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoInteractionRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoAtprotoInteractionRepositoryTest extends AtprotoInteractionRepositoryTestCase
{
	private AtprotoInteractionRepository $store;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		AtprotoInteractionSchema::create($connection);
		$this->store = new PdoAtprotoInteractionRepository($connection);
	}

	protected function repository(): AtprotoInteractionRepository
	{
		return $this->store;
	}
}
