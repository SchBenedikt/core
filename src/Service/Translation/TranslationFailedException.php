<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Translation;

use RuntimeException;

/**
 * The provider could not produce a translation for an advertised pair.
 */
final class TranslationFailedException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('The translation provider failed.');
	}
}
