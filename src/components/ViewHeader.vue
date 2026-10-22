<script setup lang="ts">
/**
 * Header of a view: heading, optional description, actions and a print
 * button. Sticky on screen; in print only the heading and the description
 * remain.
 */
import { mdiPrinterOutline, mdiRefresh } from '@mdi/js'
import { t } from '../l10n.ts'
import { useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { useStickyHeader } from '../stickyHeader.ts'

withDefaults(defineProps<{
	title: string
	description?: string
	printable?: boolean
	/** show a reload button */
	reloadable?: boolean
	loading?: boolean
}>(), {
	description: '',
	printable: false,
	reloadable: false,
	loading: false,
})

const emit = defineEmits<{
	reload: []
}>()

// Focused elements scroll below this sticky header, not under it
useStickyHeader(useTemplateRef<HTMLElement>('header'))

function print(): void {
	window.print()
}
</script>

<template>
	<header ref="header" class="view-header">
		<div class="view-header__text">
			<h2 class="view-header__title">
				{{ title }}
			</h2>
			<p v-if="description" class="view-header__description">
				{{ description }}
			</p>
		</div>
		<div class="view-header__actions no-print">
			<slot name="actions" />
			<NcButton v-if="reloadable"
				variant="tertiary"
				:aria-label="t('notenarchiv', 'Reload')"
				:disabled="loading"
				@click="emit('reload')">
				<template #icon>
					<NcLoadingIcon v-if="loading" />
					<NcIconSvgWrapper v-else :path="mdiRefresh" />
				</template>
			</NcButton>
			<NcButton v-if="printable" variant="secondary" @click="print">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPrinterOutline" />
				</template>
				{{ t('notenarchiv', 'Print') }}
			</NcButton>
		</div>
	</header>
</template>

<style scoped>
.view-header {
	position: sticky;
	top: 0;
	z-index: 2;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(2 * var(--default-grid-baseline));
	padding: calc(2 * var(--default-grid-baseline));
	/* room for the navigation toggle button */
	padding-inline-start: calc(var(--default-clickable-area) + 3 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-main-background);
}

.view-header__title {
	margin: 0;
	font-size: 1.4em;
	font-weight: bold;
}

.view-header__description {
	margin: 0;
	color: var(--color-text-maxcontrast);
	max-width: 80ch;
}

.view-header__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

@media print {
	.view-header {
		position: static;
		padding: 0 0 4mm;
		border-bottom: 1px solid #000;
	}
}
</style>
