<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

final readonly class UploadedFile
{
	public function __construct(
		public string $fieldName,
		public string $filename,
		public string $contentType,
		public string $contents,
	) {
		if ($fieldName === '' || $filename === '') {
			throw new \InvalidArgumentException('An uploaded file needs a field name and a filename.');
		}
	}
}
