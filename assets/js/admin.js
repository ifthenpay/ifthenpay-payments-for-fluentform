/* global jQuery, iftpFfAdmin */
(function ($) {
	'use strict';

	let currentGatewayKey = '';
	let methodsData = [];
	let inFlightKey = null;
	let lastLoadedKey = null;

	function init() {
		$(document).on('click', '#iftp-ff-btn-connect', onConnect);
		$(document).on('click', '#iftp-ff-btn-disconnect', onDisconnect);
		$(document).on('click', '.iftp-ff-activate-btn', onActivateMethod);

		if (window.MutationObserver) {
			const observer = new window.MutationObserver(function () {
				tryInitMethodsTable();
				tryInitDefaultMethodSelect();
				fixifthenpayBadges();
				fixifthenpayMethodIcons();
				onGatewayKeyMaybeChanged();
			});
			observer.observe(document.body, { childList: true, subtree: true });
		}

		tryInitMethodsTable();
		tryInitDefaultMethodSelect();
		fixifthenpayBadges();
		fixifthenpayMethodIcons();


		$(document).on('click', '.el-select-dropdown__item', function () {
			window.setTimeout(onGatewayKeyMaybeChanged, 150);
		});


		window.setInterval(onGatewayKeyMaybeChanged, 400);
	}



	function tryInitMethodsTable() {
		if (!iftpFfAdmin || !iftpFfAdmin.methodsConfigPlaceholder) {
			return;
		}

		$('.el-form-item').each(function () {
			const $item = $(this);
			if ($item.attr('data-iftp-methods-processed') === '1') {
				return;
			}

			const $input = $item.find('.el-input__inner').filter(function () {
				return (
					$(this).attr('placeholder') ===
					iftpFfAdmin.methodsConfigPlaceholder
				);
			});
			if (!$input.length) {
				return;
			}

			$item.attr('data-iftp-methods-processed', '1');
			$item.find('.el-input').hide();

			const $container = $('<div class="iftp-ff-methods-wrapper"></div>');
			$item.append($container);


			inFlightKey = null;
			lastLoadedKey = null;

			const gatewayKey = getGatewayKeyValue();
			currentGatewayKey = gatewayKey;

			if (!gatewayKey) {
				$container.html(
					'<p class="iftp-ff-methods-empty">' +
						iftpFfAdmin.i18n.selectGatewayFirst +
						'</p>'
				);
			} else {
				loadMethodsTable(
					$container,
					$input,
					gatewayKey,
					JSON.parse($input.val() || '{}')
				);
			}
		});
	}

	function onGatewayKeyMaybeChanged() {
		const newKey = getGatewayKeyValue();
		if (newKey === currentGatewayKey) {
			return;
		}
		currentGatewayKey = newKey;

		const $item = findMethodsTableItem();
		if (!$item) {
			return;
		}
		const $input = $item.find(
			'.el-input__inner[placeholder="' +
				iftpFfAdmin.methodsConfigPlaceholder +
				'"]'
		);
		const $container = $item.find('.iftp-ff-methods-wrapper');
		if (!$container.length) {
			return;
		}

		if (!newKey) {
			lastLoadedKey = null;
			$container.html(
				'<p class="iftp-ff-methods-empty">' +
					iftpFfAdmin.i18n.selectGatewayFirst +
					'</p>'
			);
			syncVueInput($input, JSON.stringify({}));
			updateDefaultMethodSelect([]);
			return;
		}


		loadMethodsTable($container, $input, newKey, {});
	}

	function loadMethodsTable($container, $input, gatewayKey, savedConfig) {

		if (gatewayKey === inFlightKey || gatewayKey === lastLoadedKey) {
			return;
		}
		inFlightKey = gatewayKey;

		$container.html(
			'<div class="iftp-ff-methods-loading">' +
				'<span class="iftp-ff-spinner" aria-hidden="true"></span>' +
				'<span>' +
				iftpFfAdmin.i18n.loadingMethods +
				'</span>' +
				'</div>'
		);

		$.post(iftpFfAdmin.ajaxUrl, {
			action: 'iftp_ff_get_methods',
			nonce: iftpFfAdmin.nonce,
			gateway_key: gatewayKey,
			methods_config: JSON.stringify(savedConfig),
		})
			.done(function (response) {

				if (gatewayKey !== currentGatewayKey) {
					return;
				}
				lastLoadedKey = gatewayKey;
				if (
					!response.success ||
					!response.data ||
					!response.data.html
				) {
					$container.html(
						'<p class="iftp-ff-methods-empty">' +
							iftpFfAdmin.i18n.noMethods +
							'</p>'
					);
					return;
				}

				methodsData = response.data.methods_meta || [];


				$container.html(response.data.html);


				syncFromCheckboxes($container, $input);


				$container
					.off('change.iftp')
					.on('change.iftp', '.iftp-ff-method-checkbox', function () {
						const $cb = $(this);
						const $mi = $cb.closest('.iftp-ff-method-item');
						const $lbl = $cb.closest('.el-checkbox');
						const $inp = $cb.closest('.el-checkbox__input');
						if ($cb.is(':checked')) {
							$mi.addClass('iftp-ff-method-item--checked');
							$lbl.addClass('is-checked');
							$inp.addClass('is-checked');
						} else {
							$mi.removeClass('iftp-ff-method-item--checked');
							$lbl.removeClass('is-checked');
							$inp.removeClass('is-checked');
						}
						syncFromCheckboxes($container, $input);
						updateDefaultMethodSelect(methodsData);
					});

				updateDefaultMethodSelect(methodsData);
				applyDefaultIfEmpty(response.data.suggested_default || '');
			})
			.fail(function () {
				$container.html(
					'<p class="iftp-ff-methods-empty">' +
						iftpFfAdmin.i18n.error +
						'</p>'
				);
			})
			.always(function () {

				if (inFlightKey === gatewayKey) {
					inFlightKey = null;
				}
			});
	}

	function syncFromCheckboxes($container, $input) {
		const config = {};
		$container.find('.iftp-ff-method-checkbox').each(function () {
			const entity = $(this).data('entity');
			const account = $(this).data('account');
			const enabled = $(this).is(':checked');
			if (entity) {
				config[entity] = { enabled, account: account || '' };
			}
		});
		syncVueInput($input, JSON.stringify(config));
	}

	function findMethodsTableItem() {
		let found = null;
		$('.el-form-item').each(function () {
			if (
				$(this).find(
					'.el-input__inner[placeholder="' +
						iftpFfAdmin.methodsConfigPlaceholder +
						'"]'
				).length
			) {
				found = $(this);
				return false;
			}
		});
		return found;
	}



	function onActivateMethod() {
		const $btn = $(this);
		const entity = String($btn.data('entity') || '');
		const gatewayKey = String($btn.data('gateway-key') || '');

		if ($btn.prop('disabled') || !entity || !gatewayKey) {
			return;
		}

		$btn.prop('disabled', true).text(iftpFfAdmin.i18n.activating);
		$btn.siblings('.iftp-ff-activate-error').remove();

		$.post(iftpFfAdmin.ajaxUrl, {
			action: 'iftp_ff_activate_method',
			nonce: iftpFfAdmin.nonce,
			entity,
			gateway_key: gatewayKey,
		})
			.done(function (response) {
				if (response.success) {
					$btn.text(iftpFfAdmin.i18n.activationSent).addClass(
						'iftp-ff-activate-btn--sent'
					);
				} else {
					const msg =
						response.data && response.data.message
							? response.data.message
							: iftpFfAdmin.i18n.error;
					$btn.prop('disabled', false).text(
						iftpFfAdmin.i18n.activate
					);
					$btn.after(
						'<span class="iftp-ff-activate-error">' +
							msg +
							'</span>'
					);
				}
			})
			.fail(function () {
				$btn.prop('disabled', false).text(iftpFfAdmin.i18n.activate);
			});
	}



	function tryInitDefaultMethodSelect() {
		if (!iftpFfAdmin || !iftpFfAdmin.defaultMethodPlaceholder) {
			return;
		}

		$('.el-form-item').each(function () {
			const $item = $(this);
			if ($item.attr('data-iftp-default-processed') === '1') {
				return;
			}

			const $input = $item.find('.el-input__inner').filter(function () {
				return (
					$(this).attr('placeholder') ===
					iftpFfAdmin.defaultMethodPlaceholder
				);
			});
			if (!$input.length) {
				return;
			}

			$item.attr('data-iftp-default-processed', '1');
			$item.find('.el-input').hide();

			const savedVal = $input.val() || '';
			const $select = $(
				'<select class="iftp-ff-default-select el-input__inner"></select>'
			);
			$item.append($select);

			populateDefaultMethodSelect($select, methodsData, savedVal);

			$select.on('change', function () {
				syncVueInput($input, $select.val() || '');
			});
		});
	}

	function updateDefaultMethodSelect(methods) {
		const $item = $(
			'.el-form-item[data-iftp-default-processed="1"]'
		).first();
		if (!$item.length) {
			return;
		}

		const $select = $item.find('.iftp-ff-default-select');
		if (!$select.length) {
			return;
		}

		const $input = $item.find(
			'.el-input__inner[placeholder="' +
				iftpFfAdmin.defaultMethodPlaceholder +
				'"]'
		);
		const currentVal = $select.val() || $input.val() || '';

		populateDefaultMethodSelect($select, methods, currentVal);
		syncVueInput($input, $select.val() || '');
	}

	function populateDefaultMethodSelect($select, methods, currentVal) {
		$select.empty();


		(methods || []).forEach(function (method) {
			const entity = method.entity || '';
			const label = method.label || entity;
			const account = method.account || '';
			if (!account) {
				return;
			}
			$select.append($('<option></option>').val(entity).text(label));
		});

		if (!$select.find('option').length) {
			return;
		}


		if (
			currentVal &&
			$select.find('option[value="' + currentVal + '"]').length
		) {
			$select.val(currentVal);
		} else if ($select.find('option[value="CCARD"]').length) {
			$select.val('CCARD');
		} else {
			$select.val($select.find('option').first().val());
		}
	}


	function applyDefaultIfEmpty(suggestedDefault) {
		if (!suggestedDefault) {
			return;
		}
		const $item = $(
			'.el-form-item[data-iftp-default-processed="1"]'
		).first();
		if (!$item.length) {
			return;
		}
		const $select = $item.find('.iftp-ff-default-select');
		if (!$select.length || $select.val()) {
			return;
		}
		if ($select.find('option[value="' + suggestedDefault + '"]').length) {
			const $input = $item.find(
				'.el-input__inner[placeholder="' +
					iftpFfAdmin.defaultMethodPlaceholder +
					'"]'
			);
			$select.val(suggestedDefault);
			syncVueInput($input, suggestedDefault);
		}
	}



	function getGatewayKeyValue() {

		const $methodsItem = findMethodsTableItem();
		if (!$methodsItem) {
			return '';
		}
		const $gwItem = $methodsItem.prevAll('.el-form-item').first();
		if (!$gwItem.length) {
			return '';
		}
		const $elInput = $gwItem.find('.el-input__inner');
		return $elInput.length ? $elInput.val() || '' : '';
	}



	function fixifthenpayBadges() {
		$('.ff_badge').each(function () {
			const $badge = $(this);
			const text = $badge.text().trim().toLowerCase();
			if (
				text === 'ifthenpay' &&
				!$badge.hasClass('ff_badge_ifthenpay')
			) {
				$badge
					.removeClass('ff_badge_default')
					.addClass('ff_badge_ifthenpay');
			}
			if (
				$badge.hasClass('ff_badge_ifthenpay') &&
				iftpFfAdmin &&
				iftpFfAdmin.iconUrl
			) {
				const $icon = $badge.find('img');
				if ($icon.length) {
					$icon.attr('src', iftpFfAdmin.iconUrl);
				} else {
					$badge.prepend(
						$('<img>', {
							src: iftpFfAdmin.iconUrl,
							alt: 'ifthenpay',
						})
					);
				}

				$badge
					.contents()
					.filter(function () {
						return this.nodeType === 3;
					})
					.remove();
			}
		});
	}



	function fixifthenpayMethodIcons() {
		if (!iftpFfAdmin || !iftpFfAdmin.methodIcons) {
			return;
		}
		$('.ff_brand[class*="ff_brand_"]').each(function () {
			const $brand = $(this);
			if ($brand.data('iftpIconFixed')) {
				return;
			}
			const match = /ff_brand_(\S+)/.exec(this.className);
			const entity = match ? match[1].toLowerCase() : '';
			const iconUrl = iftpFfAdmin.methodIcons[entity];
			if (!iconUrl) {
				return;
			}
			$brand.data('iftpIconFixed', true);
			const $icon = $brand.find('img');
			if ($icon.length) {
				$icon.attr('src', iconUrl);
			} else {
				$brand.append($('<img>', { src: iconUrl, alt: entity }));
			}
		});
	}



	function syncVueInput($input, newValue) {
		const input = $input[0];
		if (!input) {
			return;
		}
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		);
		if (setter && setter.set) {
			setter.set.call(input, newValue);
		} else {
			input.value = newValue;
		}
		const ev = document.createEvent('HTMLEvents');
		ev.initEvent('input', true, true);
		input.dispatchEvent(ev);
		const ev2 = document.createEvent('HTMLEvents');
		ev2.initEvent('change', true, true);
		input.dispatchEvent(ev2);
	}



	function onConnect() {
		const $btn = $(this);
		const $msg = $('#iftp-ff-msg');
		const key = $('#iftp-ff-backoffice-key').val().trim();

		$msg.hide();
		if (!key) {
			$msg.css('color', 'red').text(iftpFfAdmin.i18n.error).show();
			return;
		}

		$btn.prop('disabled', true).text(iftpFfAdmin.i18n.connecting);

		$.post(iftpFfAdmin.ajaxUrl, {
			action: 'iftp_ff_connect_backoffice',
			nonce: iftpFfAdmin.nonce,
			backoffice_key: key,
		})
			.done(function (response) {
				if (response.success) {
					window.location.reload();
				} else {
					const msg =
						response.data && response.data.message
							? response.data.message
							: iftpFfAdmin.i18n.error;
					$msg.css('color', 'red').text(msg).show();
					$btn.prop('disabled', false).text(iftpFfAdmin.i18n.connect);
				}
			})
			.fail(function () {
				$msg.css('color', 'red').text(iftpFfAdmin.i18n.error).show();
				$btn.prop('disabled', false).text(iftpFfAdmin.i18n.connect);
			});
	}

	function onDisconnect() {
		const $btn = $(this);
		const $msg = $('#iftp-ff-msg');

		$msg.hide();
		$btn.prop('disabled', true).text(iftpFfAdmin.i18n.disconnecting);

		$.post(iftpFfAdmin.ajaxUrl, {
			action: 'iftp_ff_disconnect_backoffice',
			nonce: iftpFfAdmin.nonce,
		})
			.done(function (response) {
				if (response.success) {
					window.location.reload();
				} else {
					$msg.css('color', 'red')
						.text(iftpFfAdmin.i18n.error)
						.show();
					$btn.prop('disabled', false).text(
						iftpFfAdmin.i18n.disconnect
					);
				}
			})
			.fail(function () {
				$msg.css('color', 'red').text(iftpFfAdmin.i18n.error).show();
				$btn.prop('disabled', false).text(iftpFfAdmin.i18n.disconnect);
			});
	}

	$(document).ready(init);
})(jQuery);
