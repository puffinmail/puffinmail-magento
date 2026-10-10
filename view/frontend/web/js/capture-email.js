/**
 * PuffinMail: remember the email a guest types at the standard checkout (#customer-email), so an abandoned
 * cart can be recovered. Posts to /puffinmail/cart/email with the form key.
 */
define(['jquery', 'mage/cookies'], function ($) {
    'use strict';

    var pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    return function (config) {
        var last = '';

        $(document).on('change blur', '#customer-email', function () {
            var email = String($(this).val() || '').trim().toLowerCase();
            if (email === last || !pattern.test(email)) {
                return;
            }
            last = email;
            $.post(config.url, { email: email, form_key: $.mage.cookies.get('form_key') });
        });
    };
});
