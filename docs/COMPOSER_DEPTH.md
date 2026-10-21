# Composer depth and foreground masking

Version **0.8.12 / Build 20261021.1** is verified, merged and delivered through [PR #29](https://github.com/afterburn25/velvet_vixxen/pull/29). See the [release record](releases/0.8.12-composer-depth.md), [CI evidence](releases/0.8.12-composer-depth-ci.json) and [saved-delivery evidence](releases/0.8.12-composer-depth-delivery.json). The affected owner's host still needs its own complete Upgrade and installation checks.

## Place a photo farther into the room

Open **Visual Composer**, choose **Homepage Hero** or **Visual Footer**, and select the **Desktop**, **Tablet** or **Mobile** layout you want to edit. Keep the room background, transparent photo cutout and live text/buttons as separate elements.

1. Under **Background → Depth direction**, set **Room depth X %** and **Room depth Y %** to the point farther into the room. The initial point is 50% across and 35% down. Put this point where the photo should move as it recedes, such as the back of the bed.
2. Under **Layers**, select the photo. Move **Depth in scene** from **Near** toward **Far**. The selected photo gets smaller and moves toward the room depth point. Other photos keep their own depth settings.
3. Drag the photo or adjust its X position, Y position and width to finish the placement. These controls describe its visible position and size on the canvas, including its depth adjustment. The ordinary alignment, keyboard movement, rotation, flip, shadow and opacity controls remain available.
4. **Reset depth** returns this photo to depth zero and its base placement. Moving or resizing while depth is applied also updates that base placement, so Reset depth is not a replacement for Undo.

Depth works with the existing background artwork; it does not automatically identify the bed or generate a different photograph. A transparent cutout lets the room show around the person. The source photo stays unchanged.

## Put the bed or furniture in front

A foreground outline marks the part of the background that should cover selected photos. For example, outline the near edge of the bed to hide the lower part of a cutout placed behind it.

1. Open **Background → Foreground furniture** and choose **Draw outline**.
2. Click around the furniture edge on the canvas. Use between **3 and 64 points**. Follow the perimeter without crossing the outline back over itself; click each corner once rather than repeating the first point to close it.
3. Choose **Finish outline**. Composer closes the shape and turns on **Show foreground furniture**. Invalid, crossed, repeated or flat outlines remain unfinished with an explanation.
4. Open **Layers**, select the photo, and turn on **Place behind foreground**. Only photos with this option are covered by the outlined furniture. A photo can use depth without being behind furniture, or be behind furniture at depth zero.

The foreground reuses the matching part of the current background, including its fitting, position, zoom, appearance adjustments and dark overlay. It follows the furniture when the background is panned or zoomed. Live text, grouped actions and standalone button layers remain above the foreground.

If most of a photo is covered, select it from the Layers list to adjust it. Its position and size remain independently editable.

## Refine or remove an outline

While drawing, **Undo point** removes the latest point, **Finish outline** applies the shape, and **Cancel outline** discards that unfinished replacement. Ctrl+Z removes the latest drawing point; Escape cancels. Save, Preview, Publish and history restoration wait until the unfinished outline is finished or cancelled. Switching the section, device or property tab cancels the unfinished outline safely.

After finishing, keep the **Background** tab open to drag the numbered points. **Edit outline points** also provides numeric X/Y fields for each point, expressed as percentages of the original background image. Invalid completed-outline edits revert to the last valid shape. The outline follows the source image rather than a fixed screen rectangle, including when scene height changes.

Turn off **Show foreground furniture** to hide the effect while retaining the outline. **Clear outline** removes it; ordinary Undo can restore that completed edit.

Changing to a different background disables the old outline. Draw a new outline for the new image, or return to the original image and deliberately enable its retained outline again. An outline cannot silently cover an unrelated background. If the selected background cannot load and the page displays its fallback image, the outline is not applied to that fallback.

## Devices, saving and publication

Depth, the room depth point and the foreground outline belong to the selected device layout. Check Desktop, Tablet and Mobile separately. The existing **Copy layout** action copies these settings with the layout, so review the result on the receiving device.

Existing scenes with none of the new settings keep their ordinary appearance. Completed edits participate in the existing Undo/Redo, Save, Full Page Preview, Publish and publication-history workflow. Saving a draft does not publish it. A retained published version restores its depth and foreground settings with the rest of that scene.

Composer and the public Hero/Visual Footer use the same projection and foreground renderer. The preceding proportional scene geometry, compact bottom footer and menu controls remain separate features.

## Verified acceptance

The source suite passed in both required PHP 8.2/8.3 jobs, including **215 scene-depth assertions**, **55 runtime-inventory checks** and **692 root-layout assertions**. Actual-package depth HTTP acceptance passed in file: **1,954 checks** over root/nested installations; mysql_php83: **1,954 checks** over root/nested installations; mysql_php82: **1,954 checks** over root/nested installations. The four file/MySQL root/nested browser cases passed **3,460 assertions**, **48 editor/preview/public scene comparisons** and **4,608 RGBA pixel samples** with maximum channel error **1/255**. This includes covered/exposed/front regions and horizontal/vertical polygon boundaries (maximum edge error **0/255**), both background fits and transformed/filtered backgrounds. Real controls exercise drawing, numeric and direct point edits, invalid shape refusal, depth-aware movement, reset, undo/redo, lock/hide/copy, save/reload, preview, publish and history restore. Retained scale acceptance passed **52 scene comparisons / 24 legal-footer layouts**, alongside the required existing menus, footer-placement, application, ownership, OPcache, PHP 8.2/8.3, MySQL and package gates.

All seven required CI jobs and 74 configured required steps passed. The source, all actual CI checkouts and final merge have the same complete Git tree; the four saved customer files match the downloaded gated runtime artifact. Exact execution counts and artifact bindings are recorded in the [release evidence](releases/0.8.12-composer-depth.md).

## Updating an existing site

Use the complete Upgrade for an installed site. Customer configuration, uploads, storage, owner identity and keys are preserved by its existing exclusions. The unchanged unfinished-installer repair remains separately targeted to 0.8.1.

Follow [Update verification](UPDATE_VERIFICATION.md) to verify the complete runtime inventory and running build after applying the Upgrade. The new PHP depth validator and shared JavaScript renderer are required dependencies in a 0.8.12 inventory; a partial overwrite cannot be certified as complete.
