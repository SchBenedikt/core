<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoInboundRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoInboundSchema;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoInboundRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoAtprotoInboundRepositoryTest extends AtprotoInboundRepositoryTestCase
{
	protected function repository(): AtprotoInboundRepository
	{
		$database = new PdoConnection(new PDO('sqlite::memory:'));
		AtprotoInboundSchema::create($database);

		return new PdoAtprotoInboundRepository($database);
	}
}
