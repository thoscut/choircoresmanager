<script setup lang="ts">
/**
 * Date input (value YYYY-MM-DD, "" = empty) in the look of the other text
 * fields. NcTextField renders the native date input of the browser, which
 * is accessible and works with the keyboard; its type definitions only do
 * not list "date". Other attributes (min, max, required, helper-text, …)
 * are passed on to the input.
 */
import NcTextField from '@nextcloud/vue/components/NcTextField'

const model = defineModel<string>({ required: true })

defineProps<{
	label: string
}>()

/** The input type, outside the typed props of NcTextField. */
const dateType: Record<string, string> = { type: 'date' }
</script>

<template>
	<NcTextField v-model="model" v-bind="dateType" :label="label" />
</template>
