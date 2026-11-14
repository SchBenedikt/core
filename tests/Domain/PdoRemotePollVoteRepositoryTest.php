<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\PdoRemotePollVoteRepository;
use AlohaSocial\Core\Domain\Federation\RemotePollSchema;
use AlohaSocial\Core\Domain\Federation\RemotePollVoteRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoRemotePollVoteRepositoryTest extends RemotePollVoteRepositoryTestCase
{
	protected function repository(): RemotePollVoteRepository
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		RemotePollSchema::create($connection);

		return new PdoRemotePollVoteRepository($connection);
	}
}
