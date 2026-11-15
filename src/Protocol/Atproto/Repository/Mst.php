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
 * Read-only traversal of an AT Protocol Merkle Search Tree.
 *
 * The tree is content addressed, so every lookup and full walk verifies the
 * block CID first. A cycle, a missing block, an out-of-range key or a child
 * from the same or a higher layer is refused instead of silently returning a
 * partial record set.
 */
final class Mst
{
	/** @var array<string, MstNode> */
	private array $nodes = [];

	/**
	 * @param array<string, string> $blocks keyed by CID string
	 */
	public function __construct(
		private readonly Cid $root,
		private readonly array $blocks,
	) {
	}

	public function root(): Cid
	{
		return $this->root;
	}

	public function lookup(string $key): ?Cid
	{
		MstKey::assertValid($key);

		$cid = $this->root;
		$visited = [];
		for ($depth = 0; $depth <= 256; ++$depth) {
			$node = $this->node($cid);
			if (isset($visited[$cid->toString()])) {
				throw new UnexpectedValueException('The MST contains a cycle.');
			}
			$visited[$cid->toString()] = true;

			if ($node->entries === []) {
				if ($node->left === null) {
					return null;
				}

				$cid = $node->left;

				continue;
			}

			$index = $this->firstAtOrAfter($node->entries, $key);
			if ($index !== null && $node->entries[$index]->key === $key) {
				return $node->entries[$index]->value;
			}

			$next = $index === null
				? $node->entries[count($node->entries) - 1]->right ?? null
				: ($index === 0 ? $node->left : $node->entries[$index - 1]->right);

			if ($next === null) {
				return null;
			}

			$cid = $next;
		}

		throw new UnexpectedValueException('The MST is deeper than the supported limit.');
	}

	/**
	 * @return array<string, Cid>
	 */
	public function all(): array
	{
		$records = [];
		$this->walk($this->root, null, null, null, $records, []);

		ksort($records, SORT_STRING);

		return $records;
	}

	/**
	 * @param array<string, Cid> $records
	 * @param array<string, true> $path
	 */
	private function walk(
		Cid $cid,
		?string $minimum,
		?string $maximum,
		?int $parentLayer,
		array &$records,
		array $path,
	): void {
		$id = $cid->toString();
		if (isset($path[$id])) {
			throw new UnexpectedValueException('The MST contains a cycle.');
		}

		$path[$id] = true;
		$node = $this->node($cid);
		$entries = $node->entries;
		$layer = $entries === [] ? null : MstKey::layer($entries[0]->key);

		if ($layer !== null) {
			foreach ($entries as $entry) {
				if (MstKey::layer($entry->key) !== $layer) {
					throw new UnexpectedValueException('An MST node mixes keys from different layers.');
				}
			}
		}

		if ($parentLayer !== null && $layer !== null && $layer >= $parentLayer) {
			throw new UnexpectedValueException('An MST child must be on a lower layer than its parent.');
		}

		if ($entries === [] && $node->left === null && $parentLayer !== null) {
			throw new UnexpectedValueException('An empty MST child must point to a subtree or hold a key.');
		}

		if ($node->left !== null) {
			$first = $entries[0]->key ?? $maximum;
			$this->walk($node->left, $minimum, $first, $layer, $records, $path);
		}

		foreach ($entries as $index => $entry) {
			if (($minimum !== null && strcmp($entry->key, $minimum) <= 0)
				|| ($maximum !== null && strcmp($entry->key, $maximum) >= 0)) {
				throw new UnexpectedValueException('An MST key falls outside its subtree range.');
			}

			if (isset($records[$entry->key])) {
				throw new UnexpectedValueException('The MST contains a duplicate key.');
			}

			$records[$entry->key] = $entry->value;
			$next = $entries[$index + 1]->key ?? $maximum;
			if ($entry->right !== null) {
				$this->walk($entry->right, $entry->key, $next, $layer, $records, $path);
			}
		}
	}

	private function node(Cid $cid): MstNode
	{
		$key = $cid->toString();
		if (isset($this->nodes[$key])) {
			return $this->nodes[$key];
		}

		$bytes = $this->blocks[$key] ?? null;
		if ($bytes === null) {
			throw new UnexpectedValueException('The MST block ' . $key . ' is missing.');
		}

		return $this->nodes[$key] = MstNode::decode($cid, $bytes);
	}

	/**
	 * @param list<MstEntry> $entries
	 */
	private function firstAtOrAfter(array $entries, string $key): ?int
	{
		$low = 0;
		$high = count($entries);

		while ($low < $high) {
			$middle = intdiv($low + $high, 2);
			if (strcmp($entries[$middle]->key, $key) < 0) {
				$low = $middle + 1;
			} else {
				$high = $middle;
			}
		}

		return $low < count($entries) ? $low : null;
	}
}
