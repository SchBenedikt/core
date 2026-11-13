<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\BookmarkRepository;
use AlohaSocial\Core\Domain\Post\BookmarkSchema;
use AlohaSocial\Core\Domain\Post\PdoBookmarkRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoBookmarkRepositoryTest extends BookmarkRepositoryTestCase
{
	protected function repository(): BookmarkRepository
	{
		$database = new PdoConnection(new PDO('sqlite::memory:'));
		BookmarkSchema::create($database);

		return new PdoBookmarkRepository($database);
	}
}
