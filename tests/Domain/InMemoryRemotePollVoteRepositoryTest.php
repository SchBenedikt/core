<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\InMemoryRemotePollVoteRepository;
use AlohaSocial\Core\Domain\Federation\RemotePollVoteRepository;

final class InMemoryRemotePollVoteRepositoryTest extends RemotePollVoteRepositoryTestCase
{
	protected function repository(): RemotePollVoteRepository
	{
		return new InMemoryRemotePollVoteRepository();
	}
}
