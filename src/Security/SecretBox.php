<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Security;

/**
 * Encryption at rest for material that must not be readable from a database
 * dump: actor private keys, OAuth client secrets.
 *
 * The Nextcloud host backs this with the server's crypto service, the
 * standalone host with a key from its environment or secret store.
 */
interface SecretBox
{
	/**
	 * @return string a self-describing, base64 payload
	 */
	public function seal(string $plaintext): string;

	/**
	 * @throws SecretBoxException when the payload was tampered with or belongs to another key
	 */
	public function open(string $payload): string;
}
