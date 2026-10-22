<template>
	<div class="catalogue-data">
		<h3 class="catalogue-data__heading">
			{{ t('Further catalogue data') }}
		</h3>
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-else-if="entries.length === 0" class="catalogue-data__empty">
			{{ t('No further catalogue data for this score.') }}
		</p>
		<dl v-else class="catalogue-data__list">
			<template v-for="entry in entries" :key="entry.column">
				<dt>{{ entry.column }}</dt>
				<dd>{{ entry.value }}</dd>
			</template>
		</dl>
	</div>
</template>

<script setup lang="ts">
import type { CatalogueValue } from '@/api/generated/openapi/data-contracts'

import { computed, ref, watch } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { formatDateStr } from '@/composables/useDateFormatting'
import { useCatalogueStore } from '@/stores/catalogueStore'
import { extractErrorMessage } from '@/utils/errorHandling'
import { t } from '@/utils/l10n'

interface Props {
	/** The score to show the catalogue data of */
	scoreId: number
	/** Last performance according to the imported catalogue (Y-m-d) */
	lastPerformedImported: string | null
}

const props = defineProps<Props>()

const catalogueStore = useCatalogueStore()

const values = ref<CatalogueValue[]>([])
const loading = ref(false)
const error = ref('')

const entries = computed((): CatalogueValue[] => {
	const imported = props.lastPerformedImported
		? [{ column: t('Last performed according to the catalogue'), value: formatDateStr(props.lastPerformedImported) }]
		: []
	return [...imported, ...values.value]
})

/**
 * Load the catalogue values of the current score
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		values.value = await catalogueStore.getScoreValues(props.scoreId)
	} catch (e) {
		values.value = []
		error.value = extractErrorMessage(e)
	} finally {
		loading.value = false
	}
}

watch(() => props.scoreId, load, { immediate: true })
</script>

<style lang="scss" scoped>
.catalogue-data {
	padding: var(--default-grid-baseline) 0;
}

.catalogue-data__heading {
	margin-top: 0;
}

.catalogue-data__empty {
	color: var(--color-text-maxcontrast);
}

.catalogue-data__list {
	dt {
		margin-top: calc(2 * var(--default-grid-baseline));
		color: var(--color-text-maxcontrast);
		font-weight: bold;
	}

	dd {
		margin: 0;
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}
}
</style>
