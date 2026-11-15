<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Story\InMemoryStoryRepository;
use AlohaSocial\Core\Domain\Story\StoryRepository;

final class InMemoryStoryRepositoryTest extends StoryRepositoryTestCase
{
	protected function repository(): StoryRepository
	{
		return new InMemoryStoryRepository();
	}
}
