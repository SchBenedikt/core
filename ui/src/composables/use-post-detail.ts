/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import {
	onBeforeUnmount,
	ref,
	shallowRef,
	toValue,
	watch,
	type MaybeRefOrGetter,
	type Ref,
	type ShallowRef,
} from 'vue';
import { isUiFailure, UiTransportError, type UiFailure } from '../api-error.js';
import type { Post } from '../types.js';
import { useCoreApi } from './use-core-api.js';

export interface PostDetailState {
	readonly post: Ref<Post | null>;
	readonly loading: Ref<boolean>;
	readonly error: ShallowRef<UiFailure | null>;
	reload(): Promise<void>;
}

/**
 * Loads one post by its opaque core id. The route is public, so a signed-out
 * reader gets the same projection as the timeline; the composable still
 * leaves 401 handling to the host bridge and never navigates on its own.
 *
 * The request id makes a prop change safe: a late response for the previous
 * post id cannot overwrite the new detail view.
 */
export function usePostDetail(postId: MaybeRefOrGetter<string>): PostDetailState {
	const api = useCoreApi();
	const post = ref<Post | null>(null);
	// Start in the loading state so the first render does not flash an empty
	// detail page before the route answers.
	const loading = ref(true);
	const error = shallowRef<UiFailure | null>(null);
	let disposed = false;
	let requestId = 0;

	async function load(): Promise<void> {
		const currentRequest = ++requestId;
		const id = toValue(postId);

		loading.value = true;
		error.value = null;
		post.value = null;

		try {
			const loaded = await api.getPost(id);
			if (disposed || currentRequest !== requestId) {
				return;
			}

			post.value = loaded;
		} catch (failure) {
			if (!disposed && currentRequest === requestId) {
				error.value = toFailure(failure);
			}
		} finally {
			if (!disposed && currentRequest === requestId) {
				loading.value = false;
			}
		}
	}

	watch(() => toValue(postId), () => {
		void load();
	}, { immediate: true });

	onBeforeUnmount(() => {
		disposed = true;
		requestId++;
	});

	return {
		post,
		loading,
		error,
		reload: load,
	};
}

function toFailure(error: unknown): UiFailure {
	if (isUiFailure(error)) {
		return error;
	}

	return new UiTransportError();
}
