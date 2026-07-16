/* global jQuery */
/**
 * Toggles the ifthenpay inline box when the payment method changes.
 *
 * Fluent Forms' own payment handler hides every `.ff_pay_inline` block on a
 * method change and then re-shows only the Stripe/Square wrappers — it has no
 * branch for third-party methods. So we hide/show our `.iftp-ff-inline` box
 * ourselves based on the currently selected method, independent of core.
 */
(function ($) {
	'use strict';

	var METHOD = 'ifthenpay';

	function selectedMethod($form) {
		var $radios = $form.find('input.ff_payment_method[type="radio"]');
		if ($radios.length) {
			return $radios.filter(':checked').val();
		}

		return (
			$form.find('input.ff_selected_payment_method').val() ||
			$form.find('input.ff_payment_method').val()
		);
	}

	/**
	 * Fluent Forms appends every method's inline contents after the full list of
	 * radio options, so by default our box renders under the last option (e.g.
	 * Stripe). Move it to sit directly beneath the ifthenpay option instead.
	 * Single-method forms have no radios — the box is already in the right place.
	 */
	function place($form) {
		var $inline = $form.find('.iftp-ff-inline');
		if (!$inline.length || $inline.data('iftpPlaced')) {
			return;
		}
		var $option = $form
			.find('input.ff_payment_method[type="radio"][value="' + METHOD + '"]')
			.closest('.ff-el-form-check');
		if ($option.length) {
			$inline.insertAfter($option);
		}
		$inline.data('iftpPlaced', true);
	}

	function sync($form) {
		var $inline = $form.find('.iftp-ff-inline');
		if (!$inline.length) {
			return;
		}
		if (selectedMethod($form) === METHOD) {
			$inline.css('display', 'block');
		} else {
			$inline.css('display', 'none');
		}
	}

	/**
	 * Parses a CSS color (as returned by getComputedStyle) into {r,g,b,a}.
	 * Returns null for keywords we can't read a channel from (e.g. "transparent"
	 * is handled separately since browsers report it as rgba(0,0,0,0)).
	 */
	function parseColor(value) {
		var match = value && value.match(/rgba?\(([^)]+)\)/);
		if (!match) {
			return null;
		}
		var parts = match[1].split(',').map(function (part) {
			return parseFloat(part);
		});
		return { r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1 };
	}

	/**
	 * Walks up from `el` to find the nearest ancestor (inclusive) with an opaque
	 * background color, since the box itself and most wrappers are transparent
	 * and just inherit the page/theme background underneath them.
	 */
	function findEffectiveBackground(el) {
		var node = el;
		while (node) {
			var color = parseColor(window.getComputedStyle(node).backgroundColor);
			if (color && color.a > 0) {
				return color;
			}
			node = node.parentElement;
		}
		return null;
	}


	function isDarkColor(color) {
		var yiq = (color.r * 299 + color.g * 587 + color.b * 114) / 1000;
		return yiq < 128;
	}

	function isDarkBackground(el) {
		var background = findEffectiveBackground(el);
		if (background) {
			return isDarkColor(background);
		}

		return !!(
			window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
		);
	}

	/**
	 * Detects whether the box sits on a dark background and swaps the ifthenpay
	 * logo + method icons to their dark-mode variants, toggling a class so CSS
	 * can adjust the box's own text/border colors to match.
	 */
	function applyTheme($inline) {
		var $box = $inline.find('.iftp-ff-inline-box');
		if (!$box.length) {
			return;
		}
		var dark = isDarkBackground($box.get(0));
		$inline.toggleClass('iftp-ff-inline--dark', dark);
		$inline.find('img[data-src-dark]').each(function () {
			var $img = $(this);
			var next = dark ? $img.attr('data-src-dark') : $img.attr('data-src-light');
			if (next && $img.attr('src') !== next) {
				$img.attr('src', next);
			}
		});
	}

	function applyThemeToAll() {
		$('.iftp-ff-inline').each(function () {
			applyTheme($(this));
		});
	}

	$(document).on('change', 'input.ff_payment_method', function () {
		sync($(this).closest('form'));
	});

	$(function () {
		$('form').each(function () {
			var $form = $(this);
			place($form);
			sync($form);
		});
		applyThemeToAll();


		$(window).on('load', applyThemeToAll);

		if (window.matchMedia) {
			window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyThemeToAll);
		}


		if (window.MutationObserver) {
			var scheduled = false;
			var observer = new MutationObserver(function () {
				if (scheduled) {
					return;
				}
				scheduled = true;
				window.requestAnimationFrame(function () {
					scheduled = false;
					applyThemeToAll();
				});
			});
			observer.observe(document.documentElement, {
				attributes: true,
				attributeFilter: ['class', 'style', 'data-theme'],
				subtree: false,
			});
			observer.observe(document.body, {
				attributes: true,
				attributeFilter: ['class', 'style', 'data-theme'],
				subtree: false,
			});
		}
	});
})(jQuery);
