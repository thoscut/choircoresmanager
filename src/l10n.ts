/**
 * Translation for texts that Vue shows as text. By default @nextcloud/l10n
 * prepares a translation for use as HTML: it escapes the placeholder values
 * and sanitizes the result. Vue escapes text by itself, so a title like
 * "Tom & Jerry" would then appear as "Tom &amp; Jerry". The app never puts
 * translated texts into the page as HTML (no v-html with them), so both
 * steps are switched off here and Vue's escaping protects the page.
 *
 * Use these functions instead of the ones from @nextcloud/l10n; the string
 * extraction (tools/l10n) finds `t('notenarchiv', …)` and `n(…)` the same.
 */
import { n as translatePlural, t as translate } from '@nextcloud/l10n'

/** Plain text: no escaping, no sanitizing (Vue escapes when rendering). */
const AS_TEXT = { escape: false, sanitize: false }

/** Placeholder values of a text, as typed by @nextcloud/l10n. */
type Placeholders<T extends string> = NonNullable<Parameters<typeof translate<T>>[2]>

/** Placeholder values of a plural text. */
type PluralPlaceholders<T extends string, K extends string> = NonNullable<Parameters<typeof translatePlural<T, K>>[4]>

/**
 * Translate a text.
 *
 * @param app - app id ('notenarchiv')
 * @param text - English source text with {placeholders}
 * @param placeholders - values of the placeholders
 */
export function t<T extends string>(app: string, text: T, placeholders?: Placeholders<T>): string {
	return translate(app, text, placeholders, AS_TEXT)
}

/**
 * Translate a text with singular and plural ("%n" is the count).
 *
 * @param app - app id ('notenarchiv')
 * @param singular - English singular
 * @param plural - English plural
 * @param count - the number that chooses the form
 * @param placeholders - values of further placeholders
 */
export function n<T extends string, K extends string>(app: string, singular: T, plural: K, count: number, placeholders?: PluralPlaceholders<T, K>): string {
	return translatePlural(app, singular, plural, count, placeholders, AS_TEXT)
}
