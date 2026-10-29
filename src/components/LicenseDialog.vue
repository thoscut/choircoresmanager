<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Create or edit a license of a version: holder, uses (checkboxes),
 * number of copies, validity and evidence. Licenses are never deleted; an
 * ended license stays as history.
 */
import type { License, LicenseInput } from '../types.ts'

import { t } from '../l10n.ts'
import { computed, reactive, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ActionDialog from './ActionDialog.vue'
import DateField from './DateField.vue'
import { createLicense, errorMessage, updateLicense } from '../api.ts'
import { licenseUseHint, licenseUseLabel } from '../labels.ts'
import { meta } from '../store.ts'

const props = defineProps<{
	versionId: number
	/** the license to edit; missing for a new license */
	license?: License | null
}>()

const emit = defineEmits<{
	saved: []
	close: []
}>()

const uses = computed(() => Object.keys(meta.value?.licenseUses ?? {}))

const draft = reactive({
	holder: props.license?.holder ?? '',
	uses: (props.license?.uses ?? '').split(',').map((use) => use.trim()).filter((use) => use !== ''),
	quantity: props.license?.quantity === null || props.license?.quantity === undefined ? '' : String(props.license.quantity),
	validFrom: props.license?.valid_from ?? '',
	validUntil: props.license?.valid_until ?? '',
	evidence: props.license?.evidence ?? '',
	notes: props.license?.notes ?? '',
	shareNotice: props.license?.share_notice ?? '',
})

const busy = ref(false)
const error = ref('')

const usesMissing = computed(() => draft.uses.length === 0)

/**
 * Text or null for empty input.
 *
 * @param value - input
 */
function orNull(value: string): string | null {
	return value.trim() === '' ? null : value.trim()
}

async function save(): Promise<void> {
	if (usesMissing.value) {
		error.value = t('cantoria', 'Please choose at least one use.')
		return
	}
	const quantity = draft.quantity.trim() === '' ? null : Number(draft.quantity)
	if (quantity !== null && (!Number.isInteger(quantity) || quantity < 0)) {
		error.value = t('cantoria', 'Please enter a whole number.')
		return
	}
	const input: LicenseInput = {
		holder: orNull(draft.holder),
		uses: [...draft.uses],
		quantity,
		valid_from: orNull(draft.validFrom),
		valid_until: orNull(draft.validUntil),
		evidence: orNull(draft.evidence),
		notes: orNull(draft.notes),
		share_notice: orNull(draft.shareNotice),
	}
	busy.value = true
	error.value = ''
	try {
		if (props.license) {
			await updateLicense(props.license.id, input)
		} else {
			await createLicense(props.versionId, input)
		}
		emit('saved')
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<ActionDialog :name="license ? t('cantoria', 'Edit license') : t('cantoria', 'New license')"
		:submit-label="t('cantoria', 'Save')"
		:busy="busy"
		:error="error"
		@submit="save"
		@close="emit('close')">
		<NcTextField v-model="draft.holder" :label="t('cantoria', 'License holder or publisher')" />
		<fieldset class="license-dialog__uses">
			<legend>{{ t('cantoria', 'Uses (at least one)') }}</legend>
			<NcCheckboxRadioSwitch v-for="use in uses"
				:key="use"
				v-model="draft.uses"
				:value="use"
				name="license-uses">
				{{ licenseUseLabel(use) }}
				<template v-if="licenseUseHint(use)" #description>
					{{ licenseUseHint(use) }}
				</template>
			</NcCheckboxRadioSwitch>
		</fieldset>
		<NcTextField v-model="draft.quantity"
			type="number"
			inputmode="numeric"
			min="0"
			step="1"
			:label="t('cantoria', 'Number of copies')" />
		<div class="license-dialog__dates">
			<DateField v-model="draft.validFrom" :label="t('cantoria', 'Valid from')" />
			<DateField v-model="draft.validUntil" :label="t('cantoria', 'Valid until')" />
		</div>
		<NcTextArea v-model="draft.evidence"
			:label="t('cantoria', 'Evidence (invoice, contract, letter)')"
			resize="vertical"
			rows="2" />
		<NcTextArea v-model="draft.notes"
			:label="t('cantoria', 'Notes')"
			resize="vertical"
			rows="2" />
		<NcTextArea v-model="draft.shareNotice"
			:label="t('cantoria', 'Notice on shared pages')"
			:helper-text="t('cantoria', 'Shown with excerpts for choir members and the public, for example: By kind permission of …')"
			:maxlength="1000"
			resize="vertical"
			rows="2" />
		<p class="action-dialog__hint">
			{{ t('cantoria', 'Licenses are not deleted. An ended license stays as a record.') }}
		</p>
	</ActionDialog>
</template>

<style scoped>
.license-dialog__uses {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	border: none;
}

.license-dialog__uses legend {
	margin-bottom: var(--default-grid-baseline);
	font-weight: bold;
}

.license-dialog__dates {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.license-dialog__dates > * {
	flex: 1 1 12em;
}
</style>
