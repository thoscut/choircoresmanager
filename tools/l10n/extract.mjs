// Collects all translatable source strings of the app (UI + server).
// Usage: node extract.mjs <projectRoot>  -> prints JSON { singular: [...], plural: [[s,p],...] }
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const root = process.argv[2]
const files = (dir, ext) => readdirSync(dir).flatMap((name) => {
	const p = join(dir, name)
	return statSync(p).isDirectory() ? files(p, ext) : (ext.some((e) => p.endsWith(e)) ? [p] : [])
})
const unq = (s) => s.slice(1, -1).replace(/\\(['"\\])/g, '$1')
const STR = "'(?:[^'\\\\]|\\\\.)*'"
const singular = new Map()
const plural = new Map()
const add = (s, origin) => { if (s.trim() !== '' && !singular.has(s)) singular.set(s, origin) }

// UI: t('notenarchiv', '…') and n('notenarchiv', '…', '…', …)
for (const f of files(join(root, 'src'), ['.ts', '.vue'])) {
	const src = readFileSync(f, 'utf8')
	const re = new RegExp("\\b([tn])\\(\\s*'notenarchiv'\\s*,\\s*(" + STR + ")(?:\\s*,\\s*(" + STR + "))?", 'g')
	for (const m of src.matchAll(re)) {
		if (m[1] === 'n') {
			plural.set(unq(m[2]) + '\u0000' + unq(m[3]), 'ui')
		} else {
			add(unq(m[2]), 'ui')
		}
	}
}
// Server
for (const f of files(join(root, 'lib'), ['.php'])) {
	const src = readFileSync(f, 'utf8')
	const base = relative(join(root, 'lib'), f).replace(/\\/g, '/')
	// Messages of exceptions shown to users; internal exception types are never shown
	const internal = /(Runtime|InvalidArgument|UnexpectedValue|Logic|Domain|DoesNotExist|MultipleObjectsReturned)Exception/
	for (const m of src.matchAll(new RegExp('new \\\\?\\w*Exception\\(\\s*(' + STR + ')(?:\\s*,\\s*\\[([^\\]]*)\\])?', 'g'))) {
		if (internal.test(m[0])) {
			continue
		}
		add(unq(m[1]), base + ' exception')
		// Parameters such as field labels are translated too (RunsActions::translate)
		for (const p of (m[2] ?? '').matchAll(new RegExp(STR, 'g'))) {
			if (/^'[A-Z]/.test(p[0])) add(unq(p[0]), base + ' exception parameter')
		}
	}
	// Import warnings [message, parameters]; parameters are translated too (RunsActions::translateWarnings)
	for (const m of src.matchAll(new RegExp('\\$warnings\\[\\] = \\[?\\s*(' + STR + ')(?:\\s*,\\s*\\[([^\\]]*)\\])?', 'g'))) {
		add(unq(m[1]), base + ' warning')
		for (const p of (m[2] ?? '').matchAll(new RegExp(STR, 'g'))) {
			if (/^'[A-Z]/.test(p[0])) add(unq(p[0]), base + ' warning parameter')
		}
	}
	// Labels given to the request checks, e.g. Input::text($value, 'Accession date'); they become exception parameters
	for (const m of src.matchAll(new RegExp('Input::\\w+\\([^;]*?,\\s*(' + STR + ')\\s*\\)', 'g'))) {
		if (/^'[A-Z]/.test(m[1])) add(unq(m[1]), base + ' input label')
	}
	// Reasons why a release is denied (RightsService::releaseDenial)
	if (base.endsWith('RightsService.php')) {
		for (const m of src.matchAll(/return ('[A-Z][^']*\.');/g)) add(unq(m[1]), base + ' reason')
	}
	for (const m of src.matchAll(new RegExp('->t\\(\\s*(' + STR + ')', 'g'))) add(unq(m[1]), base + ' t()')
	for (const m of src.matchAll(new RegExp('->n\\(\\s*(' + STR + ')\\s*,\\s*(' + STR + ')', 'g'))) plural.set(unq(m[1]) + '\u0000' + unq(m[2]), base + ' n()')
	if (base.includes('FieldValue')) {
		for (const m of src.matchAll(new RegExp('(?:return|:) (' + STR + ');', 'g'))) add(unq(m[1]), base + ' check')
	}
	if (base.endsWith('Fields.php')) {
		for (const m of src.matchAll(new RegExp('self::field\\([^,]+,\\s*(' + STR + ')', 'g'))) add(unq(m[1]), 'field label')
		// Every option list: public const NAME = [ 'key' => 'English label', … ]
		for (const block of src.matchAll(/public const ([A-Z0-9_]+) = \[([\s\S]*?)\];/g)) {
			for (const m of block[2].matchAll(new RegExp(STR + '\\s*=>\\s*(' + STR + ')', 'g'))) add(unq(m[1]), 'option ' + block[1])
		}
	}
	// Card labels of the start page are translated by the controller
	if (base.endsWith('DueService.php')) {
		for (const m of src.matchAll(new RegExp("'label' => (" + STR + ')', 'g'))) add(unq(m[1]), 'due card')
	}
	// What is due at each concert deadline (start page, dashboard, calendar)
	if (base.endsWith('ConcertDeadlines.php')) {
		const block = src.match(/public const LABELS = \[([\s\S]*?)\];/)
		for (const m of block[1].matchAll(new RegExp('=>\\s*(' + STR + ')', 'g'))) add(unq(m[1]), 'concert deadline')
	}
	if (base.endsWith('RightsPolicy.php')) {
		// Reasons why viewing or playback is denied (shown in the UI, also thrown)
		for (const m of src.matchAll(new RegExp('(?:return|[?:])\\s*(' + STR + ')', 'g'))) add(unq(m[1]), base + ' reason')
	}
	if (base.endsWith('Roles.php')) {
		const block = src.match(/public const LABELS = \[([\s\S]*?)\];/)
		for (const m of block[1].matchAll(new RegExp('=>\\s*(' + STR + ')', 'g'))) add(unq(m[1]), 'role label')
	}
}
console.log(JSON.stringify({
	singular: [...singular].map(([s, origin]) => ({ s, origin })),
	plural: [...plural.keys()].map((k) => k.split('\u0000')),
}, null, 1))
