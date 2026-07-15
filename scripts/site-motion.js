(() => {
  const page = document.body;
  const hero = document.querySelector("main > section:first-child");
  if (!page || !hero) {
    return;
  }

  const reducedMotion = window.matchMedia(
    "(prefers-reduced-motion: reduce)",
  ).matches;

  page.classList.add("motion-ready");
  hero.classList.add("motion-hero");

  const explicitHeroContent = hero.querySelector(
    ":scope > .hero-content, :scope > .submit-hero-content",
  );
  const singleHeroChild =
    hero.children.length === 1 && hero.firstElementChild?.tagName === "DIV"
      ? hero.firstElementChild
      : null;
  const heroContent = explicitHeroContent || singleHeroChild || hero;

  const eyebrow = heroContent.querySelector(":scope > .eyebrow");
  let kickerRow = heroContent.querySelector(":scope > .hero-kicker-row");
  if (eyebrow && !kickerRow) {
    kickerRow = document.createElement("div");
    kickerRow.className = "hero-kicker-row";
    eyebrow.before(kickerRow);
    kickerRow.append(eyebrow);
  }

  if (kickerRow && !kickerRow.querySelector(".hero-signal")) {
    const signal = document.createElement("span");
    signal.className = "hero-signal";
    signal.setAttribute("aria-hidden", "true");
    signal.innerHTML = "<i></i><i></i><i></i><i></i><i></i><i></i><i></i>";
    kickerRow.append(signal);
  }

  const heroItems = heroContent.querySelectorAll(
    ":scope > .hero-kicker-row, :scope > h1, :scope > .hero-lead, :scope > .hero-actions, :scope > .button",
  );
  heroItems.forEach((item, index) => {
    item.classList.add("motion-hero-item");
    item.style.setProperty("--motion-order", index);
    if (!reducedMotion) {
      item.addEventListener(
        "animationend",
        () => item.classList.remove("motion-hero-item"),
        { once: true },
      );
    }
  });

  const revealSections = document.querySelectorAll(
    "main > section:not(:first-child)",
  );
  revealSections.forEach((section, index) => {
    section.classList.add("reveal-section");
    section.style.setProperty("--reveal-delay", `${(index % 2) * 70}ms`);
  });

  if (reducedMotion) {
    page.classList.add("reduce-motion");
    revealSections.forEach((section) => section.classList.add("is-visible"));
    return;
  }

  if ("IntersectionObserver" in window) {
    const revealObserver = new IntersectionObserver(
      (entries, observer) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) {
            return;
          }
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        });
      },
      { rootMargin: "0px 0px -12%", threshold: 0.12 },
    );
    revealSections.forEach((section) => revealObserver.observe(section));
  } else {
    revealSections.forEach((section) => section.classList.add("is-visible"));
  }

  const heroMedia = hero.querySelector(":scope > .hero-media");
  let frameRequested = false;
  const updateHeroDepth = () => {
    frameRequested = false;
    const heroBottom = hero.getBoundingClientRect().bottom;
    const depth = heroBottom > 0 ? Math.min(window.scrollY * 0.075, 48) : 48;
    (heroMedia || hero).style.setProperty("--hero-depth", `${depth}px`);
  };
  const requestHeroDepth = () => {
    if (frameRequested) {
      return;
    }
    frameRequested = true;
    window.requestAnimationFrame(updateHeroDepth);
  };

  updateHeroDepth();
  window.addEventListener("scroll", requestHeroDepth, { passive: true });
})();
