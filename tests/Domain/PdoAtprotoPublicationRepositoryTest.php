<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationSchema;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoPublicationRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoAtprotoPublicationRepositoryTest extends AtprotoPublicationRepositoryTestCase
{
	private AtprotoPublicationRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		AtprotoPublicationSchema::create($connection);

		$this->repository = new PdoAtprotoPublicationRepository($connection);
	}

	protected function repository(): AtprotoPublicationRepository
	{
		return $this->repository;
	}
}
