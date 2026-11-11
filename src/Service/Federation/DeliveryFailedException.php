<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use RuntimeException;

/**
 * A peer answered a delivery with a non-2xx status.
 */
final class DeliveryFailedException extends RuntimeException
{
	public function __construct(
		public readonly string $inbox,
		public readonly int $status,
	) {
		parent::__construct('Delivery to ' . $inbox . ' failed with HTTP ' . $status . '.');
	}
}
