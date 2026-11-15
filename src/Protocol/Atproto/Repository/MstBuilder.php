<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto\Repository;

use InvalidArgumentException;

/**
 * Builds a complete AT Protocol MST from a key/value map.
 *
 * A node at layer L owns every key whose hash has exactly L leading two-bit
 * zero pairs. Lower-layer keys between two entries become that entry's right
 * subtree; keys before the first entry become the left subtree. Empty
 * structural nodes are inserted when the next key layer is more than one step
 * below the parent, which keeps every parent/child layer edge well-formed.
 */
final class MstBuilder
{
	/**
	 * @param array<string, Cid> $values
	 *
	 * @return array{root: Cid, blocks: array<string, string>}
	 */
	public function build(array $values): array
	{
		$keys = array_keys($values);
		foreach ($keys as $key) {
			if (!is_string($key)) {
				throw new InvalidArgumentException('MST keys must be strings.');
			}
			MstKey::assertValid($key);
		}

		usort($keys, static fn (string $left, string $right): int => strcmp($left, $right));

		if ($keys === []) {
			$node = MstNode::fromParts(null, []);
			$blocks = [$node->cid->toString() => $node->encode()];

			return ['root' => $node->cid, 'blocks' => $blocks];
		}

		$highest = 0;
		foreach ($keys as $key) {
			$highest = max($highest, MstKey::layer($key));
		}

		$blocks = [];
		$root = $this->buildRange($keys, $values, 0, count($keys), $highest, $blocks);
		if ($root === null) {
			throw new InvalidArgumentException('A non-empty MST must have a root.');
		}

		return ['root' => $root, 'blocks' => $blocks];
	}

	/**
	 * @param list<string>          $keys
	 * @param array<string, Cid>    $values
	 * @param array<string, string> $blocks
	 */
	private function buildRange(
		array $keys,
		array $values,
		int $start,
		int $end,
		int $layer,
		array &$blocks,
	): ?Cid {
		if ($start >= $end) {
			return null;
		}

		if ($layer < 0) {
			throw new InvalidArgumentException('The MST layer cannot be negative.');
		}

		$leaves = [];
		for ($index = $start; $index < $end; ++$index) {
			if (MstKey::layer($keys[$index]) === $layer) {
				$leaves[] = $index;
			}
		}

		if ($leaves === []) {
			$child = $this->buildRange($keys, $values, $start, $end, $layer - 1, $blocks);
			if ($child === null) {
				return null;
			}

			$node = MstNode::fromParts($child, []);
			$blocks[$node->cid->toString()] = $node->encode();

			return $node->cid;
		}

		$left = $this->buildRange($keys, $values, $start, $leaves[0], $layer - 1, $blocks);
		$entries = [];
		foreach ($leaves as $position => $index) {
			$rightStart = $index + 1;
			$rightEnd = $leaves[$position + 1] ?? $end;
			$right = $this->buildRange($keys, $values, $rightStart, $rightEnd, $layer - 1, $blocks);
			$entries[] = new MstEntry($keys[$index], $values[$keys[$index]], $right);
		}

		$node = MstNode::fromParts($left, $entries);
		$blocks[$node->cid->toString()] = $node->encode();

		return $node->cid;
	}
}
