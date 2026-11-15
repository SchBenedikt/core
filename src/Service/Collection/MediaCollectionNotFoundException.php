<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Collection;

use RuntimeException;

final class MediaCollectionNotFoundException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Collection not found.');
	}
}
