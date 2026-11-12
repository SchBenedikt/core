<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Protocol\Atproto\DpopProofProvider;

/**
 * Records proof inputs without doing any cryptography.
 */
final class RecordingDpopProofProvider implements DpopProofProvider
{
	/**
	 * @var list<array{method: string, url: string, nonce: ?string}>
	 */
	public array $calls = [];

	public function proof(string $method, string $url, ?string $nonce = null): string
	{
		$this->calls[] = ['method' => $method, 'url' => $url, 'nonce' => $nonce];

		return 'proof-' . count($this->calls);
	}
}
