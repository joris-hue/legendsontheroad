const rallyOptionsDialog = document.querySelector("#rally-options");
const openRallyOptionsButton = document.querySelector("#open-rally-options");
const closeRallyOptionsButton = document.querySelector("[data-close-rally-options]");

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
