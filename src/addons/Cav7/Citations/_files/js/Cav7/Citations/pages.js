/**
 * Cav7/Citations: the grant form and the citation picker. The form re-renders from its own
 * post on the server, so this only says when; the preview is the server's render. The swap
 * between picker and upload is polish, because the save refuses a wrong file or pick anyway.
 */
((window, document) =>
{
	'use strict'

	const XF = window.XF
	const RESHAPES = /^(kind|template_version_id|target_version_id|signatures\[|show_retired\[)/
	const PREVIEW_DELAY = 400

	XF.Cav7GrantForm = XF.Element.newHandler({
		options: {
			formUrl: '',
			previewUrl: '',
		},

		timer: null,
		latest: 0,

		init ()
		{
			const preview = document.querySelector('.js-grantPreview')
			this.previewBody = preview.querySelector('.js-grantPreviewBody')

			this.target.addEventListener('change', event =>
			{
				if (RESHAPES.test(event.target.name || ''))
				{
					this.reshape('')
				}
				else
				{
					this.schedulePreview()
				}
			})
			this.target.addEventListener('input', () => this.schedulePreview())
			this.target.addEventListener('click', event =>
			{
				const button = event.target.closest('[data-form-action]')
				if (button)
				{
					event.preventDefault()
					this.reshape(button.dataset.formAction)
				}
			})
			this.target.addEventListener('keydown', event =>
			{
				if (event.key === 'Enter' && event.target.name === 'add_username')
				{
					event.preventDefault()
					this.reshape('add')
				}
			})
			preview.addEventListener('click', event => this.previewClicked(event))

			this.refreshPreview()
		},

		reshape (action)
		{
			const data = XF.getDefaultFormData(this.target)
			data.set('form_action', action)

			XF.ajax('post', this.options.formUrl, data, response =>
			{
				if (!response.html)
				{
					return
				}

				XF.setupHtmlInsert(response.html, html =>
				{
					this.target.querySelector('.js-grantFormBody').replaceWith(html)
					this.refreshPreview()
				})
			})
		},

		schedulePreview ()
		{
			clearTimeout(this.timer)
			this.timer = setTimeout(() => this.refreshPreview(), PREVIEW_DELAY)
		},

		refreshPreview ()
		{
			clearTimeout(this.timer)
			const sent = ++this.latest

			XF.ajax('post', this.options.previewUrl, XF.getDefaultFormData(this.target), response =>
			{
				// A slow render must not land over a newer one.
				if (sent !== this.latest || !response.html)
				{
					return
				}

				XF.setupHtmlInsert(response.html, html =>
				{
					this.previewBody.replaceChildren(html)
				})
			}, { global: false, skipError: true })
		},

		previewClicked (event)
		{
			const tab = event.target.closest('[data-preview-member]')
			if (tab)
			{
				this.target.querySelector('.js-previewMember').value = tab.dataset.previewMember
				this.refreshPreview()
				return
			}

			if (event.target.closest('.js-grantPreviewFull'))
			{
				event.preventDefault()
				// A browser won't open a data: URL in a tab, so it goes through a blob.
				fetch(this.previewBody.querySelector('.js-grantPreviewImage').src)
					.then(reply => reply.blob())
					.then(blob => window.open(URL.createObjectURL(blob), '_blank'))
			}
		},
	})

	XF.Cav7CitationPicker = XF.Element.newHandler({
		init ()
		{
			const filter = this.target.querySelector('.js-pickerFilter')
			if (filter)
			{
				filter.addEventListener('input', () => this.filter(filter.value.trim().toLowerCase()))
			}
		},

		filter (query)
		{
			let shown = 0
			this.target.querySelectorAll('.inputChoices-choice').forEach(choice =>
			{
				if (!/^\d+$/.test(choice.querySelector('input').value))
				{
					return
				}

				const match = choice.textContent.toLowerCase().includes(query)
				choice.classList.toggle('u-hidden', !match)
				shown += match ? 1 : 0
			})

			this.target.querySelector('.js-pickerNoMatch').classList.toggle('u-hidden', shown > 0)
		},
	})

	XF.Cav7CitationSwap = XF.Element.newHandler({
		options: {
			openType: 0,
		},

		init ()
		{
			this.picker = this.target.querySelector('.js-citationPicker')
			this.upload = this.target.querySelector('.js-citationUpload')
			this.type = this.target.closest('form').querySelector('[name="record_type_id"]')

			if (this.type)
			{
				this.type.addEventListener('change', () => this.swap())
				this.swap()
			}
		},

		swap ()
		{
			const open = parseInt(this.type.value, 10) === this.options.openType

			this.show(this.picker, !open)
			this.show(this.upload, open)
		},

		show (part, shown)
		{
			part.hidden = !shown
			part.querySelectorAll('input').forEach(input =>
			{
				input.disabled = !shown
			})
		},
	})

	XF.Element.register('cav7-grant-form', 'XF.Cav7GrantForm')
	XF.Element.register('cav7-citation-picker', 'XF.Cav7CitationPicker')
	XF.Element.register('cav7-citation-swap', 'XF.Cav7CitationSwap')
})(window, document)
