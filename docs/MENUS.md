# Edit the public menus

The Menus editor is delivered in **0.8.11 / Build 20261020.12**, with verified CI, actual package acceptance, merge and saved-download read-back. See [the release record](releases/0.8.11-menus.md) for exact evidence. The affected customer host still requires its own update and behavior checks.

## Open the editor

Sign in as the current active owner, then choose **Site & Design → Menus**. Choose **Header** for the main navigation or **Footer** for the footer's navigation links. Each location has its own saved order and revision. The editor starts with the current links, and changes take effect when you choose **Save menu**.

The Cart, Login/My Account, Join Now/Sign Out controls remain separate from the editable Header menu. The footer's legal links, branding and blue Cajun Web Pro credit remain separate from the editable Footer menu.

## Add or edit an item

1. Choose **Add item**, or select an existing item's name to open its fields.
2. Enter its **Menu label** and **Link destination**. The destination can be a site path such as `about/`, a page anchor such as `#contact`, or a full `https://` or `http://` address. The field suggests the existing main pages. `/` means this installation's homepage, including when the site is installed in a subfolder. An anchor remains on the current page, so that page must contain the anchor.
3. Choose **Same tab**, **New tab**, or **New window** under **Open link in**.
4. Leave **Show this item** checked to make it visible, then save the menu.

Labels support up to 80 characters. Each location supports up to 50 items in total, including submenus, and up to three levels. Destinations are validated before saving; scripts, invalid schemes and unsafe local paths are rejected. A rejected edit does not partly replace the saved menu.

**New tab** uses an ordinary separate-tab link. **New window** requests a separate window when the visitor activates the link. Browser preferences and popup policies can open a tab or block that request; the site cannot force the operating system's window choice. Both options isolate the opened page from the original page. Modified clicks keep the visitor's normal browser behavior. See [MDN's window-opening documentation](https://developer.mozilla.org/en-US/docs/Web/API/Window/open) for the browser behavior.

## Arrange items and submenus

Use an item's **up** and **down** buttons to change its order among items with the same parent. Select a **Parent item** to move it into a submenu, or choose **No parent — top level** to move it back to the main level. The parent selector excludes choices that would create a loop or exceed three levels.

Choose **Add submenu** in an item's editor to create a child directly beneath it. A parent can have its own destination and a separate submenu toggle. To make a heading that only opens its submenu, leave its destination blank, keep **Same tab**, and give it at least one child. A heading whose children are all hidden is omitted from public navigation.

Unchecking **Show this item** hides it and its children without deleting them. **Remove item** removes the item and its submenu branch from the current draft after confirmation. Review the draft and save to apply the change. **Discard unsaved changes** returns to the menu loaded when you opened the editor; after a rejected save, it reloads the current saved menu instead. Both discard and **Reload saved menu** ask before dropping unsaved edits.

Public submenus use separate toggle buttons and ordinary links. Visitors can open them by pointer or touch, or by focusing the toggle and pressing Enter or Space. Escape closes the active submenu and returns focus to its toggle. A large Header menu uses the expandable navigation control when it cannot fit a usable desktop row; the links remain reachable.

## Save, review and restore defaults

Saving updates that location on the public homepage and other public pages. Existing untouched menus retain their historical defaults. The first Footer edit starts with the common-page list, including Messages; saving the reviewed list applies it to both footer renderers. Custom Footer menus remain accessible at narrow widths; untouched defaults retain their existing responsive visibility.

An explicitly empty saved list removes that location's editable links. It does not remove Cart/account controls or the separate legal and credit areas. **Restore default menu** restores only the selected location after confirmation.

Open the public site after saving and check the relevant menu at desktop and phone widths. Use a real click to verify destinations and the selected opening behavior. Menu edits do not change Composer scenes, photographs, page content, membership permissions or protected-route authorization.

If another tab saved or reset the same location after you opened it, an older save is refused. Your attempted items remain in the editor for review and are still marked unsaved. Reload the current saved menu before reapplying those changes. Validation errors and an expired form token also retain the attempted items; review the message above the editor before continuing. Saving Header does not invalidate a Footer editor, or vice versa. A removed, deactivated or demoted owner cannot continue saving from an old session.

## Storage and upgrades

Menus use the existing protected settings storage in file mode or the existing MySQL settings table. No new database migration is required. Reads do not create menu settings; deliberate saves and resets use revision checks and current-owner authorization under the storage lock or transaction.

The complete Upgrade preserves existing configuration, uploaded media and runtime state. Source tests, HTTP/browser evidence and engineering documentation stay outside the customer ZIP. Follow [Update verification](UPDATE_VERIFICATION.md) to verify the uploaded and running build. Actual customer-host deployment remains unverified until that host is inspected.
