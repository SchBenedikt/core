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
 * One immutable AT Protocol MST node.
 *
 * The wire form uses key-prefix compaction (`p`/`k`) and stores a left subtree
 * plus one optional right subtree per entry. Decoding reconstructs full keys
 * and rejects non-canonical prefixes, duplicate or unsorted keys and a CID
 * that does not match the encoded bytes.
 */
final readonly class MstNode
{
	/**
	 * @param list<MstEntry> $entries
	 */
	public function __construct(
		public ?Cid $left,
		public array $entries,
		public Cid $cid,
	) {
	}

	public static function decode(Cid $cid, string $bytes): self
	{
		if (!Cid::forData($bytes)->equals($cid)) {
			throw new UnexpectedValueException('The MST node block does not match its CID.');
		}

		$data = DagCbor::decode($bytes);
		if (!is_array($data) || array_is_list($data)
			|| !array_key_exists('l', $data)
			|| !array_key_exists('e', $data)) {
			throw new InvalidArgumentException('An MST node must be a map with l and e.');
		}

		$left = self::optionalCid($data['l'], 'l');
		$rawEntries = $data['e'];
		if (!is_array($rawEntries) || !array_is_list($rawEntries)) {
			throw new InvalidArgumentException('An MST node entry list must be an array.');
		}

		$entries = [];
		$lastKey = '';
		foreach ($rawEntries as $index => $rawEntry) {
			if (!is_array($rawEntry) || array_is_list($rawEntry)) {
				throw new InvalidArgumentException('Every MST entry must be a map.');
			}

			foreach (['p', 'k', 'v', 't'] as $required) {
				if (!array_key_exists($required, $rawEntry)) {
					throw new InvalidArgumentException('An MST entry is missing ' . $required . '.');
				}
			}

			$prefix = $rawEntry['p'];
			$suffix = $rawEntry['k'];
			$value = $rawEntry['v'];
			if (!is_int($prefix) || $prefix < 0
				|| !$suffix instanceof Bytes
				|| !$value instanceof Cid) {
				throw new InvalidArgumentException('An MST entry has a malformed prefix, suffix or value.');
			}

			if ($index === 0 && $prefix !== 0) {
				throw new InvalidArgumentException('The first MST entry must contain the full key.');
			}

			if ($prefix > strlen($lastKey)) {
				throw new InvalidArgumentException('An MST entry prefix exceeds the previous key.');
			}

			$key = substr($lastKey, 0, $prefix) . $suffix->value;
			MstKey::assertValid($key);

			if ($index === 0) {
				if ($prefix !== 0) {
					throw new InvalidArgumentException('The first MST entry must start at prefix zero.');
				}
			} else {
				if (strcmp($lastKey, $key) >= 0) {
					throw new InvalidArgumentException('MST entries must be strictly sorted by key.');
				}

				if ($prefix !== MstKey::commonPrefixLength($lastKey, $key)) {
					throw new InvalidArgumentException('An MST entry prefix is not the shared prefix with the previous key.');
				}
			}

			$entries[] = new MstEntry(
				key: $key,
				value: $value,
				right: self::optionalCid($rawEntry['t'], 't'),
			);
			$lastKey = $key;
		}

		return new self($left, $entries, $cid);
	}

	/**
	 * @param array<string, Cid> $values
	 */
	public static function forEntries(array $values): self
	{
		if ($values === []) {
			$data = ['l' => null, 'e' => []];
			$bytes = DagCbor::encode($data);

			return new self(null, [], Cid::forData($bytes));
		}

		$keys = array_keys($values);
		usort($keys, static fn (string $left, string $right): int => strcmp($left, $right));

		$layer = MstKey::layer($keys[0]);
		$entries = [];
		$rawEntries = [];
		$lastKey = '';

		foreach ($keys as $key) {
			if (!is_string($key)) {
				throw new InvalidArgumentException('MST keys must be strings.');
			}

			MstKey::assertValid($key);
			if (MstKey::layer($key) !== $layer) {
				throw new InvalidArgumentException('A single MST node can only hold keys from one layer.');
			}

			$value = $values[$key];
			if (!$value instanceof Cid) {
				throw new InvalidArgumentException('MST values must be CIDs.');
			}

			$prefix = MstKey::commonPrefixLength($lastKey, $key);
			$suffix = substr($key, $prefix);
			$entries[] = new MstEntry($key, $value, null);
			$rawEntries[] = [
				'p' => $prefix,
				'k' => new Bytes($suffix),
				'v' => $value,
				't' => null,
			];
			$lastKey = $key;
		}

		$bytes = DagCbor::encode(['l' => null, 'e' => $rawEntries]);

		return new self(null, $entries, Cid::forData($bytes));
	}

	public function encode(): string
	{
		$entries = [];
		$lastKey = '';

		foreach ($this->entries as $entry) {
			$prefix = MstKey::commonPrefixLength($lastKey, $entry->key);
			$entries[] = [
				'p' => $prefix,
				'k' => new Bytes(substr($entry->key, $prefix)),
				'v' => $entry->value,
				't' => $entry->right,
			];
			$lastKey = $entry->key;
		}

		return DagCbor::encode(['l' => $this->left, 'e' => $entries]);
	}

	private static function optionalCid(mixed $value, string $field): ?Cid
	{
		if ($value === null) {
			return null;
		}

		if (!$value instanceof Cid) {
			throw new InvalidArgumentException('The MST field ' . $field . ' must be a CID or null.');
		}

		return $value;
	}
}
