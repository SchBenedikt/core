/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onBeforeUnmount, ref, shallowRef, watch, type Ref, type ShallowRef } from 'vue';
import { UiApiError, type UiFailure } from '../api-error.js';
import { toUiFailure } from '../failures.js';
import type { Account, Relationship } from '../types.js';
import { useCoreApi } from './use-core-api.js';

export interface ActorProfileState {
	readonly account: Ref<Account | null>;
	readonly loading: Ref<boolean>;
	readonly busy: Ref<boolean>;
	readonly error: ShallowRef<UiFailure | null>;
	toggleFollow(): Promise<void>;
	reload(): Promise<void>;
}

/**
 * Loads one local profile and, when the core returns a viewer relationship,
 * toggles the follow edge. A late response for a previous account id is
 * ignored, so switching profiles cannot flash the old account back.
 */
export function useActorProfile(accountId: Ref<string>): ActorProfileState {
	const api = useCoreApi();
	const account = ref<Account | null>(null);
	const loading = ref(true);
	const busy = ref(false);
	const error = shallowRef<UiFailure | null>(null);
	let generation = 0;
	let disposed = false;

	async function load(): Promise<void> {
		const id = accountId.value.trim();
		const current = ++generation;
		error.value = null;

		if (id === '') {
			account.value = null;
			loading.value = false;
			error.value = new UiApiError(400, 'invalid_account_id', 'An account id is required.');

			return;
		}

		loading.value = true;
		if (account.value?.id !== id) {
			account.value = null;
		}

		try {
			const next = await api.getAccount(id);
			if (!disposed && current === generation) {
				account.value = next;
			}
		} catch (failure) {
			if (!disposed && current === generation) {
				error.value = toUiFailure(failure);
			}
		} finally {
			if (!disposed && current === generation) {
				loading.value = false;
			}
		}
	}

	async function toggleFollow(): Promise<void> {
		const current = account.value;
		if (current === null || current.relationship === null || busy.value) {
			return;
		}

		busy.value = true;
		error.value = null;

		try {
			const relationship: Relationship = current.relationship.following
				? await api.unfollowAccount(current.id)
				: await api.followAccount(current.id);

			if (account.value?.id === current.id) {
				account.value = { ...account.value, relationship };
			}
		} catch (failure) {
			error.value = toUiFailure(failure);
		} finally {
			busy.value = false;
		}
	}

	watch(accountId, () => void load(), { immediate: true });

	onBeforeUnmount(() => {
		disposed = true;
	});

	return {
		account,
		loading,
		busy,
		error,
		toggleFollow,
		reload: load,
	};
}
