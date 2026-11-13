<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

/**
 * Exposes the thumbprint of the DPoP key for an AT Protocol PAR request.
 *
 * The AT Protocol OAuth profile binds the pushed authorization request to the
 * DPoP key with `dpop_jkt`. A proof provider that cannot expose the public
 * thumbprint cannot start an authorization flow; refresh-only providers do not
 * need this capability.
 */
interface DpopKeyThumbprint
{
	public function thumbprint(): string;
}
