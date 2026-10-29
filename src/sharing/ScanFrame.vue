<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The scan (PDF) of a piece inside the page (milestone 0.15): the
 * browser's own PDF viewer in a frame, from the browser's memory. So it
 * also opens without a network, where a new tab would fail (iPhone, iPad).
 * Shown after a click only; the memory is freed again when it is closed.
 */
import type { CollectionName } from '../offline/rules.ts'

import { onBeforeUnmount, ref, useId, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { t } from '../l10n.ts'
import { scanInMemory } from '../offline/device.ts'

const props = defineProps<{
	collection: CollectionName
	/** token of the link or id of the programme item */
	pieceKey: string
	title: string
}>()

const frameId = useId()
const address = ref<string | null>(null)
const loading = ref(false)
const error = ref('')

function close(): void {
	if (address.value !== null) {
		URL.revokeObjectURL(address.value)
	}
	address.value = null
}

async function open(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		close()
		address.value = await scanInMemory(props.collection, props.pieceKey)
	} catch (reason) {
		error.value = reason instanceof Error && reason.message !== ''
			? reason.message
			: t('cantoria', 'The scan could not be loaded. Without a network, only a scan kept on this device opens.')
	} finally {
		loading.value = false
	}
}

watch(() => [props.collection, props.pieceKey], close)
onBeforeUnmount(close)
</script>

<template>
	<div class="scan-frame">
		<div class="scan-frame__buttons">
			<NcButton v-if="address === null"
				variant="secondary"
				:aria-controls="frameId"
				:disabled="loading"
				@click="open">
				{{ loading ? t('cantoria', 'Loading the scan …') : t('cantoria', 'Show the scan (PDF)') }}
			</NcButton>
			<template v-else>
				<NcButton variant="secondary" :aria-controls="frameId" @click="close">
					{{ t('cantoria', 'Close the scan') }}
				</NcButton>
				<a :href="address"
					class="scan-frame__link"
					target="_blank"
					rel="noopener noreferrer">{{ t('cantoria', 'Open the scan (PDF)') }}<span class="hidden-visually"> {{ t('cantoria', '(opens in a new tab)') }}</span></a>
			</template>
		</div>
		<NcNoteCard v-if="error" type="warning" role="alert">
			{{ error }}
		</NcNoteCard>
		<iframe v-if="address !== null"
			:id="frameId"
			class="scan-frame__frame"
			:src="address"
			:title="t('cantoria', 'Scan of {title}', { title })" />
	</div>
</template>

<style scoped>
.scan-frame {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.scan-frame__buttons {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.scan-frame__link {
	display: inline-block;
	min-height: var(--default-clickable-area);
	line-height: var(--default-clickable-area);
	color: var(--color-main-text);
	text-decoration: underline;
}

.scan-frame__link:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.scan-frame__frame {
	width: 100%;
	height: 80vh;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}
</style>
