/**
 * Admin panel for the PostgreSQL search platform. Plain script, no build step.
 * Saves every field in one PUT; the server validates them all before storing any.
 */
(function () {
	'use strict'

	const APP = 'fulltextsearch_pgsql'
	const tr = (text, vars) => (typeof window.t === 'function' ? window.t(APP, text, vars) : text)

	function requestToken() {
		return document.head.dataset.requesttoken || (window.OC && window.OC.requestToken) || ''
	}

	function renderStatus(list, status) {
		const unknown = tr('unknown')
		const rows = [
			[tr('Database'), status.postgres ? tr('PostgreSQL') : tr('Not PostgreSQL: this platform cannot work'), status.postgres],
			[tr('Indexed documents'), status.documents === null ? unknown : String(status.documents), status.documents !== null],
			[tr('Vocabulary words'), status.words === null ? unknown : String(status.words), true],
			[tr('pg_trgm extension'), status.pg_trgm ? tr('available') : tr('missing: no substring matching or spelling correction'), status.pg_trgm],
			[tr('pdftotext'), status.pdftotext || tr('not found'), true],
			[tr('Built-in PDF parser'), status.pdfparser ? tr('available') : tr('missing from this installation'), status.pdfparser],
			[tr('PDF files are read with'), {
				pdftotext: 'pdftotext',
				pdfparser: tr('the built-in parser'),
			}[status.pdf_tool] || tr('nothing: PDFs are indexed by name only'), status.pdf_tool !== null],
		]
		list.replaceChildren(...rows.map(([label, value, ok]) => {
			const item = document.createElement('li')
			item.className = ok ? 'ftspg-ok' : 'ftspg-warn'
			const name = document.createElement('span')
			name.className = 'ftspg-status-label'
			name.textContent = label
			const text = document.createElement('span')
			text.textContent = value
			item.append(name, text)
			return item
		}))
	}

	function collect(form) {
		const settings = {}
		for (const field of form.elements) {
			if (!field.name) {
				continue
			}
			if (field.type === 'checkbox') {
				settings[field.name] = field.checked
			} else if (field.type === 'number') {
				settings[field.name] = Number(field.value)
			} else {
				settings[field.name] = field.value.trim()
			}
		}
		return settings
	}

	function init() {
		const root = document.getElementById('ftspg-settings')
		if (!root) {
			return
		}
		const form = root.querySelector('.ftspg-form')
		const statusList = root.querySelector('.ftspg-status')
		const message = root.querySelector('.ftspg-message')
		const reindex = root.querySelector('.ftspg-reindex')
		const button = form.querySelector('button[type="submit"]')

		try {
			renderStatus(statusList, JSON.parse(root.dataset.status))
		} catch (e) {
			// Status is informative only; the form still works without it.
		}

		form.addEventListener('submit', async (event) => {
			event.preventDefault()
			if (!form.reportValidity()) {
				return
			}
			button.disabled = true
			message.className = 'ftspg-message'
			message.textContent = tr('Saving…')
			try {
				const response = await fetch(root.dataset.saveUrl, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
						requesttoken: requestToken(),
					},
					body: JSON.stringify({ settings: collect(form) }),
				})
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					throw new Error(data.message || tr('Could not save the settings ({status})', { status: response.status }))
				}
				message.classList.add('ftspg-success')
				message.textContent = tr('Saved')
				if (data.languageChanged) {
					reindex.hidden = false
				}
				if (data.status) {
					renderStatus(statusList, data.status)
				}
			} catch (error) {
				message.classList.add('ftspg-error')
				message.textContent = error.message
			} finally {
				button.disabled = false
			}
		})
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
