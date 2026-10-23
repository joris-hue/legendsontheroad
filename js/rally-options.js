const rallyOptionsDialog = document.querySelector("#rally-options");
const openRallyOptionsButton = document.querySelector("#open-rally-options");
const closeRallyOptionsButton = document.querySelector("[data-close-rally-options]");
const contactDialog = document.querySelector("#contact-dialog");
const openContactButton = document.querySelector("#open-contact");
const closeContactButton = document.querySelector("[data-close-contact]");

if (
	rallyOptionsDialog instanceof HTMLDialogElement
	&& openRallyOptionsButton instanceof HTMLButtonElement
	&& closeRallyOptionsButton instanceof HTMLButtonElement
) {
	openRallyOptionsButton.addEventListener("click", () => {
		rallyOptionsDialog.showModal();
	});

	closeRallyOptionsButton.addEventListener("click", () => {
		rallyOptionsDialog.close();
	});

	rallyOptionsDialog.addEventListener("click", (event) => {
		if (event.target === rallyOptionsDialog) {
			rallyOptionsDialog.close();
		}
	});
}

if (
	contactDialog instanceof HTMLDialogElement
	&& openContactButton instanceof HTMLButtonElement
	&& closeContactButton instanceof HTMLButtonElement
) {
	openContactButton.addEventListener("click", () => {
		contactDialog.showModal();
	});

	closeContactButton.addEventListener("click", () => {
		contactDialog.close();
	});

	contactDialog.addEventListener("click", (event) => {
		if (event.target === contactDialog) {
			contactDialog.close();
		}
	});
}
