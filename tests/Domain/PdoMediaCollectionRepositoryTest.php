<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Collection\MediaCollectionRepository;
use AlohaSocial\Core\Domain\Collection\MediaCollectionSchema;
use AlohaSocial\Core\Domain\Collection\PdoMediaCollectionRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoMediaCollectionRepositoryTest extends MediaCollectionRepositoryTestCase
{
	protected function repository(): MediaCollectionRepository
	{
		$database = new PdoConnection(new PDO('sqlite::memory:'));
		MediaCollectionSchema::create($database);

		return new PdoMediaCollectionRepository($database);
	}
}
