<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\InMemoryPostPinRepository;
use AlohaSocial\Core\Domain\Post\PostPinRepository;

final class InMemoryPostPinRepositoryTest extends PostPinRepositoryTestCase
{
	protected function repository(): PostPinRepository
	{
		return new InMemoryPostPinRepository();
	}
}
