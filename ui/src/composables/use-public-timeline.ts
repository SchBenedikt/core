/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onBeforeUnmount, onMounted, ref, shallowRef, type Ref, type ShallowRef } from 'vue';
import { isUiFailure, UiTransportError, type UiFailure } from '../api-error.js';
import { normalizeTimelineLimit } from '../core-api.js';
import type { Post } from '../types.js';
import { useCoreApi } from './use-core-api.js';

export interface PublicTimelineState {
	readonly posts: Ref<Post[]>;
	readonly loading: Ref<boolean>;
	readonly error: ShallowRef<UiFailure | null>;
	readonly hasMore: Ref<boolean>;
	loadMore(): Promise<void>;
	reload(): Promise<void>;
}

export function usePublicTimeline(limit?: number, tag?: string): PublicTimelineState {
	const api = useCoreApi();
	const pageLimit = normalizeTimelineLimit(limit);
	const posts = ref<Post[]>([]);
	// The first render is a loading state, not an empty timeline. The mounted
	// hook starts the request; this avoids a misleading "No posts yet" flash.
	const loading = ref(true);
	const error = shallowRef<UiFailure | null>(null);
	const hasMore = ref(false);
	let disposed = false;
	let started = false;

	async function loadPage(reset: boolean): Promise<void> {
		if (reset) {
			if (started && loading.value) {
				return;
			}
			started = true;
		} else if (loading.value || !hasMore.value) {
			return;
		}

		const before = reset ? undefined : lastCursor(posts.value);
		if (!reset && before === undefined) {
			hasMore.value = false;
			return;
		}

		loading.value = true;
		error.value = null;

		try {
			const options = before === undefined ? { limit: pageLimit } : { limit: pageLimit, before };
			const page = tag === undefined || tag.trim() === ''
				? await api.publicTimeline(options)
				: await api.hashtagTimeline(tag, options);

			if (disposed) {
				return;
			}

			posts.value = reset ? page : mergePosts(posts.value, page);
			const nextCursor = lastCursor(posts.value);
			hasMore.value = page.length === pageLimit
				&& nextCursor !== undefined
				&& nextCursor !== before;
		} catch (failure) {
			if (!disposed) {
				error.value = toFailure(failure);
			}
		} finally {
			if (!disposed) {
				loading.value = false;
			}
		}
	}

	onMounted(() => {
		void loadPage(true);
	});

	onBeforeUnmount(() => {
		disposed = true;
	});

	return {
		posts,
		loading,
		error,
		hasMore,
		loadMore: (): Promise<void> => loadPage(false),
		reload: (): Promise<void> => loadPage(true),
	};
}

function lastCursor(posts: Post[]): string | undefined {
	return posts.at(-1)?.published_at;
}

function mergePosts(existing: Post[], page: Post[]): Post[] {
	const merged = new Map<string, Post>();
	for (const post of existing) {
		merged.set(post.id, post);
	}
	for (const post of page) {
		merged.set(post.id, post);
	}

	return [...merged.values()];
}

function toFailure(error: unknown): UiFailure {
	if (isUiFailure(error)) {
		return error;
	}

	return new UiTransportError();
}
