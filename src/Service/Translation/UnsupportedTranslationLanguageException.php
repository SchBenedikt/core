<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Translation;

use RuntimeException;

/**
 * The requested target language is not one the provider advertises.
 */
final class UnsupportedTranslationLanguageException extends RuntimeException
{
	public function __construct(
		public readonly string $language,
	) {
		parent::__construct('Unsupported translation language: ' . $language);
	}
}
