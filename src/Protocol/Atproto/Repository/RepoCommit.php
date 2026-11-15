<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto\Repository;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * A version-3 AT Protocol repository commit.
 *
 * The signature covers the DAG-CBOR encoding of the unsigned commit, exactly
 * as the reference implementation does. The signed commit is itself a
 * dag-cbor block and gets its own CID; `prev` links it to the prior commit.
 */
final readonly class RepoCommit
{
	public const VERSION = 3;

	private const DID_PATTERN = '/^did:[a-z]+:[a-zA-Z0-9._:%-]+$/';

	public function __construct(
		public string $did,
		public string $rev,
		public ?Cid $previous,
		public Cid $data,
		public ?string $signature = null,
	) {
		if (preg_match(self::DID_PATTERN, $did) !== 1) {
			throw new InvalidArgumentException('The commit DID is malformed.');
		}

		if ($rev === '' || strlen($rev) > 64) {
			throw new InvalidArgumentException('The commit revision is malformed.');
		}

		if ($signature !== null && strlen($signature) !== Ecdsa::SIGNATURE_BYTES) {
			throw new InvalidArgumentException('The commit signature must be 64 raw bytes.');
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		foreach (['did', 'version', 'rev', 'prev', 'data'] as $required) {
			if (!array_key_exists($required, $data)) {
				throw new InvalidArgumentException('The commit is missing ' . $required . '.');
			}
		}

		if ($data['version'] !== self::VERSION
			|| !is_string($data['did'])
			|| !is_string($data['rev'])
			|| !$data['data'] instanceof Cid
			|| ($data['prev'] !== null && !$data['prev'] instanceof Cid)) {
			throw new InvalidArgumentException('The commit fields have invalid types.');
		}

		$signature = $data['sig'] ?? null;
		if ($signature instanceof Bytes) {
			$signature = $signature->value;
		}

		if ($signature !== null && !is_string($signature)) {
			throw new InvalidArgumentException('The commit signature must be a byte string.');
		}

		return new self(
			did: $data['did'],
			rev: $data['rev'],
			previous: $data['prev'],
			data: $data['data'],
			signature: $signature,
		);
	}

	public static function decode(string $bytes): self
	{
		$data = DagCbor::decode($bytes);
		if (!is_array($data) || array_is_list($data)) {
			throw new InvalidArgumentException('A repository commit must be a DAG-CBOR map.');
		}

		return self::fromArray($data);
	}

	public static function sign(
		string $did,
		string $rev,
		?Cid $previous,
		Cid $data,
		string $privateKeyPem,
	): self {
		$unsigned = new self($did, $rev, $previous, $data);
		$signature = Ecdsa::sign($unsigned->unsignedBytes(), $privateKeyPem);

		return new self($did, $rev, $previous, $data, $signature);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(bool $withSignature = true): array
	{
		$data = [
			'did' => $this->did,
			'version' => self::VERSION,
			'rev' => $this->rev,
			'prev' => $this->previous,
			'data' => $this->data,
		];

		if ($withSignature) {
			if ($this->signature === null) {
				throw new UnexpectedValueException('The commit has no signature.');
			}

			$data['sig'] = new Bytes($this->signature);
		}

		return $data;
	}

	public function unsignedBytes(): string
	{
		return DagCbor::encode($this->toArray(false));
	}

	public function bytes(): string
	{
		return DagCbor::encode($this->toArray(true));
	}

	public function cid(): Cid
	{
		return Cid::forData($this->bytes());
	}

	public function verify(string $publicKeyPem): bool
	{
		if ($this->signature === null) {
			return false;
		}

		return Ecdsa::verify($this->unsignedBytes(), $this->signature, $publicKeyPem);
	}
}
