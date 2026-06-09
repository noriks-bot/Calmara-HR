jQuery(document).ready(function ($) {
  // Auto-discover and bind all license forms on the page.
  // Convention: <form id="adt-{key}-license-form" data-ajax-action="...">
  $('form[data-ajax-action]').each(function () {
    var $form = $(this);
    var ajaxAction = $form.data('ajax-action');

    // Extract plugin key from form id: "adt-pfe-license-form" → "pfe".
    var key = $form
      .attr('id')
      .replace(/^adt-/, '')
      .replace(/-license-form$/, '');

    adtBindLicenseForm($form, key, ajaxAction);
  });

  // Auto-populate from query string and trigger submit on the matching form.
  var license_key = adtGetParamByName('license_key');
  var activation_email = adtGetParamByName('activation_email');

  if (license_key && activation_email) {
    var $target = $('form[data-ajax-action]').first();

    if ($target.length) {
      $target.find('#adt-license-key').val(license_key);
      $target.find('#adt-activation-email').val(activation_email);

      var queryString = location.href.split('?')[1];
      var newURL = location.href.split('?')[0];
      if (queryString) {
        newURL +=
          '?' +
          queryString
            .replace(/&?license_key=[^&]*/g, '')
            .replace(/&?activation_email=[^&]*/g, '')
            .replace(/^&/, ''); // Remove leading & if present
      }
      window.history.pushState('', '', newURL);

      setTimeout(function () {
        $target.find('#activate-license').trigger('click');
      }, 1000);
    }
  }

  // Dismiss notification.
  $('.adt-license-settings').on('click', '.adt-license-notification .notice-dismiss', function (e) {
    e.preventDefault();

    var $notice = $(this).closest('.adt-notice');
    $notice.slideUp();
    $notice.removeClass('notice-success notice-error');
  });
});

/**
 * Bind submit handler to a license form.
 *
 * @param {jQuery} $form      The form element.
 * @param {string} key        Plugin key derived from form id, e.g. 'pfe'.
 * @param {string} ajaxAction WP AJAX action name from data-ajax-action attribute.
 */
function adtBindLicenseForm($form, key, ajaxAction) {
  var $ = jQuery;

  $form.on('submit', function (e) {
    e.preventDefault();

    var $_this = $(this);
    var $btn = $_this.find('#activate-license');
    var $spinner = $_this.find('.input-button .spinner');
    var $notification = $_this
      .closest('.adt-license-settings-container, .adt-container')
      .find('.adt-license-notification');
    var $status = $_this.closest('.tab').find('.license-status span[class^="text-color"]');
    var $activation_notice = $('.adt-activate-license-notice');

    $_this.find('input').prop('disabled', true);
    $btn.prop('disabled', true);
    $spinner.css('visibility', 'visible');

    $notification.find('.adt-notice').removeClass('notice-success notice-error');
    $notification.find('.adt-notice').slideUp();

    jQuery
      .ajax({
        type: 'POST',
        url: ajaxurl,
        data: {
          action: ajaxAction,
          license_key: $_this.find('#adt-license-key').val(),
          activation_email: $_this.find('#adt-activation-email').val(),
          ajax_nonce: $_this.find('#adt_' + key + '_activate_license_nonce').val(),
        },
        dataType: 'json',
      })
      .done(function (response) {
        var isSuccess = response.status === 'success';
        var message = isSuccess ? response.success_msg : response.error_msg ? response.error_msg : response.message;

        $notification.find('.adt-notice .message').html(message + '. <strong>Refreshing...</strong>');
        $notification.find('.adt-notice').addClass(isSuccess ? 'notice-success' : 'notice-error');
        $notification.find('.adt-notice').slideDown();

        setTimeout(function () {
          location.reload();
        }, 3500);

        if (isSuccess && $activation_notice.length) {
          $activation_notice.slideUp();
        }

        if (response.license_status != null) {
          var i18n = adt_pfp_license_args.i18n;
          var statusMap = {
            expired: { label: i18n.expired + ' (' + response.expired_date + ')', cls: 'text-color-red' },
            inactive: { label: i18n.inactive, cls: 'text-color-red' },
            active: { label: i18n.active, cls: 'text-color-green' },
          };
          var statusEntry = statusMap[response.license_status];

          $status.removeClass('text-color-red text-color-green');
          $status.html(statusEntry.label);
          $status.addClass(statusEntry.cls);
        }
      })
      .fail(function (jqXHR, textStatus, errorThrown) {
        console.log(jqXHR);
        console.log(textStatus);
        console.log(errorThrown);
      })
      .always(function () {
        $_this.find('input').prop('disabled', false);
        $btn.prop('disabled', false);
        $spinner.css('visibility', 'hidden');
      });
  });
}

/**
 * Get URL parameter by name.
 *
 * @param {string} param The parameter name.
 * @return {string|undefined} The parameter value.
 */
function adtGetParamByName(param) {
  var queryIndex = window.location.href.indexOf('?');
  if (queryIndex === -1) {
    return undefined;
  }
  var url = window.location.href.slice(queryIndex + 1).split('&');
  for (var i = 0; i < url.length; i++) {
    var urlparam = url[i].split('=');
    if (urlparam[0] === param) {
      return adtDecodeString(urlparam[1]);
    }
  }
}

/**
 * Decode URL-encoded string.
 *
 * @param {string} string The encoded string.
 * @return {string} The decoded string.
 */
function adtDecodeString(string) {
  return string
    .replace(/%40/gi, '@')
    .replace(/%3A/gi, ':')
    .replace(/%24/gi, '$')
    .replace(/%2C/gi, ',')
    .replace(/%3B/gi, ';')
    .replace(/%2B/gi, '+')
    .replace(/%3D/gi, '=')
    .replace(/%3F/gi, '?')
    .replace(/%2F/gi, '/');
}
