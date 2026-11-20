/**
 * Cav7/Citations: the template and signature previews. The images are always the server's
 * render; this only zooms the template one into a full-window view, draws the field boxes over
 * it as page chrome, and asks for a new render when a value changes. Zoom and scroll belong to
 * the elements here, so a new render leaves both alone.
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
			this.minimap = this.target.querySelector('.js-plateMinimap')
			this.minimapImage = this.target.querySelector('.js-plateMinimapImage')
			this.minimapView = this.target.querySelector('.js-plateMinimapView')
			this.mirrors = [...this.target.querySelectorAll('.js-boxMirror')]

			const aspect = `${this.options.plateWidth} / ${this.options.plateHeight}`
			this.stage.style.aspectRatio = aspect
			this.minimap.style.aspectRatio = aspect
			this.drawBoxes()
			this.applyZoom(null)

			this.target.querySelector('.js-zoomIn').addEventListener('click', () => this.step(1))
			this.target.querySelector('.js-zoomOut').addEventListener('click', () => this.step(-1))
			this.target.querySelector('.js-zoomFit').addEventListener('click', () => this.applyZoom(null))
			// While the window is open it covers the form: Esc closes the window but not the field
			// editor overlay, Enter in one of its inputs must not save the form, and focus headed for
			// a control behind it stays on the plate.
			this.target.addEventListener('keydown', event =>
			{
				if (!this.target.hasAttribute('popover'))
				{
					return
				}

				if (event.key === 'Escape')
				{
					event.stopPropagation()
					this.applyZoom(null)
				}
				else if (event.key === 'Enter' && event.target.matches('input'))
				{
					event.preventDefault()
				}
			})
			this.target.addEventListener('focusout', event =>
			{
				if (this.target.hasAttribute('popover') && event.relatedTarget && !this.target.contains(event.relatedTarget))
				{
					this.viewport.focus({ preventScroll: true })
				}
			})
			this.listenForPan()
			this.listenForMinimap()

			// The form hides behind the window, so its box inputs ride along in the toolbar. The input
			// event still bubbles to the form, which moves the box and sends the render.
			this.mirrors.forEach(mirror => mirror.addEventListener('input', () =>
			{
				this.boxInput(mirror.dataset.key).value = mirror.value
			}))
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
			this.mirrors.forEach(mirror =>
			{
				if (mirror !== document.activeElement)
				{
					mirror.value = this.boxInput(mirror.dataset.key).value
				}
			})
		},

		fitScale ()
		{
			return this.viewport.clientWidth / this.options.plateWidth
		},

		step (direction)
		{
			if (this.scale === null && direction < 0)
			{
				return
			}

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
			let centreX = (viewport.scrollLeft + viewport.clientWidth / 2) / from
			let centreY = (viewport.scrollTop + viewport.clientHeight / 2) / from

			// The box being placed is what S1 is after, unless they have panned away from it.
			const box = this.selected
			const boxInView = box
				&& box.offsetLeft < viewport.scrollLeft + viewport.clientWidth && box.offsetLeft + box.offsetWidth > viewport.scrollLeft
				&& box.offsetTop < viewport.scrollTop + viewport.clientHeight && box.offsetTop + box.offsetHeight > viewport.scrollTop
			if (scale !== null && box && (this.scale === null || boxInView))
			{
				centreX = (box.offsetLeft + box.offsetWidth / 2) / from
				centreY = (box.offsetTop + box.offsetHeight / 2) / from
			}

			if ((this.scale === null) !== (scale === null))
			{
				this.showWindow(scale !== null)
			}
			this.scale = scale
			const to = scale ?? this.fitScale()
			this.stage.style.width = scale === null ? '100%' : `${this.options.plateWidth * scale}px`
			this.stage.classList.toggle('is-pixelated', scale !== null)
			this.label.textContent = scale === null ? '' : `${scale * 100}%`

			viewport.scrollLeft = centreX * to - viewport.clientWidth / 2
			viewport.scrollTop = centreY * to - viewport.clientHeight / 2
			this.drawMinimap()
		},

		// The popover attribute is only set while zoomed: a closed popover is display: none. A
		// browser with no popovers zooms inside the column, as before.
		showWindow (show)
		{
			if (!this.target.showPopover)
			{
				return
			}

			// Disabled while hidden, so the copies take no part in the form's validation.
			this.mirrors.forEach(mirror => mirror.disabled = !show)

			if (show)
			{
				this.target.popover = 'manual'
				this.target.showPopover()
				if (!this.target.contains(document.activeElement))
				{
					this.viewport.focus({ preventScroll: true })
				}
			}
			else
			{
				// Hiding drops focus to the body, where Esc no longer reaches the field editor overlay.
				// A control that hides with the zoom, such as a toolbar box input, hands it to the viewport.
				const focused = document.activeElement
				this.target.hidePopover()
				this.target.removeAttribute('popover')
				const back = this.target.contains(focused) && focused.getClientRects().length ? focused : this.viewport
				back.focus({ preventScroll: true })
			}
		},

		// Mouse only: touch already pans the viewport natively.
		listenForPan ()
		{
			const viewport = this.viewport
			let grab = null

			viewport.addEventListener('pointerdown', event =>
			{
				if (this.scale === null || event.pointerType !== 'mouse' || event.button !== 0 || !this.stage.contains(event.target))
				{
					return
				}

				event.preventDefault()
				viewport.setPointerCapture(event.pointerId)
				viewport.classList.add('is-dragging')
				grab = { x: event.clientX + viewport.scrollLeft, y: event.clientY + viewport.scrollTop }
			})
			viewport.addEventListener('pointermove', event =>
			{
				if (viewport.hasPointerCapture(event.pointerId))
				{
					viewport.scrollLeft = grab.x - event.clientX
					viewport.scrollTop = grab.y - event.clientY
				}
			})
			viewport.addEventListener('lostpointercapture', () => viewport.classList.remove('is-dragging'))
			viewport.addEventListener('scroll', () => this.drawMinimap())
			new ResizeObserver(() => this.drawMinimap()).observe(viewport)
		},

		// Pressing or dragging on the minimap centres the view on that spot of the plate.
		listenForMinimap ()
		{
			const minimap = this.minimap
			const jump = event =>
			{
				const rect = minimap.getBoundingClientRect()
				this.viewport.scrollLeft = (event.clientX - rect.left) / rect.width * this.stage.offsetWidth - this.viewport.clientWidth / 2
				this.viewport.scrollTop = (event.clientY - rect.top) / rect.height * this.stage.offsetHeight - this.viewport.clientHeight / 2
			}

			minimap.addEventListener('pointerdown', event =>
			{
				minimap.setPointerCapture(event.pointerId)
				jump(event)
			})
			minimap.addEventListener('pointermove', event =>
			{
				if (minimap.hasPointerCapture(event.pointerId))
				{
					jump(event)
				}
			})
		},

		drawMinimap ()
		{
			if (this.scale === null)
			{
				return
			}

			const viewport = this.viewport
			const width = this.stage.offsetWidth
			const height = this.stage.offsetHeight

			Object.assign(this.minimapView.style, {
				left: `${viewport.scrollLeft / width * 100}%`,
				top: `${viewport.scrollTop / height * 100}%`,
				width: `${Math.min(1, viewport.clientWidth / width) * 100}%`,
				height: `${Math.min(1, viewport.clientHeight / height) * 100}%`,
			})
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
					this.minimapImage.src = response.src
				}

				XF.setupHtmlInsert(response.html, html =>
				{
					this.report.replaceChildren(html)
				})
			}, { global: false, skipError: true })
		},
	})

	XF.Cav7SignaturePreview = XF.Element.newHandler({
		options: {
			previewUrl: '',
		},

		timer: null,
		latest: 0,

		init ()
		{
			this.body = this.target.querySelector('.js-signaturePreview')
			this.target.addEventListener('input', () => this.schedule())
			this.target.addEventListener('change', () => this.refresh())
			this.refresh()
		},

		schedule ()
		{
			clearTimeout(this.timer)
			this.timer = setTimeout(() => this.refresh(), PREVIEW_DELAY)
		},

		refresh ()
		{
			clearTimeout(this.timer)
			const sent = ++this.latest

			XF.ajax('post', this.options.previewUrl, XF.getDefaultFormData(this.target), response =>
			{
				if (sent !== this.latest || !response.html)
				{
					return
				}

				XF.setupHtmlInsert(response.html, html =>
				{
					this.body.replaceChildren(html)
				})
			}, { global: false, skipError: true })
		},
	})

	XF.Element.register('cav7-plate-preview', 'XF.Cav7PlatePreview')
	XF.Element.register('cav7-signature-preview', 'XF.Cav7SignaturePreview')
})(window, document)
