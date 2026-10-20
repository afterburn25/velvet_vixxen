# Booking Form Builder

Sign in as the owner and open **Members & Communications → Booking Form Builder**. Changes affect the public `/booking/` form. Submitted inquiries remain in **Booking Requests**, where the owner can review their answers, attachments, status, and private conversation.

## Create and arrange questions

Select **New Field**, enter a label, choose an answer type, and add optional help text or an example answer. Choose whether an answer is required and whether the field appears on the public form, then select **Add Field**.

Each field has these controls:

| Control | Result |
| --- | --- |
| **Edit** | Changes the label, type, help, example, choices, required/optional rule, and visibility. Select **Save Field** to publish the change to future forms. |
| **↑ / ↓** | Moves the question one position up or down in the public form. |
| **Disable / Enable** | Hides or restores the question without deleting its definition. Disabled questions are not required and do not collect submitted values. |
| **Delete** | Removes the question from future forms after a browser confirmation. Earlier inquiries keep the original question and answer. |
| **Preview Public Form** | Opens the actual booking page in another tab. |

The original inquiry type, name, email, phone, date, city, venue, budget, and details fields are configurable. They can be required, optional, disabled, or removed. The builder does not secretly require a removed contact field. On a phone, the field editor appears before the field list so it is easy to reach.

## Answer types and validation

The seven requested answer types are available, along with the existing date/time question:

| Type | Behavior |
| --- | --- |
| **Short text** | A single-line answer. |
| **Paragraph** | A longer answer with line breaks. |
| **Email address** | Requires a complete email address, such as `name@example.com`, when answered. Normal email aliases such as `name+booking@example.com` are supported. |
| **US phone number** | Checks the area code, exchange, and ten-digit length. Digits format while typing as `(337) 450-8598`; an optional leading `1` or `+1` is normalized. Backspace also works through formatting punctuation. |
| **Dropdown** | Selects one configured choice. |
| **Single choice** | Displays linked radio buttons. Selecting one answer clears the previous answer in that group. |
| **Multiple choices** | Allows several checkboxes, subject to the configured minimum. |
| **Date and time** | Collects a valid date and time. |

For choice fields, enter **2–30 distinct choices, one per line**. A multiple-choice minimum cannot exceed the number of available choices. Required checkbox groups always need at least one selection. An optional group can be left entirely unanswered; if the visitor answers it, the configured minimum applies. Repeating the same submitted choice does not count as additional selections.

Every question can be required or optional. Validation errors appear beside the affected question. The server independently validates the answers, including email, phone, choice membership, single-answer radio groups, and checkbox minimums; disabling browser validation does not bypass these rules.

The original **Email address** field supplies the address used for email acknowledgments and replies. Its label may change, but keep its answer type as Email address if those emails are needed. Additional custom email fields are saved as answers. If the original contact email is removed, disabled, left blank while optional, or changed to another type, the inquiry still reaches the booking queue; no email is sent to an invalid or empty address. Signed-in members retain their private account conversation.

## Preserve earlier inquiries

Each new inquiry saves the exact questions, choices, and normalized answers shown at submission. Open an inquiry in **Booking Requests** and review **Submitted questions and answers**. Renaming, reordering, disabling, or deleting a field later does not rewrite that history. Bookings created before the builder remain readable with their original details.

The form has a revision. If another owner tab has changed it, a stale owner save is rejected. Review the current definition before saving again. A visitor submitting a form opened before a definition change is asked to review the current questions before resubmitting.

For SQL-backed sites, apply the pending booking migration through **System → Database Updates** before using the builder. See [UPGRADING.md](UPGRADING.md) for the owner and command-line process. Missing migrations never trigger a reinstall.

## Private references and submission protection

When enabled in Communication Settings, visitors may attach up to five matching JPG, PNG, WebP, PDF, or plain-text `.txt` files, within the configured size limit. The entire upload batch is validated before any file is stored. An invalid file prevents the inquiry from being saved. Disabling reference uploads also rejects attempts to submit files through a modified form.

References are saved in protected private storage. Direct public file URLs are blocked. Downloads require an authorized administrator or the member associated with the inquiry; a guest cannot retrieve an attachment merely by knowing its ID.

Public submission also requires the session's CSRF token and consent checkbox. A hidden spam trap and a server-side attempt limit help protect the intake form. Do not include identity documents, payment-card details, or other unnecessary sensitive information in booking references.
