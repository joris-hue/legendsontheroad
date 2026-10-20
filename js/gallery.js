const gallery = document.querySelector(".gallery-grid");
const controls = document.querySelector(".gallery-controls");

if (gallery instanceof HTMLElement && controls instanceof HTMLElement) {
	let slides = Array.from(gallery.querySelectorAll("img"));

	if (slides.length === 0 && gallery.dataset.photoCount) {
		const photoCount = Number(gallery.dataset.photoCount);
		if (!Number.isInteger(photoCount) || photoCount < 1) {
			throw new Error("De fotogalerij heeft een ongeldig aantal foto's.");
		}

		slides = Array.from({ length: photoCount }, (_, index) => {
			const photoNumber = String(index + 1).padStart(2, "0");
			const image = document.createElement("img");
			image.src = `images/rally-gallery/photo-${photoNumber}.jpeg`;
			image.alt = `Rallyfoto ${index + 1}`;
			image.loading = "lazy";
			image.decoding = "async";
			gallery.append(image);
			return image;
		});
	}
	let currentSlide = 0;

	if (slides.length > 0) {
		const previousButton = document.createElement("button");
		previousButton.className = "gallery-arrow";
		previousButton.type = "button";
		previousButton.textContent = "← Vorige";
		previousButton.setAttribute("aria-label", "Vorige foto");

		const status = document.createElement("span");
		status.className = "gallery-status";
		status.setAttribute("aria-live", "polite");
		status.setAttribute("aria-atomic", "true");

		const nextButton = document.createElement("button");
		nextButton.className = "gallery-arrow";
		nextButton.type = "button";
		nextButton.textContent = "Volgende →";
		nextButton.setAttribute("aria-label", "Volgende foto");

		const showSlide = (index) => {
			currentSlide = (index + slides.length) % slides.length;

			slides.forEach((slide, slideIndex) => {
				const isActive = slideIndex === currentSlide;
				slide.classList.toggle("is-active", isActive);
				slide.hidden = !isActive;
				slide.setAttribute("aria-hidden", String(!isActive));
				slide.loading = isActive ? "eager" : "lazy";
			});

			status.textContent = `${currentSlide + 1} / ${slides.length}`;
		};

		previousButton.addEventListener("click", () => {
			showSlide(currentSlide - 1);
		});

		nextButton.addEventListener("click", () => {
			showSlide(currentSlide + 1);
		});

		const slideshow = gallery.closest(".photo-gallery");
		slideshow?.addEventListener("keydown", (event) => {
			if (event.key === "ArrowLeft") {
				event.preventDefault();
				showSlide(currentSlide - 1);
			} else if (event.key === "ArrowRight") {
				event.preventDefault();
				showSlide(currentSlide + 1);
			}
		});

		controls.replaceChildren(previousButton, status, nextButton);
		showSlide(0);
	}
}
