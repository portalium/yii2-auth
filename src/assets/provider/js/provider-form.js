/**
 * Identity provider form: shows only the fields of the chosen type (LDAP, OIDC, SAML).
 */
(function () {
    'use strict';

    function sync(select) {
        document.querySelectorAll('.provider-type-fields').forEach(function (box) {
            box.hidden = box.getAttribute('data-type') !== select.value;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var select = document.getElementById('authprovider-type');
        if (!select) {
            return;
        }
        select.addEventListener('change', function () {
            sync(select);
        });
        sync(select);
    });
}());
