<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoPublicationRepository;

final class InMemoryAtprotoPublicationRepositoryTest extends AtprotoPublicationRepositoryTestCase
{
	private AtprotoPublicationRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryAtprotoPublicationRepository();
	}

	protected function repository(): AtprotoPublicationRepository
	{
		return $this->repository;
	}
}
