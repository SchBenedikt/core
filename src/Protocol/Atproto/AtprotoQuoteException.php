<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use RuntimeException;

final class AtprotoQuoteException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('The quoted AT Protocol post could not be verified on the PDS.');
	}
}
