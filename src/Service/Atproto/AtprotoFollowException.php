<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use RuntimeException;

final class AtprotoFollowException extends RuntimeException
{
	public const SUBJECT_INVALID = 'subject_invalid';

	public const ACCOUNT_CHANGED = 'account_changed';

	public const RECORD_MISMATCH = 'record_mismatch';

	public const RECORD_UNAVAILABLE = 'record_unavailable';

	public function __construct(public readonly string $reason, string $message)
	{
		parent::__construct($message);
	}
}
