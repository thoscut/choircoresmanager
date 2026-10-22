<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * "Customize view": each person hides the areas of the navigation and the
 * parts of the start page they do not need. Stored on the server as a
 * setting of the account, so it follows the person to every device. It
 * only hides: permissions stay the same, and hidden areas still open
 * through links.
 */
import type { ViewName } from '../startParams.ts'

import { showSuccess } from '@nextcloud/dialogs'
import { t } from '../l10n.ts'
import { computed, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import ActionDialog from './ActionDialog.vue'
import { errorMessage, savePersonalView } from '../api.ts'
import { dueCardLabel } from '../dueLabels.ts'
import { cardActive, DUE_CONCERTS, DUE_TASKS, HIDEABLE_CARDS, HIDEABLE_VIEWS, toggleHidden } from '../modules.ts'
import {
	canSeeFolderBook,
	canSeeProposals,
	hasRole,
	isLibrarian,
	isViewActive,
	meta,
	moduleStates,
	personalView,
	personalViewDialogOpen,
} from '../store.ts'
import { viewTitle } from '../viewTitles.ts'

// Drafts start from what is stored; names of areas not offered here stay
const hiddenViews = ref<string[]>([...personalView.value.navigation])
const hiddenCards = ref<string[]>([...personalView.value.cards])
const busy = ref(false)
const error = ref('')

/** Navigation areas the person can see now (start page and catalog always stay). */
const views = computed(() => HIDEABLE_VIEWS
	.filter((view) => isViewActive(view) && (view !== 'proposals' || canSeeProposals.value)
		&& (view !== 'folderBook' || canSeeFolderBook.value))
	.map((view: ViewName) => ({ view, label: viewTitle(view, isLibrarian.value) })))

/**
 * Label of a card or list of the start page.
 *
 * @param key - card key, `concerts` or `tasks`
 */
function cardLabel(key: string): string {
	if (key === DUE_CONCERTS) {
		return t('notenarchiv', 'Concert deadlines')
	}
	if (key === DUE_TASKS) {
		return t('notenarchiv', 'Tasks for your roles')
	}
	return dueCardLabel(key, 2, key)
}

/** Cards and lists of the start page for the person's roles and the active modules. */
const cards = computed(() => HIDEABLE_CARDS
	.filter((card) => cardActive(card.key, moduleStates.value))
	.filter((card) => card.roles === null || card.roles.some((role) => hasRole(role)))
	.map((card) => ({ key: card.key, label: cardLabel(card.key) })))

function close(): void {
	personalViewDialogOpen.value = false
}

async function save(): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		const saved = await savePersonalView({ navigation: hiddenViews.value, cards: hiddenCards.value })
		if (meta.value !== null) {
			meta.value = { ...meta.value, personalView: saved }
		}
		showSuccess(t('notenarchiv', 'Your view has been saved.'))
		close()
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<ActionDialog :name="t('notenarchiv', 'Customize view')"
		:submit-label="t('notenarchiv', 'Save view')"
		:busy="busy"
		:error="error"
		@submit="save"
		@close="close">
		<p>
			{{ t('notenarchiv', 'Hide what you do not need. This only changes what you see: your permissions stay the same, and hidden areas still open through links and notifications.') }}
		</p>

		<fieldset class="personal-view__group">
			<legend>{{ t('notenarchiv', 'Navigation') }}</legend>
			<p v-if="views.length === 0" class="personal-view__muted">
				{{ t('notenarchiv', 'There is nothing to hide here.') }}
			</p>
			<NcCheckboxRadioSwitch v-for="entry in views"
				:key="entry.view"
				:model-value="!hiddenViews.includes(entry.view)"
				@update:model-value="hiddenViews = toggleHidden(hiddenViews, entry.view, $event)">
				{{ entry.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<fieldset class="personal-view__group">
			<legend>{{ t('notenarchiv', 'Start page') }}</legend>
			<p v-if="cards.length === 0" class="personal-view__muted">
				{{ t('notenarchiv', 'There is nothing to hide here.') }}
			</p>
			<NcCheckboxRadioSwitch v-for="card in cards"
				:key="card.key"
				:model-value="!hiddenCards.includes(card.key)"
				@update:model-value="hiddenCards = toggleHidden(hiddenCards, card.key, $event)">
				{{ card.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>
	</ActionDialog>
</template>

<style scoped>
.personal-view__group {
	display: flex;
	flex-direction: column;
	margin: 0 0 calc(2 * var(--default-grid-baseline));
	padding: 0;
	border: none;
}

.personal-view__group legend {
	margin-bottom: var(--default-grid-baseline);
	font-weight: bold;
}

.personal-view__muted {
	color: var(--color-text-maxcontrast);
}
</style>
