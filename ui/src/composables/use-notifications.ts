/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { onBeforeUnmount, onMounted, ref, shallowRef, watch, type Ref, type ShallowRef } from 'vue';
import type { UiFailure } from '../api-error.js';
import { normalizeNotificationLimit } from '../core-api.js';
import { toUiFailure } from '../failures.js';
import type { Notification } from '../types.js';
import { useCoreApi } from './use-core-api.js';

export interface NotificationsState {
	readonly notifications: Ref<Notification[]>;
	readonly unreadCount: Ref<number>;
	readonly loading: Ref<boolean>;
	readonly mutating: Ref<boolean>;
	readonly error: ShallowRef<UiFailure | null>;
	readonly hasMore: Ref<boolean>;
	loadMore(): Promise<void>;
	reload(): Promise<void>;
	dismiss(id: string): Promise<void>;
	clear(): Promise<void>;
}

/**
 * The first page of unread notifications. The cursor is the last notification
 * id; the core returns the current unread count with every page, so the badge
 * and list cannot disagree after a dismiss or clear.
 */
export function useNotifications(limit?: number, enabled?: Ref<boolean>): NotificationsState {
	const api = useCoreApi();
	const pageLimit = normalizeNotificationLimit(limit);
	const notifications = ref<Notification[]>([]);
	const unreadCount = ref(0);
	const loading = ref(true);
	const mutating = ref(false);
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

		const before = reset ? undefined : notifications.value.at(-1)?.id;
		if (!reset && before === undefined) {
			hasMore.value = false;

			return;
		}

		loading.value = true;
		error.value = null;

		try {
			const page = await api.notifications(
				before === undefined ? { limit: pageLimit } : { limit: pageLimit, before },
			);

			if (disposed) {
				return;
			}

			notifications.value = reset
				? page.notifications
				: mergeNotifications(notifications.value, page.notifications);
			unreadCount.value = page.unreadCount;
			hasMore.value = page.notifications.length === pageLimit;
		} catch (failure) {
			if (!disposed) {
				error.value = toUiFailure(failure);
			}
		} finally {
			if (!disposed) {
				loading.value = false;
			}
		}
	}

	async function dismiss(id: string): Promise<void> {
		if (mutating.value) {
			return;
		}

		mutating.value = true;
		error.value = null;

		try {
			await api.dismissNotification(id);
			if (disposed) {
				return;
			}

			notifications.value = notifications.value.filter((notification) => notification.id !== id);
			unreadCount.value = Math.max(0, unreadCount.value - 1);
		} catch (failure) {
			if (!disposed) {
				error.value = toUiFailure(failure);
			}
		} finally {
			mutating.value = false;
		}
	}

	async function clear(): Promise<void> {
		if (mutating.value) {
			return;
		}

		mutating.value = true;
		error.value = null;

		try {
			await api.clearNotifications();
			if (disposed) {
				return;
			}

			notifications.value = [];
			unreadCount.value = 0;
			hasMore.value = false;
		} catch (failure) {
			if (!disposed) {
				error.value = toUiFailure(failure);
			}
		} finally {
			mutating.value = false;
		}
	}

	if (enabled === undefined) {
		onMounted(() => {
			void loadPage(true);
		});
	} else {
		// The screen checks the host session first. Waiting keeps an anonymous
		// page from making a request that can only answer 401.
		watch(enabled, (active) => {
			if (active && !started) {
				void loadPage(true);
			}
		}, { immediate: true });
	}

	onBeforeUnmount(() => {
		disposed = true;
	});

	return {
		notifications,
		unreadCount,
		loading,
		mutating,
		error,
		hasMore,
		loadMore: (): Promise<void> => loadPage(false),
		reload: (): Promise<void> => loadPage(true),
		dismiss,
		clear,
	};
}

function mergeNotifications(existing: Notification[], page: Notification[]): Notification[] {
	const merged = new Map<string, Notification>();
	for (const notification of existing) {
		merged.set(notification.id, notification);
	}
	for (const notification of page) {
		merged.set(notification.id, notification);
	}

	return [...merged.values()];
}
