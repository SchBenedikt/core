<script setup lang="ts">
import { computed } from 'vue';
import { usePublicTimeline } from '../composables/use-public-timeline.js';
import PostCard from './PostCard.vue';

const props = withDefaults(defineProps<{ limit?: number; tag?: string }>(), {
	limit: 20,
});

const normalizedTag = computed(() => props.tag?.trim().replace(/^#/, '') ?? '');
const label = computed(() => normalizedTag.value === ''
	? 'Public timeline'
	: `Hashtag #${normalizedTag.value} timeline`);
const loadingMessage = computed(() => normalizedTag.value === ''
	? 'Loading public timeline…'
	: `Loading hashtag #${normalizedTag.value}…`);
const emptyMessage = computed(() => normalizedTag.value === ''
	? 'No posts yet.'
	: `No posts for #${normalizedTag.value} yet.`);

const { posts, loading, error, hasMore, loadMore, reload } = usePublicTimeline(props.limit, props.tag);
</script>

<template>
	<section class="aloha-timeline" :aria-label="label" :aria-busy="loading">
		<p v-if="loading && posts.length === 0" class="aloha-timeline__status" role="status" aria-live="polite">
			{{ loadingMessage }}
		</p>

		<div v-else-if="error && posts.length === 0" class="aloha-timeline__error" role="alert">
			<p>{{ error.message }}</p>
			<button type="button" :disabled="loading" @click="reload">Try again</button>
		</div>

		<p v-else-if="posts.length === 0" class="aloha-timeline__status" role="status" aria-live="polite">
			{{ emptyMessage }}
		</p>

		<template v-else>
			<ul class="aloha-timeline__list" aria-label="Posts">
				<li v-for="post in posts" :key="post.id">
					<PostCard :post="post" />
				</li>
			</ul>

			<p v-if="error" class="aloha-timeline__error" role="alert">
				{{ error.message }}
				<button type="button" :disabled="loading" @click="loadMore">Try again</button>
			</p>

			<button
				v-if="hasMore && !error"
				class="aloha-timeline__more"
				type="button"
				:disabled="loading"
				@click="loadMore"
			>
				{{ loading ? 'Loading…' : 'Load more' }}
			</button>
		</template>
	</section>
</template>

<style scoped>
.aloha-timeline {
	display: grid;
	gap: 1rem;
}

.aloha-timeline__list {
	display: grid;
	gap: 0.75rem;
	list-style: none;
	margin: 0;
	padding: 0;
}

.aloha-timeline__status,
.aloha-timeline__error {
	margin: 0;
	color: var(--aloha-muted-text, #5f6b7a);
}

.aloha-timeline__error {
	color: var(--aloha-error-text, #9f1d20);
}

.aloha-timeline__more {
	justify-self: start;
	padding: 0.5rem 0.9rem;
	border: 1px solid var(--aloha-border-color, #d9dee7);
	border-radius: 0.5rem;
	background: var(--aloha-button-background, transparent);
	cursor: pointer;
}

.aloha-timeline__more:disabled {
	cursor: wait;
	opacity: 0.65;
}

.aloha-timeline__more:focus-visible {
	outline: 2px solid var(--aloha-focus-color, #2457a6);
	outline-offset: 2px;
}
</style>
