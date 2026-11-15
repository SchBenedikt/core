<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto\Repository;

/**
 * A DAG-CBOR byte string.
 *
 * PHP strings do not distinguish text from bytes. Repository signatures and
 * MST key suffixes must stay byte strings even when they happen to be valid
 * UTF-8, so the encoder takes this wrapper instead of guessing.
 */
final readonly class Bytes
{
	public function __construct(public string $value)
	{
	}
}
