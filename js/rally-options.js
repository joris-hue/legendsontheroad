const rallyOptionsDialog = document.querySelector("#rally-options");
const openRallyOptionsButton = document.querySelector("#open-rally-options");
const closeRallyOptionsButton = document.querySelector("[data-close-rally-options]");
const contactDialog = document.querySelector("#contact-dialog");
const openContactButton = document.querySelector("#open-contact");
const closeContactButton = document.querySelector("[data-close-contact]");
const contactForm = document.querySelector("#contact-form");

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

if (contactForm instanceof HTMLFormElement) {
	contactForm.addEventListener("submit", (event) => {
		event.preventDefault();

		const formData = new FormData(contactForm);
		const name = String(formData.get("name") || "").trim();
		const email = String(formData.get("email") || "").trim();
		const subject = String(formData.get("subject") || "").trim();
		const message = String(formData.get("message") || "").trim();
		const body = `Naam: ${name}\nE-mailadres: ${email}\n\n${message}`;
		const mailto = `mailto:info@legendsontheroad.be?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;

		window.location.href = mailto;
	});
}
