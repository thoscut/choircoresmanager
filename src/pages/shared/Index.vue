<template>
	<Layout :title="t('Shared with me')">
		<template #content>
			<ContentStateWrapper
				:loading="loading"
				:error="loadError"
				:isEmpty="targets.length === 0"
				:errorText="t('Failed to load shared items')"
				:errorDescription="t('Please try again later.')"
				:emptyText="t('Nothing shared with you yet')"
				:emptyDescription="t('Scores and folder collections shared with one of your groups appear here.')">
				<template #emptyIcon>
					<ShareIcon :size="64" />
				</template>
				<template #default>
					<div class="shared-page">
						<section v-for="target in targets" :key="`${target.targetType}-${target.targetId}`" class="shared-page__target">
							<div class="shared-page__heading">
								<FolderCollectionIcon v-if="target.targetType === 'folder_collection'" :size="24" />
								<ScoreIcon v-else :size="24" />
								<h2>{{ target.title }}</h2>
							</div>
							<p v-if="target.description" class="shared-page__description">
								{{ target.description }}
							</p>
							<SharedTargetView :target="target" :loadFile="scoresStore.fetchScoreFile" />
						</section>
					</div>
				</template>
			</ContentStateWrapper>
		</template>
	</Layout>
</template>

<script setup lang="ts">
import type { SharedTarget } from '@/api/generated/openapi/data-contracts'

import { onMounted, ref } from 'vue'
import ContentStateWrapper from '@/components/ContentStateWrapper.vue'
import Layout from '@/components/Layout.vue'
import SharedTargetView from '@/components/shares/SharedTargetView.vue'
import { apiClients } from '@/api/client'
import { FolderCollectionIcon, ScoreIcon, ShareIcon } from '@/icons/vue-material'
import { useScoresStore } from '@/stores/scoresStore'
import { t } from '@/utils/l10n'

const scoresStore = useScoresStore()

const targets = ref<SharedTarget[]>([])
const loading = ref(true)
const loadError = ref(false)

onMounted(async () => {
	try {
		const response = await apiClients.default.shareApiGetSharedWithMe()
		targets.value = response.data.ocs.data
	} catch (error) {
		console.error('Failed to load shared items:', error)
		loadError.value = true
	} finally {
		loading.value = false
	}
})
</script>

<style lang="scss" scoped>
.shared-page {
	display: flex;
	flex-direction: column;
	gap: calc(6 * var(--default-grid-baseline));
	max-width: 1100px;

	&__target {
		display: flex;
		flex-direction: column;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__heading {
		display: flex;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));

		h2 {
			margin: 0;
		}
	}

	&__description {
		color: var(--color-text-maxcontrast);
	}
}
</style>
