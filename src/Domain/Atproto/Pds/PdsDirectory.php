<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

/**
 * Publishes a signed PLC operation to the PLC directory.
 *
 * The PLC directory is an external service. This port keeps the transport in
 * the host, where proxy, CA and retry policy already live, while the PDS owns
 * operation construction and signing. A host that does not configure a
 * directory can still create identities; it can retry publication later with
 * `submitPlcOperation`.
 */
interface PdsDirectory
{
	/**
	 * @throws \Throwable when the operation was not accepted
	 */
	public function submit(PdsPlcOperation $operation): void;
}
