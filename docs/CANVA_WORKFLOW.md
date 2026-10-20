# Canva export/import workflow

Canva is a manual artwork bridge in 0.8.1. **Edit in Canva opens the design URL saved by the owner. It does not synchronize designs, run an automatic export or call a tested Canva API.**

## Hero and Footer artwork

1. Open Site & Design → Canva Templates. Download the starter SVG or starter kit and import it into Canva, or use an existing owner design.
2. Design the room background, decorative shapes and other static artwork. Keep a floor/placement zone and space for the live website copy.
3. Save the Canva design link in its template card. This provides a convenient return link only.
4. Export an individual PNG, JPEG or WebP. Import that file through the template card. Optionally assign it as the draft Hero or Footer background.
5. Open Visual Composer. Imported artwork replaces the background for each device while existing cutout photo layers remain separate. Adjust each device's crop, focal point, appearance, photo placement and live text/button box.
6. Save Draft and open Full Page Preview. Publish the relevant Hero/Footer when the actual page is ready. Publishing creates a restorable version.

Upload the owner's transparent PNG/WebP photos through the Media Library or Composer. Move, scale and crop them in Composer. Do not flatten them into the Canva room artwork. The 30 bundled people-free scenes can also be selected directly from Composer without Canva.

## Gallery covers and video posters

Export static cover/poster artwork from Canva, then upload it in Content & Media → Photo Galleries or Videos using the existing cover/poster controls. The template bridge can retain the artwork and saved edit link; media publishing and access controls still belong to the Media Center. Changing the Canva design later requires a new export and another upload.

## Functional website content

Navigation, biography, statistics, headlines, buttons, prices, forms, memberships, paywalls and media players remain real HTML/JavaScript and application records. Edit these in their website editors. They are not baked into an image and do not acquire functionality by being drawn in Canva.

Automatic Canva OAuth/export/synchronization remains unimplemented. No credentials are requested or bundled for this manual workflow.
