/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref, shallowRef, type Ref, type ShallowRef } from 'vue';
import type { UiFailure } from '../api-error.js';
import { toUiFailure } from '../failures.js';
import type { CreatePostInput, Post } from '../types.js';
import { useCoreApi } from './use-core-api.js';

export interface ComposerState {
	readonly submitting: Ref<boolean>;
	readonly error: ShallowRef<UiFailure | null>;
	submit(input: CreatePostInput): Promise<Post | null>;
	resetError(): void;
}

/**
 * Submits one post through the neutral write route. Client-side rules mirror
 * the core only to keep an empty or oversized draft from making a request;
 * the service remains the authority and its validation errors are shown.
 */
export function useComposer(): ComposerState {
	const api = useCoreApi();
	const submitting = ref(false);
	const error = shallowRef<UiFailure | null>(null);

	async function submit(input: CreatePostInput): Promise<Post | null> {
		if (submitting.value) {
			return null;
		}

		submitting.value = true;
		error.value = null;

		try {
			return await api.createPost(input);
		} catch (failure) {
			error.value = toUiFailure(failure);

			return null;
		} finally {
			submitting.value = false;
		}
	}

	return {
		submitting,
		error,
		submit,
		resetError: (): void => {
			error.value = null;
		},
	};
}
