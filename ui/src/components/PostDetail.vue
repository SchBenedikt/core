<script setup lang="ts">
import { computed } from 'vue';
import { usePostDetail } from '../composables/use-post-detail.js';
import PostCard from './PostCard.vue';

const props = defineProps<{ postId: string }>();
const detail = usePostDetail(computed(() => props.postId));
const { post, loading, error, reload } = detail;
</script>

<template>
	<section class="aloha-post-detail" aria-label="Post detail" :aria-busy="loading">
		<p v-if="loading && post === null" class="aloha-post-detail__status" role="status" aria-live="polite">
			Loading post…
		</p>

		<div v-else-if="error && post === null" class="aloha-post-detail__error" role="alert">
			<p>{{ error.message }}</p>
			<button type="button" :disabled="loading" @click="reload">Try again</button>
		</div>

		<p v-else-if="post === null" class="aloha-post-detail__status" role="status" aria-live="polite">
			Post not found.
		</p>

		<template v-else>
			<PostCard :post="post" />

			<p v-if="error" class="aloha-post-detail__error" role="alert">
				{{ error.message }}
				<button type="button" :disabled="loading" @click="reload">Try again</button>
			</p>
		</template>
	</section>
</template>

<style scoped>
.aloha-post-detail {
	display: grid;
	gap: 1rem;
}

.aloha-post-detail__status,
.aloha-post-detail__error {
	margin: 0;
	color: var(--aloha-muted-text, #5f6b7a);
}

.aloha-post-detail__error {
	color: var(--aloha-error-text, #9f1d20);
}

.aloha-post-detail__error button {
	margin-left: 0.5rem;
	padding: 0.5rem 0.9rem;
	border: 1px solid var(--aloha-border-color, #d9dee7);
	border-radius: 0.5rem;
	background: var(--aloha-button-background, transparent);
	cursor: pointer;
}

.aloha-post-detail__error button:disabled {
	cursor: wait;
	opacity: 0.65;
}

.aloha-post-detail__error button:focus-visible {
	outline: 2px solid var(--aloha-focus-color, #2457a6);
	outline-offset: 2px;
}
</style>
