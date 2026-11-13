<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\BookmarkRepository;
use AlohaSocial\Core\Domain\Post\InMemoryBookmarkRepository;

final class InMemoryBookmarkRepositoryTest extends BookmarkRepositoryTestCase
{
	protected function repository(): BookmarkRepository
	{
		return new InMemoryBookmarkRepository();
	}
}
