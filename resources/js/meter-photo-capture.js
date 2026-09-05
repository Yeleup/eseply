/**
 * Camera capture for the meter reading photo field.
 *
 * A controller fills the readings in from a phone while walking the zone, so
 * the photo of the meter is taken on the spot far more often than it is picked
 * from the gallery. FilePond offers a single drop area, and the system file
 * chooser behind it may or may not surface the camera depending on the vendor
 * shell — so the two paths are made explicit instead: «Сделать фото» opens the
 * camera through a `capture` input, «Из галереи» opens FilePond's own browser.
 *
 * The file taken by the camera is handed to FilePond via `addFile()`, which is
 * the same entry point a normal pick uses: client-side resizing, validation and
 * the upload to Livewire all stay exactly as they are.
 */

const CAMERA_LABEL = 'Сделать фото';

const GALLERY_LABEL = 'Из галереи';

const BUTTONS_CLASS = 'fi-meter-photo-capture';

/**
 * Set on the field only once the buttons are actually in the DOM. FilePond's
 * own «перетащите файл» label is hidden by this class and not by a media
 * query, so a field whose script failed to load keeps the drop area it has
 * always had instead of losing every way to attach a photo.
 */
const READY_CLASS = 'fi-meter-photo-capture-ready';

/**
 * `capture` is honoured by mobile browsers only; a desktop browser silently
 * ignores it and opens the ordinary file dialog, which would make the camera
 * button lie about what it does. A coarse pointer is the signal that the
 * device is the phone or tablet the controller carries.
 */
function isCameraDevice() {
    return window.matchMedia?.('(pointer: coarse)').matches === true;
}

/**
 * Filament renders `disabled` on the file input, and FilePond mirrors it onto
 * the browse input it replaces that element with — so whichever of the two is
 * currently in the DOM answers the question. `isDisabled` is a closure
 * parameter inside Filament's Alpine component and is not readable from here,
 * and the state can change after the field is built, so this is checked on
 * every click rather than once at startup.
 */
function isFieldDisabled(root) {
    return Array.from(root.querySelectorAll('input[type="file"]')).some(input => input.disabled);
}

function createButton(label, onClick) {
    const button = document.createElement('button');

    button.type = 'button';
    button.textContent = label;
    button.addEventListener('click', onClick);

    return button;
}

/**
 * @param {HTMLElement} root The Filament file upload wrapper, which is also the
 *                           Alpine component root.
 * @param {object} component The `fileUploadFormComponent` Alpine data.
 */
window.initMeterPhotoCapture = function (root, component) {
    if (! isCameraDevice() || root.querySelector(`.${BUTTONS_CLASS}`)) {
        return;
    }

    const cameraInput = document.createElement('input');

    cameraInput.type = 'file';
    cameraInput.accept = 'image/*';
    cameraInput.hidden = true;
    cameraInput.setAttribute('capture', 'environment');

    cameraInput.addEventListener('change', () => {
        const file = cameraInput.files?.[0];

        // Reset before handing the file over: without it, retaking the same
        // shot twice in a row fires no second "change" event.
        cameraInput.value = '';

        if (file) {
            component.pond?.addFile(file);
        }
    });

    const buttons = document.createElement('div');

    buttons.className = BUTTONS_CLASS;
    buttons.append(
        createButton(CAMERA_LABEL, () => {
            if (! isFieldDisabled(root)) {
                cameraInput.click();
            }
        }),
        // FilePond replaces the input Filament rendered with one of its own, so
        // the gallery button reaches for that element rather than for `$refs`.
        createButton(GALLERY_LABEL, () => {
            if (! isFieldDisabled(root)) {
                root.querySelector('input[id^="filepond--browser-"]')?.click();
            }
        }),
        cameraInput,
    );

    root.prepend(buttons);
    root.classList.add(READY_CLASS);
};
