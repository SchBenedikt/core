<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform\Storage;

use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;

final class InMemoryBlobStoreTest extends BlobStoreTestCase
{
	protected function store(): BlobStore
	{
		return new InMemoryBlobStore();
	}
}
