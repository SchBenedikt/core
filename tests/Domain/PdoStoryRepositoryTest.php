<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Story\PdoStoryRepository;
use AlohaSocial\Core\Domain\Story\StoryRepository;
use AlohaSocial\Core\Domain\Story\StorySchema;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoStoryRepositoryTest extends StoryRepositoryTestCase
{
	protected function repository(): StoryRepository
	{
		$database = new PdoConnection(new PDO('sqlite::memory:'));
		StorySchema::create($database);

		return new PdoStoryRepository($database);
	}
}
