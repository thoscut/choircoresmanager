<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Settings of the folder book (administrators): how many months after the
 * return the names, accounts and notes of a loan are removed.
 */
import { showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage } from '../api.ts'
import { fetchFolderBookSettings, retentionText, saveFolderBookSettings } from '../folderBook.ts'

const months = ref('')
const saved = ref<number | null>(null)
const limits = ref({ min: 1, max: 120 })
const busy = ref(false)
const error = ref('')

onMounted(async () => {
	try {
		const settings = await fetchFolderBookSettings()
		months.value = String(settings.retentionMonths)
		saved.value = settings.retentionMonths
		limits.value = { min: settings.min, max: settings.max }
	} catch (caught) {
		error.value = errorMessage(caught)
	}
})

async function save(): Promise<void> {
	const value = Number(months.value)
	if (!Number.isInteger(value) || value < limits.value.min || value > limits.value.max) {
		error.value = t('cantoria', 'Please enter the retention period as a whole number of months between {min} and {max}.', limits.value)
		return
	}
	busy.value = true
	error.value = ''
	try {
		const settings = await saveFolderBookSettings(value)
		saved.value = settings.retentionMonths
		months.value = String(settings.retentionMonths)
		showSuccess(t('cantoria', 'The retention period has been saved.'))
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div class="folder-book-settings">
		<p v-if="saved !== null">
			{{ retentionText(saved) }}
		</p>
		<p class="folder-book-settings__muted">
			{{ t('cantoria', 'The daily check removes them, also while the folder book is switched off. The audit log keeps that a loan was anonymised, without the name. Backups made before still contain the names: keep them no longer than this period.') }}
		</p>
		<form class="folder-book-settings__form" @submit.prevent="save">
			<NcTextField v-model="months"
				type="number"
				inputmode="numeric"
				:min="String(limits.min)"
				:max="String(limits.max)"
				step="1"
				:label="t('cantoria', 'Retention period in months after the return')" />
			<NcButton type="submit" :disabled="busy">
				<template v-if="busy" #icon>
					<NcLoadingIcon />
				</template>
				{{ t('cantoria', 'Save retention period') }}
			</NcButton>
		</form>
		<NcNoteCard v-if="error" type="error" role="alert">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<style scoped>
.folder-book-settings {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.folder-book-settings p {
	max-width: 70ch;
	margin: 0;
}

.folder-book-settings__muted {
	color: var(--color-text-maxcontrast);
}

.folder-book-settings__form {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(2 * var(--default-grid-baseline));
}

.folder-book-settings__form > :first-child {
	flex: 0 1 20em;
}
</style>
