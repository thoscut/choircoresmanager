<template>
	<NcActions
		:menuName="buttonText(t('Catalogue'))"
		:size="buttonSize"
		variant="secondary"
		:forceName="true">
		<template #icon>
			<CatalogueIcon :size="20" />
		</template>
		<NcActionButton
			v-if="editable"
			:closeAfterClick="true"
			@click="importOpen = true">
			<template #icon>
				<ImportIcon :size="20" />
			</template>
			{{ t('Import catalogue') }}
		</NcActionButton>
		<NcActionButton
			:closeAfterClick="true"
			:disabled="exporting"
			@click="onExport">
			<template #icon>
				<LoadingIcon v-if="exporting" :size="20" class="spin" />
				<ExportIcon v-else :size="20" />
			</template>
			{{ t('Export catalogue') }}
		</NcActionButton>
	</NcActions>

	<CatalogueImportDialog v-if="editable" v-model:open="importOpen" />
</template>

<script setup lang="ts">
import { ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import CatalogueImportDialog from './CatalogueImportDialog.vue'
import { useBreakpoints } from '@/composables/useBreakpoints'
import { CatalogueIcon, ExportIcon, ImportIcon, LoadingIcon } from '@/icons/vue-material'
import { useCatalogueStore } from '@/stores/catalogueStore'
import { tryShowError } from '@/utils/errorHandling'
import { downloadTextFile } from '@/utils/fileUtils'
import { t } from '@/utils/l10n'

interface Props {
	/** Whether the current user may edit scores (and thus import) */
	editable: boolean
}

defineProps<Props>()

const catalogueStore = useCatalogueStore()
const { buttonSize, buttonText } = useBreakpoints()

const importOpen = ref(false)
const exporting = ref(false)

/**
 * Download all scores as catalogue CSV
 */
async function onExport(): Promise<void> {
	exporting.value = true
	await tryShowError(
		async () => {
			const file = await catalogueStore.exportCatalogue()
			downloadTextFile(file.fileName, file.content, 'text/csv;charset=utf-8')
		},
		t('Export failed: '),
	)
	exporting.value = false
}
</script>

<style lang="scss" scoped>
.spin {
	animation: spin 1s linear infinite;
}

@keyframes spin {
	from {
		transform: rotate(0deg);
	}

	to {
		transform: rotate(360deg);
	}
}
</style>
