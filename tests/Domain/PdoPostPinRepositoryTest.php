<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\PdoPostPinRepository;
use AlohaSocial\Core\Domain\Post\PostPinRepository;
use AlohaSocial\Core\Domain\Post\PostPinSchema;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoPostPinRepositoryTest extends PostPinRepositoryTestCase
{
	protected function repository(): PostPinRepository
	{
		$database = new PdoConnection(new PDO('sqlite::memory:'));
		PostPinSchema::create($database);

		return new PdoPostPinRepository($database);
	}
}
