(() => {
  const providers = {
    local: { count: 5, extension: "wav", directory: "local" },
    gemini: { count: 30, extension: "mp3", directory: "gemini" },
  };

  function createCards(provider, details) {
    const list = document.getElementById(`${provider}-voice-list`);
    for (let number = 1; number <= details.count; number += 1) {
      const index = String(number).padStart(2, "0");
      const card = document.createElement("article");
      card.className = "voice-card";
      card.innerHTML = `
        <h4>Voice ${number}</h4>
        <div class="voice-card-actions">
          <button class="voice-play" type="button">Play</button>
          <button class="voice-stop" type="button" disabled>Stop</button>
        </div>
        <audio preload="none" src="assets/voice-samples/${details.directory}/voice-${index}.${details.extension}"></audio>
      `;
      const audio = card.querySelector("audio");
      const play = card.querySelector(".voice-play");
      const stop = card.querySelector(".voice-stop");
      const reset = () => {
        audio.pause();
        audio.currentTime = 0;
        play.textContent = "Play";
        stop.disabled = true;
      };
      play.addEventListener("click", async () => {
        document.querySelectorAll(".voice-card audio").forEach((other) => {
          if (other !== audio) {
            other.pause();
            other.currentTime = 0;
            const otherCard = other.closest(".voice-card");
            otherCard.querySelector(".voice-play").textContent = "Play";
            otherCard.querySelector(".voice-stop").disabled = true;
          }
        });
        if (audio.paused) {
          try {
            await audio.play();
            play.textContent = "Playing";
            stop.disabled = false;
          } catch {
            reset();
          }
        } else {
          reset();
        }
      });
      stop.addEventListener("click", reset);
      audio.addEventListener("ended", reset);
      list.append(card);
    }
  }

  Object.entries(providers).forEach(([provider, details]) => createCards(provider, details));

  document.querySelectorAll(".voice-provider-button").forEach((button) => {
    button.addEventListener("click", () => {
      const selected = button.dataset.provider;
      document.querySelectorAll(".voice-provider-button").forEach((item) => {
        const active = item === button;
        item.classList.toggle("is-selected", active);
        item.setAttribute("aria-selected", String(active));
      });
      document.querySelectorAll(".voice-panel").forEach((panel) => {
        panel.hidden = panel.dataset.panel !== selected;
      });
    });
  });
})();
