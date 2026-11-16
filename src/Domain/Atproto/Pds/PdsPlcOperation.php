<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Protocol\Atproto\Repository\Bytes;
use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use AlohaSocial\Core\Protocol\Atproto\Repository\DagCbor;

final readonly class PdsPlcOperation
{
	public function __construct(
		public string $did,
		public Cid $cid,
		public int $seq,
		public string $bytes,
		public string $createdAt,
	) {
	}

	/**
	 * The signed operation as the PLC directory's JSON object.
	 *
	 * DAG-CBOR keeps the signature and `prev` as typed values. The directory
	 * accepts base64url signatures and CID strings, so this is a lossless wire
	 * conversion, not a second unsigned operation.
	 *
	 * @return array<string, mixed>
	 */
	public function json(): array
	{
		$value = DagCbor::decode($this->bytes);
		if (!is_array($value) || array_is_list($value)) {
			throw new \UnexpectedValueException('A PLC operation must be a DAG-CBOR map.');
		}

		$converted = $this->toJsonValue($value);
		if (!is_array($converted)) {
			throw new \UnexpectedValueException('A PLC operation must decode to a JSON object.');
		}

		return $converted;
	}

	private function toJsonValue(mixed $value): mixed
	{
		if ($value instanceof Cid) {
			return $value->toString();
		}

		if ($value instanceof Bytes) {
			return rtrim(strtr(base64_encode($value->value), '+/', '-_'), '=');
		}

		if (is_array($value)) {
			$output = [];
			foreach ($value as $key => $entry) {
				$output[$key] = $this->toJsonValue($entry);
			}

			return $output;
		}

		return $value;
	}
}
