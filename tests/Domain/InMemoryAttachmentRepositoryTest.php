<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;

final class InMemoryAttachmentRepositoryTest extends AttachmentRepositoryTestCase
{
	protected function repository(): AttachmentRepository
	{
		return new InMemoryAttachmentRepository();
	}
}
