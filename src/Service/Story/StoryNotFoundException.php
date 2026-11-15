<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Story;

use RuntimeException;

/**
 * Also used for a story the viewer may not see: a missing and a forbidden
 * story answer identically so an id cannot be probed for existence.
 */
final class StoryNotFoundException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Story not found.');
	}
}
