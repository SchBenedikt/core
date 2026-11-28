<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Translation;

use RuntimeException;

/**
 * Translation was requested but this instance has no provider configured.
 */
final class TranslationUnavailableException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Translation is not configured on this instance.');
	}
}
