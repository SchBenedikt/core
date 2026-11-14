<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Migration;

use function array_key_exists;
use function is_string;

/**
 * Compares what the old and the new read path return for the same slice.
 *
 * A cutover is read-only until this comparison is clean: both sides are asked
 * for the same records (the host fills the rows, the core only compares), and
 * every difference becomes a finding. A record the old side had but the new
 * side does not is a failure — that is the silent data loss the gate exists
 * for. A record only the new side returns, or a field only the new side adds,
 * is a warning: the read path grew, which is reviewable but not lost data.
 *
 * The rows are canonical neutral arrays with an `id`; no host schema is
 * assumed, which is what makes the same fixture reusable by either host.
 */
final class ShadowReadComparator
{
	/**
	 * @param list<array<string, mixed>> $oldRows
	 * @param list<array<string, mixed>> $newRows
	 */
	public function compare(string $feature, array $oldRows, array $newRows): MigrationReport
	{
		/** @var list<MigrationViolation> $violations */
		$violations = [];

		$old = $this->index($feature, $oldRows, $violations);
		$new = $this->index($feature, $newRows, $violations);

		foreach ($old as $id => $row) {
			$record = $feature . '[' . $id . ']';

			if (!array_key_exists($id, $new)) {
				$violations[] = new MigrationViolation(
					$record,
					'id',
					'The record is missing in the new read path.',
					$id,
					null,
				);

				continue;
			}

			$newRow = $new[$id];
			foreach ($row as $field => $value) {
				if (!array_key_exists($field, $newRow)) {
					$violations[] = new MigrationViolation(
						$record,
						$field,
						'The field is missing in the new read path.',
						$value,
						null,
					);

					continue;
				}

				if ($newRow[$field] !== $value) {
					$violations[] = new MigrationViolation(
						$record,
						$field,
						'The two read paths return different values.',
						$value,
						$newRow[$field],
					);
				}
			}

			foreach ($newRow as $field => $value) {
				if (!array_key_exists($field, $row)) {
					$violations[] = new MigrationViolation(
						$record,
						$field,
						'The new read path adds a field the old one did not return.',
						null,
						$value,
						MigrationViolation::WARN,
					);
				}
			}
		}

		foreach ($new as $id => $row) {
			if (!array_key_exists($id, $old)) {
				$violations[] = new MigrationViolation(
					$feature . '[' . $id . ']',
					'id',
					'The record exists only in the new read path.',
					null,
					$id,
					MigrationViolation::WARN,
				);
			}
		}

		return new MigrationReport('shadow:' . $feature, $violations);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param list<MigrationViolation> $violations
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function index(string $feature, array $rows, array &$violations): array
	{
		$indexed = [];
		foreach ($rows as $position => $row) {
			$id = $row['id'] ?? null;
			if (!is_string($id) || $id === '') {
				throw new InvalidMigrationDatasetException(
					$feature . '[' . $position . '].id: expected a non-empty string.',
				);
			}

			if (array_key_exists($id, $indexed)) {
				$violations[] = new MigrationViolation(
					$feature . '[' . $id . ']',
					'id',
					'Duplicate record id in one read path.',
					'unique id',
					$id,
				);

				continue;
			}

			$indexed[$id] = $row;
		}

		return $indexed;
	}
}
