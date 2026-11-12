<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Domain\Media\Attachment;
use InvalidArgumentException;

/**
 * One image attachment advertised by a remote ActivityPub object.
 *
 * The URL is the remote identity used for idempotence; the declared media type
 * is optional and is still checked against the bytes by the media service.
 */
final readonly class RemoteAttachment
{
	public function __construct(
		public string $url,
		public ?string $declaredMimeType = null,
		public ?string $altText = null,
	) {
		if ($this->url === '' || mb_strlen($this->url) > Attachment::MAX_SOURCE_URL_LENGTH) {
			throw new InvalidArgumentException('A remote attachment needs a bounded URL.');
		}
	}
}
