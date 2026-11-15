<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Story;

use RuntimeException;

final class StoryMediaUnavailableException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Story media is not locally available.');
	}
}
