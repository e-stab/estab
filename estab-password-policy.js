(function () {
  'use strict';

  var selector = '[data-estab-password-minimum-codepoints]';

  function unicodeLength(value) {
    var length = 0;
    for (var character of value) {
      length += 1;
    }
    return length;
  }

  function validate(input) {
    var minimum = Number.parseInt(
      input.getAttribute('data-estab-password-minimum-codepoints'),
      10
    );
    if (!Number.isInteger(minimum) || minimum < 1 || input.value === '') {
      input.setCustomValidity('');
      return true;
    }
    if (unicodeLength(input.value) < minimum) {
      input.setCustomValidity(
        'Das Kennwort muss mindestens ' + minimum
          + ' Unicode-Zeichen enthalten.'
      );
      return false;
    }
    input.setCustomValidity('');
    return true;
  }

  document.addEventListener('input', function (event) {
    if (event.target instanceof HTMLInputElement
        && event.target.matches(selector)) {
      validate(event.target);
    }
  });

  document.addEventListener('submit', function (event) {
    if (!(event.target instanceof HTMLFormElement)) {
      return;
    }
    var fields = event.target.querySelectorAll(selector);
    var firstInvalid = null;
    fields.forEach(function (field) {
      if (!validate(field) && firstInvalid === null) {
        firstInvalid = field;
      }
    });
    if (firstInvalid !== null) {
      event.preventDefault();
      firstInvalid.reportValidity();
      firstInvalid.focus();
    }
  });
}());
