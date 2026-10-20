(() => {
    'use strict';
    const editor = document.querySelector('.booking-field-editor');
    if (editor) {
        const type = editor.querySelector('[name="field_type"]');
        const update = () => {
            editor.querySelector('[data-booking-choice-settings]').hidden = !['select', 'radio', 'checkbox'].includes(type.value);
            editor.querySelector('[data-booking-minimum-settings]').hidden = type.value !== 'checkbox';
        };
        type.addEventListener('change', update);
        update();
    }
    document.querySelectorAll('[data-confirm-field-delete]').forEach(button => {
        button.addEventListener('click', event => {
            if (!window.confirm('Remove "' + button.dataset.confirmFieldDelete + '" from future booking forms? Earlier inquiries will keep this question and its answers.')) event.preventDefault();
        });
    });

    const form = document.querySelector('form[data-booking-form]');
    if (!form) return;
    form.noValidate = true;
    const fields = [...form.querySelectorAll('[data-booking-type]')];
    const phoneDigits = raw => {
        let digits = raw.replace(/\D/g, '');
        if (digits.length === 11 && digits.startsWith('1')) digits = digits.slice(1);
        return digits;
    };
    const validPhone = value => {
        const digits = phoneDigits(value);
        return /^\+?[\d\s().-]+$/.test(value) && /^[2-9]\d{2}[2-9]\d{6}$/.test(digits) && !/^(\d)\1{9}$/.test(digits);
    };
    const formatPhone = raw => {
        const digits = phoneDigits(raw);
        if (digits.length > 10) return raw;
        if (digits.length === 0) return '';
        if (digits.length < 3) return '(' + digits;
        if (digits.length === 3) return '(' + digits + ')';
        if (digits.length < 7) return '(' + digits.slice(0, 3) + ') ' + digits.slice(3);
        return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
    };
    const showError = (field, message) => {
        field.querySelector('.booking-field-error').textContent = message;
        field.querySelectorAll('input,select,textarea').forEach(input => {
            input.setAttribute('aria-invalid', message ? 'true' : 'false');
            input.setCustomValidity(message);
        });
    };
    const validateField = field => {
        const type = field.dataset.bookingType;
        const required = field.dataset.bookingRequired === 'true';
        const controls = [...field.querySelectorAll('input,select,textarea')];
        const control = controls[0];
        if (!control) return true;
        let message = '';
        if (type === 'checkbox' || type === 'radio') {
            const selected = controls.filter(input => input.checked).length;
            const minimum = type === 'radio' ? (required ? 1 : 0) : Math.max(required ? 1 : 0, Number(field.dataset.bookingMinimum) || 0);
            if ((required || selected > 0) && selected < minimum) message = 'Choose at least ' + minimum + ' option' + (minimum === 1 ? '.' : 's.');
        } else {
            const value = control.value.trim();
            if (!value && required) message = 'This field is required.';
            else if (value && type === 'email' && (!/^[^\s@]+@[^\s@]+\.[^\s@.]+$/.test(value) || control.validity.typeMismatch)) {
                message = 'Enter a complete email address, such as name@example.com.';
            } else if (value && type === 'phone' && !validPhone(value)) {
                message = 'Enter a valid 10-digit US phone number, including the area code.';
            } else if (value && control.maxLength > 0 && value.length > control.maxLength) {
                message = 'Use ' + control.maxLength + ' characters or fewer.';
            }
        }
        showError(field, message);
        return !message;
    };
    fields.forEach(field => {
        field.querySelectorAll('input,select,textarea').forEach(control => {
            if (field.dataset.bookingType === 'phone') {
                control.addEventListener('beforeinput', event => {
                    const backward = event.inputType === 'deleteContentBackward';
                    const forward = event.inputType === 'deleteContentForward';
                    const caret = control.selectionStart;
                    if ((!backward && !forward) || caret === null || caret !== control.selectionEnd) return;
                    const original = control.value;
                    let index = backward ? caret - 1 : caret;
                    if (index < 0 || index >= original.length || /\d/.test(original[index])) return;
                    while (index >= 0 && index < original.length && !/\d/.test(original[index])) index += backward ? -1 : 1;
                    if (index < 0 || index >= original.length) return;
                    event.preventDefault();
                    const digitsBefore = original.slice(0, index).replace(/\D/g, '').length;
                    control.value = formatPhone(original.slice(0, index) + original.slice(index + 1));
                    let seen = 0;
                    let nextCaret = digitsBefore === 0 ? Math.min(1, control.value.length) : control.value.length;
                    for (let i = 0; i < control.value.length && digitsBefore > 0; i++) {
                        if (/\d/.test(control.value[i])) seen++;
                        if (seen === digitsBefore) { nextCaret = i + 1; break; }
                    }
                    control.setSelectionRange(nextCaret, nextCaret);
                    if (field.querySelector('.booking-field-error').textContent) validateField(field);
                });
                control.addEventListener('input', () => {
                    const original = control.value;
                    const originalCaret = control.selectionStart ?? original.length;
                    const beforeCaret = original.slice(0, originalCaret).replace(/\D/g, '').length;
                    const formatted = formatPhone(original);
                    control.value = formatted;
                    if (formatted !== original && document.activeElement === control) {
                        let count = 0;
                        let caret = formatted.length;
                        for (let i = 0; i < formatted.length; i++) {
                            if (/\d/.test(formatted[i])) count++;
                            if (count === beforeCaret) { caret = i + 1; break; }
                        }
                        if (original.length === originalCaret) caret = formatted.length;
                        control.setSelectionRange(caret, caret);
                    }
                    if (field.querySelector('.booking-field-error').textContent) validateField(field);
                });
            }
            control.addEventListener('blur', () => validateField(field));
            control.addEventListener('change', () => validateField(field));
        });
    });
    form.addEventListener('submit', event => {
        let firstInvalid = null;
        fields.forEach(field => {
            if (!validateField(field) && firstInvalid === null) firstInvalid = field.querySelector('input,select,textarea');
        });
        const consent = form.querySelector('[name="consent"]');
        if (consent && !consent.checked) {
            consent.setCustomValidity('Confirm that these details may be used to respond.');
            firstInvalid ??= consent;
        } else if (consent) consent.setCustomValidity('');
        if (firstInvalid) {
            event.preventDefault();
            firstInvalid.focus({preventScroll: true});
            firstInvalid.scrollIntoView({behavior: 'smooth', block: 'center'});
            firstInvalid.reportValidity();
        }
    });
})();
