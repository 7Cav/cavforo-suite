/**
 * Cav7/Citations: the template preview. The image is always the server's render; this only
 * zooms it, draws the field boxes over it as page chrome, and asks for a new one when a value
 * changes. Zoom and scroll belong to the elements here, so a new render leaves both alone.
 */
((window, document) =>
{
	'use strict'

	const XF = window.XF
	const ZOOMS = [1, 2, 3, 4, 6, 8, 12, 16]
	const PREVIEW_DELAY = 250

	XF.Cav7PlatePreview = XF.Element.newHandler({
		options: {
			previewUrl: '',
			plateWidth: 0,
			plateHeight: 0,
		},

		scale: null,
		timer: null,
		latest: 0,

		init ()
		{
			this.form = this.target.closest('form')
			this.viewport = this.target.querySelector('.js-plateViewport')
			this.stage = this.target.querySelector('.js-plateStage')
			this.image = this.target.querySelector('.js-plateImage')
			this.report = this.target.querySelector('.js-plateReport')
			this.label = this.target.querySelector('.js-zoomLabel')

			this.stage.style.aspectRatio = `${this.options.plateWidth} / ${this.options.plateHeight}`
			this.drawBoxes()
			this.applyZoom(null)

			this.target.querySelector('.js-zoomIn').addEventListener('click', () => this.step(1))
			this.target.querySelector('.js-zoomOut').addEventListener('click', () => this.step(-1))
			this.target.querySelector('.js-zoomFit').addEventListener('click', () => this.applyZoom(null))
			this.target.querySelector('.js-showBoxes').addEventListener('change', event =>
			{
				this.stage.classList.toggle('is-boxesHidden', !event.target.checked)
			})

			if (this.form.matches('.js-platePanel'))
			{
				this.form.addEventListener('submit', event =>
				{
					event.preventDefault()
					this.refresh()
				})
			}
			this.form.addEventListener('input', () => this.changed())
			this.form.addEventListener('change', () => this.changed())
			this.form.addEventListener('click', event =>
			{
				if (event.target.closest('.js-patternChip'))
				{
					this.insertPlaceholder(event.target.textContent)
				}
			})

			this.refresh()
		},

		drawBoxes ()
		{
			const original = this.form.querySelector('[name="original_key"]')
			const boxes = JSON.parse(this.target.dataset.boxes || '[]')

			boxes.filter(box => box.key !== original?.value).forEach(box =>
			{
				this.place(this.stage.appendChild(this.newBox()), box)
			})

			if (this.boxInput('x'))
			{
				this.selected = this.stage.appendChild(this.newBox())
				this.selected.classList.add('cav7Plate-box--selected')
				this.placeSelected()
			}
		},

		newBox ()
		{
			const box = document.createElement('div')
			box.className = 'cav7Plate-box'
			return box
		},

		// Percentages, so a box follows the stage through every zoom with no help.
		place (element, box)
		{
			element.style.left = `${box.x / this.options.plateWidth * 100}%`
			element.style.top = `${box.y / this.options.plateHeight * 100}%`
			element.style.width = `${box.w / this.options.plateWidth * 100}%`
			element.style.height = `${box.h / this.options.plateHeight * 100}%`
		},

		boxInput (name)
		{
			return this.form.querySelector(`[name="field[${name}]"]`)
		},

		placeSelected ()
		{
			const value = name => parseInt(this.boxInput(name).value, 10) || 0

			this.place(this.selected, { x: value('x'), y: value('y'), w: value('w'), h: value('h') })
		},

		fitScale ()
		{
			return this.viewport.clientWidth / this.options.plateWidth
		},

		step (direction)
		{
			const current = this.scale ?? this.fitScale()
			const levels = direction > 0
				? ZOOMS.filter(zoom => zoom > current)
				: ZOOMS.filter(zoom => zoom < current).reverse()

			this.applyZoom(levels.length ? levels[0] : (direction > 0 ? this.scale : null))
		},

		applyZoom (scale)
		{
			const viewport = this.viewport
			const from = this.scale ?? this.fitScale()
			const to = scale ?? this.fitScale()
			let centreX = (viewport.scrollLeft + viewport.clientWidth / 2) / from
			let centreY = (viewport.scrollTop + viewport.clientHeight / 2) / from

			// Coming in from the whole plate, the box being placed is what S1 is after.
			if (this.scale === null && scale !== null && this.selected)
			{
				const box = this.selected
				centreX = (box.offsetLeft + box.offsetWidth / 2) / from
				centreY = (box.offsetTop + box.offsetHeight / 2) / from
			}

			this.scale = scale
			this.stage.style.width = scale === null ? '100%' : `${this.options.plateWidth * scale}px`
			this.stage.classList.toggle('is-pixelated', scale !== null)
			this.label.textContent = scale === null ? '' : `${scale * 100}%`

			viewport.scrollLeft = centreX * to - viewport.clientWidth / 2
			viewport.scrollTop = centreY * to - viewport.clientHeight / 2
		},

		insertPlaceholder (text)
		{
			const pattern = this.form.querySelector('.js-fieldPattern')

			pattern.setRangeText(text, pattern.selectionStart, pattern.selectionEnd, 'end')
			pattern.focus()
			this.changed()
		},

		changed ()
		{
			if (this.selected)
			{
				this.placeSelected()
			}

			clearTimeout(this.timer)
			this.timer = setTimeout(() => this.refresh(), PREVIEW_DELAY)
		},

		refresh ()
		{
			clearTimeout(this.timer)
			const sent = ++this.latest

			XF.ajax('post', this.options.previewUrl, XF.getDefaultFormData(this.form), response =>
			{
				// A slow render must not land over a newer one.
				if (sent !== this.latest || !response.html)
				{
					return
				}

				if (response.src)
				{
					this.image.src = response.src
				}

				XF.setupHtmlInsert(response.html, html =>
				{
					this.report.replaceChildren(html)
				})
			}, { global: false, skipError: true })
		},
	})

	XF.Element.register('cav7-plate-preview', 'XF.Cav7PlatePreview')
})(window, document)
