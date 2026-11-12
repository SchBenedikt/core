<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Mastodon;

use AlohaSocial\Core\Http\HttpException;

/**
 * A Mastodon-compatible API refusal with a stable HTTP status and code.
 *
 * It is already an {@see HttpException}, so a service refusal can never fall
 * through to a generic 500. The handler still maps it explicitly to keep the
 * API boundary easy to read.
 */
final class MastodonRequestException extends HttpException
{
	public function __construct(
		int $status,
		string $errorCode,
		string $message,
	) {
		parent::__construct($status, $errorCode, $message);
	}
}
